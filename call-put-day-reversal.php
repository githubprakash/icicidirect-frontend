<?php
// 1. Database Connection
$conn = new mysqli("localhost", "root", "root", "options_m_db_by_fut");

// --- AJAX DATA HANDLER ---
if (isset($_GET['ajax'])) {
    $date = $_GET['date'];
    $strike = $_GET['strike'];
    $expiry = $_GET['expiry'];
    $interval = $_GET['interval'] ?? '15m';
    $intervalMap = ['5m'=>300, '15m'=>900, '30m'=>1800, '60m'=>3600];
    $sec = $intervalMap[$interval] ?? 900;

    $sql = "SELECT 
                FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(C.datetime)/$sec)*$sec) as time_slot, 
                AVG(C.close) as cp, AVG(P.close) as pp, AVG(C.delta) as cd, AVG(P.delta) as pd
            FROM option_prices_5m_call C 
            JOIN option_prices_5m_put P ON C.datetime = P.datetime AND C.strikePrice = P.strikePrice AND C.expiryDate = P.expiryDate
            WHERE C.strikePrice = '$strike' AND C.expiryDate = '$expiry'
              AND DATE(C.datetime) >= '$date' AND DATE(C.datetime) <= '$expiry'
            GROUP BY time_slot ORDER BY time_slot ASC";

    $res = $conn->query($sql);
    $output = [
        'labels'=>[], 'comb'=>[], 'cp'=>[], 'pp'=>[], 'nd'=>[], 'cd'=>[], 
        'dayHighlights'=>[], 'dailyLevels'=>[], 'startPrice'=>0, 'totalLow'=>999999, 'totalHigh'=>0
    ];

    $idx = 0; $prevDate = null; $tempHigh = 0; $tempLow = 999999; $dayStartIdx = 0;
    while($row = $res->fetch_assoc()) {
        $currD = date('Y-m-d', strtotime($row['time_slot']));
        $price = round($row['cp'] + $row['pp'], 2);

        if ($prevDate && $currD != $prevDate) {
            $output['dailyLevels'][] = ['start' => $dayStartIdx, 'end' => $idx - 1, 'high' => $tempHigh, 'low' => $tempLow];
            $output['dayHighlights'][] = ['index' => $idx, 'label' => date('d M', strtotime($row['time_slot']))];
            $tempHigh = 0; $tempLow = 999999; $dayStartIdx = $idx;
        }

        if($idx == 0) $output['startPrice'] = $price;
        if($price > $tempHigh) $tempHigh = $price;
        if($price < $tempLow) $tempLow = $price;
        if($price > $output['totalHigh']) $output['totalHigh'] = $price;
        if($price < $output['totalLow']) $output['totalLow'] = $price;

        $output['labels'][] = date('d M H:i', strtotime($row['time_slot']));
        $output['comb'][] = $price;
        $output['cp'][] = round($row['cp'], 2);
        $output['pp'][] = round($row['pp'], 2);
        $output['cd'][] = round($row['cd'], 3);
        $output['nd'][] = round($row['cd'] + $row['pd'], 3);
        $prevDate = $currD; $idx++;
    }
    $output['dailyLevels'][] = ['start' => $dayStartIdx, 'end' => $idx - 1, 'high' => $tempHigh, 'low' => $tempLow];
    echo json_encode($output);
    exit;
}

// 2. Fetch Journey Unique Combinations for Initial Load
$datesList = [];
$dateQuery = "SELECT MIN(DATE(datetime)) as start_date, strikePrice, expiryDate FROM option_prices_5m_call GROUP BY strikePrice, expiryDate ORDER BY start_date DESC, strikePrice ASC";
$dateRes = $conn->query($dateQuery);
while($r = $dateRes->fetch_assoc()) { $datesList[] = ['date' => $r['start_date'], 'strike' => $r['strikePrice'], 'expiry' => $r['expiryDate']]; }

$selectedDate = $_GET['date'] ?? ($datesList[0]['date'] ?? '');
$selectedStrike = $_GET['strike'] ?? ($datesList[0]['strike'] ?? '');
$selectedExpiry = $_GET['expiry'] ?? ($datesList[0]['expiry'] ?? '');
$selectedInterval = $_GET['interval'] ?? '15m';
?>

<!DOCTYPE html>
<html>
<head>
    <title>Strike Journey Tracker (AJAX)</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-annotation@2.2.1"></script>
    <style>
        body { font-family: sans-serif; background: #f4f6f9; padding: 20px; }
        .nav-bar { background: white; padding: 15px; border-radius: 8px; display: flex; gap: 20px; align-items: flex-end; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 15px; }
        .chart-card { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); height: 75vh; position: relative; }
        .settings-bar { background: #fff; padding: 10px 20px; border-radius: 8px; margin-bottom: 10px; display: flex; gap: 20px; font-size: 13px; font-weight: bold; align-items: center; border: 1px solid #ddd; }
        .info-badge { padding: 5px 12px; background: #e9ecef; border-radius: 4px; font-size: 13px; color: #495057; }
        .sr-toggle { margin-left: auto; background: #fff1f2; color: #e11d48; padding: 4px 10px; border-radius: 4px; border: 1px solid #fda4af; display: flex; gap: 10px;}
        select { padding: 8px; border-radius: 4px; border: 1px solid #ccc; max-width: 300px; }
        #loader { display:none; position: absolute; top: 10px; right: 20px; background: orange; color: white; padding: 2px 10px; border-radius: 4px; font-size: 11px; }
    </style>
</head>
<body>

<div class="nav-bar">
    <div style="display:flex; flex-direction:column; gap:4px;">
        <label style="font-size:11px; color:#666;">SELECT STRIKE & DATE</label>
        <select id="main_selector">
            <?php foreach($datesList as $d): 
                $val = "{$d['date']}|{$d['strike']}|{$d['expiry']}";
                $is_selected = ($d['date'] == $selectedDate && $d['strike'] == $selectedStrike && $d['expiry'] == $selectedExpiry) ? 'selected' : '';
            ?>
                <option value="<?php echo $val; ?>" <?php echo $is_selected; ?>>
                    <?php echo date('d M Y', strtotime($d['date'])); ?> - <?php echo $d['strike']; ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div style="display:flex; flex-direction:column; gap:4px;">
        <label style="font-size:11px; color:#666;">INTERVAL</label>
        <select id="interval_selector">
            <option value="5m" <?php echo $selectedInterval=='5m'?'selected':''; ?>>5m</option>
            <option value="15m" <?php echo $selectedInterval=='15m'?'selected':''; ?>>15m</option>
            <option value="30m" <?php echo $selectedInterval=='30m'?'selected':''; ?>>30m</option>
            <option value="60m" <?php echo $selectedInterval=='60m'?'selected':''; ?>>60m</option>
        </select>
    </div>

    <div style="margin-left:auto; display:flex; gap:10px; align-items: center;">
        <label style="font-size:11px; color: #555; background: #f0f0f0; padding: 5px; border-radius: 4px; border: 1px solid #ddd; cursor:pointer;">
            <input type="checkbox" id="key_reload_switch"> Auto-Load Keys
        </label>
        <div class="info-badge">Strike: <b id="badge_strike"><?php echo $selectedStrike; ?></b></div>
        <div class="info-badge">Expiry: <b id="badge_expiry"><?php echo $selectedExpiry; ?></b></div>
    </div>
</div>

<div class="settings-bar" id="chartToggles">
    <span style="color:#888">VIEW:</span>
    <label><input type="checkbox" data-index="0" checked> Combined</label>
    <label style="color:green;"><input type="checkbox" data-index="1" checked> Call P</label>
    <label style="color:red;"><input type="checkbox" data-index="2" checked> Put P</label>
    <label style="color:purple;"><input type="checkbox" data-index="3" checked> Net Δ</label>
    <label style="color:orange;"><input type="checkbox" data-index="4" checked> Call Δ</label>
    <div class="sr-toggle"><label><input type="checkbox" id="sr_switch"> Day S&R</label></div>
</div>

<div class="chart-card">
    <div id="loader">Loading Data...</div>
    <canvas id="journeyChart"></canvas>
</div>

<script>
let mainChart;
let globalData = {};
const STORAGE_KEY = 'options_journey_ajax_v1';

async function refreshData() {
    const selector = document.getElementById('main_selector');
    const interval = document.getElementById('interval_selector').value;
    const parts = selector.value.split('|');
    const loader = document.getElementById('loader');

    loader.style.display = 'block';

    // Update URL without reloading
    const url = new URL(window.location.href);
    url.searchParams.set('date', parts[0]);
    url.searchParams.set('strike', parts[1]);
    url.searchParams.set('expiry', parts[2]);
    url.searchParams.set('interval', interval);
    window.history.pushState({}, '', url);

    // Fetch new data via AJAX
    const response = await fetch(`${url.origin}${url.pathname}?ajax=1&date=${parts[0]}&strike=${parts[1]}&expiry=${parts[2]}&interval=${interval}`);
    const data = await response.json();
    globalData = data;

    // Update Header Badges
    document.getElementById('badge_strike').innerText = parts[1];
    document.getElementById('badge_expiry').innerText = parts[2];

    // Update Chart Data
    mainChart.data.labels = data.labels;
    mainChart.data.datasets[0].data = data.comb;
    mainChart.data.datasets[1].data = data.cp;
    mainChart.data.datasets[2].data = data.pp;
    mainChart.data.datasets[3].data = data.nd;
    mainChart.data.datasets[4].data = data.cd;

    // Update Percentage Axis Scale
    mainChart.options.scales.yPct.min = ((data.totalLow - data.startPrice)/data.startPrice)*100 - 2;
    mainChart.options.scales.yPct.max = ((data.totalHigh - data.startPrice)/data.startPrice)*100 + 2;

    updateAnnotations();
    loader.style.display = 'none';
}

function updateAnnotations() {
    const showSR = document.getElementById('sr_switch').checked;
    let anns = {};
    globalData.dayHighlights.forEach((h, i) => {
        anns['line'+i] = { type: 'line', xMin: h.index-0.5, xMax: h.index-0.5, borderColor: '#999', borderWidth: 1, borderDash: [5,5], label: { display: true, content: h.label, position: 'start', font: {size: 10}, backgroundColor: '#f4f6f9' } };
    });
    if(showSR) {
        globalData.dailyLevels.forEach((dl, i) => {
            anns['res'+i] = { type: 'line', yMin: dl.high, yMax: dl.high, xMin: dl.start, xMax: dl.end, borderColor: 'rgba(255,0,0,0.5)', borderWidth: 2, borderDash: [2,2], label: { display: true, content: 'R:'+dl.high, position: 'end', font: {size:9} } };
            anns['sup'+i] = { type: 'line', yMin: dl.low, yMax: dl.low, xMin: dl.start, xMax: dl.end, borderColor: 'rgba(0,128,0,0.5)', borderWidth: 2, borderDash: [2,2], label: { display: true, content: 'S:'+dl.low, position: 'end', font: {size:9} } };
        });
    }
    mainChart.options.plugins.annotation.annotations = anns;
    mainChart.update();
}

// Initial Chart Setup
const ctx = document.getElementById('journeyChart').getContext('2d');
mainChart = new Chart(ctx, {
    type: 'line',
    data: { labels: [], datasets: [
        { label: 'Combined', data: [], borderColor: '#007bff', yAxisID: 'yPrice', borderWidth: 2, pointRadius: 0 },
        { label: 'Call P', data: [], borderColor: 'green', yAxisID: 'yPrice', borderWidth: 1, pointRadius: 0 },
        { label: 'Put P', data: [], borderColor: 'red', yAxisID: 'yPrice', borderWidth: 1, pointRadius: 0 },
        { label: 'Net Delta', data: [], borderColor: 'purple', yAxisID: 'yDelta', borderDash: [5,3], pointRadius: 0 },
        { label: 'Call Delta', data: [], borderColor: 'orange', yAxisID: 'yDelta', borderDash: [2,2], pointRadius: 0 }
    ]},
    options: {
        responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
        scales: {
            yPrice: { type: 'linear', position: 'right', title: { display: true, text: 'Price' } },
            yDelta: { type: 'linear', position: 'left', title: { display: true, text: 'Delta' }, grid: { drawOnChartArea: false } },
            yPct: { type: 'linear', position: 'right', grid: { drawOnChartArea: false }, callback: v => v.toFixed(1) + '%', title: { display: true, text: '%' } }
        },
        plugins: { legend: { display: false }, annotation: { annotations: {} } }
    }
});

// Event Listeners
document.getElementById('main_selector').addEventListener('change', refreshData);
document.getElementById('interval_selector').addEventListener('change', refreshData);
document.getElementById('main_selector').addEventListener('keyup', (e) => {
    if ((e.key === 'ArrowUp' || e.key === 'ArrowDown') && document.getElementById('key_reload_switch').checked) {
        refreshData();
    }
});

function save() {
    const cfg = { vis: {}, sr: document.getElementById('sr_switch').checked, keyReload: document.getElementById('key_reload_switch').checked };
    document.querySelectorAll('#chartToggles input[data-index]').forEach(cb => cfg.vis[cb.dataset.index] = cb.checked);
    localStorage.setItem(STORAGE_KEY, JSON.stringify(cfg));
}

function loadSettings() {
    const data = localStorage.getItem(STORAGE_KEY);
    if(data) {
        const cfg = JSON.parse(data);
        Object.keys(cfg.vis).forEach(idx => {
            const cb = document.querySelector(`input[data-index="${idx}"]`);
            if(cb) { cb.checked = cfg.vis[idx]; mainChart.setDatasetVisibility(idx, cb.checked); }
        });
        document.getElementById('sr_switch').checked = cfg.sr;
        document.getElementById('key_reload_switch').checked = cfg.keyReload;
    }
    refreshData(); // Initial Data Load
}

document.querySelectorAll('#chartToggles input, #key_reload_switch').forEach(cb => {
    cb.addEventListener('change', () => {
        if(cb.id === 'sr_switch') updateAnnotations();
        else if(cb.dataset.index !== undefined) mainChart.setDatasetVisibility(cb.dataset.index, cb.checked);
        mainChart.update();
        save();
    });
});

window.onload = loadSettings;
</script>
</body>
</html>