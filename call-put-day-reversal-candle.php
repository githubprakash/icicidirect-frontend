<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 1. टाइमजोन सेट करें
date_default_timezone_set('Asia/Kolkata');

$conn = new mysqli("localhost", "root", "root", "options_m_db_by_fut");
$conn->query("SET time_zone = '+05:30'");

// --- 1. Mode selection logic ---
$mode = $_GET['mode'] ?? 'buy'; 
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
$intervalMap = [
    '5m' => 300, '15m' => 900, '30m' => 1800, '45m' => 2700, 
    '1h' => 3600, '75m' => 4500, '90m' => 5400, '2h' => 7200, 
    '4h' => 14400, '1d' => 86400
];
$seconds = $intervalMap[$intervalKey] ?? 900;

$labels = []; $candleData = []; $daySeps = []; $hourSeps = []; $rawVolumes = [];
$dPocSeries = []; $hPocSeries = []; $m30PocSeries = [];

// Profile logic
$dailyFixedPocs = [];
$fixedProfiles = [];
$latestDateStr = "";

if ($selectedDate && $selectedStrike) {
    $sql = "SELECT 
            TIMESTAMPADD(SECOND, FLOOR(TIMESTAMPDIFF(SECOND, CONCAT(DATE(datetime), ' 09:15:00'), datetime) / $seconds) * $seconds, CONCAT(DATE(datetime), ' 09:15:00')) as time_slot,
            SUBSTRING_INDEX(GROUP_CONCAT(open ORDER BY datetime ASC), ',', 1) as o,
            MAX(high) as h, MIN(low) as l,
            SUBSTRING_INDEX(GROUP_CONCAT(close ORDER BY datetime DESC), ',', 1) as c,
            SUM(volume) as total_v, DATE(datetime) as d_only
            FROM $tableName
            WHERE strikePrice = '$selectedStrike' 
              AND DATE(datetime) >= '$selectedDate' 
              AND DATE(datetime) <= '$selectedExpiry'
              AND TIME(datetime) BETWEEN '09:15:00' AND '15:30:00'
            GROUP BY d_only, time_slot ORDER BY time_slot ASC";
            
    $result = $conn->query($sql);
    
    $prevDate = null; $lastHour = null;
    $dayProfile = []; $hourProfile = []; $m30Profile = [];

    while($row = $result->fetch_assoc()) {
        $ts = strtotime($row['time_slot']);
        $currD = $row['d_only'];
        $timePart = date('H:i', $ts);
        $timeLabel = date('d M H:i', $ts);
        $strikeVal = (float)$selectedStrike;
        
        if ($mode === 'spot') {
            $c = (float)$row['c'] + $strikeVal; $o = (float)$row['o'] + $strikeVal;
            $h = (float)$row['h'] + $strikeVal; $l = (float)$row['l'] + $strikeVal;
        } else {
            $c = (float)$row['c']; $o = (float)$row['o']; $h = (float)$row['h']; $l = (float)$row['l'];
        }

        $priceClose = (int)round($c, 0);
        $vol = (int)$row['total_v'];

        if ($prevDate && $currD != $prevDate) {
            $daySeps[] = ['x' => $timeLabel, 'label' => date('d M (D)', $ts)];
            $dayProfile = []; $hourProfile = []; $m30Profile = [];
        }

        // Window Profiles
        if ($timePart >= '09:15' && $timePart < '09:45') {
            $fixedProfiles[$currD]['945'][$priceClose] = ($fixedProfiles[$currD]['945'][$priceClose] ?? 0) + $vol;
        }
        if ($timePart >= '09:15' && $timePart < '10:15') {
            $fixedProfiles[$currD]['1015'][$priceClose] = ($fixedProfiles[$currD]['1015'][$priceClose] ?? 0) + $vol;
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

        $labels[] = $timeLabel;
        $candleData[] = [round($o,2), round($h,2), round($l,2), round($c,2)];
        $rawVolumes[] = $vol;
        $prevDate = $currD;
    }
    $latestDateStr = $prevDate;

    foreach($fixedProfiles as $d => $profs) {
        $dailyFixedPocs[$d] = [
            'p945' => !empty($profs['945']) ? array_search(max($profs['945']), $profs['945']) : null,
            'p1015' => !empty($profs['1015']) ? array_search(max($profs['1015']), $profs['1015']) : null
        ];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Synthetic Spot - Persistent Settings</title>
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
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); }
        .modal-content { background: white; margin: 5% auto; padding: 20px; border-radius: 12px; width: 60%; max-height: 80vh; overflow-y: auto; line-height: 1.6; }
        .close { color: #aaa; float: right; font-size: 28px; font-weight: bold; cursor: pointer; }
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
            <?php foreach($datesList as $d) {
                $val = $d['start_date'] . "|" . $d['strikePrice'];
                $is_selected = ($selectedDate == $d['start_date'] && $selectedStrike == $d['strikePrice']) ? 'selected' : '';
                echo "<option value='$val' $is_selected>" . date('d M (Y)', strtotime($d['start_date'])) . " - {$d['strikePrice']}</option>";
            } ?>
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
    <button class="btn btn-tool" style="background:#10b981;" onclick="openModal()">📖 Help</button>

    <label class="switch-label"><input type="checkbox" id="blast_switch"> Blast</label>
    <label class="switch-label"><input type="checkbox" id="div_switch"> Div</label>
    <label class="switch-label"><input type="checkbox" id="pricey_switch"> Pricey</label>
    <label class="switch-label"><input type="checkbox" id="fixed_poc_switch" onchange="saveFixedSettings()"> Fixed</label>

    <div style="margin-left: auto; display:flex; gap:8px; align-items:center;">
        <button class="btn" style="background:#ef4444;" onclick="clearDrawings('rays')">🗑️ Rays</button>
        <button class="btn" style="background:#ef4444;" onclick="clearDrawings('frvp')">🗑️ FRVP</button>
    </div>
</div>

<div id="mainChart" class="chart-card"></div>

<div id="helpModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal()">&times;</span>
        <h2>Help Guide</h2>
        <p>1. <b>Fixed Switch:</b> Saves state automatically. Shows 30m/1h POC for current day.</p>
        <p>2. <b>FRVP:</b> Now includes Day Name in labels.</p>
    </div>
</div>

<script>
const labels = <?php echo json_encode($labels); ?>;
const candleSeries = <?php echo json_encode($candleData); ?>;
const dPoc = <?php echo json_encode($dPocSeries); ?>;
const hPoc = <?php echo json_encode($hPocSeries); ?>;
const m30Poc = <?php echo json_encode($m30PocSeries); ?>;
const volumes = <?php echo json_encode($rawVolumes); ?>;
const daySeps = <?php echo json_encode($daySeps); ?>;
const hourSeps = <?php echo json_encode($hourSeps); ?>;
const fixedPocs = <?php echo json_encode($dailyFixedPocs); ?>;
const latestDate = "<?php echo $latestDateStr; ?>";
const currentInterval = "<?php echo $intervalKey; ?>";

let frvpLines = JSON.parse(localStorage.getItem('v18_frvp')) || [];
let horizontalRays = JSON.parse(localStorage.getItem('v18_rays')) || [];
let currentTool = null; 

const xAnns = [];
hourSeps.forEach(h => { xAnns.push({ x: h.x, borderColor: 'rgba(0, 0, 0, 0.2)', borderWidth: 1, strokeDashArray: 4 }); });
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

function getDayName(label) {
    if(!label) return "";
    const parts = label.split(' '); 
    const dateStr = parts[0] + " " + parts[1] + " " + new Date().getFullYear();
    try { return new Intl.DateTimeFormat('en-US', { weekday: 'long' }).format(new Date(dateStr)); } catch(e) { return ""; }
}

function syncUI() {
    // Persistent POC Type
    const pocType = localStorage.getItem('v18_poc_type') || 'none';
    document.getElementById('poc_type_select').value = pocType;

    // Persistent Fixed POC State
    const savedFixed = localStorage.getItem('v18_show_fixed');
    const showFixed = (savedFixed === null) ? true : (savedFixed === 'true');
    document.getElementById('fixed_poc_switch').checked = showFixed;
    
    let activePocData = (pocType === 'developing') ? dPoc : (pocType === 'hourly' ? hPoc : (pocType === 'm30' ? m30Poc : []));
    let yAnns = [];

    // 1. Fixed POC (Current Day Only)
    if(showFixed && fixedPocs[latestDate]) {
        const p = fixedPocs[latestDate];
        if(p.p945) yAnns.push({ y: p.p945, borderColor: 'rgba(0,0,0,0.25)', borderWidth: 2, label: { text: '30m POC', style: { color: '#fff', background: 'rgba(0,0,0,0.3)' }}});
        if(p.p1015) yAnns.push({ y: p.p1015, borderColor: 'rgba(0,0,0,0.45)', borderWidth: 2, strokeDashArray: 4, label: { text: '1h POC', style: { color: '#fff', background: 'rgba(0,0,0,0.5)' }}});
    }

    // 2. Rays
    horizontalRays.forEach(ray => { yAnns.push({ y: ray.y, borderColor: '#4f46e5', borderWidth: 2, label: { text: ray.y, style: { color: '#fff', background: '#4f46e5' } } }); });

    // 3. FRVP with Day Name
    frvpLines.forEach(line => { 
        yAnns.push({ 
            y: line.y, x: line.xStart, x2: line.xEnd, borderColor: '#f97316', borderWidth: 3,
            label: { text: `FRVP ${line.day || ''} (${line.y})`, position: 'right', style: { color: '#fff', background: '#f59e0b', fontSize: '10px' } }
        }); 
    });

    chart.updateOptions({ 
        annotations: { position: 'back', xaxis: xAnns, yaxis: yAnns },
        series: [
            { name: 'Price', type: 'candlestick', data: candleSeries.map((val, i) => ({ x: labels[i], y: val })) },
            { name: 'POC', type: 'line', data: activePocData.map((val, i) => ({ x: labels[i], y: val })) }
        ]
    });
}

function saveFixedSettings() {
    localStorage.setItem('v18_show_fixed', document.getElementById('fixed_poc_switch').checked);
    syncUI();
}

function savePocSettings() {
    localStorage.setItem('v18_poc_type', document.getElementById('poc_type_select').value);
    syncUI();
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
        frvpLines.push({ 
            y: parseInt(poc), xStart: labels[start], xEnd: labels[end],
            day: getDayName(labels[start]) 
        });
        localStorage.setItem('v18_frvp', JSON.stringify(frvpLines));
        syncUI();
    }
}

function openModal() { document.getElementById("helpModal").style.display = "block"; }
function closeModal() { document.getElementById("helpModal").style.display = "none"; }
function toggleTool(tool) { currentTool = (currentTool === tool) ? null : tool; document.getElementById('ray_btn').classList.toggle('active', currentTool === 'ray'); }
function addRay(price) { horizontalRays.push({ y: price }); localStorage.setItem('v18_rays', JSON.stringify(horizontalRays)); currentTool = null; syncUI(); }
function clearDrawings(type) { if(type === 'rays') { horizontalRays = []; localStorage.removeItem('v18_rays'); } if(type === 'frvp') { frvpLines = []; localStorage.removeItem('v18_frvp'); } syncUI(); }

window.onload = () => { syncUI(); };
</script>
</body>
</html>