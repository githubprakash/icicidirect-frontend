<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
$conn = new mysqli("localhost", "root", "root", "options_m_db_by_fut");

$mode = $_GET['mode'] ?? 'buy'; 
$tableName = ($mode === 'sell') ? 'option_prices_5m_combined_option_sell' : 'option_prices_5m_combined';

$datesList = [];
$dateRes = $conn->query("SELECT MIN(DATE(datetime)) as start_date, strikePrice, expiryDate FROM $tableName GROUP BY strikePrice, expiryDate ORDER BY start_date DESC");
while($r = $dateRes->fetch_assoc()) { $datesList[] = $r; }

$selectedDate = $_GET['date'] ?? ($datesList[0]['start_date'] ?? '');
$intervalKey = $_GET['interval'] ?? '15m';
$intervalMap = ['5m' => 300, '15m' => 900, '30m' => 1800, '1h' => 3600, '2h' => 7200, '4h' => 14400, '1d' => 86400];
$seconds = $intervalMap[$intervalKey] ?? 900;

$selectedStrike = ''; $selectedExpiry = '';
foreach($datesList as $item) { if($item['start_date'] == $selectedDate) { $selectedStrike = $item['strikePrice']; $selectedExpiry = $item['expiryDate']; break; } }

$labels = []; $candleData = []; $daySeps = []; $hourSeps = []; $srAnnotations = []; $rawVolumes = [];
$dPocSeries = []; $hPocSeries = []; $m30PocSeries = [];

if ($selectedDate && $selectedStrike) {
    $sql = "SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(datetime)/$seconds)*$seconds) as time_slot,
            SUBSTRING_INDEX(GROUP_CONCAT(open ORDER BY datetime ASC), ',', 1) as o,
            MAX(high) as h, MIN(low) as l,
            SUBSTRING_INDEX(GROUP_CONCAT(close ORDER BY datetime DESC), ',', 1) as c,
            SUM(volume) as total_v, DATE(datetime) as d_only
            FROM $tableName
            WHERE strikePrice = '$selectedStrike' AND DATE(datetime) >= '$selectedDate' AND DATE(datetime) <= '$selectedExpiry'
              AND TIME(datetime) BETWEEN '09:15:00' AND '15:30:00'
            GROUP BY time_slot, d_only ORDER BY time_slot ASC";
    $result = $conn->query($sql);
    
    $prevDate = null; $lastHour = null; $last30m = null;
    $dayProfile = []; $hourProfile = []; $m30Profile = [];
    $tHigh = 0; $tLow = 999999;

    while($row = $result->fetch_assoc()) {
        $ts = strtotime($row['time_slot']);
        $currD = $row['d_only'];
        $timePart = date('H:i', $ts);
        $timeLabel = date('d M H:i', $ts);
        $priceClose = (int)round((float)$row['c'], 0);
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
        if($last30m && $last30m != $m30Key) $m30Profile = [];
        $m30Profile[$priceClose] = ($m30Profile[$priceClose] ?? 0) + $vol;
        $m30PocSeries[] = array_search(max($m30Profile), $m30Profile);
        $last30m = $m30Key;

        if ($timePart != '09:15' && strpos($timePart, ':15') !== false) $hourSeps[] = ['x' => $timeLabel];

        $rowHigh = (float)$row['h']; $rowLow = (float)$row['l'];
        if($rowHigh > $tHigh) $tHigh = $rowHigh;
        if($rowLow < $tLow) $tLow = $rowLow;

        $labels[] = $timeLabel;
        $candleData[] = [round((float)$row['o'],2), round($rowHigh,2), round($rowLow,2), round((float)$row['c'],2)];
        $rawVolumes[] = $vol;
        $prevDate = $currD;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Options Pro - Final Fixed</title>
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <style>
        html, body { height: 100%; margin: 0; padding: 0; overflow: hidden; background: #fff; font-family: 'Segoe UI', sans-serif; }
        .header { height: 55px; background: white; display: flex; align-items: center; padding: 0 15px; gap: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); border-bottom: 1px solid #eee; }
        .chart-card { height: calc(100vh - 75px); margin: 10px; background: white; border-radius: 8px; border: 1px solid #e0e0e0; }
        .btn { background: #2563eb; color: white; border: none; padding: 7px 12px; border-radius: 4px; cursor: pointer; font-weight: 600; font-size: 11px; }
        select { padding: 5px; border-radius: 4px; border: 1px solid #ccc; font-size: 11px; background: #f9fafb; }
        .btn-tool { background: #64748b; border: 2px solid transparent; }
        .btn-tool.active { background: #f59e0b !important; border-color: #000; animation: pulse 1.5s infinite; }
        @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.7; } 100% { opacity: 1; } }
    </style>
</head>
<body>

<div class="header">
    <form method="GET" style="display:flex; gap:8px; align-items:center;">
        <select name="mode" onchange="this.form.submit()">
            <option value="buy" <?php echo $mode=='buy'?'selected':''; ?>>BUY MODE</option>
            <option value="sell" <?php echo $mode=='sell'?'selected':''; ?>>SELL MODE</option>
        </select>
        <select name="date">
            <?php foreach($datesList as $d) echo "<option value='{$d['start_date']}' ".($d['start_date']==$selectedDate?'selected':'').">".date('d M (D)', strtotime($d['start_date']))." - {$d['strikePrice']}</option>"; ?>
        </select>
        <select name="interval">
            <?php foreach($intervalMap as $k => $v) echo "<option value='$k' ".($intervalKey==$k?'selected':'').">$k</option>"; ?>
        </select>
        <button type="submit" class="btn">Update</button>
    </form>

    <div style="height: 20px; width: 1px; background: #ddd;"></div>

    <select id="poc_type_select" onchange="savePocSettings()">
        <option value="none">POC: None</option>
        <option value="developing">Developing</option>
        <option value="hourly">1 Hour</option>
        <option value="m30">30 Min</option>
    </select>

    <button id="ray_btn" class="btn btn-tool" onclick="toggleTool('ray')">🪄 Ray</button>
    <a href="jurney.php" id="ray_btn" class="btn btn-tool" target="_blank">Journey</a>
    
    <div style="margin-left: auto; display:flex; gap:8px; align-items:center;">
        <button class="btn" style="background:#ef4444;" onclick="clearDrawings('rays')">🗑️ Rays</button>
        <button class="btn" style="background:#ef4444;" onclick="clearDrawings('frvp')">🗑️ FRVP</button>
        <label style="font-size:11px; font-weight:bold;"><input type="checkbox" id="sr_switch" onchange="syncUI()"> S&R</label>
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

let frvpLines = JSON.parse(localStorage.getItem('v15_frvp')) || [];
let horizontalRays = JSON.parse(localStorage.getItem('v15_rays')) || [];

let currentTool = null; // 'ray'

const xAnns = [];
// --- Hourly Separator: Dashed and Light ---
hourSeps.forEach(h => { 
    xAnns.push({ 
        x: h.x, 
        borderColor: 'rgba(0, 0, 0, 0.25)', 
        borderWidth: 1,                 
        strokeDashArray: 5                
    }); 
});

daySeps.forEach(s => { 
    xAnns.push({ 
        x: s.x, 
        borderColor: '#2563eb', 
        borderWidth: 2, 
        label: { text: s.label, style: { color: '#fff', background: '#2563eb' } } 
    }); 
});

const options = {
    series: [
        { name: 'Price', type: 'candlestick', data: candleSeries.map((val, i) => ({ x: labels[i], y: val })) },
        { name: 'POC', type: 'line', data: [] }
    ],
    chart: { 
        height: '100%', animations: { enabled: false },
        toolbar: { autoSelected: 'selection', tools: { selection: true, zoom: true, pan: false } },
        events: { 
            selection: function(ctx, { xaxis }) { 
                if (xaxis.min && xaxis.max) addFRVP(xaxis.min, xaxis.max); 
            },
            click: function(e, chartContext, config) {
                if (currentTool === 'ray') {
                    const yAxis = chartContext.w.globals.yAxisScale[0];
                    const gridHeight = chartContext.w.globals.gridHeight;
                    const topOffset = chartContext.w.globals.translateY;
                    const relativeY = e.offsetY - topOffset;
                    const priceRange = yAxis.niceMax - yAxis.niceMin;
                    const pricePerPixel = priceRange / gridHeight;
                    const clickedPrice = yAxis.niceMax - (relativeY * pricePerPixel);
                    addRay(parseFloat(clickedPrice.toFixed(2)));
                }
            }
        }
    },
    stroke: { width: [1, 2], curve: 'stepline' },
    colors: ['#000', '#3b82f6'],
    xaxis: { type: 'category', labels: { style: { fontSize: '10px' } }, tickAmount: 15 },
    yaxis: { opposite: true },
    annotations: { xaxis: xAnns, yaxis: [] },
    plotOptions: { candlestick: { colors: { upward: '#10b981', downward: '#ef4444' } } }
};

const chart = new ApexCharts(document.querySelector("#mainChart"), options);
chart.render();

function toggleTool(tool) {
    currentTool = (currentTool === tool) ? null : tool;
    document.getElementById('ray_btn').classList.toggle('active', currentTool === 'ray');
    document.getElementById('mainChart').style.cursor = currentTool ? 'crosshair' : 'default';
}

function addRay(price) {
    if(!price || price < 0) return;
    horizontalRays.push({ y: price });
    localStorage.setItem('v15_rays', JSON.stringify(horizontalRays));
    currentTool = null;
    toggleTool(null);
    syncUI();
}

function clearDrawings(type) {
    if(!confirm("Delete all " + type + "?")) return;
    if(type === 'rays') { horizontalRays = []; localStorage.removeItem('rays'); }
    if(type === 'frvp') { frvpLines = []; localStorage.removeItem('v15_frvp'); }
    syncUI();
}

function savePocSettings() {
    localStorage.setItem('v15_poc_type', document.getElementById('poc_type_select').value);
    syncUI();
}

function syncUI() {
    const pocType = localStorage.getItem('v15_poc_type') || 'none';
    document.getElementById('poc_type_select').value = pocType;
    const showSR = document.getElementById('sr_switch').checked;
    
    let activePocData = [];
    if(pocType === 'developing') activePocData = dPoc;
    else if(pocType === 'hourly') activePocData = hPoc;
    else if(pocType === 'm30') activePocData = m30Poc;

    let yAnns = [];
    horizontalRays.forEach(ray => {
        yAnns.push({ y: ray.y, borderColor: '#4f46e5', borderWidth: 2, label: { text: 'RAY ' + ray.y, style: { color: '#fff', background: '#4f46e5' } } });
    });

    if(showSR) {
        srData.forEach(l => {
            yAnns.push({ y: l.high, borderColor: '#ef4444', strokeDashArray: 4, label: { text: 'R: '+l.high, style: { color:'#fff', background:'#ef4444'} } });
            yAnns.push({ y: l.low, borderColor: '#10b981', strokeDashArray: 4, label: { text: 'S: '+l.low, style: { color:'#fff', background:'#10b981'} } });
        });
    }

    frvpLines.forEach(line => {
        yAnns.push({ y: line.y, x: line.xStart, x2: line.xEnd, borderColor: '#f97316', borderWidth: 4, label: { text: 'POC: ' + line.label, style: { color: '#fff', background: '#f97316' } } });
    });

    chart.updateOptions({ 
        annotations: { xaxis: xAnns, yaxis: yAnns },
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
        localStorage.setItem('v15_frvp', JSON.stringify(frvpLines));
        syncUI();
    }
}

window.onload = () => { syncUI(); };
</script>
</body>
</html>