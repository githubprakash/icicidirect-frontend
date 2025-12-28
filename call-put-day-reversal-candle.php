<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
$conn = new mysqli("localhost", "root", "root", "options_m_db_by_fut");

// --- 1. Mode selection logic ---
$mode = $_GET['mode'] ?? 'buy'; 

// 'spot' और 'sell' दोनों के लिए हम C-P वाली टेबल यूज करेंगे
$tableName = ($mode === 'sell' || $mode === 'spot') ? 'option_prices_5m_combined_option_sell' : 'option_prices_5m_combined';

$historyRange = $_GET['history_range'] ?? '1m';
$cutoffDate = "";
if ($historyRange !== 'all') {
    $months = (int)$historyRange;
    $cutoffDate = date('Y-m-d', strtotime("-$months months"));
}

$datesList = [];
$havingSql = $cutoffDate ? "HAVING start_date >= '$cutoffDate'" : "";
$dateRes = $conn->query("SELECT MIN(DATE(datetime)) as start_date, strikePrice, expiryDate 
                         FROM $tableName 
                         GROUP BY strikePrice, expiryDate 
                         $havingSql 
                         ORDER BY start_date DESC");

while($r = $dateRes->fetch_assoc()) { $datesList[] = $r; }

$rawSelection = $_GET['selection'] ?? ''; 
if ($rawSelection && strpos($rawSelection, '|') !== false) {
    $parts = explode('|', $rawSelection);
    $selectedDate = $parts[0];
    $selectedStrike = $parts[1];
} else {
    $selectedDate = $datesList[0]['start_date'] ?? '';
    $selectedStrike = $datesList[0]['strikePrice'] ?? '';
}

$selectedExpiry = '';
foreach($datesList as $item) { 
    if($item['start_date'] == $selectedDate && $item['strikePrice'] == $selectedStrike) { 
        $selectedExpiry = $item['expiryDate']; 
        break; 
    } 
}

$intervalKey = $_GET['interval'] ?? '15m';
$intervalMap = ['5m' => 300, '15m' => 900, '30m' => 1800, '1h' => 3600, '2h' => 7200, '4h' => 14400, '1d' => 86400];
$seconds = $intervalMap[$intervalKey] ?? 900;

$labels = []; $candleData = []; $daySeps = []; $hourSeps = []; $srAnnotations = []; $rawVolumes = [];
$dPocSeries = []; $hPocSeries = []; $m30PocSeries = [];

if ($selectedDate && $selectedStrike) {
    $sql = "SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(datetime)/$seconds)*$seconds) as time_slot,
            SUBSTRING_INDEX(GROUP_CONCAT(open ORDER BY datetime ASC), ',', 1) as o,
            MAX(high) as h, MIN(low) as l,
            SUBSTRING_INDEX(GROUP_CONCAT(close ORDER BY datetime DESC), ',', 1) as c,
            SUM(volume) as total_v, DATE(datetime) as d_only
            FROM $tableName
            WHERE strikePrice = '$selectedStrike' 
              AND DATE(datetime) >= '$selectedDate' 
              AND DATE(datetime) <= '$selectedExpiry'
              AND TIME(datetime) BETWEEN '09:15:00' AND '15:30:00'
            GROUP BY time_slot, d_only ORDER BY time_slot ASC";
            
    $result = $conn->query($sql);
    
    $prevDate = null; $lastHour = null;
    $dayProfile = []; $hourProfile = []; $m30Profile = [];
    $tHigh = 0; $tLow = 999999;

    while($row = $result->fetch_assoc()) {
        $ts = strtotime($row['time_slot']);
        $currD = $row['d_only'];
        $timePart = date('H:i', $ts);
        $timeLabel = date('d M H:i', $ts);
        
        $strikeVal = (float)$selectedStrike;
        
        if ($mode === 'spot') {
            $c = (float)$row['c'] + $strikeVal;
            $o = (float)$row['o'] + $strikeVal;
            $h = (float)$row['h'] + $strikeVal;
            $l = (float)$row['l'] + $strikeVal;
        } else {
            $c = (float)$row['c'];
            $o = (float)$row['o'];
            $h = (float)$row['h'];
            $l = (float)$row['l'];
        }

        $priceClose = (int)round($c, 0);
        $vol = (int)$row['total_v'];

        if ($prevDate && $currD != $prevDate) {
            $daySeps[] = ['x' => $timeLabel, 'label' => date('d M (D)', $ts)];
            $srAnnotations[] = ['high' => $tHigh, 'low' => $tLow];
            $dayProfile = []; $hourProfile = []; $m30Profile = [];
            $tHigh = 0; $tLow = 999999;
        }

        $dayProfile[$priceClose] = ($dayProfile[$priceClose] ?? 0) + $vol;
        $dPocSeries[] = array_search(max($dayProfile), $dayProfile);

        $hourKey = date('Y-m-d H', $ts);
        if($lastHour && $lastHour != $hourKey) $hourProfile = [];
        $hourProfile[$priceClose] = ($hourProfile[$priceClose] ?? 0) + $vol;
        $hPocSeries[] = array_search(max($hourProfile), $hourProfile);
        $lastHour = $hourKey;

        $m30Key = date('Y-m-d H:', $ts) . (date('i', $ts) < 30 ? '00' : '30');
        $m30Profile[$priceClose] = ($m30Profile[$priceClose] ?? 0) + $vol;
        $m30PocSeries[] = array_search(max($m30Profile), $m30Profile);

        if ($seconds < 3600 && $timePart != '09:15' && strpos($timePart, ':15') !== false) {
            $hourSeps[] = ['x' => $timeLabel];
        }

        if($h > $tHigh) $tHigh = $h;
        if($l < $tLow) $tLow = $l;

        $labels[] = $timeLabel;
        $candleData[] = [round($o,2), round($h,2), round($l,2), round($c,2)];
        $rawVolumes[] = $vol;
        $prevDate = $currD;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Synthetic Spot - Help Manual Integrated</title>
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <style>
        html, body { height: 100%; margin: 0; padding: 0; overflow: hidden; background: #fff; font-family: 'Segoe UI', sans-serif; }
        .header { height: 55px; background: white; display: flex; align-items: center; padding: 0 15px; gap: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); border-bottom: 1px solid #eee; }
        .chart-card { height: calc(100vh - 85px); margin: 10px; background: white; border-radius: 8px; border: 1px solid #e0e0e0; }
        .btn { background: #2563eb; color: white; border: none; padding: 7px 12px; border-radius: 4px; cursor: pointer; font-weight: 600; font-size: 11px; }
        select { padding: 5px; border-radius: 4px; border: 1px solid #ccc; font-size: 11px; background: #f9fafb; }
        .btn-tool { background: #64748b; color:white; padding:7px 12px; border-radius:4px; font-size:11px; cursor:pointer; text-decoration:none; border:none;}
        .btn-tool.active { background: #f59e0b !important; }
        .switch-label { font-size: 10px; font-weight: bold; display: flex; align-items: center; gap: 3px; cursor: pointer; white-space: nowrap; }
        
        /* Modal Style */
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); }
        .modal-content { background: white; margin: 5% auto; padding: 20px; border-radius: 12px; width: 60%; max-height: 80vh; overflow-y: auto; box-shadow: 0 10px 30px rgba(0,0,0,0.3); line-height: 1.6; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #eee; padding-bottom: 10px; margin-bottom: 15px; }
        .modal-body h3 { color: #2563eb; margin-top: 20px; border-left: 4px solid #2563eb; padding-left: 10px; }
        .close { color: #aaa; float: right; font-size: 28px; font-weight: bold; cursor: pointer; }
        .close:hover { color: black; }
        .help-btn { background: #10b981 !important; }
    </style>
</head>
<body>

<div class="header">
    <form method="GET" style="display:flex; gap:8px; align-items:center;">
        <select name="history_range" onchange="this.form.submit()">
            <option value="1" <?php echo $historyRange == '1' ? 'selected' : ''; ?>>1M</option>
            <option value="2" <?php echo $historyRange == '2' ? 'selected' : ''; ?>>2M</option>
            <option value="3" <?php echo $historyRange == '3' ? 'selected' : ''; ?>>3M</option>
            <option value="all" <?php echo $historyRange == 'all' ? 'selected' : ''; ?>>All</option>
        </select>

        <select name="mode" onchange="this.form.submit()">
            <option value="buy" <?php echo $mode=='buy'?'selected':''; ?>>BUY (C+P)</option>
            <option value="sell" <?php echo $mode=='sell'?'selected':''; ?>>SELL (C-P)</option>
            <option value="spot" <?php echo $mode=='spot'?'selected':''; ?>>SYNTHETIC SPOT</option>
        </select>

        <select name="selection" onchange="this.form.submit()">
            <?php 
            foreach($datesList as $d) {
                $val = $d['start_date'] . "|" . $d['strikePrice'];
                $is_selected = ($selectedDate == $d['start_date'] && $selectedStrike == $d['strikePrice']) ? 'selected' : '';
                $formattedDate = date('d M (Y)', strtotime($d['start_date']));
                echo "<option value='$val' $is_selected>" . $formattedDate . " - {$d['strikePrice']}</option>";
            }
            ?>
        </select>

        <select name="interval" onchange="this.form.submit()">
            <?php foreach($intervalMap as $k => $v) echo "<option value='$k' ".($intervalKey==$k?'selected':'').">$k</option>"; ?>
        </select>
        <button type="submit" class="btn">Update</button>
    </form>

    <div style="height: 20px; width: 1px; background: #ddd; margin: 0 5px;"></div>
    
    <select id="poc_type_select" onchange="savePocSettings()">
        <option value="none">POC: None</option>
        <option value="developing">Developing</option>
        <option value="hourly">1 Hour</option>
        <option value="m30">30 Min</option>
    </select>
    
    <button id="ray_btn" class="btn btn-tool" onclick="toggleTool('ray')">🪄 Ray</button>
    <button class="btn help-btn" onclick="openModal()">📖 Help</button>

    <label class="switch-label"><input type="checkbox" id="blast_switch" onchange="syncUI(); saveSignalSettings()"> Blast</label>
    <label class="switch-label"><input type="checkbox" id="div_switch" onchange="syncUI(); saveSignalSettings()"> Div</label>
    <label class="switch-label"><input type="checkbox" id="pricey_switch" onchange="syncUI(); saveSignalSettings()"> Pricey</label>
    <label class="switch-label"><input type="checkbox" id="sr_switch" onchange="syncUI()"> S&R</label>

    <div style="margin-left: auto; display:flex; gap:8px; align-items:center;">
        <button class="btn" style="background:#ef4444;" onclick="clearDrawings('rays')">🗑️ Rays</button>
        <button class="btn" style="background:#ef4444;" onclick="clearDrawings('frvp')">🗑️ FRVP</button>
    </div>
</div>

<!-- Modal Box -->
<div id="helpModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 style="margin:0;">Pro Trading System - Help Manual</h2>
            <span class="close" onclick="closeModal()">&times;</span>
        </div>
        <div class="modal-body">
            <h3>1. Synthetic Spot (C-P + Strike)</h3>
            <p>यह ऑप्शन प्रीमियम के आधार पर निफ्टी की असली वैल्यू दिखाता है। अगर असली निफ्टी और सिंथेटिक निफ्टी में बड़ा अंतर है, तो वह एक <b>Mean Reversion</b> का मौका होता है।</p>
            
            <h3>2. 💥 BLAST (Volatility Expansion)</h3>
            <p>जब वॉल्यूम पिछले 10 कैंडल्स के औसत से 3 गुना ज्यादा हो और कीमत POC के ऊपर निकले, तब यह सिग्नल आता है। यह <b>Option Buying (Gamma Blast)</b> के लिए सबसे बेस्ट समय है।</p>
            
            <h3>3. 🔻 DIV (Divergence)</h3>
            <p>जब कीमत नया हाई बनाए लेकिन वॉल्यूम कम हो जाए, तब यह लाल सिग्नल दिखता है। इसका मतलब है कि बड़े प्लेयर अब माल छोड़ रहे हैं और मार्केट यहाँ से गिर सकता है।</p>
            
            <h3>4. 💰 PRICEY (Overpriced Value)</h3>
            <p>जब कंबाइंड प्रीमियम (Straddle) अपने औसत से 20% ज्यादा महंगा हो जाए, तब यह दिखता है। यहाँ ऑप्शन खरीदने से बचें क्योंकि <b>Theta Decay</b> आपका पैसा खा सकता है।</p>
            
            <h3>5. POC (Point of Control)</h3>
            <p>चार्ट पर जो ऑरेंज लाइन दिखती है, वह वह लेवल है जहाँ सबसे ज्यादा वॉल्यूम ट्रेड हुआ है। यह मार्केट का <b>Magnet</b> और सबसे मजबूत सपोर्ट/रेजिस्टेंस है।</p>
            
            <h3>6. FRVP & Rays</h3>
            <p><b>FRVP:</b> माउस से सेलेक्ट किए गए एरिया का वॉल्यूम प्रोफाइल। <br> <b>Rays:</b> मैनुअल सपोर्ट/रेजिस्टेंस लाइन ड्रा करने के लिए।</p>
            
            <p style="background: #fef3c7; padding: 10px; border-radius: 6px; margin-top: 20px;"><b>Pro Tip:</b> हमेशा BLAST सिग्नल को POC सपोर्ट के साथ इस्तेमाल करें।</p>
        </div>
    </div>
</div>

<div class="chart-card" id="mainChart"></div>

<script>
const labels = <?php echo json_encode($labels); ?>;
const candleSeries = <?php echo json_encode($candleData); ?>;
const dPoc = <?php echo json_encode($dPocSeries); ?>;
const hPoc = <?php echo json_encode($hPocSeries); ?>;
const m30Poc = <?php echo json_encode($m30PocSeries); ?>;
const volumes = <?php echo json_encode($rawVolumes); ?>;
const daySeps = <?php echo json_encode($daySeps); ?>;
const hourSeps = <?php echo json_encode($hourSeps); ?>;
const srData = <?php echo json_encode($srAnnotations); ?>;

let frvpLines = JSON.parse(localStorage.getItem('v18_frvp')) || [];
let horizontalRays = JSON.parse(localStorage.getItem('v18_rays')) || [];
let currentTool = null; 

// Initial Signal Load from Storage
document.getElementById('blast_switch').checked = localStorage.getItem('v18_show_blast') !== 'false';
document.getElementById('div_switch').checked = localStorage.getItem('v18_show_div') !== 'false';
document.getElementById('pricey_switch').checked = localStorage.getItem('v18_show_pricey') !== 'false';

const xAnns = [];
hourSeps.forEach(h => { xAnns.push({ x: h.x, borderColor: 'rgba(0, 0, 0, 0.4)', borderWidth: 1.5, strokeDashArray: 4 }); });
daySeps.forEach(s => { xAnns.push({ x: s.x, borderColor: '#2563eb', borderWidth: 2, label: { text: s.label, style: { color: '#fff', background: '#2563eb' } } }); });

const options = {
    series: [
        { name: 'Price', type: 'candlestick', data: candleSeries.map((val, i) => ({ x: labels[i], y: val })) },
        { name: 'POC', type: 'line', data: [] }
    ],
    chart: { 
        height: '100%', animations: { enabled: false },
        toolbar: { autoSelected: 'selection', tools: { selection: true, zoom: true, pan: true } },
        events: { 
            selection: function(ctx, { xaxis }) { if (xaxis.min && xaxis.max) addFRVP(xaxis.min, xaxis.max); },
            click: function(e, chartContext, config) {
                if (currentTool === 'ray') {
                    const yAxis = chartContext.w.globals.yAxisScale[0];
                    const gridHeight = chartContext.w.globals.gridHeight;
                    const topOffset = chartContext.w.globals.translateY;
                    const relativeY = e.offsetY - topOffset;
                    const priceRange = yAxis.niceMax - yAxis.niceMin;
                    const clickedPrice = yAxis.niceMax - (relativeY * (priceRange / gridHeight));
                    addRay(parseFloat(clickedPrice.toFixed(2)));
                }
            }
        }
    },
    stroke: { show: true, width: [1.5, 2] },
    xaxis: { type: 'category', labels: { show: true, rotate: -45, style: { fontSize: '10px' } }, tickAmount: 15 },
    yaxis: { opposite: true, labels: { style: { fontSize: '10px' } } },
    annotations: { position: 'back', xaxis: xAnns, yaxis: [], points: [] },
    plotOptions: { candlestick: { colors: { upward: '#10b981', downward: '#ef4444' } } }
};

const chart = new ApexCharts(document.querySelector("#mainChart"), options);
chart.render();

// Modal Functions
function openModal() { document.getElementById("helpModal").style.display = "block"; }
function closeModal() { document.getElementById("helpModal").style.display = "none"; }
window.onclick = function(event) { if (event.target == document.getElementById("helpModal")) closeModal(); }

function getBlastSignals() {
    let signals = [];
    for (let i = 10; i < volumes.length; i++) {
        let avgVol = volumes.slice(i - 10, i).reduce((a, b) => a + b, 0) / 10;
        if (volumes[i] > avgVol * 3 && candleSeries[i][3] > dPoc[i]) {
            signals.push({ x: labels[i], y: candleSeries[i][1], marker: { size: 6, fillColor: '#facc15', strokeColor: '#000' }, label: { text: '💥 BLAST', style: { color: '#000', background: '#facc15', fontSize: '10px', fontWeight: 'bold' } } });
        }
    }
    return signals;
}

function getDivergenceSignals() {
    let signals = [];
    let lookback = 8; 
    for (let i = lookback; i < candleSeries.length; i++) {
        let curH = candleSeries[i][1];
        let prevH = candleSeries[i-lookback][1];
        if (curH > prevH && volumes[i] < volumes[i-lookback] * 0.7) {
            signals.push({ x: labels[i], y: curH + 2, marker: { size: 0 }, label: { text: '🔻 DIV', style: { color: '#fff', background: '#ef4444', fontSize: '10px', fontWeight: 'bold' }, offsetY: -12 } });
        }
    }
    return signals;
}

function getValueZoneSignals() {
    let signals = [];
    let period = 20; 
    for (let i = period; i < candleSeries.length; i++) {
        let currentPrice = candleSeries[i][3];
        let avgPrice = candleSeries.slice(i - period, i).reduce((acc, c) => acc + c[3], 0) / period;
        if (currentPrice > avgPrice * 1.20) {
            signals.push({ x: labels[i], y: candleSeries[i][1] + 5, marker: { size: 5, fillColor: '#ef4444' }, label: { text: '💰 PRICEY', style: { color: '#fff', background: '#ef4444', fontSize: '9px', fontWeight: 'bold' }, offsetY: -25 } });
        }
    }
    return signals;
}

function toggleTool(tool) {
    currentTool = (currentTool === tool) ? null : tool;
    document.getElementById('ray_btn').classList.toggle('active', currentTool === 'ray');
}

function addRay(price) {
    horizontalRays.push({ y: price });
    localStorage.setItem('v18_rays', JSON.stringify(horizontalRays));
    currentTool = null; syncUI();
}

function clearDrawings(type) {
    if(type === 'rays') { horizontalRays = []; localStorage.removeItem('v18_rays'); }
    if(type === 'frvp') { frvpLines = []; localStorage.removeItem('v18_frvp'); }
    syncUI();
}

function savePocSettings() {
    localStorage.setItem('v18_poc_type', document.getElementById('poc_type_select').value);
    syncUI();
}

function saveSignalSettings() {
    localStorage.setItem('v18_show_blast', document.getElementById('blast_switch').checked);
    localStorage.setItem('v18_show_div', document.getElementById('div_switch').checked);
    localStorage.setItem('v18_show_pricey', document.getElementById('pricey_switch').checked);
}

function syncUI() {
    const pocType = localStorage.getItem('v18_poc_type') || 'none';
    document.getElementById('poc_type_select').value = pocType;
    const showSR = document.getElementById('sr_switch').checked;
    
    let activePocData = (pocType === 'developing') ? dPoc : (pocType === 'hourly' ? hPoc : (pocType === 'm30' ? m30Poc : []));

    let yAnns = [];
    horizontalRays.forEach(ray => { yAnns.push({ y: ray.y, borderColor: '#4f46e5', borderWidth: 2, label: { text: ray.y, style: { color: '#fff', background: '#4f46e5' } } }); });
    if(showSR) {
        srData.forEach(l => {
            yAnns.push({ y: l.high, borderColor: 'rgba(239, 68, 68, 0.4)', strokeDashArray: 4 });
            yAnns.push({ y: l.low, borderColor: 'rgba(16, 185, 129, 0.4)', strokeDashArray: 4 });
        });
    }
    frvpLines.forEach(line => { yAnns.push({ y: line.y, x: line.xStart, x2: line.xEnd, borderColor: '#f97316', borderWidth: 3 }); });

    let allSignals = [];
    if(document.getElementById('blast_switch').checked) allSignals.push(...getBlastSignals());
    if(document.getElementById('div_switch').checked) allSignals.push(...getDivergenceSignals());
    if(document.getElementById('pricey_switch').checked) allSignals.push(...getValueZoneSignals());

    chart.updateOptions({ 
        annotations: { xaxis: xAnns, yaxis: yAnns, points: allSignals },
        series: [
            { name: 'Price', type: 'candlestick', data: candleSeries.map((val, i) => ({ x: labels[i], y: val })) },
            { name: 'POC', type: 'line', data: activePocData.map((val, i) => ({ x: labels[i], y: val })) }
        ]
    });
}

function addFRVP(minIdx, maxIdx) {
    let start = Math.max(0, Math.round(minIdx) - 1);
    let end = Math.min(candleSeries.length - 1, Math.round(maxIdx) - 1);
    let profile = {};
    for (let i = start; i <= end; i++) {
        let price = Math.round(candleSeries[i][3]); 
        profile[price] = (profile[price] || 0) + volumes[i];
    }
    if (Object.keys(profile).length > 0) {
        let poc = Object.keys(profile).reduce((a, b) => profile[a] > profile[b] ? a : b);
        frvpLines.push({ y: parseInt(poc), xStart: labels[start], xEnd: labels[end], label: poc });
        localStorage.setItem('v18_frvp', JSON.stringify(frvpLines));
        syncUI();
    }
}
window.onload = () => { syncUI(); };
</script>
</body>
</html>