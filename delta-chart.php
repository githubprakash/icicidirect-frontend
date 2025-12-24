<?php
// ==========================================
// 1. DATABASE CONNECTION
// ==========================================
$host = "localhost";
$user = "root";
$pass = "root";
$dbname = "options_m_db_by_fut";

$conn = new mysqli($host, $user, $pass, $dbname);
if ($conn->connect_error) { die("Connection failed: " . $conn->connect_error); }

// ==========================================
// 2. INPUT HANDLING
// ==========================================
$selectedType = $_GET['type'] ?? 'call'; 
$metaTable = ($selectedType == 'put') ? 'option_prices_5m_put' : 'option_prices_5m_call';

// Interval Map
$intervalMap = ['5m' => 300, '15m' => 900, '30m' => 1800, '60m' => 3600];
$selectedInterval = $_GET['interval'] ?? '30m';
$sec = $intervalMap[$selectedInterval] ?? 1800;

// Expiry
$expQuery = "SELECT DISTINCT expiryDate FROM $metaTable ORDER BY expiryDate ASC";
$expRes = $conn->query($expQuery);
$expiries = [];
while($row = $expRes->fetch_assoc()) { $expiries[] = $row['expiryDate']; }
$selectedExpiry = (isset($_GET['expiry']) && in_array($_GET['expiry'], $expiries)) ? $_GET['expiry'] : ($expiries[0] ?? '');

// Strike
$strikes = [];
if ($selectedExpiry) {
    $strQuery = "SELECT DISTINCT strikePrice FROM $metaTable WHERE expiryDate = '$selectedExpiry' ORDER BY strikePrice ASC";
    $strRes = $conn->query($strQuery);
    while($row = $strRes->fetch_assoc()) { $strikes[] = $row['strikePrice']; }
}
$selectedStrike = (isset($_GET['strike']) && in_array($_GET['strike'], $strikes)) ? $_GET['strike'] : ($strikes[0] ?? '');

// Date Range
$currentDate = date('Y-m-d');
$fromDate = $_GET['from_date'] ?? $currentDate;
$toDate   = $_GET['to_date'] ?? $currentDate;

// ==========================================
// 3. FETCH DATA
// ==========================================
$chartLabels = [];
$callDataMap = [];
$putDataMap = [];

if ($selectedExpiry && $selectedStrike) {
    // Call Query
    if ($selectedType == 'call' || $selectedType == 'both') {
        $sqlCall = "SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(DATETIME)/$sec)*$sec) as time_slot, AVG(delta) as avg_delta
                    FROM option_prices_5m_call
                    WHERE expiryDate = '$selectedExpiry' AND strikePrice = '$selectedStrike'
                      AND DATETIME BETWEEN '$fromDate 00:00:00' AND '$toDate 23:59:59'
                    GROUP BY time_slot ORDER BY time_slot ASC";
        $resCall = $conn->query($sqlCall);
        while($row = $resCall->fetch_assoc()) {
            $t = date('d M H:i', strtotime($row['time_slot']));
            $callDataMap[$t] = $row['avg_delta'];
            if (!in_array($t, $chartLabels)) { $chartLabels[] = $t; }
        }
    }
    // Put Query
    if ($selectedType == 'put' || $selectedType == 'both') {
        $sqlPut = "SELECT FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(DATETIME)/$sec)*$sec) as time_slot, AVG(delta) as avg_delta
                    FROM option_prices_5m_put
                    WHERE expiryDate = '$selectedExpiry' AND strikePrice = '$selectedStrike'
                      AND DATETIME BETWEEN '$fromDate 00:00:00' AND '$toDate 23:59:59'
                    GROUP BY time_slot ORDER BY time_slot ASC";
        $resPut = $conn->query($sqlPut);
        while($row = $resPut->fetch_assoc()) {
            $t = date('d M H:i', strtotime($row['time_slot']));
            $putDataMap[$t] = $row['avg_delta'];
            if (!in_array($t, $chartLabels)) { $chartLabels[] = $t; }
        }
    }
    usort($chartLabels, function($a, $b) { return strtotime($a) - strtotime($b); });
}

// Align Data
$finalCallData = [];
$finalPutData = [];
foreach ($chartLabels as $time) {
    if ($selectedType == 'call' || $selectedType == 'both') $finalCallData[] = $callDataMap[$time] ?? null;
    if ($selectedType == 'put' || $selectedType == 'both') $finalPutData[] = $putDataMap[$time] ?? null;
}

$jsLabels = json_encode($chartLabels);
$jsCallData = json_encode($finalCallData);
$jsPutData = json_encode($finalPutData);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Delta Chart with Separator</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-zoom@1.2.1/dist/chartjs-plugin-zoom.min.js"></script>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f4f6f9; padding: 20px; }
        .filter-container { background: white; padding: 15px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .filter-row { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; }
        .form-group { display: flex; flex-direction: column; }
        label { font-size: 11px; font-weight: bold; margin-bottom: 3px; color: #555; text-transform: uppercase; }
        select, input { padding: 6px; border: 1px solid #ccc; border-radius: 4px; min-width: 120px; }
        button { padding: 7px 20px; background: #2962ff; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; }
        button:hover { background: #0039cb; }
        .chart-container { background: white; padding: 10px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); height: 600px; position: relative; }
    </style>
</head>
<body>

    <div class="filter-container">
        <form method="GET" class="filter-row">
            <div class="form-group"><label>Type</label>
                <select name="type" onchange="this.form.submit()">
                    <option value="call" <?php echo ($selectedType=='call')?'selected':''; ?>>Call (CE)</option>
                    <option value="put" <?php echo ($selectedType=='put')?'selected':''; ?>>Put (PE)</option>
                    <option value="both" <?php echo ($selectedType=='both')?'selected':''; ?>>Both</option>
                </select>
            </div>
            <div class="form-group"><label>Expiry</label>
                <select name="expiry" onchange="this.form.submit()">
                    <?php foreach($expiries as $e) echo "<option value='$e' ".($e==$selectedExpiry?'selected':'').">".date('d M Y',strtotime($e))."</option>"; ?>
                </select>
            </div>
            <div class="form-group"><label>Strike</label>
                <select name="strike">
                    <?php foreach($strikes as $s) echo "<option value='$s' ".($s==$selectedStrike?'selected':'').">$s</option>"; ?>
                </select>
            </div>
            <div class="form-group"><label>Interval</label>
                <select name="interval">
                    <option value="5m" <?php echo ($selectedInterval=='5m')?'selected':''; ?>>5 Min</option>
                    <option value="15m" <?php echo ($selectedInterval=='15m')?'selected':''; ?>>15 Min</option>
                    <option value="30m" <?php echo ($selectedInterval=='30m')?'selected':''; ?>>30 Min</option>
                    <option value="60m" <?php echo ($selectedInterval=='60m')?'selected':''; ?>>60 Min</option>
                </select>
            </div>
            <div class="form-group"><label>From</label><input type="date" name="from_date" value="<?php echo $fromDate; ?>"></div>
            <div class="form-group"><label>To</label><input type="date" name="to_date" value="<?php echo $toDate; ?>"></div>
            <div class="form-group"><button type="submit">Show</button></div>
        </form>
    </div>

    <div class="chart-container">
        <canvas id="comparisonChart"></canvas>
    </div>

    <script>
        const ctx = document.getElementById('comparisonChart').getContext('2d');
        const labels = <?php echo $jsLabels; ?>;
        const selectedType = "<?php echo $selectedType; ?>";

        // ===========================================
        // CUSTOM PLUGIN: DAY SEPARATOR
        // ===========================================
        const daySeparatorPlugin = {
            id: 'daySeparator',
            beforeDatasetsDraw: (chart) => {
                const { ctx, chartArea: { top, bottom }, scales: { x } } = chart;
                const labels = chart.data.labels;
                
                ctx.save();
                ctx.beginPath();
                ctx.lineWidth = 1;
                ctx.strokeStyle = '#666'; // Grey color line
                ctx.setLineDash([5, 5]);  // Dashed line pattern

                let lastDate = null;

                labels.forEach((label, index) => {
                    // Label format ex: "12 Oct 09:15"
                    // Hum split karke '12 Oct' nikalenge
                    const datePart = label.split(' ').slice(0, 2).join(' ');

                    if (lastDate && datePart !== lastDate) {
                        // Date badal gayi! Pichle index aur current index ke beech line draw karo
                        const xPosPrev = x.getPixelForValue(index - 1);
                        const xPosCurr = x.getPixelForValue(index);
                        const xMid = (xPosPrev + xPosCurr) / 2; // Middle point

                        ctx.moveTo(xMid, top);
                        ctx.lineTo(xMid, bottom);
                    }
                    lastDate = datePart;
                });

                ctx.stroke();
                ctx.restore();
            }
        };

        let datasets = [];
        if (selectedType === 'call' || selectedType === 'both') {
            datasets.push({
                label: 'CALL Delta',
                data: <?php echo $jsCallData; ?>,
                borderColor: '#28a745',
                backgroundColor: 'rgba(40, 167, 69, 0.1)',
                borderWidth: 2,
                tension: 0.2,
                pointRadius: 1
            });
        }
        if (selectedType === 'put' || selectedType === 'both') {
            datasets.push({
                label: 'PUT Delta',
                data: <?php echo $jsPutData; ?>,
                borderColor: '#dc3545',
                backgroundColor: 'rgba(220, 53, 69, 0.1)',
                borderWidth: 2,
                tension: 0.2,
                pointRadius: 1
            });
        }

        const myChart = new Chart(ctx, {
            type: 'line',
            data: { labels: labels, datasets: datasets },
            plugins: [daySeparatorPlugin], // Plugin Register kiya
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    title: { display: true, text: 'Delta Analysis' },
                    zoom: {
                        pan: { enabled: true, mode: 'x' },
                        zoom: { wheel: { enabled: true }, pinch: { enabled: true }, mode: 'x' }
                    }
                },
                scales: {
                    x: { ticks: { maxRotation: 45, minRotation: 45 } },
                    y: { grid: { color: '#f0f0f0', zeroLineColor: '#000' } }
                }
            }
        });
    </script>
</body>
</html>
<?php $conn->close(); ?>