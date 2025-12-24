<?php
// ==========================================
// 1. CONFIG & DATA FETCH
// ==========================================
$host = "localhost";
$user = "root";
$pass = "root";
$dbname = "options_m_db_by_fut";
$conn = new mysqli($host, $user, $pass, $dbname);
if ($conn->connect_error) { die("Connection failed: " . $conn->connect_error); }

// Fetch Daily History
$sqlHistory = "SELECT DATE(trade_date) as t_date, SUBSTRING_INDEX(GROUP_CONCAT(close_price ORDER BY trade_date DESC), ',', 1) as day_close, MAX(high_price) as day_high, MIN(low_price) as day_low FROM nifty_ohlc_30m GROUP BY DATE(trade_date) ORDER BY t_date ASC";
$resHistory = $conn->query($sqlHistory);
$dailyHistory = []; $availableDates = [];
while($row = $resHistory->fetch_assoc()) {
    $d = $row['t_date'];
    $dailyHistory[$d] = [
        'close' => floatval($row['day_close']), 
        'high' => floatval($row['day_high']), 
        'low' => floatval($row['day_low']), 
        'label' => date('d M', strtotime($d))
    ];
    $availableDates[] = $d;
}
rsort($availableDates);

$defaultTo = $availableDates[0];
$defaultFrom = $availableDates[min(3, count($availableDates)-1)];
$fromDate = $_GET['from_date'] ?? $defaultFrom;
$toDate = $_GET['to_date'] ?? $defaultTo;
$toTime = $_GET['time'] ?? '15:30'; 
$simEndTs = $toDate . ' ' . $toTime . ':00';
$ctxStartTs = date('Y-m-d', strtotime("$fromDate - 3 days")) . ' 00:00:00';

// Fetch Chart Data
$sqlChart = "SELECT * FROM nifty_ohlc_30m WHERE trade_date >= '$ctxStartTs' AND trade_date <= '$simEndTs' ORDER BY trade_date ASC";
$resChart = $conn->query($sqlChart);

$chartLabels = []; $chartPrices = []; $chartDataObj = [];
$currentClose = 0; $simMax = 0; $simMin = 999999; $simStartIndex = -1; $idx = 0;

// To map Dates to Chart Index (New Level kab start karna hai)
$dateToIndexMap = []; 

while($row = $resChart->fetch_assoc()) {
    $rawDate = $row['trade_date'];
    $datePart = date('Y-m-d', strtotime($rawDate));
    $c = floatval($row['close_price']);
    $h = floatval($row['high_price']);
    $l = floatval($row['low_price']);
    
    // Map first occurrence of a date to its index
    if (!isset($dateToIndexMap[$datePart])) {
        $dateToIndexMap[$datePart] = $idx;
    }

    if ($datePart >= $fromDate) {
        if ($simStartIndex == -1) $simStartIndex = $idx;
        if ($h > $simMax) $simMax = $h;
        if ($l < $simMin) $simMin = $l;
    }
    $currentClose = $c;
    
    $chartLabels[] = date('d M H:i', strtotime($rawDate));
    $chartPrices[] = $c;
    
    // Store for Logic
    $chartDataObj[] = ['h'=>$h, 'l'=>$l, 'c'=>$c, 'date'=>$datePart];
    $idx++;
}

// ==========================================
// 2. DYNAMIC LEVEL GENERATION
// ==========================================
$datasetsJS = [];
$dataCount = count($chartLabels);

// Loop through ALL history dates that are BEFORE the Simulation End Date
foreach ($dailyHistory as $histDate => $data) {
    if ($histDate >= $toDate) continue; // Future relative to Sim End

    $level = $data['close'];
    $label = $data['label'];
    $isOldLevel = ($histDate < $fromDate); // Created before Sim Start
    
    // 1. Where should this line start?
    // If Old: Start from index 0
    // If New (In-Sim): Start from the index of the NEXT trading day
    $startIndex = 0;
    
    if (!$isOldLevel) {
        // Find next trading day index
        $foundNextDay = false;
        foreach($dateToIndexMap as $mapDate => $mapIndex) {
            if ($mapDate > $histDate) {
                $startIndex = $mapIndex;
                $foundNextDay = true;
                break;
            }
        }
        // Agar agla din aaya hi nahi (e.g. last day of sim), to level show mat karo
        if (!$foundNextDay) continue; 
    }

    // 2. Check Breakage / Freshness
    // We only check candles appearing AFTER the level was created
    $status = 'fresh';
    $brokenInPast = false; // Logic for pre-sim rejection

    // Logic Loop
    $isTouched = false;
    $startCheckingFromIndex = $isOldLevel ? 0 : $startIndex;

    // A. For Old Levels: Check history DB first for pre-sim breaks
    if ($isOldLevel) {
        foreach ($dailyHistory as $iDate => $iData) {
            if ($iDate <= $histDate || $iDate >= $fromDate) continue;
            if (($level > $currentClose && $iData['high'] >= $level) || ($level < $currentClose && $iData['low'] <= $level)) {
                $brokenInPast = true; break;
            }
        }
    }
    if ($brokenInPast) continue; // Hide rejected old levels

    // B. Check Intraday Data for Breakage (For both Old & New)
    // SMC Logic: Touch = Tested. Close beyond Swing = Fresh again (Simplified to Touch = Tested)
    for ($i = $startCheckingFromIndex; $i < $dataCount; $i++) {
        $candle = $chartDataObj[$i];
        
        if ($level > $currentClose) { // Res
            if ($candle['h'] >= $level) $status = 'tested';
        } else { // Sup
            if ($candle['l'] <= $level) $status = 'tested';
        }
    }

    // 3. Prepare Dataset Array (with NULL padding for new levels)
    $dataPoints = array_fill(0, $dataCount, null); // Fill all with null
    for ($k = $startIndex; $k < $dataCount; $k++) {
        $dataPoints[$k] = $level;
    }

    // Filter Zoom
    $zoomLimit = 300;
    if ($level > $simMax + $zoomLimit || $level < $simMin - $zoomLimit) continue;

    $color = ($level > $currentClose) ? '#d32f2f' : '#388e3c'; // Red or Green
    
    $datasetsJS[] = [
        'label' => ($level > $currentClose ? 'R' : 'S') . ": $level ($label)",
        'data' => $dataPoints,
        'borderColor' => $color,
        'borderWidth' => ($status === 'tested') ? 1.5 : 2,
        'borderDash' => ($status === 'tested') ? [5, 5] : [],
        'pointRadius' => 0,
        'fill' => false,
        'isTested' => ($status === 'tested')
    ];
}

$focusMin = ($simMin == 999999) ? 20000 : floor(($simMin - 200) / 50) * 50;
$focusMax = ($simMax == 0) ? 22000 : ceil(($simMax + 200) / 50) * 50;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Dynamic In-Sim Levels</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/hammerjs@2.0.8"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-zoom@1.2.1/dist/chartjs-plugin-zoom.min.js"></script>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f0f2f5; padding: 20px; }
        .top-bar { background: white; padding: 10px; border-radius: 8px; margin-bottom: 15px; display: flex; gap: 10px; flex-wrap:wrap; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        select, input { padding: 5px; border: 1px solid #ccc; border-radius: 4px; }
        .btn { padding: 6px 12px; background: #2962ff; color: white; border: none; border-radius: 4px; cursor: pointer; }
        .chart-container { background: white; padding: 15px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); height: 600px; position: relative; }
    </style>
</head>
<body>

<div class="top-bar">
    <form method="GET" style="display:flex; gap:10px;">
        <select name="from_date"><?php foreach($availableDates as $d): ?><option value="<?php echo $d; ?>" <?php echo ($d==$fromDate)?'selected':'';?>><?php echo date('d M Y',strtotime($d));?></option><?php endforeach; ?></select>
        <select name="to_date"><?php foreach($availableDates as $d): ?><option value="<?php echo $d; ?>" <?php echo ($d==$toDate)?'selected':'';?>><?php echo date('d M Y',strtotime($d));?></option><?php endforeach; ?></select>
        <input type="time" name="time" value="<?php echo $toTime; ?>">
        <button type="submit" class="btn">Simulate</button>
    </form>
    <button class="btn" style="background:#607d8b" onclick="myChart.resetZoom()">Reset View</button>
</div>

<div class="chart-container"><canvas id="paChart"></canvas></div>

<script>
    const labels = <?php echo json_encode($chartLabels); ?>;
    const prices = <?php echo json_encode($chartPrices); ?>;
    const dynamicLevels = <?php echo json_encode($datasetsJS); ?>;
    const simStartIndex = <?php echo $simStartIndex; ?>;

    let datasets = [];
    // Price Line
    datasets.push({ label: 'Price', data: prices, borderColor: '#2962ff', borderWidth: 1.5, pointRadius: 0, tension: 0.1 });
    
    // Add All Levels
    dynamicLevels.forEach(d => datasets.push(d));

    const ctx = document.getElementById('paChart').getContext('2d');
    
    const plugin = {
        id: 'plugin',
        afterDraw: (chart) => {
            const { ctx, chartArea, scales: { x } } = chart;
            if (simStartIndex > 0) {
                const xPos = x.getPixelForValue(simStartIndex);
                if (xPos > chartArea.left && xPos < chartArea.right) {
                    ctx.save(); ctx.strokeStyle = '#000'; ctx.lineWidth=2; ctx.setLineDash([5,5]); 
                    ctx.beginPath(); ctx.moveTo(xPos, chartArea.top); ctx.lineTo(xPos, chartArea.bottom); ctx.stroke();
                    ctx.fillStyle = '#000'; ctx.font = 'bold 12px Arial'; ctx.fillText('SIM START', xPos + 5, chartArea.top + 20);
                    ctx.restore();
                }
            }
            // Tested Labels
            chart.data.datasets.forEach((ds, i) => {
                if (ds.isTested) {
                    const meta = chart.getDatasetMeta(i);
                    // Find last visible point to draw label
                    // Since dynamic lines start late, find first non-null
                    let yPos = null;
                    for(let k=0; k<ds.data.length; k++) { if(ds.data[k] !== null) { yPos = chart.scales.y.getPixelForValue(ds.data[k]); break; } }
                    
                    if(yPos && yPos > chartArea.top && yPos < chartArea.bottom) {
                        ctx.save(); ctx.fillStyle = ds.borderColor; ctx.textAlign = 'right'; ctx.font = 'bold 10px Arial';
                        ctx.fillText('TESTED', chartArea.right - 5, yPos - 3); ctx.restore();
                    }
                }
            });
        }
    };

    const myChart = new Chart(ctx, {
        type: 'line',
        data: { labels: labels, datasets: datasets },
        options: {
            responsive: true, maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { display: false }, zoom: { pan: { enabled: true, mode: 'y' }, zoom: { wheel: { enabled: true }, mode: 'y' } } },
            scales: { y: { min: <?php echo $focusMin; ?>, max: <?php echo $focusMax; ?>, grid: { color: '#e0e0e0' } }, x: { grid: { display: false } } }
        },
        plugins: [plugin]
    });
</script>
</body>
</html>
<?php $conn->close(); ?>