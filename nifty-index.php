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

$type = $_GET['type'] ?? 'index';
if ($type === 'futures') { $tableName = "nifty_futures_ohlc"; $pageTitle = "NIFTY FUTURES"; } 
else { $tableName = "nifty_index_ohlc"; $pageTitle = "NIFTY INDEX"; $type = 'index'; }

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
    $res = $conn->query("SELECT close_price FROM $tbl WHERE trade_date <= '$dt' AND close_price > 0 ORDER BY trade_date DESC LIMIT 1");
    return ($res && $res->num_rows>0) ? (float)$res->fetch_assoc()['close_price'] : 0.0;
}
$currP = getClose($conn, $tableName, $toDate);

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
    if ((float)$row['close_price'] <= 0) continue;

    $ts = strtotime($row['trade_date']);
    $dLabel = date($dateFormat, $ts);
    
    $chartLabels[] = $dLabel;
    $chartPrices[] = (float)$row['close_price'];
    
    // For Candle (Time Axis)
    $chartOHLC[] = [
        'x' => $ts * 1000, 
        'o' => (float)$row['open_price'],
        'h' => (float)$row['high_price'],
        'l' => (float)$row['low_price'],
        'c' => (float)$row['close_price']
    ];

    $tooltips[] = date('l, d F Y', $ts);
    $lastDayData = $row;
}

// Rejection Logic
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
// 5. FETCH LEVELS & LINES
// ==========================================
function fetchExpiryLevels(mysqli $conn, string $ohlcTable, string $expiryTable, string $fromDate, string $toDate, string $filterMode): array {
    $sql = "SELECT e.expiry_date, o.close_price 
            FROM $expiryTable e
            INNER JOIN $ohlcTable o ON o.trade_date = e.expiry_date
            WHERE e.expiry_date BETWEEN '$fromDate' AND '$toDate' AND o.close_price > 0
            ORDER BY e.expiry_date ASC";

    $res = $conn->query($sql);
    $levels = [];
    while($row = $res->fetch_assoc()) {
        $ts = strtotime($row['expiry_date']);
        $m = (int)date('n', $ts); $y = (int)date('Y', $ts);
        $add = false; $lbl = "";
        
        // Formatted Text Logic
        if ($filterMode === 'WEEKLY') { $add=true; $w=(int)ceil((int)date('d',$ts)/7); $lbl="W / ".date('M',$ts)."-".$w; }
        elseif ($filterMode === 'MONTHLY') { $add=true; $lbl="M / ".date('M',$ts)." (W4)"; }
        elseif ($filterMode === 'QUARTERLY' && $m%3==0) { $add=true; $lbl="Q".ceil($m/3)." / ".date('M y',$ts); }
        elseif ($filterMode === 'HY' && $m%6==0) { $add=true; $lbl="H".ceil($m/6)." / ".$y; }
        elseif ($filterMode === 'YEARLY' && $m==12) { $add=true; $lbl="FY / ".$y; }
        elseif ($filterMode === '2YEARLY' && $m==12 && $y%2==0) { $add=true; $lbl="2Y / ".$y; }

        if($add) {
            $levels[] = [
                'price'=>(float)$row['close_price'], 
                'label'=>$lbl, 
                'dateLabel'=>date('d M Y', $ts),
                'ts' => $ts * 1000 
            ];
        }
    }
    return $levels;
}

$weeklyLevels = fetchExpiryLevels($conn, $tableName, 'nifty_weekly_expiry_dates', $fromDate, $toDate, 'WEEKLY');
$monthlyLevels = fetchExpiryLevels($conn, $tableName, 'nifty_expiry_dates', $fromDate, $toDate, 'MONTHLY');
$quarterlyLevels = fetchExpiryLevels($conn, $tableName, 'nifty_expiry_dates', $fromDate, $toDate, 'QUARTERLY');
$halfYearLevels = fetchExpiryLevels($conn, $tableName, 'nifty_expiry_dates', $fromDate, $toDate, 'HY');
$yearlyLevels = fetchExpiryLevels($conn, $tableName, 'nifty_expiry_dates', $fromDate, $toDate, 'YEARLY');
$twoYearLevels = fetchExpiryLevels($conn, $tableName, 'nifty_expiry_dates', $fromDate, $toDate, '2YEARLY');

// Line Data for LINE CHART
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
        body { font-family: 'Segoe UI', sans-serif; background: #0e1217; padding: 20px; color: #ccc; }
        .switch-container { text-align: center; margin-bottom: 20px; }
        .switch-btn { padding: 8px 20px; margin: 0 5px; background: #1c2128; color: #fff; text-decoration: none; border: 1px solid #333; border-radius: 20px; font-weight: bold; }
        .switch-btn.active { background: #2962ff; border-color: #2962ff; }
        
        .metrics-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 10px; margin-bottom: 20px; }
        .m-card { background: #1c2128; padding: 10px; border-radius: 6px; text-align: center; border-bottom: 3px solid #333; }
        .m-title { font-size: 10px; font-weight: bold; color: #888; text-transform: uppercase; }
        .m-val { font-size: 15px; font-weight: 800; margin: 5px 0; color: #fff; }
        .pos { color: #00c853; } .neg { color: #ff1744; }

        .filter-container { background: #1c2128; padding: 15px; border-radius: 8px; margin-bottom: 15px; display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap; }
        .group { display: flex; flex-direction: column; gap: 5px; }
        .row { display: flex; gap: 5px; }
        label { font-size: 11px; font-weight: bold; text-transform: uppercase; color: #888; }
        select { padding: 5px; background: #0e1217; color: #fff; border: 1px solid #333; border-radius: 4px; font-size:13px; }
        button { padding: 6px 15px; background: #2962ff; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; }
        
        .controls-row { width: 100%; display: flex; justify-content: space-between; align-items: center; margin-top: 10px; flex-wrap: wrap; gap: 10px;}
        .toggle-section { display: flex; gap: 8px; align-items: center; background: #2d333b; padding: 5px 10px; border-radius: 6px; border: 1px solid #444; }
        .section-label { font-size: 11px; font-weight: 800; color: #bbb; margin-right: 5px; text-transform: uppercase; }
        .check-group { display: flex; align-items: center; gap: 3px; }
        .check-group input { cursor: pointer; }
        .check-group span { font-size: 10px; font-weight: bold; cursor: pointer; color: #ccc; }

        .chart-style-btn { padding: 6px 15px; background: #E65100; color: white; border: none; border-radius: 20px; cursor: pointer; font-weight: bold; font-size: 12px; margin-left: 10px; }
        .size-btn { padding: 6px 12px; background: #546E7A; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; font-size: 12px; margin-left: 5px; }
        
        .chart-wrap { 
            background: #13171f; 
            padding: 10px; 
            border-radius: 8px; 
            box-shadow: 0 4px 12px rgba(0,0,0,0.3); 
            height: 600px; 
            position: relative; 
            overflow: hidden; 
            cursor: crosshair;
        }

        .legend-info { text-align: center; margin-bottom: 10px; font-size: 11px; }
        .badge { padding: 3px 8px; border-radius: 4px; color: #fff; margin: 0 4px; font-size: 10px; font-weight: bold; display: inline-block;}
    </style>
</head>
<body>

    <div class="switch-container">
        <a href="?type=index" class="switch-btn <?php echo ($type=='index'?'active':''); ?>">INDEX</a>
        <a href="?type=futures" class="switch-btn <?php echo ($type=='futures'?'active':''); ?>">FUTURES</a>
    </div>

    <!-- Metrics -->
    <div class="metrics-grid">
        <div class="m-card"><div class="m-title">Run Week</div><div class="m-val <?php echo ($ptsRunW>=0)?'pos':'neg'; ?>"><?php echo number_format($ptsRunW,2); ?></div></div>
        <div class="m-card"><div class="m-title">Last Week</div><div class="m-val <?php echo ($ptsLastW>=0)?'pos':'neg'; ?>"><?php echo number_format($ptsLastW,2); ?></div></div>
        <div class="m-card"><div class="m-title">Run Month</div><div class="m-val <?php echo ($ptsRunM>=0)?'pos':'neg'; ?>"><?php echo number_format($ptsRunM,2); ?></div></div>
        <div class="m-card"><div class="m-title">Last Month</div><div class="m-val <?php echo ($ptsLastM>=0)?'pos':'neg'; ?>"><?php echo number_format($ptsLastM,2); ?></div></div>
        <div class="m-card"><div class="m-title">Run Quarter</div><div class="m-val <?php echo ($ptsRunQ>=0)?'pos':'neg'; ?>"><?php echo number_format($ptsRunQ,2); ?></div></div>
        <div class="m-card"><div class="m-title">Last Quarter</div><div class="m-val <?php echo ($ptsLastQ>=0)?'pos':'neg'; ?>"><?php echo number_format($ptsLastQ,2); ?></div></div>
    </div>

    <!-- Controls -->
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
        
        <button type="button" id="chartStyleBtn" class="chart-style-btn" onclick="toggleChartType()">Switch to Candle</button>
        <button type="button" class="size-btn" onclick="resetHeight()">Reset Height</button>
        <button type="button" class="size-btn" onclick="changeHeight(-100)">-</button>
        <button type="button" class="size-btn" onclick="changeHeight(100)">+</button>

        <div class="controls-row">
            <div class="toggle-section" style="border-color: #2962ff; background: #131b2e;">
                <span class="section-label" style="color: #2962ff;">Toggle:</span>
                <div class="check-group">
                    <input type="checkbox" id="showTextValues" checked onchange="updateAll()">
                    <span onclick="document.getElementById('showTextValues').click()">Show Labels</span>
                </div>
            </div>
            <div class="toggle-section">
                <span class="section-label">Levels:</span>
                <div class="check-group"><input type="checkbox" id="showWeeks" onchange="updateAll()"><span onclick="document.getElementById('showWeeks').click()">Wk</span></div>
                <div class="check-group"><input type="checkbox" id="showMonths" onchange="updateAll()"><span onclick="document.getElementById('showMonths').click()">Mo</span></div>
                <div class="check-group"><input type="checkbox" id="showQuarters" onchange="updateAll()"><span onclick="document.getElementById('showQuarters').click()">Qt</span></div>
                <div class="check-group"><input type="checkbox" id="showHY" onchange="updateAll()"><span onclick="document.getElementById('showHY').click()">6M</span></div>
                <div class="check-group"><input type="checkbox" id="showY" onchange="updateAll()"><span onclick="document.getElementById('showY').click()">1Y</span></div>
                <div class="check-group"><input type="checkbox" id="show2Y" onchange="updateAll()"><span onclick="document.getElementById('show2Y').click()">2Y</span></div>
            </div>
            <div class="toggle-section">
                <span class="section-label">Connect:</span>
                <div class="check-group"><input type="checkbox" id="lineDaily" onchange="updateAll()"><span onclick="document.getElementById('lineDaily').click()">Day</span></div>
                <div class="check-group"><input type="checkbox" id="lineWeek" onchange="updateAll()"><span onclick="document.getElementById('lineWeek').click()">Wk</span></div>
                <div class="check-group"><input type="checkbox" id="lineMonth" onchange="updateAll()"><span onclick="document.getElementById('lineMonth').click()">Mo</span></div>
                <div class="check-group"><input type="checkbox" id="lineHY" onchange="updateAll()"><span onclick="document.getElementById('lineHY').click()">6M</span></div>
                <div class="check-group"><input type="checkbox" id="lineY" onchange="updateAll()"><span onclick="document.getElementById('lineY').click()">1Y</span></div>
                <div class="check-group"><input type="checkbox" id="line2Y" onchange="updateAll()"><span onclick="document.getElementById('line2Y').click()">2Y</span></div>
            </div>
        </div>
    </form>

    <div class="legend-info">
        <span class="badge" style="background: #212121;">2 Year (24M)</span>
        <span class="badge" style="background: #1A237E;">1 Year (12M)</span>
        <span class="badge" style="background: #C2185B;">6 Month (6M)</span>
        <span class="badge" style="background: #D32F2F;">Quarter (3M)</span>
        <span class="badge" style="background: #8E44AD;">Month (1M)</span>
        <span class="badge" style="background: #00897B;">Week (1W)</span>
    </div>

    <!-- Chart Containers -->
    <div class="chart-wrap" id="chartContainer">
        <div id="lineChartContainer" style="display:block; height:100%; width:100%;">
            <canvas id="lineChartCanvas"></canvas>
        </div>
        <div id="candleChartContainer" style="display:none; height:100%; width:100%;">
            <canvas id="candleChartCanvas"></canvas>
        </div>
    </div>

    <script>
        // --- 1. DATA PREP ---
        const labels = <?php echo json_encode($chartLabels); ?>;
        const prices = <?php echo json_encode($chartPrices); ?>;
        const ohlcData = <?php echo json_encode($chartOHLC); ?>; 
        const tooltips = <?php echo json_encode($tooltips); ?>;

        const wkLineData = <?php echo json_encode($wkLineData); ?>;
        const moLineData = <?php echo json_encode($moLineData); ?>;
        const hyLineData = <?php echo json_encode($hyLineData); ?>;
        const yrLineData = <?php echo json_encode($yrLineData); ?>;
        const tyLineData = <?php echo json_encode($tyLineData); ?>;

        const wLevels = <?php echo json_encode($weeklyLevels); ?>;
        const mLevels = <?php echo json_encode($monthlyLevels); ?>;
        const qLevels = <?php echo json_encode($quarterlyLevels); ?>;
        const hLevels = <?php echo json_encode($halfYearLevels); ?>;
        const yLevels = <?php echo json_encode($yearlyLevels); ?>;
        const tyLevels = <?php echo json_encode($twoYearLevels); ?>;
        const rejection = <?php echo json_encode($rejectionInfo); ?>;

        function prepCandleLine(lvls) { return lvls.map(l => ({ x: l.ts, y: l.price })); }
        const wkCandleData = prepCandleLine(wLevels);
        const moCandleData = prepCandleLine(mLevels);
        const hyCandleData = prepCandleLine(hLevels);
        const yrCandleData = prepCandleLine(yLevels);
        const tyCandleData = prepCandleLine(tyLevels);

        function getIdx(dateStr) { return labels.indexOf(dateStr); }

        // --- 2. RESTORE DEFAULTS & MODE ---
        let currentMode = localStorage.getItem('chartMode') || 'line';
        const defaults = {'showTextValues':true, 'showWeeks':true, 'showMonths':true, 'lineDaily':true};
        for(let k in defaults){
            const el=document.getElementById(k);
            if(el) el.checked = (localStorage.getItem(k)!==null) ? (localStorage.getItem(k)==='true') : defaults[k];
        }

        if(currentMode === 'candle') {
            document.getElementById('lineChartContainer').style.display = 'none';
            document.getElementById('candleChartContainer').style.display = 'block';
            document.getElementById('chartStyleBtn').innerText = "Switch to Line";
        }

        // --- 3. PLUGIN: RIGHT AXIS LABEL WITH COMBINED TEXT ---
        const rightAxisLabelPlugin = {
            id: 'rightAxisLabels',
            afterDraw: (chart) => {
                const ctx = chart.ctx;
                const yAxis = chart.scales.y;
                const chartArea = chart.chartArea;
                
                const drawBadge = (levels, color, toggleId) => {
                    const el = document.getElementById(toggleId);
                    if (!el || !el.checked) return;

                    levels.forEach(lvl => {
                        const y = yAxis.getPixelForValue(lvl.price);
                        if (y < chartArea.top || y > chartArea.bottom) return;

                        // COMBINED TEXT format: "Price (Label)"
                        const combinedText = `${lvl.price.toFixed(2)} (${lvl.label})`;

                        const padding = 6;
                        ctx.font = 'bold 11px sans-serif';
                        const textWidth = ctx.measureText(combinedText).width;
                        const boxWidth = textWidth + (padding * 2);
                        const boxHeight = 20;
                        
                        const x = chartArea.right; 
                        
                        // Draw Rect
                        ctx.fillStyle = color;
                        ctx.fillRect(x, y - (boxHeight/2), boxWidth, boxHeight);
                        
                        // Draw Text
                        ctx.fillStyle = '#fff';
                        ctx.textAlign = 'left';
                        ctx.textBaseline = 'middle';
                        ctx.fillText(combinedText, x + padding, y);
                    });
                };
                
                drawBadge(wLevels, '#00897B', 'showWeeks'); 
                drawBadge(mLevels, '#8E44AD', 'showMonths');
                drawBadge(qLevels, '#D32F2F', 'showQuarters'); 
                drawBadge(hLevels, '#C2185B', 'showHY');
                drawBadge(yLevels, '#1A237E', 'showY'); 
                drawBadge(tyLevels, '#212121', 'show2Y');
            }
        };

        // --- 4. MEASURE TOOL ---
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
                
                const boxBg = 'rgba(255, 255, 255, 0.9)';
                const lineColor = (diff >= 0) ? '#00c853' : '#ff1744';

                ctx.save(); ctx.beginPath(); ctx.moveTo(startX, startY); ctx.lineTo(currentX, currentY); 
                ctx.lineWidth = 1; ctx.strokeStyle = lineColor; ctx.setLineDash([5, 3]); ctx.stroke();
                
                const text = `${percent}% (${diff.toFixed(2)})`; 
                ctx.font = 'bold 12px sans-serif'; ctx.textBaseline = 'middle'; ctx.textAlign = 'left';
                const textW = ctx.measureText(text).width; const boxW = textW + 20;
                let boxX = currentX + 10, boxY = currentY - 30; 
                if(boxX+boxW > chart.width) boxX = currentX-boxW-10; if(boxY < 0) boxY = currentY+10;
                
                ctx.fillStyle = boxBg; ctx.beginPath(); ctx.roundRect(boxX, boxY, boxW, 26, 4); ctx.fill(); 
                ctx.fillStyle = '#000'; ctx.fillText(text, boxX + 10, boxY + 13); 
                ctx.restore();
            }
        };

        // --- 5. CHARTS ---
        let lineChart = null, candleChart = null;

        function initLineChart() {
            const ctx = document.getElementById('lineChartCanvas').getContext('2d');
            lineChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [
                        { label: 'Price', data: prices, borderColor: '#2962ff', backgroundColor: 'rgba(41,98,255,0.05)', borderWidth: 2, fill: true, tension: 0.2, pointRadius: 2, order: 1 },
                        { label: 'Weekly', data: wkLineData, borderColor: '#00897B', borderWidth: 1.5, tension: 0, spanGaps: true, pointRadius: 0, hidden: true, order: 2, borderDash: [4, 4] },
                        { label: 'Monthly', data: moLineData, borderColor: '#8E44AD', borderWidth: 2, tension: 0, spanGaps: true, pointRadius: 0, hidden: true, order: 3 },
                        { label: '6M', data: hyLineData, borderColor: '#C2185B', borderWidth: 2, tension: 0, spanGaps: true, pointRadius: 0, hidden: true, order: 4 },
                        { label: '1Y', data: yrLineData, borderColor: '#1A237E', borderWidth: 2.5, tension: 0, spanGaps: true, pointRadius: 0, hidden: true, order: 5 },
                        { label: '2Y', data: tyLineData, borderColor: '#212121', borderWidth: 3, tension: 0, spanGaps: true, pointRadius: 0, hidden: true, order: 6 }
                    ]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    // Increased Right Padding for the text box
                    layout: { padding: { top: 20, bottom: 20, left: 10, right: 120 } },
                    plugins: { legend: { display: false }, annotation: { annotations: {} }, tooltip: { callbacks: { title: (c) => tooltips[c[0].dataIndex] } } },
                    scales: { 
                        x: { display: true, grid: { color: '#2d333b' } }, 
                        y: { position: 'right', beginAtZero: false, grace: '5%', grid: { color: '#2d333b' } } 
                    }
                },
                plugins: [rightAxisLabelPlugin, measurePlugin]
            });
        }

        function initCandleChart() {
            const ctx = document.getElementById('candleChartCanvas').getContext('2d');
            candleChart = new Chart(ctx, {
                type: 'candlestick',
                data: {
                    datasets: [
                        { label: 'Price', data: ohlcData, color: { up: '#00C853', down: '#ff1744', unchanged: '#757575' }, barPercentage: 0.8, categoryPercentage: 0.9, order: 1 },
                        { type: 'line', label: 'Weekly', data: wkCandleData, borderColor: '#00897B', borderWidth: 1.5, tension: 0, spanGaps: true, pointRadius: 0, hidden: true, order: 2, borderDash: [4, 4] },
                        { type: 'line', label: 'Monthly', data: moCandleData, borderColor: '#8E44AD', borderWidth: 2, tension: 0, spanGaps: true, pointRadius: 0, hidden: true, order: 3 },
                        { type: 'line', label: '6M', data: hyCandleData, borderColor: '#C2185B', borderWidth: 2, tension: 0, spanGaps: true, pointRadius: 0, hidden: true, order: 4 },
                        { type: 'line', label: '1Y', data: yrCandleData, borderColor: '#1A237E', borderWidth: 2.5, tension: 0, spanGaps: true, pointRadius: 0, hidden: true, order: 5 },
                        { type: 'line', label: '2Y', data: tyCandleData, borderColor: '#212121', borderWidth: 3, tension: 0, spanGaps: true, pointRadius: 0, hidden: true, order: 6 }
                    ]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    layout: { padding: { top: 20, bottom: 20, left: 10, right: 120 } },
                    scales: {
                        x: { type: 'time', time: { unit: 'day', displayFormats: { day: 'd MMM' } }, ticks: { source: 'auto', maxTicksLimit: 15 }, grid: { color: '#2d333b' }, offset: true },
                        y: { position: 'right', beginAtZero: false, grace: '5%', grid: { color: '#2d333b' } }
                    },
                    plugins: { legend: { display: false }, annotation: { annotations: {} } }
                },
                plugins: [rightAxisLabelPlugin, measurePlugin]
            });
        }

        // --- 6. UPDATE ---
        function updateAll() {
            // Save state
            ['showTextValues','showWeeks','showMonths','showQuarters','showHY','showY','show2Y',
             'lineDaily','lineWeek','lineMonth','lineHY','lineY','line2Y'].forEach(id => { 
                const el = document.getElementById(id); if(el) localStorage.setItem(id, el.checked); 
            });

            // Line Chart Update
            if(lineChart) {
                lineChart.setDatasetVisibility(0, document.getElementById('lineDaily').checked);
                lineChart.setDatasetVisibility(1, document.getElementById('lineWeek').checked);
                lineChart.setDatasetVisibility(2, document.getElementById('lineMonth').checked);
                lineChart.setDatasetVisibility(3, document.getElementById('lineHY').checked);
                lineChart.setDatasetVisibility(4, document.getElementById('lineY').checked);
                lineChart.setDatasetVisibility(5, document.getElementById('line2Y').checked);

                const annos = {};
                const addAnno = (arr, prefix, color, toggleId) => {
                    if(!document.getElementById(toggleId).checked) return;
                    arr.forEach((l, i) => {
                        let idx = getIdx(l.dateLabel);
                        if(idx !== -1) {
                            annos[prefix+'_l_'+i] = { type: 'line', yMin: l.price, yMax: l.price, borderColor: color, borderWidth: 1 };
                        }
                    });
                };
                addAnno(wLevels, 'wk', '#00897B', 'showWeeks');
                addAnno(mLevels, 'mo', '#8E44AD', 'showMonths');
                addAnno(qLevels, 'qt', '#D32F2F', 'showQuarters');
                addAnno(hLevels, 'hy', '#C2185B', 'showHY');
                addAnno(yLevels, 'yr', '#1A237E', 'showY');
                addAnno(tyLevels, '2y', '#212121', 'show2Y');
                
                lineChart.options.plugins.annotation.annotations = annos;
                lineChart.update();
            }

            // Candle Chart Update
            if(candleChart) {
                candleChart.setDatasetVisibility(0, document.getElementById('lineDaily').checked);
                candleChart.setDatasetVisibility(1, document.getElementById('lineWeek').checked);
                candleChart.setDatasetVisibility(2, document.getElementById('lineMonth').checked);
                candleChart.setDatasetVisibility(3, document.getElementById('lineHY').checked);
                candleChart.setDatasetVisibility(4, document.getElementById('lineY').checked);
                candleChart.setDatasetVisibility(5, document.getElementById('line2Y').checked);

                const cAnnos = {};
                const addCAnno = (arr, prefix, color, toggleId) => {
                    if(!document.getElementById(toggleId).checked) return;
                    arr.forEach((l, i) => {
                        cAnnos[prefix+'_line_'+i] = { type: 'line', yMin: l.price, yMax: l.price, borderColor: color, borderWidth: 1 };
                    });
                };
                addCAnno(wLevels, 'cwk', '#00897B', 'showWeeks');
                addCAnno(mLevels, 'cmo', '#8E44AD', 'showMonths');
                addCAnno(qLevels, 'cqt', '#D32F2F', 'showQuarters');
                addCAnno(hLevels, 'chy', '#C2185B', 'showHY');
                addCAnno(yLevels, 'cyr', '#1A237E', 'showY');
                addCAnno(tyLevels, 'c2y', '#212121', 'show2Y');
                
                candleChart.options.plugins.annotation.annotations = cAnnos;
                candleChart.update();
            }
        }

        // --- 7. TOGGLE MODE ---
        function toggleChartType() {
            const lDiv = document.getElementById('lineChartContainer');
            const cDiv = document.getElementById('candleChartContainer');
            const btn = document.getElementById('chartStyleBtn');

            if(currentMode === 'line') {
                currentMode = 'candle';
                lDiv.style.display = 'none';
                cDiv.style.display = 'block';
                btn.innerText = "Switch to Line";
                candleChart.resize();
            } else {
                currentMode = 'line';
                cDiv.style.display = 'none';
                lDiv.style.display = 'block';
                btn.innerText = "Switch to Candle";
                lineChart.resize();
            }
            localStorage.setItem('chartMode', currentMode);
        }

        // --- 8. HEIGHT ---
        let currentHeight = parseInt(localStorage.getItem('chartHeight')) || 600;
        const mainWrap = document.getElementById('chartContainer');
        mainWrap.style.height = currentHeight + 'px';
        function changeHeight(amt) {
            currentHeight += amt; if(currentHeight < 300) currentHeight = 300;
            mainWrap.style.height = currentHeight + 'px';
            localStorage.setItem('chartHeight', currentHeight);
            if(lineChart) lineChart.resize();
            if(candleChart) candleChart.resize();
        }
        function resetHeight() {
            currentHeight = 600;
            mainWrap.style.height = currentHeight + 'px';
            localStorage.setItem('chartHeight', currentHeight);
            if(lineChart) lineChart.resize();
            if(candleChart) candleChart.resize();
        }

        // --- 9. MEASURE EVENTS ---
        function attachMeasureEvents(canvasId, getChart) {
            const cvs = document.getElementById(canvasId);
            cvs.addEventListener('mousedown', (e) => { 
                const c = getChart(); if(!c) return;
                const r = cvs.getBoundingClientRect(); 
                c.measureState = { active: true, startX: e.clientX-r.left, startY: e.clientY-r.top, currentX: e.clientX-r.left, currentY: e.clientY-r.top }; 
            });
            cvs.addEventListener('mousemove', (e) => { 
                const c = getChart(); if(!c || !c.measureState || !c.measureState.active) return;
                const r = cvs.getBoundingClientRect(); 
                c.measureState.currentX = e.clientX-r.left; c.measureState.currentY = e.clientY-r.top; 
                c.draw(); 
            });
            cvs.addEventListener('mouseup', () => { const c = getChart(); if(c && c.measureState) { c.measureState.active = false; c.draw(); } });
            cvs.addEventListener('mouseleave', () => { const c = getChart(); if(c && c.measureState) { c.measureState.active = false; c.draw(); } });
        }

        // Init
        initLineChart();
        initCandleChart();
        attachMeasureEvents('lineChartCanvas', () => lineChart);
        attachMeasureEvents('candleChartCanvas', () => candleChart);
        updateAll();

    </script>
</body>
</html>
<?php $conn->close(); ?>