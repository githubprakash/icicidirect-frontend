<?php
// 1. Database Connection
$conn = new mysqli("localhost", "root", "root", "options_m_db_by_fut");

// 2. Fetch Journey Start Dates (Dropdown)
$datesList = [];
$dateQuery = "SELECT MIN(DATE(datetime)) as start_date, strikePrice, expiryDate 
              FROM option_prices_5m_call 
              GROUP BY strikePrice, expiryDate 
              ORDER BY start_date DESC";
$dateRes = $conn->query($dateQuery);
while($r = $dateRes->fetch_assoc()) {
    $datesList[] = ['date' => $r['start_date'], 'strike' => $r['strikePrice'], 'expiry' => $r['expiryDate']];
}

$selectedDate = $_GET['date'] ?? ($datesList[0]['date'] ?? '');
$selectedStrike = ''; $selectedExpiry = '';

foreach($datesList as $item) {
    if($item['date'] == $selectedDate) {
        $selectedStrike = $item['strike'];
        $selectedExpiry = $item['expiry'];
        break;
    }
}

$intervalMap = ['5m'=>300, '15m'=>900, '30m'=>1800, '60m'=>3600];
$selectedInterval = $_GET['interval'] ?? '15m';
$seconds = $intervalMap[$selectedInterval] ?? 900;

// 3. Fetch Data & Calculate Levels
$labels = []; $combData = []; $cPriceData = []; $pPriceData = []; $nDeltaData = []; $cDeltaData = [];
$dayHighlights = []; $dailyLevels = [];
$startPrice = 0; $totalHigh = 0; $totalLow = 999999;

if ($selectedDate && $selectedStrike) {
    $sql = "SELECT 
                FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(C.datetime)/$seconds)*$seconds) as time_slot, 
                AVG(C.close) as cp, AVG(P.close) as pp, AVG(C.delta) as cd, AVG(P.delta) as pd
            FROM option_prices_5m_call C 
            JOIN option_prices_5m_put P ON C.datetime = P.datetime AND C.strikePrice = P.strikePrice AND C.expiryDate = P.expiryDate
            WHERE C.strikePrice = '$selectedStrike' AND C.expiryDate = '$selectedExpiry'
              AND DATE(C.datetime) >= '$selectedDate' AND DATE(C.datetime) <= '$selectedExpiry'
            GROUP BY time_slot ORDER BY time_slot ASC";

    $result = $conn->query($sql);
    $idx = 0; $prevDate = null;
    $tempHigh = 0; $tempLow = 999999; $dayStartIdx = 0;

    while($row = $result->fetch_assoc()) {
        $currFullDate = date('Y-m-d', strtotime($row['time_slot']));
        $price = round($row['cp'] + $row['pp'], 2);

        if ($prevDate && $currFullDate != $prevDate) {
            $dailyLevels[] = ['start' => $dayStartIdx, 'end' => $idx - 1, 'high' => $tempHigh, 'low' => $tempLow];
            $dayHighlights[] = ['index' => $idx, 'label' => date('d M', strtotime($row['time_slot']))];
            $tempHigh = 0; $tempLow = 999999; $dayStartIdx = $idx;
        }

        if($idx == 0) $startPrice = $price;
        if($price > $tempHigh) $tempHigh = $price;
        if($price < $tempLow) $tempLow = $price;
        if($price > $totalHigh) $totalHigh = $price;
        if($price < $totalLow) $totalLow = $price;

        $labels[] = date('d M H:i', strtotime($row['time_slot']));
        $combData[] = $price;
        $cPriceData[] = round($row['cp'], 2);
        $pPriceData[] = round($row['pp'], 2);
        $cDeltaData[] = round($row['cd'], 3);
        $nDeltaData[] = round($row['cd'] + $row['pd'], 3);
        
        $prevDate = $currFullDate;
        $idx++;
    }
    $dailyLevels[] = ['start' => $dayStartIdx, 'end' => $idx - 1, 'high' => $tempHigh, 'low' => $tempLow];
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Strike Journey Tracker</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-annotation@2.2.1"></script>
    <style>
        body { font-family: sans-serif; background: #f4f6f9; padding: 20px; }
        .nav-bar { background: white; padding: 15px; border-radius: 8px; display: flex; gap: 20px; align-items: flex-end; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 15px; }
        .chart-card { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); height: 75vh; }
        .settings-bar { background: #fff; padding: 10px 20px; border-radius: 8px; margin-bottom: 10px; display: flex; gap: 20px; font-size: 13px; font-weight: bold; align-items: center; border: 1px solid #ddd; }
        .info-badge { padding: 5px 12px; background: #e9ecef; border-radius: 4px; font-size: 13px; color: #495057; }
        .btn-update { background: #007bff; color: white; border: none; padding: 9px 20px; border-radius: 4px; cursor: pointer; font-weight: bold; }
        .sr-toggle { margin-left: auto; background: #fff1f2; color: #e11d48; padding: 4px 10px; border-radius: 4px; border: 1px solid #fda4af; }
        select { padding: 8px; border-radius: 4px; border: 1px solid #ccc; }
        label { display: flex; align-items: center; gap: 5px; cursor: pointer; }
    </style>
</head>
<body>

<div class="nav-bar">
    <form method="GET" style="display:flex; gap:15px; align-items:flex-end;">
        <div style="display:flex; flex-direction:column; gap:4px;">
            <label style="font-size:11px; color:#666;">START DATE</label>
            <select name="date">
                <?php foreach($datesList as $d): ?>
                    <option value="<?php echo $d['date']; ?>" <?php echo ($d['date'] == $selectedDate) ? 'selected' : ''; ?>>
                        <?php echo date('d M Y', strtotime($d['date'])); ?> (<?php echo $d['strike']; ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="display:flex; flex-direction:column; gap:4px;">
            <label style="font-size:11px; color:#666;">INTERVAL</label>
            <select name="interval">
                <?php foreach($intervalMap as $k => $v) echo "<option value='$k' ".($selectedInterval==$k?'selected':'').">$k</option>"; ?>
            </select>
        </div>
        <button type="submit" class="btn-update">Update</button>
    </form>

    <div style="margin-left:auto; display:flex; gap:10px;">
        <div class="info-badge">Strike: <b><?php echo $selectedStrike; ?></b></div>
        <div class="info-badge">Expiry: <b><?php echo $selectedExpiry; ?></b></div>
    </div>
</div>

<div class="settings-bar" id="chartToggles">
    <span style="color:#888">VIEW:</span>
    <label><input type="checkbox" data-index="0" checked> Combined</label>
    <label style="color:green;"><input type="checkbox" data-index="1" checked> Call P</label>
    <label style="color:red;"><input type="checkbox" data-index="2" checked> Put P</label>
    <label style="color:purple;"><input type="checkbox" data-index="3" checked> Net Δ</label>
    <label style="color:orange;"><input type="checkbox" data-index="4" checked> Call Δ</label>
    
    <div class="sr-toggle">
        <label><input type="checkbox" id="sr_switch"> Day S&R</label>
    </div>
</div>

<div class="chart-card">
    <canvas id="journeyChart"></canvas>
</div>

<script>
const ctx = document.getElementById('journeyChart').getContext('2d');
const dayHighlights = <?php echo json_encode($dayHighlights); ?>;
const dailyLevels = <?php echo json_encode($dailyLevels); ?>;
const startPrice = <?php echo (float)$startPrice; ?>;
const totalLow = <?php echo (float)$totalLow; ?>;
const totalHigh = <?php echo (float)$totalHigh; ?>;

function getAnns(showSR) {
    let anns = {};
    // Vertical Day Separators
    dayHighlights.forEach((h, i) => {
        anns['line'+i] = {
            type: 'line', xMin: h.index-0.5, xMax: h.index-0.5,
            borderColor: '#999', borderWidth: 1, borderDash: [5,5],
            label: { display: true, content: h.label, position: 'start', font: {size: 10}, backgroundColor: '#f4f6f9' }
        };
    });
    // Horizontal S&R
    if(showSR) {
        dailyLevels.forEach((dl, i) => {
            anns['res'+i] = {
                type: 'line', yMin: dl.high, yMax: dl.high, xMin: dl.start, xMax: dl.end,
                borderColor: 'rgba(255,0,0,0.5)', borderWidth: 2, borderDash: [2,2],
                label: { display: true, content: 'R:'+dl.high, position: 'end', font: {size:9} }
            };
            anns['sup'+i] = {
                type: 'line', yMin: dl.low, yMax: dl.low, xMin: dl.start, xMax: dl.end,
                borderColor: 'rgba(0,128,0,0.5)', borderWidth: 2, borderDash: [2,2],
                label: { display: true, content: 'S:'+dl.low, position: 'end', font: {size:9} }
            };
        });
    }
    return anns;
}

const mainChart = new Chart(ctx, {
    type: 'line',
    data: {
        labels: <?php echo json_encode($labels); ?>,
        datasets: [
            { label: 'Combined', data: <?php echo json_encode($combData); ?>, borderColor: '#007bff', yAxisID: 'yPrice', borderWidth: 2, pointRadius: 0 },
            { label: 'Call P', data: <?php echo json_encode($cPriceData); ?>, borderColor: 'green', yAxisID: 'yPrice', borderWidth: 1, pointRadius: 0 },
            { label: 'Put P', data: <?php echo json_encode($pPriceData); ?>, borderColor: 'red', yAxisID: 'yPrice', borderWidth: 1, pointRadius: 0 },
            { label: 'Net Delta', data: <?php echo json_encode($nDeltaData); ?>, borderColor: 'purple', yAxisID: 'yDelta', borderDash: [5,3], pointRadius: 0 },
            { label: 'Call Delta', data: <?php echo json_encode($cDeltaData); ?>, borderColor: 'orange', yAxisID: 'yDelta', borderDash: [2,2], pointRadius: 0 }
        ]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        scales: {
            yPrice: { type: 'linear', position: 'right', title: { display: true, text: 'Price' } },
            yDelta: { type: 'linear', position: 'left', title: { display: true, text: 'Delta' }, grid: { drawOnChartArea: false } },
            yPct: {
                type: 'linear', position: 'right', grid: { drawOnChartArea: false },
                min: ((totalLow - startPrice)/startPrice)*100 - 2,
                max: ((totalHigh - startPrice)/startPrice)*100 + 2,
                callback: v => v.toFixed(1) + '%',
                title: { display: true, text: '%' }
            }
        },
        plugins: {
            legend: { display: false },
            annotation: { annotations: getAnns(false) }
        }
    }
});

// Settings persistence
const STORAGE_KEY = 'options_journey_v6';

function save() {
    const cfg = { vis: {}, sr: document.getElementById('sr_switch').checked };
    document.querySelectorAll('#chartToggles input[data-index]').forEach(cb => cfg.vis[cb.dataset.index] = cb.checked);
    localStorage.setItem(STORAGE_KEY, JSON.stringify(cfg));
}

function load() {
    const data = localStorage.getItem(STORAGE_KEY);
    if(data) {
        const cfg = JSON.parse(data);
        Object.keys(cfg.vis).forEach(idx => {
            const cb = document.querySelector(`input[data-index="${idx}"]`);
            if(cb) { cb.checked = cfg.vis[idx]; mainChart.setDatasetVisibility(idx, cb.checked); }
        });
        document.getElementById('sr_switch').checked = cfg.sr;
        mainChart.options.plugins.annotation.annotations = getAnns(cfg.sr);
        mainChart.update();
    }
}

document.querySelectorAll('#chartToggles input').forEach(cb => {
    cb.addEventListener('change', () => {
        if(cb.id === 'sr_switch') mainChart.options.plugins.annotation.annotations = getAnns(cb.checked);
        else mainChart.setDatasetVisibility(cb.dataset.index, cb.checked);
        mainChart.update();
        save();
    });
});

window.onload = load;
</script>

</body>
</html>