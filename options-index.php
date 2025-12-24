<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

// ==========================================
// 1. DATABASE CONNECTION
// ==========================================
$host = "localhost"; $user = "root"; $pass = "root"; $dbname = "options_m_db_by_fut";
$conn = new mysqli($host, $user, $pass, $dbname);
if ($conn->connect_error) { die("Connection failed: " . $conn->connect_error); }

// ==========================================
// 2. HELPER & INPUTS
// ==========================================
function getYears(mysqli $conn, string $table): array {
    $sql = "SELECT DISTINCT YEAR(trade_date) as y FROM $table ORDER BY y DESC";
    $res = $conn->query($sql);
    $arr = []; if($res) { while($r=$res->fetch_assoc()) $arr[] = $r['y']; }
    return $arr;
}

$type = isset($_GET['type']) ? $_GET['type'] : 'call';
if ($type === 'put') { $tableName = "nifty_options_put_ohlc"; $pageTitle = "Options Put"; } 
else { $tableName = "nifty_options_call_ohlc"; $pageTitle = "Options Call"; $type = 'call'; }

$availYears = getYears($conn, $tableName);
if(empty($availYears)) $availYears = [date('Y')];

// DATE LOGIC
$curYear = date('Y'); $curMonth = date('m');
$fYear = $_GET['f_year'] ?? $curYear; $fMonth = $_GET['f_month'] ?? $curMonth; $fWeek = $_GET['f_week'] ?? ''; 
$tYear = $_GET['t_year'] ?? $curYear; $tMonth = $_GET['t_month'] ?? $curMonth; $tWeek = $_GET['t_week'] ?? ''; 

function calculateDate($year, $month, $week, $isStart) {
    $firstDay = "$year-$month-01"; $lastDay = date("Y-m-t", strtotime($firstDay));
    if($week == '') return $isStart ? $firstDay : $lastDay;
    if ($isStart) {
        $day = 1 + ((int)$week - 1) * 7; if($day > (int)date('d', strtotime($lastDay))) $day = (int)date('d', strtotime($lastDay));
        return "$year-$month-" . str_pad((string)$day, 2, '0', STR_PAD_LEFT);
    } else {
        $day = ((int)$week * 7); $maxDay = (int)date('d', strtotime($lastDay)); if ($day > $maxDay) $day = $maxDay;
        return "$year-$month-" . str_pad((string)$day, 2, '0', STR_PAD_LEFT);
    }
}
$fromDate = calculateDate($fYear, $fMonth, $fWeek, true);
$toDate   = calculateDate($tYear, $tMonth, $tWeek, false);
if($toDate > date('Y-m-d')) $toDate = date('Y-m-d');

// ==========================================
// 3. FETCH METRICS
// ==========================================
function getClose(mysqli $conn, string $tbl, string $dt): float {
    $res = $conn->query("SELECT close_price FROM $tbl WHERE trade_date <= '$dt' ORDER BY trade_date DESC LIMIT 1");
    return ($res && $res->num_rows>0) ? (float)$res->fetch_assoc()['close_price'] : 0.0;
}
$currP = getClose($conn, $tableName, $toDate);

// Metrics
$lwEnd = date('Y-m-d', strtotime('last sunday', strtotime($toDate)));
$ptsRunW = $currP - getClose($conn, $tableName, $lwEnd);
$ptsLastW = getClose($conn, $tableName, $lwEnd) - getClose($conn, $tableName, date('Y-m-d', strtotime('-1 week', strtotime($lwEnd))));

$lmEnd = date('Y-m-d', strtotime('last day of previous month', strtotime($toDate)));
$ptsRunM = $currP - getClose($conn, $tableName, $lmEnd);
$ptsLastM = getClose($conn, $tableName, $lmEnd) - getClose($conn, $tableName, date('Y-m-d', strtotime('last day of previous month', strtotime($lmEnd))));

function getQEnd(string $d): string { $m=date('m',strtotime($d)); $y=date('Y',strtotime($d)); $qm=(int)ceil((int)$m/3)*3; $qd="$y-".str_pad((string)($qm-2),2,'0',STR_PAD_LEFT)."-01"; return date('Y-m-d',strtotime('-1 day',strtotime($qd))); }
$lqEnd = getQEnd($toDate);
$ptsRunQ = $currP - getClose($conn, $tableName, $lqEnd);
$ptsLastQ = getClose($conn, $tableName, $lqEnd) - getClose($conn, $tableName, getQEnd($lqEnd));

// ==========================================
// 4. FETCH CHART DATA
// ==========================================
$dateFormat = 'd M Y'; 

$resDaily = $conn->query("SELECT trade_date, open_price, high_price, low_price, close_price FROM $tableName WHERE trade_date BETWEEN '$fromDate' AND '$toDate' ORDER BY trade_date ASC");
$chartLabels = []; 
$chartPrices = []; 
$chartOHLC = [];   
$tooltips = [];
$lastDayData = null; 

while($row = $resDaily->fetch_assoc()) {
    $ts = strtotime($row['trade_date']);
    $dLabel = date($dateFormat, $ts);
    
    $chartLabels[] = $dLabel;
    $chartPrices[] = (float)$row['close_price'];
    
    $chartOHLC[] = [
        'x' => $dLabel, 
        'o' => (float)$row['open_price'],
        'h' => (float)$row['high_price'],
        'l' => (float)$row['low_price'],
        'c' => (float)$row['close_price']
    ];

    $tooltips[] = date('l, d F Y', $ts);
    $lastDayData = $row;
}

// Rejection
$rejectionInfo = null;
if ($lastDayData) {
    $open = (float)$lastDayData['open_price']; $close = (float)$lastDayData['close_price'];
    $high = (float)$lastDayData['high_price']; $low = (float)$lastDayData['low_price'];
    $upperWick = $high - max($open, $close); $lowerWick = min($open, $close) - $low;
    if ($upperWick > $lowerWick && $upperWick > 0) {
        $rejectionInfo = ['type'=>'top', 'yMin'=>max($open,$close), 'yMax'=>$high, 'label'=>'Rejection @ '.$high];
    } elseif ($lowerWick > $upperWick && $lowerWick > 0) {
        $rejectionInfo = ['type'=>'bottom', 'yMin'=>$low, 'yMax'=>min($open,$close), 'label'=>'Rejection @ '.$low];
    }
}

// ==========================================
// 5. FETCH LEVELS (FILTER INCOMPLETE)
// ==========================================
function fetchLevels(mysqli $conn, string $tbl, string $from, string $to, string $groupByClause, string $labelFormat): array {
    $sql = "SELECT m.trade_date, m.close_price FROM $tbl m 
            INNER JOIN (SELECT MAX(trade_date) as max_date FROM $tbl WHERE trade_date BETWEEN '$from' AND '$to' GROUP BY $groupByClause) max_dates 
            ON m.trade_date = max_dates.max_date ORDER BY m.trade_date ASC";
    $res = $conn->query($sql);
    $levels = [];
    
    $now = time();
    $currWeek = date('oW', $now);
    $currMonth = date('Y-m', $now);
    $currYear = date('Y', $now);
    $currQ = $currYear . '-' . ceil(date('n', $now)/3);
    $currHY = $currYear . '-' . ceil(date('n', $now)/6);
    $curr2Y = floor((int)$currYear/2);

    while($row = $res->fetch_assoc()) {
        $ts = strtotime($row['trade_date']);
        $formattedLabel = '';
        $skip = false;

        if ($labelFormat === 'W') { 
            if (date('oW', $ts) === $currWeek) $skip = true;
            $formattedLabel = date('M', $ts)." W".(intval(date('d',$ts)/7)+1); 
        }
        elseif ($labelFormat === 'M') { 
            if (date('Y-m', $ts) === $currMonth) $skip = true;
            $formattedLabel = date('M', $ts)." Close"; 
        }
        elseif ($labelFormat === 'Q') { 
            $qStr = date('Y', $ts) . '-' . ceil(date('n', $ts)/3);
            if ($qStr === $currQ) $skip = true;
            $formattedLabel = "Q".ceil(date('n',$ts)/3)." FY".date('y',$ts); 
        }
        elseif ($labelFormat === 'HY') { 
            $hyStr = date('Y', $ts) . '-' . ceil(date('n', $ts)/6);
            if ($hyStr === $currHY) $skip = true;
            $formattedLabel = "H".ceil(date('n',$ts)/6)." ".date('Y',$ts); 
        }
        elseif ($labelFormat === 'Y') { 
            if (date('Y', $ts) === $currYear) $skip = true;
            $formattedLabel = "FY ".date('Y',$ts); 
        }
        elseif ($labelFormat === '2Y') { 
            $block2Y = floor((int)date('Y', $ts)/2);
            if ($block2Y == $curr2Y) $skip = true;
            $formattedLabel = "2Y ".date('Y',$ts); 
        }

        if (!$skip) {
            $levels[] = ['price'=>(float)$row['close_price'], 'label'=>$formattedLabel, 'dateLabel'=>date('d M Y', $ts)];
        }
    }
    return $levels;
}

$weeklyLevels = fetchLevels($conn, $tableName, $fromDate, $toDate, "YEARWEEK(trade_date, 1)", 'W');
$monthlyLevels = fetchLevels($conn, $tableName, $fromDate, $toDate, "YEAR(trade_date), MONTH(trade_date)", 'M');
$quarterlyLevels = fetchLevels($conn, $tableName, $fromDate, $toDate, "YEAR(trade_date), QUARTER(trade_date)", 'Q');
$halfYearLevels = fetchLevels($conn, $tableName, $fromDate, $toDate, "YEAR(trade_date), CEIL(MONTH(trade_date)/6)", 'HY');
$yearlyLevels = fetchLevels($conn, $tableName, $fromDate, $toDate, "YEAR(trade_date)", 'Y');
$twoYearLevels = fetchLevels($conn, $tableName, $fromDate, $toDate, "FLOOR(YEAR(trade_date)/2)", '2Y');

$runningDays = [];
if ($toDate >= date('Y-m-d')) {
    $ws = date('Y-m-d', strtotime('monday this week')); $td = date('Y-m-d');
    $resRun = $conn->query("SELECT trade_date, close_price, DAYNAME(trade_date) as dname FROM $tableName WHERE trade_date >= '$ws' AND trade_date <= '$td' ORDER BY trade_date ASC");
    while($row = $resRun->fetch_assoc()) {
        $runningDays[] = ['price'=>(float)$row['close_price'], 'label'=>substr($row['dname'],0,3), 'dateLabel'=>date($dateFormat, strtotime($row['trade_date']))];
    }
}

$labelMap = array_flip($chartLabels);
function prepLine($lvlArr, $map, $count) {
    $data = array_fill(0, $count, null);
    foreach($lvlArr as $l) { if(isset($map[$l['dateLabel']])) $data[$map[$l['dateLabel']]] = $l['price']; }
    return $data;
}
$wkLineData = prepLine($weeklyLevels, $labelMap, count($chartLabels));
$moLineData = prepLine($monthlyLevels, $labelMap, count($chartLabels));
$hyLineData = prepLine($halfYearLevels, $labelMap, count($chartLabels));
$yrLineData = prepLine($yearlyLevels, $labelMap, count($chartLabels));
$tyLineData = prepLine($twoYearLevels, $labelMap, count($chartLabels));

function echoMonthOptions($sel) { for($m=1; $m<=12; $m++) { $v=str_pad((string)$m,2,'0',STR_PAD_LEFT); $n=date("M",mktime(0,0,0,$m,10)); echo "<option value='$v' ".($sel==$v?'selected':'').">$n</option>"; } }
function echoWeekOptions($sel) { echo "<option value='' ".($sel==''?'selected':'').">All</option>"; for($w=1; $w<=5; $w++) echo "<option value='$w' ".($sel==$w?'selected':'').">W$w</option>"; }
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo $pageTitle; ?> Analysis</title>
    <!-- Libraries -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/luxon@3.0.1/build/global/luxon.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-luxon@1.2.0/dist/chartjs-adapter-luxon.min.js"></script>
    <script src="https://www.chartjs.org/chartjs-chart-financial/chartjs-chart-financial.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-annotation@2.0.1/dist/chartjs-plugin-annotation.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-zoom@1.2.1/dist/chartjs-plugin-zoom.min.js"></script>

    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f4f6f9; padding: 20px; color: #333; }
        .switch-container { text-align: center; margin-bottom: 20px; }
        .switch-btn { padding: 8px 20px; margin: 0 5px; background: #fff; color: #555; text-decoration: none; border: 1px solid #ccc; border-radius: 20px; font-weight: bold; }
        .switch-btn.active { background: #2962ff; color: #fff; border-color: #2962ff; }
        
        .metrics-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 10px; margin-bottom: 20px; }
        .m-card { background: #fff; padding: 10px; border-radius: 6px; text-align: center; border-bottom: 3px solid #ccc; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .m-title { font-size: 10px; font-weight: bold; color: #888; text-transform: uppercase; }
        .m-val { font-size: 15px; font-weight: 800; margin: 5px 0; }
        .pos { color: #28a745; } .neg { color: #dc3545; }
        .b-blue { border-color: #007bff; } .b-grey { border-color: #6c757d; }

        .filter-container { background: #fff; padding: 15px; border-radius: 8px; margin-bottom: 15px; display: flex; gap: 15px; align-items: flex-end; box-shadow: 0 2px 5px rgba(0,0,0,0.05); flex-wrap: wrap; }
        .group { display: flex; flex-direction: column; gap: 5px; }
        .row { display: flex; gap: 5px; }
        label { font-size: 11px; font-weight: bold; text-transform: uppercase; color: #555; }
        select { padding: 5px; border: 1px solid #ddd; border-radius: 4px; font-size:13px; }
        button { padding: 6px 15px; background: #2962ff; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; }
        
        .controls-row { width: 100%; display: flex; justify-content: space-between; align-items: center; margin-top: 10px; flex-wrap: wrap; gap: 10px;}
        .toggle-section { display: flex; gap: 8px; align-items: center; background: #f8f9fa; padding: 5px 10px; border-radius: 6px; border: 1px solid #ddd; }
        .section-label { font-size: 11px; font-weight: 800; color: #888; margin-right: 5px; text-transform: uppercase; }
        .check-group { display: flex; align-items: center; gap: 3px; }
        .check-group input { cursor: pointer; }
        .check-group span { font-size: 10px; font-weight: bold; cursor: pointer; }

        .chart-style-btn { padding: 6px 15px; background: #E65100; color: white; border: none; border-radius: 20px; cursor: pointer; font-weight: bold; font-size: 12px; margin-left: 10px; }
        .size-btn { padding: 6px 12px; background: #607D8B; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; font-size: 12px; margin-left: 5px; }
        .size-btn:hover { background: #546E7A; }

        .chart-wrap { 
            background: #fff; 
            padding: 10px; 
            border-radius: 8px; 
            box-shadow: 0 2px 8px rgba(0,0,0,0.05); 
            height: 600px; 
            position: relative; 
            cursor: crosshair;
            overflow: hidden; 
        }

        .legend-info { text-align: center; margin-bottom: 10px; font-size: 11px; }
        .badge { padding: 3px 8px; border-radius: 4px; color: #fff; margin: 0 4px; font-size: 10px; font-weight: bold; display: inline-block;}
    </style>
</head>
<body>

    <div class="switch-container">
        <a href="?type=call" class="switch-btn <?php echo ($type=='call'?'active':''); ?>">CALL</a>
        <a href="?type=put" class="switch-btn <?php echo ($type=='put'?'active':''); ?>">PUT</a>
    </div>

    <!-- Metrics Grid -->
    <div class="metrics-grid">
        <div class="m-card b-blue"><div class="m-title">Run Week</div><div class="m-val <?php echo ($ptsRunW>=0)?'pos':'neg'; ?>"><?php echo number_format($ptsRunW,2); ?></div></div>
        <div class="m-card b-grey"><div class="m-title">Last Week</div><div class="m-val <?php echo ($ptsLastW>=0)?'pos':'neg'; ?>"><?php echo number_format($ptsLastW,2); ?></div></div>
        <div class="m-card b-blue"><div class="m-title">Run Month</div><div class="m-val <?php echo ($ptsRunM>=0)?'pos':'neg'; ?>"><?php echo number_format($ptsRunM,2); ?></div></div>
        <div class="m-card b-grey"><div class="m-title">Last Month</div><div class="m-val <?php echo ($ptsLastM>=0)?'pos':'neg'; ?>"><?php echo number_format($ptsLastM,2); ?></div></div>
        <div class="m-card b-blue"><div class="m-title">Run Quarter</div><div class="m-val <?php echo ($ptsRunQ>=0)?'pos':'neg'; ?>"><?php echo number_format($ptsRunQ,2); ?></div></div>
        <div class="m-card b-grey"><div class="m-title">Last Quarter</div><div class="m-val <?php echo ($ptsLastQ>=0)?'pos':'neg'; ?>"><?php echo number_format($ptsLastQ,2); ?></div></div>
    </div>

    <!-- Filters & Controls -->
    <form class="filter-container" method="GET">
        <input type="hidden" name="type" value="<?php echo $type; ?>">
        <div class="group"><label>From</label><div class="row">
            <select name="f_year"><?php foreach($availYears as $y) echo "<option value='$y' ".($fYear==$y?'selected':'').">$y</option>"; ?></select>
            <select name="f_month"><?php echoMonthOptions($fMonth); ?></select>
            <select name="f_week"><?php echoWeekOptions($fWeek); ?></select>
        </div></div>
        <div class="group"><label>To</label><div class="row">
            <select name="t_year"><?php foreach($availYears as $y) echo "<option value='$y' ".($tYear==$y?'selected':'').">$y</option>"; ?></select>
            <select name="t_month"><?php echoMonthOptions($tMonth); ?></select>
            <select name="t_week"><?php echoWeekOptions($tWeek); ?></select>
        </div></div>
        <button type="submit">Go</button>
        
        <!-- Controls -->
        <button type="button" id="chartStyleBtn" class="chart-style-btn" onclick="toggleChartType()">Switch to Candle</button>
        <button type="button" class="size-btn" onclick="resetHeight()">Reset Height</button>
        <button type="button" class="size-btn" onclick="changeHeight(-100)">-</button>
        <button type="button" class="size-btn" onclick="changeHeight(100)">+</button>

        <div class="controls-row">
            <!-- NEW SHOW VALUES TOGGLE -->
            <div class="toggle-section" style="border-color: #2962ff; background: #e3f2fd;">
                <span class="section-label" style="color: #2962ff;">Toggle:</span>
                <div class="check-group">
                    <input type="checkbox" id="showTextValues" checked onchange="updateDisplay()">
                    <span onclick="document.getElementById('showTextValues').click()">Show Values</span>
                </div>
            </div>

            <div class="toggle-section">
                <span class="section-label">Dots:</span>
                <div class="check-group"><input type="checkbox" id="showWeeks" onchange="updateDisplay()"><span onclick="document.getElementById('showWeeks').click()">Wk</span></div>
                <div class="check-group"><input type="checkbox" id="showMonths" onchange="updateDisplay()"><span onclick="document.getElementById('showMonths').click()">Mo</span></div>
                <div class="check-group"><input type="checkbox" id="showQuarters" onchange="updateDisplay()"><span onclick="document.getElementById('showQuarters').click()">Qt</span></div>
                <div class="check-group"><input type="checkbox" id="showHY" onchange="updateDisplay()"><span onclick="document.getElementById('showHY').click()">6M</span></div>
                <div class="check-group"><input type="checkbox" id="showY" onchange="updateDisplay()"><span onclick="document.getElementById('showY').click()">1Y</span></div>
                <div class="check-group"><input type="checkbox" id="show2Y" onchange="updateDisplay()"><span onclick="document.getElementById('show2Y').click()">2Y</span></div>
            </div>

            <div class="toggle-section">
                <span class="section-label">Lines:</span>
                <div class="check-group"><input type="checkbox" id="lineDaily" onchange="toggleGraph(0, 'lineDaily')"><span onclick="document.getElementById('lineDaily').click()">Day</span></div>
                <div class="check-group"><input type="checkbox" id="lineWeek" onchange="toggleGraph(1, 'lineWeek')"><span onclick="document.getElementById('lineWeek').click()">Wk</span></div>
                <div class="check-group"><input type="checkbox" id="lineMonth" onchange="toggleGraph(2, 'lineMonth')"><span onclick="document.getElementById('lineMonth').click()">Mo</span></div>
                <div class="check-group"><input type="checkbox" id="lineHY" onchange="toggleGraph(3, 'lineHY')"><span onclick="document.getElementById('lineHY').click()">6M</span></div>
                <div class="check-group"><input type="checkbox" id="lineY" onchange="toggleGraph(4, 'lineY')"><span onclick="document.getElementById('lineY').click()">1Y</span></div>
                <div class="check-group"><input type="checkbox" id="line2Y" onchange="toggleGraph(5, 'line2Y')"><span onclick="document.getElementById('line2Y').click()">2Y</span></div>
            </div>
        </div>
    </form>

    <div class="legend-info">
        <span class="badge" style="background: #212121;">2 Year</span>
        <span class="badge" style="background: #1A237E;">1 Year</span>
        <span class="badge" style="background: #E65100;">6 Month</span>
        <span class="badge" style="background: #D32F2F;">Quarter</span>
        <span class="badge" style="background: #8E44AD;">Month</span>
        <span class="badge" style="background: #00897B;">Week</span>
    </div>

    <!-- Chart Container -->
    <div class="chart-wrap" id="chartContainer">
        <canvas id="mainChart"></canvas>
    </div>

    <script>
        // --- 1. HEIGHT CONTROL LOGIC ---
        let currentHeight = parseInt(localStorage.getItem('chartHeight')) || 600;
        const chartContainer = document.getElementById('chartContainer');
        chartContainer.style.height = currentHeight + 'px';

        function changeHeight(amount) {
            currentHeight += amount;
            if (currentHeight < 300) currentHeight = 300; 
            chartContainer.style.height = currentHeight + 'px';
            localStorage.setItem('chartHeight', currentHeight);
            if(myChart) myChart.resize();
        }

        function resetHeight() {
            currentHeight = 600;
            chartContainer.style.height = currentHeight + 'px';
            localStorage.setItem('chartHeight', currentHeight);
            if(myChart) myChart.resize();
        }

        // --- 2. DEFAULTS & STORAGE ---
        const defaults = {
            'showTextValues': true,
            'showWeeks': true, 'showMonths': true, 'showQuarters': true,
            'showHY': false, 'showY': false, 'show2Y': false,
            'lineDaily': true, 'lineWeek': false, 'lineMonth': false,
            'lineHY': false, 'lineY': false, 'line2Y': false
        };

        for (const [id, defVal] of Object.entries(defaults)) {
            const saved = localStorage.getItem(id);
            const el = document.getElementById(id);
            if(saved !== null) { el.checked = (saved === 'true'); } 
            else { el.checked = defVal; }
        }

        let currentChartType = localStorage.getItem('chartType') || 'line';

        const ctx = document.getElementById('mainChart').getContext('2d');
        const labels = <?php echo json_encode($chartLabels); ?>;
        const prices = <?php echo json_encode($chartPrices); ?>;
        const ohlcData = <?php echo json_encode($chartOHLC); ?>;
        
        const wkLineData = <?php echo json_encode($wkLineData); ?>;
        const moLineData = <?php echo json_encode($moLineData); ?>;
        const hyLineData = <?php echo json_encode($hyLineData); ?>;
        const yrLineData = <?php echo json_encode($yrLineData); ?>;
        const tyLineData = <?php echo json_encode($tyLineData); ?>;
        
        const tooltips = <?php echo json_encode($tooltips); ?>;
        const wLevels = <?php echo json_encode($weeklyLevels); ?>;
        const mLevels = <?php echo json_encode($monthlyLevels); ?>;
        const qLevels = <?php echo json_encode($quarterlyLevels); ?>;
        const hLevels = <?php echo json_encode($halfYearLevels); ?>;
        const yLevels = <?php echo json_encode($yearlyLevels); ?>;
        const tyLevels = <?php echo json_encode($twoYearLevels); ?>;
        const dLevels = <?php echo json_encode($runningDays); ?>;
        const rejection = <?php echo json_encode($rejectionInfo); ?>;

        function getIdx(dateStr) { return labels.indexOf(dateStr); }
        const wIndices = new Set(); wLevels.forEach(l => { let i = getIdx(l.dateLabel); if(i!==-1) wIndices.add(i); });
        const mIndices = new Set(); mLevels.forEach(l => { let i = getIdx(l.dateLabel); if(i!==-1) mIndices.add(i); });
        const qIndices = new Set(); qLevels.forEach(l => { let i = getIdx(l.dateLabel); if(i!==-1) qIndices.add(i); });
        const hIndices = new Set(); hLevels.forEach(l => { let i = getIdx(l.dateLabel); if(i!==-1) hIndices.add(i); });
        const yIndices = new Set(); yLevels.forEach(l => { let i = getIdx(l.dateLabel); if(i!==-1) yIndices.add(i); });
        const tyIndices = new Set(); tyLevels.forEach(l => { let i = getIdx(l.dateLabel); if(i!==-1) tyIndices.add(i); });

        const annotations = {};
        function addAnno(levels, prefix, color, yAdj, fSize, isDashed=false) {
            levels.forEach((lvl, i) => {
                let idx = getIdx(lvl.dateLabel);
                if(idx !== -1) {
                    annotations[prefix+'_line_'+i] = { type: 'line', yMin: lvl.price, yMax: lvl.price, borderColor: color, borderWidth: (isDashed?1.5:2), borderDash: (isDashed?[6,4]:[]), z: 1, display: true, label: { display: false } };
                    annotations[prefix+'_lbl_'+i] = { type: 'line', yMin: lvl.price, yMax: lvl.price, xMin: idx, xMax: idx, borderColor: 'transparent', z: 10, display: true, label: { display: true, content: [lvl.label, lvl.price], position: 'center', yAdjust: yAdj, backgroundColor: color, color: '#fff', font: { size: fSize, weight: 'bold' }, borderRadius: 3, padding: 4 } };
                }
            });
        }
        addAnno(wLevels, 'wk', '#00897B', -24, 9, true);
        addAnno(mLevels, 'mo', '#8E44AD', -45, 10);
        addAnno(qLevels, 'qt', '#D32F2F', -65, 11);
        addAnno(hLevels, 'hy', '#E65100', -85, 11);
        addAnno(yLevels, 'yr', '#1A237E', -105, 12);
        addAnno(tyLevels, '2y', '#212121', -125, 12);

        dLevels.forEach((lvl, i) => {
            let idx = getIdx(lvl.dateLabel);
            if(idx !== -1) {
                annotations['rd'+i] = { type: 'line', yMin: lvl.price, yMax: lvl.price, xMin: idx-1, xMax: idx+1, borderColor: 'rgba(0,188,212,0.6)', borderWidth: 1, borderDash: [2,2], z: 1, label: { display: true, content: [lvl.label, lvl.price], position: 'center', xValue: idx, yAdjust: 24, backgroundColor: 'rgba(0,188,212,0.9)', color: '#fff', font: { size: 9 }, borderRadius: 3, padding: 3 } };
            }
        });

        if(rejection) {
            const lastIdx = labels.length - 1;
            const color = rejection.type === 'top' ? 'rgba(233,30,99,0.2)' : 'rgba(76,175,80,0.2)';
            const bColor = rejection.type === 'top' ? '#e91e63' : '#4caf50';
            const yOffset = rejection.type === 'top' ? -25 : 25;
            annotations['rejection_box'] = { type: 'box', xMin: lastIdx - 0.2, xMax: lastIdx + 0.2, yMin: rejection.yMin, yMax: rejection.yMax, backgroundColor: color, borderColor: bColor, borderWidth: 1, z: 0, label: { display: true, content: rejection.label, position: rejection.type === 'top' ? 'start' : 'end', yAdjust: yOffset, backgroundColor: bColor, color: '#fff', font: { size: 9, weight: 'bold' } } };
        }

        const measurePlugin = {
            id: 'measureTool',
            afterDraw: (chart) => {
                if (!chart.measureState || !chart.measureState.active) return;
                const { ctx, scales: { y } } = chart;
                const { startX, startY, currentX, currentY } = chart.measureState;
                const startPrice = y.getValueForPixel(startY);
                const endPrice = y.getValueForPixel(currentY);
                const diff = endPrice - startPrice;
                const percent = ((diff / startPrice) * 100).toFixed(2);
                const pts = diff.toFixed(2);
                const isPositive = diff >= 0;
                const bgColor = isPositive ? 'rgba(40, 167, 69, 0.8)' : 'rgba(220, 53, 69, 0.8)';
                ctx.save(); ctx.beginPath(); ctx.moveTo(startX, startY); ctx.lineTo(currentX, currentY); ctx.lineWidth = 1; ctx.strokeStyle = isPositive?'#28a745':'#dc3545'; ctx.setLineDash([5, 3]); ctx.stroke();
                const text = `${percent}% (${pts} pts)`; ctx.font = 'bold 12px sans-serif';
                const textWidth = ctx.measureText(text).width; const boxW = textWidth + 20, boxH = 25;
                let boxX = currentX + 10, boxY = currentY - 30; if(boxX+boxW > chart.width) boxX = currentX-boxW-10; if(boxY < 0) boxY = currentY + 10;
                ctx.fillStyle = bgColor; ctx.beginPath(); ctx.roundRect(boxX, boxY, boxW, boxH, 5); ctx.fill(); ctx.fillStyle = '#fff'; ctx.fillText(text, boxX + 10, boxY + 17); ctx.restore();
            }
        };

        const mainDatasetConfig = { label: 'Daily Price', order: 1 };
        if (currentChartType === 'candlestick') {
            mainDatasetConfig.type = 'candlestick';
            mainDatasetConfig.data = ohlcData;
            mainDatasetConfig.color = { up: '#00C853', down: '#D50000', unchanged: '#757575' };
            mainDatasetConfig.barPercentage = 0.8; mainDatasetConfig.categoryPercentage = 0.9; mainDatasetConfig.maxBarThickness = 15; 
        } else {
            mainDatasetConfig.type = 'line';
            mainDatasetConfig.data = prices;
            mainDatasetConfig.borderColor = '#2962ff'; mainDatasetConfig.backgroundColor = 'rgba(41,98,255,0.05)';
            mainDatasetConfig.borderWidth = 2; mainDatasetConfig.tension = 0.2; mainDatasetConfig.fill = true; mainDatasetConfig.pointRadius = 2; 
            mainDatasetConfig.pointBackgroundColor = []; mainDatasetConfig.pointBorderColor = [];
        }

        const myChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    mainDatasetConfig,
                    { label: 'Weekly Line', data: wkLineData, borderColor: '#00897B', borderWidth: 2, tension: 0, spanGaps: true, pointRadius: 0, hidden: true, order: 2, type: 'line' },
                    { label: 'Monthly Line', data: moLineData, borderColor: '#8E44AD', borderWidth: 3, tension: 0, spanGaps: true, pointRadius: 0, hidden: true, order: 3, type: 'line' },
                    { label: '6M Line', data: hyLineData, borderColor: '#E65100', borderWidth: 3, tension: 0, spanGaps: true, pointRadius: 0, hidden: true, order: 4, type: 'line' },
                    { label: '1Y Line', data: yrLineData, borderColor: '#1A237E', borderWidth: 3.5, tension: 0, spanGaps: true, pointRadius: 0, hidden: true, order: 5, type: 'line' },
                    { label: '2Y Line', data: tyLineData, borderColor: '#212121', borderWidth: 4, tension: 0, spanGaps: true, pointRadius: 0, hidden: true, order: 6, type: 'line' }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false, 
                interaction: { mode: 'index', intersect: false },
                layout: { padding: { top: 20, bottom: 20, left: 10, right: 10 } },
                plugins: { 
                    legend: { display: false }, 
                    annotation: { annotations: annotations, clip: false }, 
                    tooltip: { callbacks: { title: (c) => tooltips[c[0].dataIndex] }, backgroundColor: 'rgba(0,0,0,0.85)' }, 
                    zoom: { pan: { enabled: true, mode: 'x' }, zoom: { wheel: { enabled: true }, pinch: { enabled: true }, mode: 'x' } } 
                },
                scales: { 
                    x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 15 }, offset: true }, 
                    y: { position: 'right', beginAtZero: false, grace: '3%', grid: { color: '#f0f0f0' } } 
                }
            },
            plugins: [measurePlugin]
        });

        function toggleChartType() {
            currentChartType = (currentChartType === 'line') ? 'candlestick' : 'line';
            localStorage.setItem('chartType', currentChartType);
            document.getElementById('chartStyleBtn').innerText = (currentChartType === 'line') ? "Switch to Candle" : "Switch to Line";

            const ds = myChart.data.datasets[0];
            if (currentChartType === 'candlestick') {
                ds.type = 'candlestick';
                ds.data = ohlcData;
                ds.color = { up: '#00C853', down: '#D50000', unchanged: '#757575' };
                ds.barPercentage = 0.8; ds.categoryPercentage = 0.9; ds.maxBarThickness = 15;
                delete ds.tension; delete ds.fill; delete ds.pointRadius;
            } else {
                ds.type = 'line';
                ds.data = prices;
                ds.borderColor = '#2962ff'; ds.backgroundColor = 'rgba(41,98,255,0.05)';
                ds.borderWidth = 2; ds.tension = 0.2; ds.fill = true; ds.pointRadius = 2;
                ds.pointBackgroundColor = []; ds.pointBorderColor = [];
            }
            myChart.update();
            updateDisplay(); 
        }
        document.getElementById('chartStyleBtn').innerText = (currentChartType === 'line') ? "Switch to Candle" : "Switch to Line";

        function updateDisplay() {
            ['showTextValues','showWeeks','showMonths','showQuarters','showHY','showY','show2Y'].forEach(id => { localStorage.setItem(id, document.getElementById(id).checked); });
            
            const showText = document.getElementById('showTextValues').checked; // Master Toggle for Text
            const sW = document.getElementById('showWeeks').checked;
            const sM = document.getElementById('showMonths').checked;
            const sQ = document.getElementById('showQuarters').checked;
            const sH = document.getElementById('showHY').checked;
            const sY = document.getElementById('showY').checked;
            const s2 = document.getElementById('show2Y').checked;

            const annos = myChart.options.plugins.annotation.annotations;
            for (let key in annos) {
                let categoryActive = false;
                if (key.startsWith('wk_')) categoryActive = sW;
                else if (key.startsWith('mo_')) categoryActive = sM;
                else if (key.startsWith('qt_')) categoryActive = sQ;
                else if (key.startsWith('hy_')) categoryActive = sH;
                else if (key.startsWith('yr_')) categoryActive = sY;
                else if (key.startsWith('2y_')) categoryActive = s2;
                else if (key.startsWith('rd') || key.startsWith('rejection')) categoryActive = true; 

                // MAIN LOGIC: 
                // 1. Line is always visible if category is active.
                // 2. Text (Label) is visible ONLY if category is active AND 'Show Values' is ON.
                if (key.includes('_line_')) {
                    annos[key].display = categoryActive;
                } else if (key.includes('_lbl_')) {
                    // This is the label/dabba
                    annos[key].display = categoryActive && showText;
                } else {
                    // Fallback for daily/rejection
                    if (annos[key].label) {
                        annos[key].label.display = categoryActive && showText;
                    }
                    annos[key].display = categoryActive;
                }
            }

            if (currentChartType === 'line') {
                const ds = myChart.data.datasets[0];
                const pBg = ds.pointBackgroundColor = [];
                const pBd = ds.pointBorderColor = [];
                const pRd = ds.pointRadius = [];

                for(let i=0; i < labels.length; i++) {
                    let color = '#fff', bColor = '#2962ff', radius = 2;
                    // Dots should still show if category is active, even if text is hidden
                    if (tyIndices.has(i) && s2) { color = '#212121'; bColor = '#212121'; radius = 10; }
                    else if (yIndices.has(i) && sY) { color = '#1A237E'; bColor = '#1A237E'; radius = 9; }
                    else if (hIndices.has(i) && sH) { color = '#E65100'; bColor = '#E65100'; radius = 8; }
                    else if (qIndices.has(i) && sQ) { color = '#D32F2F'; bColor = '#D32F2F'; radius = 8; }
                    else if (mIndices.has(i) && sM) { color = '#8E44AD'; bColor = '#8E44AD'; radius = 6; }
                    else if (wIndices.has(i) && sW) { color = '#00897B'; bColor = '#00897B'; radius = 5; }
                    
                    pBg[i] = color; pBd[i] = bColor; pRd[i] = radius;
                }
            }
            myChart.update();
        }

        function toggleGraph(idx, id) {
            const el = document.getElementById(id);
            localStorage.setItem(id, el.checked);
            myChart.setDatasetVisibility(idx, el.checked);
            myChart.update();
        }

        function initGraphVisibility() {
            const lineMap = [{idx:0,id:'lineDaily'},{idx:1,id:'lineWeek'},{idx:2,id:'lineMonth'},{idx:3,id:'lineHY'},{idx:4,id:'lineY'},{idx:5,id:'line2Y'}];
            lineMap.forEach(item => {
                const isChecked = document.getElementById(item.id).checked;
                myChart.setDatasetVisibility(item.idx, isChecked);
            });
            updateDisplay();
        }
        initGraphVisibility();

        const cvs = document.getElementById('mainChart');
        cvs.addEventListener('mousedown', (e) => { const r = cvs.getBoundingClientRect(); myChart.measureState = { active: true, startX: e.clientX-r.left, startY: e.clientY-r.top, currentX: e.clientX-r.left, currentY: e.clientY-r.top }; });
        cvs.addEventListener('mousemove', (e) => { if (myChart.measureState && myChart.measureState.active) { const r = cvs.getBoundingClientRect(); myChart.measureState.currentX = e.clientX-r.left; myChart.measureState.currentY = e.clientY-r.top; myChart.draw(); } });
        cvs.addEventListener('mouseup', () => { if (myChart.measureState) { myChart.measureState.active = false; myChart.draw(); } });
        cvs.addEventListener('mouseleave', () => { if (myChart.measureState) { myChart.measureState.active = false; myChart.draw(); } });
    </script>
</body>
</html>
<?php $conn->close(); ?>