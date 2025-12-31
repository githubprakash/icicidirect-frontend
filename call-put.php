<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 1. टाइमजोन और डेटाबेस कनेक्शन
date_default_timezone_set('Asia/Kolkata');
$conn = new mysqli("34.180.49.73", "dev-user", ";blAFxC>*BVu:4g3", "options_m_db_by_fut");
$conn->query("SET time_zone = '+05:30'");

// --- 1. Settings & Quantity ---
$callQty = isset($_GET['call_qty']) ? (float)$_GET['call_qty'] : 1;
$putQty = isset($_GET['put_qty']) ? (float)$_GET['put_qty'] : 1;
$historyRange = $_GET['history_range'] ?? '1';
$cutoffDate = ($historyRange !== 'all') ? date('Y-m-d', strtotime("-$historyRange months")) : "";

// --- 2. Selection List ---
$datesList = [];
$havingSql = $cutoffDate ? "HAVING start_date >= '$cutoffDate'" : "";
$dateRes = $conn->query("SELECT MIN(DATE(datetime)) as start_date, strikePrice, expiryDate 
                         FROM option_prices_5m_call 
                         GROUP BY strikePrice, expiryDate $havingSql 
                         ORDER BY start_date DESC, expiryDate ASC");
while($r = $dateRes->fetch_assoc()) { $datesList[] = $r; }

$rawSelection = $_GET['selection'] ?? ''; 
if ($rawSelection && strpos($rawSelection, '|') !== false) {
    $parts = explode('|', $rawSelection);
    list($selectedDate, $selectedStrike, $selectedExpiry) = $parts;
} else {
    $selectedDate = $datesList[0]['start_date'] ?? '';
    $selectedStrike = $datesList[0]['strikePrice'] ?? '';
    $selectedExpiry = $datesList[0]['expiryDate'] ?? '';
}

$intervalKey = $_GET['interval'] ?? '15m';
$intervalMap = ['5m'=>300,'15m'=>900,'30m'=>1800,'60m'=>3600,'75m'=>4500,'90m'=>5400,'125m'=>7500,'1d'=>86400];
$seconds = $intervalMap[$intervalKey] ?? 900;

$candleData = []; $rawVolumes = []; $callCloses = []; $putCloses = []; 
$devPocArr = []; $devVahArr = []; $devValArr = []; $vwapArr = []; $dayBreaks = [];

if ($selectedDate && $selectedStrike && $selectedExpiry) {
    $sql = "SELECT 
            TIMESTAMPADD(SECOND, FLOOR(TIMESTAMPDIFF(SECOND, CONCAT(DATE(c.datetime), ' 09:15:00'), c.datetime) / $seconds) * $seconds, CONCAT(DATE(c.datetime), ' 09:15:00')) as time_slot,
            SUBSTRING_INDEX(GROUP_CONCAT((c.open * $callQty + p.open * $putQty) ORDER BY c.datetime ASC), ',', 1) as o,
            MAX(c.high * $callQty + p.high * $putQty) as h, 
            MIN(c.low * $callQty + p.low * $putQty) as l,
            SUBSTRING_INDEX(GROUP_CONCAT((c.close * $callQty + p.close * $putQty) ORDER BY c.datetime DESC), ',', 1) as c,
            SUBSTRING_INDEX(GROUP_CONCAT(c.close ORDER BY c.datetime DESC), ',', 1) as cr,
            SUBSTRING_INDEX(GROUP_CONCAT(p.close ORDER BY p.datetime DESC), ',', 1) as pr,
            SUM(c.volume + p.volume) as total_v, DATE(c.datetime) as d_only
            FROM option_prices_5m_call c
            INNER JOIN option_prices_5m_put p ON c.datetime = p.datetime 
            WHERE c.strikePrice = '$selectedStrike' AND p.strikePrice = '$selectedStrike'
              AND c.expiryDate = '$selectedExpiry' AND p.expiryDate = '$selectedExpiry'
              AND DATE(c.datetime) >= '$selectedDate' AND DATE(c.datetime) <= '$selectedExpiry'
              AND TIME(c.datetime) BETWEEN '09:15:00' AND '15:30:00'
            GROUP BY d_only, time_slot ORDER BY time_slot ASC";

    $result = $conn->query($sql);
    $cumulativeDayProfile = []; $prevDate = null;
    $sumPV = 0; $sumV = 0;

    while($row = $result->fetch_assoc()) {
        $currD = $row['d_only'];
        $ts = strtotime($row['time_slot']) + 19800; // Force IST Offset
        $o = (float)$row['o']; $h = (float)$row['h']; $l = (float)$row['l']; $c = (float)$row['c'];
        $vol = (int)$row['total_v'];

        if ($prevDate != $currD) { 
            $cumulativeDayProfile = []; 
            $dayBreaks[] = $ts; 
            $sumPV = 0; $sumV = 0; 
        }
        
        // VWAP
        $sumPV += $c * $vol;
        $sumV += $vol;
        $vwapValue = ($sumV > 0) ? ($sumPV / $sumV) : $c;

        // POC
        $pCl = (int)round($c, 0);
        $cumulativeDayProfile[$pCl] = ($cumulativeDayProfile[$pCl] ?? 0) + $vol;
        $currentDPoc = array_search(max($cumulativeDayProfile), $cumulativeDayProfile);
        
        $totalVol = array_sum($cumulativeDayProfile);
        $targetVA = $totalVol * 0.70;
        $sortedPrices = array_keys($cumulativeDayProfile); sort($sortedPrices);
        $pocIdx = array_search($currentDPoc, $sortedPrices);
        $vaLowIdx = $pocIdx; $vaHighIdx = $pocIdx; $vVol = $cumulativeDayProfile[$currentDPoc];
        while ($vVol < $targetVA && ($vaLowIdx > 0 || $vaHighIdx < count($sortedPrices)-1)) {
            $pVol = ($vaLowIdx > 0) ? $cumulativeDayProfile[$sortedPrices[$vaLowIdx-1]] : 0;
            $nVol = ($vaHighIdx < count($sortedPrices)-1) ? $cumulativeDayProfile[$sortedPrices[$vaHighIdx+1]] : 0;
            if ($pVol >= $nVol && $vaLowIdx > 0) { $vaLowIdx--; $vVol += $pVol; }
            elseif ($vaHighIdx < count($sortedPrices)-1) { $vaHighIdx++; $vVol += $nVol; } else { break; }
        }

        $candleData[] = ['time' => $ts, 'open' => $o, 'high' => $h, 'low' => $l, 'close' => $c];
        $rawVolumes[] = ['time' => $ts, 'value' => $vol, 'color' => ($c >= $o ? 'rgba(38, 166, 154, 0.4)' : 'rgba(239, 83, 80, 0.4)')];
        $callCloses[] = (float)$row['cr'];
        $putCloses[] = (float)$row['pr'];
        $devPocArr[] = ['time' => $ts, 'value' => (float)$currentDPoc];
        $devVahArr[] = ['time' => $ts, 'value' => (float)($sortedPrices[$vaHighIdx] ?? $pCl)];
        $devValArr[] = ['time' => $ts, 'value' => (float)($sortedPrices[$vaLowIdx] ?? $pCl)];
        $vwapArr[] = ['time' => $ts, 'value' => (float)$vwapValue];
        $prevDate = $currD;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Dynamic Balanced Chart</title>
    <!-- AUTO REFRESH EVERY 5 MINUTES (300 Seconds) -->
    <meta http-equiv="refresh" content="300">
    <script src="https://unpkg.com/lightweight-charts@4.1.1/dist/lightweight-charts.standalone.production.js"></script>
    <style>
        html, body { height: 100%; margin: 0; padding: 0; background: #fff; font-family: sans-serif; overflow: hidden; }
        .header { height: 60px; display: flex; align-items: center; padding: 0 15px; gap: 8px; border-bottom: 1px solid #eee; background: white; }
        #chart-container { height: calc(100vh - 60px); position: relative; }
        .btn { background: #2563eb; color: white; border: none; padding: 7px 12px; border-radius: 4px; cursor: pointer; font-size: 11px; font-weight: bold; }
        select, input { padding: 5px; font-size: 11px; border: 1px solid #ccc; border-radius: 4px; }
        #tooltip { position: absolute; display: none; padding: 10px; font-size: 12px; z-index: 1000; top: 10px; left: 10px; pointer-events: none; border: 1px solid #ccc; background: rgba(255, 255, 255, 0.98); border-radius: 4px; box-shadow: 0 2px 8px rgba(0,0,0,0.15); line-height: 1.4; }
        .btn-tool.active { background: #f59e0b !important; color: #000; }
    </style>
</head>
<body>

<div class="header">
    <form method="GET" style="display:flex; gap:8px; align-items:center;">
        <select name="history_range" onchange="this.form.submit()">
            <option value="1" <?= $historyRange=='1'?'selected':'' ?>>1M</option>
            <option value="2" <?= $historyRange=='2'?'selected':'' ?>>2M</option>
            <option value="3" <?= $historyRange=='3'?'selected':'' ?>>3M</option>
            <option value="all" <?= $historyRange=='all'?'selected':'' ?>>All</option>
        </select>
        <div style="font-size:10px;"> C: <input type="number" name="call_qty" value="<?= $callQty ?>" style="width:32px;"> P: <input type="number" name="put_qty" value="<?= $putQty ?>" style="width:32px;"> </div>
        
        <select name="selection" style="width: 220px;" onchange="this.form.submit()">
            <?php foreach($datesList as $d) {
                $val = $d['start_date'] . "|" . $d['strikePrice'] . "|" . $d['expiryDate'];
                $sel = ($selectedDate == $d['start_date'] && $selectedStrike == $d['strikePrice'] && $selectedExpiry == $d['expiryDate']) ? 'selected' : '';
                echo "<option value='$val' $sel>".date('d M', strtotime($d['start_date']))." | ".$d['strikePrice']." | ".date('d M', strtotime($d['expiryDate']))."</option>";
            } ?>
        </select>

        <select name="interval" onchange="this.form.submit()">
            <?php foreach($intervalMap as $k => $v) echo "<option value='$k' ".($intervalKey==$k?'selected':'').">$k</option>"; ?>
        </select>
        <button type="submit" class="btn">Update</button>
    </form>
    <div style="height: 20px; width: 1px; background: #ddd; margin: 0 2px;"></div>
    <button id="ray_btn" class="btn" onclick="toggleTool('ray')">🪄 Ray</button>
    <button id="frvp_btn" class="btn" onclick="toggleTool('frvp')">🎯 FRVP</button>
    <label style="font-size:11px; color:#ff9800; font-weight:bold;"><input type="checkbox" id="vwap_switch" onchange="saveSwitchState()"> VWAP</label>
    <label style="font-size:11px;"><input type="checkbox" id="poc_switch" onchange="saveSwitchState()"> d-POC</label>
    <label style="font-size:11px;"><input type="checkbox" id="va_switch" onchange="saveSwitchState()"> d-VA</label>
    <button class="btn" style="background:#ef4444;" onclick="clearToolsInstant()">🗑️</button>
    <span id="tool_hint" style="font-size:10px; color:red;"></span>
</div>

<div id="chart-container">
    <div id="tooltip"></div>
</div>

<script>
const container = document.getElementById('chart-container');
const chart = LightweightCharts.createChart(container, {
    layout: { background: { color: '#ffffff' }, textColor: '#333' },
    grid: { vertLines: { color: '#f2f2f2' }, horzLines: { color: '#f2f2f2' } },
    timeScale: { timeVisible: true, secondsVisible: false, borderVisible: false, fixLeftEdge: true },
    rightPriceScale: { borderVisible: false }
});

const mainSeries = chart.addCandlestickSeries({ upColor: '#26a69a', downColor: '#ef5350', wickUpColor: '#26a69a', wickDownColor: '#ef5350' });
const volumeSeries = chart.addHistogramSeries({ color: '#26a69a', priceFormat: { type: 'volume' }, priceScaleId: '' });
volumeSeries.priceScale().applyOptions({ scaleMargins: { top: 0.8, bottom: 0 } });

const vwapSeries = chart.addLineSeries({ color: '#ff9800', lineWidth: 1.5, lineStyle: 2, visible: false });
const pocSeries = chart.addLineSeries({ color: '#2962FF', lineWidth: 2, visible: false });
const vahSeries = chart.addLineSeries({ color: '#94a3b8', lineWidth: 1, lineStyle: 2, visible: false });
const valSeries = chart.addLineSeries({ color: '#94a3b8', lineWidth: 1, lineStyle: 2, visible: false });

const breakSeries = chart.addHistogramSeries({ color: 'rgba(0, 0, 0, 0.12)', priceFormat: { type: 'volume' }, priceScaleId: 'overlay' });
breakSeries.priceScale().applyOptions({ scaleMargins: { top: 0, bottom: 0 } });

const candleData = <?= json_encode($candleData) ?>;
const volumeData = <?= json_encode($rawVolumes) ?>;
const callC = <?= json_encode($callCloses) ?>;
const putC = <?= json_encode($putCloses) ?>;
const dPoc = <?= json_encode($devPocArr) ?>;
const dVah = <?= json_encode($devVahArr) ?>;
const dVal = <?= json_encode($devValArr) ?>;
const vwapData = <?= json_encode($vwapArr) ?>;
const dayBreaks = <?= json_encode($dayBreaks) ?>;

mainSeries.setData(candleData);
volumeSeries.setData(volumeData);
vwapSeries.setData(vwapData);
pocSeries.setData(dPoc);
vahSeries.setData(dVah);
valSeries.setData(dVal);
breakSeries.setData(dayBreaks.map(ts => ({ time: ts, value: 1000000000 })));

let rayLineRefs = [];
let frvpLineRefs = [];

const tooltip = document.getElementById('tooltip');
chart.subscribeCrosshairMove(param => {
    if (!param.time || param.point.x < 0 || param.point.y < 0) { tooltip.style.display = 'none'; return; }
    const data = param.seriesData.get(mainSeries);
    const idx = candleData.findIndex(d => d.time === param.time);
    if (data && idx !== -1) {
        tooltip.style.display = 'block';
        tooltip.innerHTML = `O: ${data.open.toFixed(2)} | H: ${data.high.toFixed(2)}<br/>L: ${data.low.toFixed(2)} | <b>C: ${data.close.toFixed(2)}</b><hr/>Call: ${callC[idx]} | Put: ${putC[idx]}`;
    }
});

function saveSwitchState() {
    localStorage.setItem('tv_vwap_on', document.getElementById('vwap_switch').checked);
    localStorage.setItem('tv_poc_on', document.getElementById('poc_switch').checked);
    localStorage.setItem('tv_va_on', document.getElementById('va_switch').checked);
    updateLines();
}

function updateLines() {
    vwapSeries.applyOptions({ visible: document.getElementById('vwap_switch').checked });
    pocSeries.applyOptions({ visible: document.getElementById('poc_switch').checked });
    const vaOn = document.getElementById('va_switch').checked;
    vahSeries.applyOptions({ visible: vaOn });
    valSeries.applyOptions({ visible: vaOn });
}

let currentTool = null;
let frvpStart = null;
let horizontalRays = JSON.parse(localStorage.getItem('tv_rays')) || [];
let frvpLines = JSON.parse(localStorage.getItem('tv_frvp')) || [];

function toggleTool(tool) {
    currentTool = (currentTool === tool) ? null : tool;
    document.getElementById('ray_btn').className = (currentTool === 'ray' ? 'btn btn-tool active' : 'btn');
    document.getElementById('frvp_btn').className = (currentTool === 'frvp' ? 'btn btn-tool active' : 'btn');
    container.style.cursor = currentTool ? 'crosshair' : 'default';
}

chart.subscribeClick(param => {
    if (!param.time || !currentTool) return;
    const price = mainSeries.coordinateToPrice(param.point.y);
    if (currentTool === 'ray') {
        const p = parseFloat(price.toFixed(2));
        horizontalRays.push(p);
        localStorage.setItem('tv_rays', JSON.stringify(horizontalRays));
        rayLineRefs.push(mainSeries.createPriceLine({ price: p, color: '#4f46e5', lineWidth: 2, axisLabelVisible: true, title: 'RAY' }));
        toggleTool(null);
    } 
    else if (currentTool === 'frvp') {
        if (!frvpStart) {
            frvpStart = param.time;
            mainSeries.setMarkers([{ time: frvpStart, position: 'aboveBar', color: '#f59e0b', shape: 'arrowDown', text: 'START' }]);
        } else {
            const start = Math.min(frvpStart, param.time);
            const end = Math.max(frvpStart, param.time);
            let profile = {};
            candleData.forEach((d, i) => {
                if (d.time >= start && d.time <= end) {
                    let p = Math.round(d.close);
                    profile[p] = (profile[p] || 0) + (volumeData[i] ? volumeData[i].value : 0);
                }
            });
            if(Object.keys(profile).length > 0) {
                const poc = Object.keys(profile).reduce((a, b) => profile[a] > profile[b] ? a : b);
                const pVal = parseFloat(poc);
                frvpLines.push(pVal);
                localStorage.setItem('tv_frvp', JSON.stringify(frvpLines));
                frvpLineRefs.push(mainSeries.createPriceLine({ price: pVal, color: '#64748b', lineWidth: 3, lineStyle: 2, axisLabelVisible: true, title: 'FRVP POC' }));
            }
            mainSeries.setMarkers([]);
            frvpStart = null;
            toggleTool(null);
        }
    }
});

function clearToolsInstant() {
    rayLineRefs.forEach(ref => mainSeries.removePriceLine(ref));
    frvpLineRefs.forEach(ref => mainSeries.removePriceLine(ref));
    rayLineRefs = []; frvpLineRefs = []; horizontalRays = []; frvpLines = [];
    localStorage.removeItem('tv_rays'); localStorage.removeItem('tv_frvp');
}

window.onload = () => {
    horizontalRays.forEach(p => rayLineRefs.push(mainSeries.createPriceLine({ price: p, color: '#4f46e5', lineWidth: 2, title: 'RAY' })));
    frvpLines.forEach(p => frvpLineRefs.push(mainSeries.createPriceLine({ price: parseFloat(p), color: '#64748b', lineWidth: 3, lineStyle: 2, title: 'FRVP POC' })));
    document.getElementById('vwap_switch').checked = localStorage.getItem('tv_vwap_on') === 'true';
    document.getElementById('poc_switch').checked = localStorage.getItem('tv_poc_on') === 'true';
    document.getElementById('va_switch').checked = localStorage.getItem('tv_va_on') === 'true';
    updateLines();
    setTimeout(() => chart.timeScale().fitContent(), 100);
};

window.onresize = () => chart.applyOptions({ width: container.clientWidth, height: container.clientHeight });
</script>
</body>
</html>