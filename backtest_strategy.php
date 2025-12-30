<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(0); 
date_default_timezone_set('Asia/Kolkata');

$conn = new mysqli("localhost", "root", "root", "options_m_db_by_fut");

// --- 1. Dropdown Data ---
$tableName = 'option_prices_5m_combined_option_sell'; 
$datesList = [];
$dateRes = $conn->query("SELECT MIN(DATE(datetime)) as start_date, strikePrice, expiryDate 
                         FROM $tableName GROUP BY strikePrice, expiryDate ORDER BY start_date DESC");
while($r = $dateRes->fetch_assoc()) { $datesList[] = $r; }

// --- 2. Selection ---
$rawSelection = $_GET['selection'] ?? ''; 
if ($rawSelection && strpos($rawSelection, '|') !== false) {
    $parts = explode('|', $rawSelection);
    $selectedStrike = $parts[0];
    $selectedExpiry = $parts[1];
} else {
    $selectedStrike = $datesList[0]['strikePrice'] ?? '';
    $selectedExpiry = $datesList[0]['expiryDate'] ?? '';
}
$strikeVal = (float)$selectedStrike;

// --- 3. Trading Logic & POC Calculation ---
function calculatePOC($candles) {
    $profile = [];
    foreach($candles as $c) {
        $price = (int)round($c['synth_close'], 0);
        $profile[$price] = ($profile[$price] ?? 0) + $c['volume'];
    }
    return !empty($profile) ? array_search(max($profile), $profile) : null;
}

$backtestResults = [];
$totalPL = 0;
$allChartData = []; // Store 5m data for JS chart

if ($selectedStrike && $selectedExpiry) {
    $sql = "SELECT datetime, (close + $strikeVal) as synth_close, open + $strikeVal as synth_open, 
                   high + $strikeVal as synth_high, low + $strikeVal as synth_low, 
                   volume, DATE(datetime) as d_only 
            FROM $tableName 
            WHERE strikePrice = '$selectedStrike' AND expiryDate = '$selectedExpiry'
              AND TIME(datetime) BETWEEN '09:15:00' AND '15:30:00'
            ORDER BY datetime ASC";

    $result = $conn->query($sql);
    $dataByDay = [];
    while($row = $result->fetch_assoc()) { $dataByDay[$row['d_only']][] = $row; }

    $dates = array_keys($dataByDay);
    for ($i = 1; $i < count($dates); $i++) {
        $today = $dates[$i];
        $yesterday = $dates[$i-1];
        $todayCandles = $dataByDay[$today];
        $yesterdayCandles = $dataByDay[$yesterday];

        $yDayPoc = calculatePOC($yesterdayCandles);
        $y30mPoc = calculatePOC(array_slice($yesterdayCandles, 0, 6));
        $t30mPoc = calculatePOC(array_slice($todayCandles, 0, 6));

        if (!$yDayPoc || !$y30mPoc || !$t30mPoc) continue;

        $tradeAction = "NO TRADE"; $entryPrice = 0; $exitPrice = 0; $reason = ""; $pnl = 0;

        if (isset($todayCandles[6])) {
            $entryCandle = $todayCandles[6];
            $entryPrice = $entryCandle['synth_close'];
            if ($yDayPoc > $y30mPoc) { // Case 1
                if ($t30mPoc > $yDayPoc) { $tradeAction = "BUY"; $reason = "C1: T30m > Y-Day POC"; }
                elseif ($t30mPoc < $y30mPoc) { $tradeAction = "SELL"; $reason = "C1: T30m < Y-30m POC"; }
            } else { // Case 2
                if ($t30mPoc > $y30mPoc) { $tradeAction = "BUY"; $reason = "C2: T30m > Y-30m POC"; }
                elseif ($t30mPoc < $yDayPoc) { $tradeAction = "SELL"; $reason = "C2: T30m < Y-Day POC"; }
            }
        }

        if ($tradeAction !== "NO TRADE") {
            $lastCandle = end($todayCandles);
            $exitPrice = $lastCandle['synth_close'];
            $pnl = ($tradeAction == "BUY") ? ($exitPrice - $entryPrice) : ($entryPrice - $exitPrice);
            $totalPL += $pnl;
        }

        $backtestResults[] = [
            'date' => $today, 'yDayPoc' => $yDayPoc, 'y30mPoc' => $y30mPoc, 't30mPoc' => $t30mPoc,
            'action' => $tradeAction, 'entry' => $entryPrice, 'exit' => $exitPrice, 'pnl' => round($pnl, 2), 'reason' => $reason,
            'candles' => $todayCandles // Pass to JS
        ];
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Synthetic Backtest & Chart</title>
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f1f5f9; padding: 20px; font-size: 13px; }
        .container { max-width: 1200px; margin: auto; }
        .header-box { background: white; padding: 15px; border-radius: 8px; margin-bottom: 10px; display: flex; align-items: center; gap: 15px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        #chart-container { background: white; padding: 15px; border-radius: 8px; margin-bottom: 20px; height: 400px; display: none; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .summary { background: #1e293b; color: white; padding: 15px; border-radius: 8px; font-size: 18px; margin-bottom: 10px; text-align: center; }
        table { width: 100%; border-collapse: collapse; background: white; border-radius: 8px; overflow: hidden; }
        th, td { border: 1px solid #e2e8f0; padding: 10px; text-align: left; }
        th { background: #f8fafc; }
        .buy { color: #059669; font-weight: bold; }
        .sell { color: #dc2626; font-weight: bold; }
        .profit { background-color: #f0fdf4; }
        .loss { background-color: #fef2f2; }
        .btn-view { background: #6366f1; color: white; border: none; padding: 4px 8px; border-radius: 4px; cursor: pointer; }
    </style>
</head>
<body>

<div class="container">
    <div class="header-box">
        <form method="GET" style="display:flex; gap:10px; align-items:center;">
            <strong>Selection:</strong>
            <select name="selection">
                <?php foreach($datesList as $d): 
                    $val = $d['strikePrice'] . "|" . $d['expiryDate'];
                    $sel = ($selectedStrike == $d['strikePrice'] && $selectedExpiry == $d['expiryDate']) ? 'selected' : '';
                    echo "<option value='$val' $sel>".date('d M Y', strtotime($d['start_date']))." - Strike: {$d['strikePrice']}</option>";
                endforeach; ?>
            </select>
            <button type="submit" style="background:#2563eb; color:white; border:none; padding:8px 15px; border-radius:4px;">Run Backtest</button>
        </form>
    </div>

    <div class="summary">
        Strike: <?php echo $selectedStrike; ?> | Expiry: <?php echo $selectedExpiry; ?> | 
        Total P&L: <span style="color:<?php echo $totalPL>=0?'#10b981':'#ff4d4d'; ?>"><?php echo round($totalPL, 2); ?> Points</span>
    </div>

    <div id="chart-container">
        <div id="dayChart"></div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Action</th>
                <th>P&L</th>
                <th>Logic</th>
                <th>Chart</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach(array_reverse($backtestResults) as $idx => $res): ?>
            <tr class="<?php echo $res['action']!='NO TRADE'?($res['pnl']>=0?'profit':'loss'):''; ?>">
                <td><?php echo $res['date']; ?></td>
                <td class="<?php echo strtolower($res['action']); ?>"><?php echo $res['action']; ?></td>
                <td><strong><?php echo $res['pnl']; ?></strong></td>
                <td><?php echo $res['reason']; ?></td>
                <td><button class="btn-view" onclick="showChart(<?php echo htmlspecialchars(json_encode($res)); ?>)">👁️ View</button></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script>
let chart;

function showChart(data) {
    document.getElementById('chart-container').style.display = 'block';
    window.scrollTo({ top: 0, behavior: 'smooth' });

    const candleData = data.candles.map(c => ({
        x: c.datetime.split(' ')[1], // Time only
        y: [parseFloat(c.synth_open), parseFloat(c.synth_high), parseFloat(c.synth_low), parseFloat(c.synth_close)]
    }));

    const options = {
        series: [{ name: 'Price', type: 'candlestick', data: candleData }],
        chart: { type: 'candlestick', height: 350, animations: { enabled: false } },
        title: { text: 'Date: ' + data.date + ' | ' + data.reason, align: 'left' },
        xaxis: { type: 'category' },
        yaxis: { opposite: true, tooltip: { enabled: true } },
        annotations: {
            yaxis: [
                { y: data.yDayPoc, borderColor: '#3b82f6', label: { text: 'Y-Day POC', style: { color: '#fff', background: '#3b82f6' } } },
                { y: data.y30mPoc, borderColor: '#10b981', label: { text: 'Y-30m POC', style: { color: '#fff', background: '#10b981' } } },
                { y: data.t30mPoc, borderColor: '#f59e0b', label: { text: 'T-30m POC', style: { color: '#fff', background: '#f59e0b' } } }
            ],
            xaxis: [
                { x: candleData[6] ? candleData[6].x : '', borderColor: '#000', label: { text: 'Entry', style: { color: '#fff', background: '#000' } } }
            ]
        }
    };

    if (chart) chart.destroy();
    chart = new ApexCharts(document.querySelector("#dayChart"), options);
    chart.render();
}
</script>
</body>
</html>