<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set("Asia/Kolkata");

// 1. डेटाबेस कनेक्शन
$conn = new mysqli("localhost", "root", "root", "options_m_db_by_fut");
if ($conn->connect_error) { die("Connection failed: " . $conn->connect_error); }
$conn->query("SET SESSION sql_mode=''");

// 2. ड्रॉपडाउन लिस्ट
$listRes = $conn->query("SELECT MIN(DATE(datetime)) as start_date, strikePrice, expiryDate FROM option_prices_5m_combined_option_sell GROUP BY strikePrice, expiryDate ORDER BY start_date DESC");

$selected_id = $_GET['journey'] ?? '';
$interval = $_GET['interval'] ?? 'daily';
$intervalMap = ['daily' => 86400, '15m' => 900, '30m' => 1800, '1h' => 3600];
$seconds = $intervalMap[$interval] ?? 86400;

$journeyData = []; $chartLabels = []; $chartValues = []; $dayAnnotations = [];

if ($selected_id) {
    list($sPrice, $eDate) = explode('|', $selected_id);
    $sql = "SELECT datetime, close, DATE(datetime) as date_only, FLOOR(UNIX_TIMESTAMP(datetime) / $seconds) * $seconds as time_slot FROM option_prices_5m_combined_option_sell WHERE strikePrice = '$sPrice' AND expiryDate = '$eDate' AND TIME(datetime) BETWEEN '09:15:00' AND '15:30:00' ORDER BY datetime ASC";

    $res = $conn->query($sql);
    $temp = []; $prev_date = "";

    while ($row = $res->fetch_assoc()) {
        $slot = $row['time_slot'];
        $db_date = $row['date_only'];
        $actual_ts = strtotime($row['datetime']);

        if (!isset($temp[$slot])) {
            $display_t = ($interval == 'daily') ? date('d M', $actual_ts) : date('d M H:i', $actual_ts);
            $temp[$slot] = [
                'display_time' => $display_t,
                'low' => $row['close'], 'high' => $row['close'],
                'low_t' => date('H:i', $actual_ts), 'high_t' => date('H:i', $actual_ts),
                'new_day' => ($db_date !== $prev_date) ? $display_t : null
            ];
            $prev_date = $db_date;
        } else {
            if ($row['close'] < $temp[$slot]['low']) { $temp[$slot]['low'] = $row['close']; $temp[$slot]['low_t'] = date('H:i', $actual_ts); }
            if ($row['close'] > $temp[$slot]['high']) { $temp[$slot]['high'] = $row['close']; $temp[$slot]['high_t'] = date('H:i', $actual_ts); }
        }
    }

    foreach ($temp as $d) {
        $diff = round($d['high'] - $d['low'], 2);
        $journeyData[] = ['time' => $d['display_time'], 'low' => $d['low'], 'high' => $d['high'], 'low_t' => $d['low_t'], 'high_t' => $d['high_t'], 'diff' => $diff];
        $chartLabels[] = $d['display_time'];
        $chartValues[] = $diff;
        if ($d['new_day'] && $interval !== 'daily') {
            $dayAnnotations[] = ['x' => $d['display_time'], 'borderColor' => '#000', 'borderWidth' => 1, 'strokeDashArray' => 3, 'label' => ['text' => $d['new_day'], 'style' => ['color' => '#fff', 'background' => '#333']] ];
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Option Journey Pro</title>
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <style>
        body { font-family: sans-serif; background: #f1f5f9; padding: 20px; }
        .container { max-width: 1000px; margin: auto; }
        .card { background: #fff; padding: 20px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); margin-bottom: 20px; }
        .controls { display: flex; gap: 10px; justify-content: center; }
        select, button { padding: 10px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 14px; }
        button { background: #2563eb; color: white; border: none; cursor: pointer; }
        .table-wrapper { background: white; border-radius: 12px; overflow: hidden; border: 1px solid #e2e8f0; }
        table { width: 100%; border-collapse: collapse; text-align: left; }
        th { background: #f8fafc; padding: 12px; font-size: 12px; color: #64748b; border-bottom: 2px solid #e2e8f0; }
        td { padding: 12px; border-bottom: 1px solid #f1f5f9; font-size: 14px; }
        .diff-badge { font-weight: bold; color: #dc2626; background: #fee2e2; padding: 4px 8px; border-radius: 6px; }
    </style>
</head>
<body>

<div class="container">
    <div class="card">
        <form method="GET" class="controls">
            <select name="journey" required>
                <option value="">-- Select Strike --</option>
                <?php $listRes->data_seek(0); while($row = $listRes->fetch_assoc()): 
                    $v = $row['strikePrice'] . "|" . $row['expiryDate'];
                    $disp = date('d M (D)', strtotime($row['start_date'])) . " - " . $row['strikePrice'];
                    $sel = ($selected_id == $v) ? 'selected' : '';
                    echo "<option value='$v' $sel>$disp</option>";
                endwhile; ?>
            </select>
            <select name="interval">
                <option value="daily" <?=$interval=='daily'?'selected':''?>>Daily Range</option>
                <option value="15m" <?=$interval=='15m'?'selected':''?>>15 Min Range</option>
                <option value="30m" <?=$interval=='30m'?'selected':''?>>30 Min Range</option>
                <option value="1h" <?=$interval=='1h'?'selected':''?>>1 Hour Range</option>
            </select>
            <button type="submit">Analyze</button>
        </form>
    </div>

    <?php if (!empty($journeyData)): ?>
        <div class="card"><div id="chart"></div></div>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>Time Period</th><th>Low Close (Time)</th><th>High Close (Time)</th><th>Range (Diff)</th></tr></thead>
                <tbody>
                    <?php foreach (array_reverse($journeyData) as $row): ?>
                    <tr>
                        <td><b><?=$row['time']?></b></td>
                        <td><?=number_format($row['low'],2)?> <small style="color:#94a3b8">at <?=$row['low_t']?></small></td>
                        <td><?=number_format($row['high'],2)?> <small style="color:#94a3b8">at <?=$row['high_t']?></small></td>
                        <td><span class="diff-badge"><?=number_format($row['diff'],2)?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script>
<?php if (!empty($chartValues)): ?>
    var options = {
        series: [{ name: 'Points', data: <?=json_encode($chartValues)?> }],
        chart: { type: 'area', height: 350, toolbar: { show: false }, zoom: { enabled: false } },
        stroke: { curve: 'smooth', width: 3 },
        fill: { type: 'gradient', gradient: { opacityFrom: 0.4, opacityTo: 0.05 } },
        dataLabels: { 
            enabled: true, // रेंज टेक्स्ट को इनेबल किया गया
            style: { fontSize: '12px', colors: ['#333'] },
            background: { enabled: true, foreColor: '#fff', padding: 4, borderRadius: 4, borderWidth: 1, borderColor: '#3b82f6', opacity: 1, dropShadow: { enabled: false } },
            formatter: function(val) { return val; } 
        },
        xaxis: { categories: <?=json_encode($chartLabels)?>, labels: { rotate: -45, style: { fontSize: '10px' } } },
        annotations: { xaxis: <?=json_encode($dayAnnotations)?> },
        tooltip: { theme: 'light' }
    };
    new ApexCharts(document.querySelector("#chart"), options).render();
<?php endif; ?>
</script>
</body>
</html>