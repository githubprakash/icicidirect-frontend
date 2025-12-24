<?php
// ==========================================
// 1. DATABASE CONNECTION
// ==========================================
$host = "localhost"; $user = "root"; $pass = "root"; $dbname = "options_m_db_by_fut";
$conn = new mysqli($host, $user, $pass, $dbname);
if ($conn->connect_error) { die("Connection failed: " . $conn->connect_error); }

// ==========================================
// 2. GET FILTER INPUTS
// ==========================================
$defaultDate = date('Y-m-d');
$fromDate = isset($_GET['from_date']) ? $_GET['from_date'] : $defaultDate;
$toDate   = isset($_GET['to_date']) ? $_GET['to_date'] : $defaultDate;
$filterInterval = isset($_GET['interval']) ? $_GET['interval'] : '15';
$filterBasket = isset($_GET['basket']) ? $_GET['basket'] : []; 

// ==========================================
// 3. FETCH BASKET NAMES
// ==========================================
$basketOptions = [];
$sqlBaskets = "SELECT DISTINCT basket_name FROM basket_tracker_log ORDER BY basket_name ASC";
$resBaskets = $conn->query($sqlBaskets);
if ($resBaskets && $resBaskets->num_rows > 0) {
    while($row = $resBaskets->fetch_assoc()) {
        $basketOptions[] = $row['basket_name'];
    }
}

// ==========================================
// 4. BUILD QUERY
// ==========================================
$sql = "SELECT * FROM basket_tracker_log WHERE 1=1";

if (!empty($fromDate) && !empty($toDate)) {
    $sql .= " AND DATE(log_time) BETWEEN '$fromDate' AND '$toDate'";
}

if (!empty($filterBasket)) {
    $safeNames = array_map(function($n) use ($conn) { return "'" . $conn->real_escape_string($n) . "'"; }, $filterBasket);
    $inQuery = implode(',', $safeNames);
    $sql .= " AND basket_name IN ($inQuery)";
}

if ($filterInterval !== '15') {
    if ($filterInterval === 'DAILY') {
        $sql .= " AND HOUR(log_time) = 15 AND MINUTE(log_time) >= 15";
    } else {
        $mins = intval($filterInterval);
        $sql .= " AND MOD((HOUR(log_time) * 60 + MINUTE(log_time)) - 555, $mins) = 0";
    }
}

$sql .= " ORDER BY log_time ASC"; 

$result = $conn->query($sql);

// ==========================================
// 5. PROCESS DATA
// ==========================================
$tableData = []; 
$chartLabels = []; 
$chartDatasets = []; 

if ($result && $result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $rawTime = strtotime($row['log_time']);
        $dateKey = date("d M Y (l)", $rawTime);
        $timeKey = date("h:i A", $rawTime);
        $chartLabel = date("d M H:i", $rawTime); 

        $cleanName = str_replace('Basket Tracker - ', '', $row['basket_name']);
        $cleanName = explode('(', $cleanName)[0];
        $cleanName = trim($cleanName);
        $row['display_name'] = $cleanName;

        // Table Grouping
        $tableData[$dateKey][$timeKey][] = $row;

        // Chart Grouping
        if (!in_array($chartLabel, $chartLabels)) {
            $chartLabels[] = $chartLabel;
        }
        $chartDatasets[$cleanName][$chartLabel] = floatval($row['pnl_percent']);
    }
}

// Format Chart Data
$finalDatasets = [];
$colors = ['#0f62fe', '#da1e28', '#24a148', '#f1c21b', '#8a3ffc', '#ff832b', '#0043ce', '#fa4d56'];
$colorIdx = 0;

foreach ($chartDatasets as $bName => $dataPoints) {
    $dataSeries = [];
    foreach ($chartLabels as $lbl) {
        $dataSeries[] = isset($dataPoints[$lbl]) ? $dataPoints[$lbl] : null;
    }
    $finalDatasets[] = [
        'label' => $bName,
        'data' => $dataSeries,
        'borderColor' => $colors[$colorIdx % count($colors)],
        'backgroundColor' => $colors[$colorIdx % count($colors)],
        'borderWidth' => 2,
        'tension' => 0.3,
        'pointRadius' => 2,
        'fill' => false
    ];
    $colorIdx++;
}

// Reverse Table Data (Newest First)
$tableData = array_reverse($tableData);
foreach ($tableData as $k => $v) { $tableData[$k] = array_reverse($v); }
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Basket Analytics Pro</title>
    
    <!-- Chart.js & Annotation Plugin -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-annotation@3.0.1/dist/chartjs-plugin-annotation.min.js"></script>

    <style>
        body { font-family: 'Segoe UI', Tahoma, sans-serif; background-color: #f4f7f6; margin: 0; padding: 20px; color:#333; }
        .container { max-width: 1200px; margin: 0 auto; }

        /* Filter Bar */
        .filter-bar { background: #fff; padding: 20px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap; margin-bottom: 20px; border: 1px solid #e1e4e8; }
        .form-group { display: flex; flex-direction: column; gap: 5px; position: relative; }
        .form-group label { font-size: 11px; font-weight: 700; color: #666; text-transform: uppercase; }
        input[type="date"], select { padding: 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 14px; background: #fafafa; }
        button.btn-apply { padding: 10px 25px; background: #0f62fe; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; }
        
        /* Dropdown Check List */
        .dropdown-check-list { display: inline-block; position: relative; min-width: 200px; }
        .dropdown-check-list .anchor { position: relative; cursor: pointer; display: block; padding: 10px; border: 1px solid #ccc; border-radius: 6px; background: #fafafa; font-size: 14px; }
        .dropdown-check-list .anchor:after { content: ""; border-left: 2px solid black; border-bottom: 2px solid black; width: 5px; height: 5px; transform: rotate(-45deg); position: absolute; right: 10px; top: 12px; }
        .dropdown-check-list ul.items { padding: 5px; display: none; margin: 0; border: 1px solid #ccc; border-top: none; position: absolute; top: 100%; left: 0; right: 0; background: #fff; max-height: 200px; overflow-y: auto; z-index: 100; border-radius: 0 0 6px 6px; box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
        .dropdown-check-list ul.items li { list-style: none; padding: 5px; border-bottom: 1px solid #f0f0f0; }
        .dropdown-check-list.visible ul.items { display: block; }

        /* Chart Section */
        .chart-wrapper { background: #fff; padding: 20px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); margin-bottom: 40px; border: 1px solid #e1e4e8; }
        .chart-toolbar { display: flex; gap: 10px; margin-bottom: 10px; border-bottom: 1px solid #eee; padding-bottom: 10px; align-items: center; flex-wrap: wrap; }
        .tool-btn { padding: 6px 12px; border: 1px solid #ccc; background: #fff; cursor: pointer; border-radius: 4px; font-size: 13px; font-weight: 600; display: flex; align-items: center; gap: 5px; transition: all 0.2s; }
        .tool-btn:hover { background: #f0f0f0; }
        .tool-btn.active { background: #e8f0fe; border-color: #0f62fe; color: #0f62fe; box-shadow: inset 0 0 5px rgba(0,0,0,0.1); }
        .tool-eraser.active { background: #ffebeb; border-color: #da1e28; color: #da1e28; }
        .chart-box { height: 450px; position: relative; }

        /* Grid Section */
        .date-section { margin-bottom: 30px; border-top: 2px dashed #e0e0e0; padding-top: 15px; }
        .date-header { background: #333; color: #fff; padding: 5px 15px; border-radius: 20px; font-size: 14px; font-weight: 600; display: inline-block; margin-bottom: 10px; }
        .time-row { display: flex; margin-bottom: 10px; }
        .time-label { min-width: 80px; font-weight: 700; color: #555; margin-top: 8px; font-size: 13px; border-right: 2px solid #ddd; margin-right: 15px; }
        .card-grid { display: flex; flex-wrap: wrap; gap: 10px; flex-grow: 1; }
        .pnl-card { background: #fff; padding: 10px; border-radius: 8px; width: 150px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); border: 1px solid #eee; }
        .pnl-value { font-size: 16px; font-weight: 800; }
        .profit { color: #16a34a; border-left: 4px solid #16a34a; }
        .loss { color: #dc2626; border-left: 4px solid #dc2626; }
        .neutral { color: #555; border-left: 4px solid #999; }
        .no-data { text-align: center; padding: 30px; color: #888; background: #fff; border-radius: 8px; }
    </style>
</head>
<body>

<div class="container">
    
    <!-- FILTER BAR -->
    <form class="filter-bar" method="GET" action="">
        <div class="form-group"><label>From</label><input type="date" name="from_date" value="<?php echo $fromDate; ?>"></div>
        <div class="form-group"><label>To</label><input type="date" name="to_date" value="<?php echo $toDate; ?>"></div>
        <div class="form-group"><label>Baskets</label>
            <div id="list1" class="dropdown-check-list" tabindex="100">
                <span class="anchor" onclick="document.getElementById('list1').classList.toggle('visible')">Select Baskets</span>
                <ul class="items">
                    <?php foreach($basketOptions as $bName): 
                        $display = str_replace('Basket Tracker - ', '', $bName); $display = explode('(', $display)[0];
                        $checked = in_array($bName, $filterBasket) ? 'checked' : '';
                    ?>
                    <li><label><input type="checkbox" name="basket[]" value="<?php echo htmlspecialchars($bName); ?>" <?php echo $checked; ?> /> <?php echo htmlspecialchars($display); ?></label></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <div class="form-group"><label>Interval</label>
            <select name="interval">
                <option value="15" <?php echo ($filterInterval=='15')?'selected':''; ?>>15m</option>
                <option value="30" <?php echo ($filterInterval=='30')?'selected':''; ?>>30m</option>
                <option value="60" <?php echo ($filterInterval=='60')?'selected':''; ?>>60m</option>
                <option value="75" <?php echo ($filterInterval=='75')?'selected':''; ?>>75m</option>
                <option value="DAILY" <?php echo ($filterInterval=='DAILY')?'selected':''; ?>>Daily</option>
            </select>
        </div>
        <div class="form-group"><button type="submit" class="btn-apply">Show</button></div>
        <div class="form-group"><a href="dashboard.php" style="font-size:12px; text-decoration:none; margin-top:10px; color:#666;">Reset</a></div>
    </form>

    <?php if (empty($tableData)): ?>
        <div class="no-data">No data found.</div>
    <?php else: ?>

        <!-- CHART AREA -->
        <div class="chart-wrapper">
            <div class="chart-toolbar">
                <div style="font-weight:bold; font-size:12px; color:#666; margin-right:10px;">TOOLS:</div>
                <button class="tool-btn" onclick="setDrawMode('h')" id="btnH">➖ Horz. Line</button>
                <button class="tool-btn" onclick="setDrawMode('t')" id="btnT">📈 Trendline</button>
                <button class="tool-btn" onclick="setDrawMode('s')" id="btnS">📐 Scale</button>
                <button class="tool-btn" onclick="setDrawMode('p')" id="btnP">✏️ Pencil</button>
                
                <div style="width:1px; height:20px; background:#ddd; margin:0 5px;"></div>
                
                <button class="tool-btn tool-eraser" onclick="setDrawMode('e')" id="btnE">🧽 Eraser (Delete One)</button>
                <button class="tool-btn" onclick="clearAll()" style="margin-left:auto; color:#da1e28; border-color:#da1e28;">🗑️ Clear All</button>
            </div>
            <div class="chart-box">
                <canvas id="pnlChart"></canvas>
            </div>
            <div id="statusMsg" style="font-size:12px; color:#666; margin-top:5px; height:15px; padding-left:5px;"></div>
        </div>

        <!-- GRID AREA -->
        <?php foreach ($tableData as $date => $times): ?>
            <div class="date-section">
                <div class="date-header"><?php echo $date; ?></div>
                <?php foreach ($times as $time => $baskets): ?>
                    <div class="time-row">
                        <div class="time-label"><?php echo $time; ?></div>
                        <div class="card-grid">
                            <?php foreach ($baskets as $item): 
                                $val = floatval($item['pnl_percent']);
                                $class = ($val > 0) ? 'profit' : (($val < 0) ? 'loss' : 'neutral');
                                $sign = ($val > 0) ? '+' : '';
                            ?>
                                <div class="pnl-card <?php echo $class; ?>">
                                    <h4><?php echo htmlspecialchars($item['display_name']); ?></h4>
                                    <div class="pnl-value"><?php echo $sign . number_format($val, 2); ?>%</div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>

    <?php endif; ?>

</div>

<script>
    // --- UI HELPERS ---
    function updateText() {
        const count = document.querySelectorAll('input[type="checkbox"]:checked').length;
        document.querySelector('.anchor').innerText = count > 0 ? count + " Baskets Selected" : "Select Baskets";
    }
    updateText();
    document.querySelectorAll('input[type="checkbox"]').forEach(cb => cb.addEventListener('change', updateText));
    
    document.addEventListener('click', function(e) {
        var list = document.getElementById('list1');
        if (!list.contains(e.target) && list.classList.contains('visible')) list.classList.remove('visible');
    });

    // --- CHART LOGIC ---
    <?php if (!empty($finalDatasets)): ?>
    
    // CUSTOM PLUGIN FOR FREEHAND DRAWING
    const freehandDrawings = []; // Array of arrays [{x, y}, {x, y}...]
    
    const pencilPlugin = {
        id: 'pencilPlugin',
        afterDatasetsDraw: (chart) => {
            const ctx = chart.ctx;
            ctx.save();
            ctx.beginPath();
            ctx.lineWidth = 2;
            ctx.strokeStyle = '#000000';
            ctx.lineJoin = 'round';
            ctx.lineCap = 'round';

            freehandDrawings.forEach(path => {
                if(path.length < 2) return;
                const startX = chart.scales.x.getPixelForValue(path[0].x);
                const startY = chart.scales.y.getPixelForValue(path[0].y);
                
                ctx.moveTo(startX, startY);
                for(let i=1; i<path.length; i++){
                    const px = chart.scales.x.getPixelForValue(path[i].x);
                    const py = chart.scales.y.getPixelForValue(path[i].y);
                    ctx.lineTo(px, py);
                }
            });
            ctx.stroke();
            ctx.restore();
        }
    };
    
    Chart.register(pencilPlugin);

    const initialAnnotations = {
        zeroLine: { type: 'line', yMin: 0, yMax: 0, borderColor: 'rgba(0,0,0,0.8)', borderWidth: 1.5, borderDash: [5, 5], label: {display:false} }
    };
    
    let annotations = JSON.parse(JSON.stringify(initialAnnotations));
    
    let drawMode = null; 
    let annCounter = 1;
    let trendPointA = null; 
    
    let dragTarget = null; 
    let isDragging = false;
    let dragStartPos = null; 
    
    let isDrawingPencil = false;
    let currentPencilPath = [];

    const ctx = document.getElementById('pnlChart').getContext('2d');
    const statusDiv = document.getElementById('statusMsg');
    const canvas = document.getElementById('pnlChart');

    const pnlChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($chartLabels); ?>,
            datasets: <?php echo json_encode($finalDatasets); ?>
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                annotation: { annotations: annotations },
                tooltip: { enabled: true }
            },
            onClick: (e) => {
                // Ignore creations if we are in Eraser mode or Pencil mode
                if(drawMode === 'e' || drawMode === 'p' || isDragging) return;

                const chart = e.chart;
                const yVal = chart.scales.y.getValueForPixel(e.y);
                const xVal = chart.scales.x.getValueForPixel(e.x); 
                
                // CREATE HORIZONTAL
                if (drawMode === 'h') {
                    const id = 'line_' + (annCounter++);
                    annotations[id] = {
                        type: 'line', yMin: yVal, yMax: yVal,
                        borderColor: '#e91e63', borderWidth: 2,
                        label: { display: true, content: yVal.toFixed(2) + '%', position: 'start', backgroundColor: '#e91e63', color: '#fff' },
                        _subType: 'h'
                    };
                    updateChart();
                    setDrawMode(null); 
                } 
                // CREATE TRENDLINE
                else if (drawMode === 't') {
                    if (trendPointA === null) {
                        trendPointA = { x: xVal, y: yVal };
                        statusDiv.innerText = "Trendline: Click end point...";
                    } else {
                        const id = 'trend_' + (annCounter++);
                        annotations[id] = {
                            type: 'line',
                            xMin: trendPointA.x, xMax: xVal,
                            yMin: trendPointA.y, yMax: yVal,
                            borderColor: '#0043ce', borderWidth: 3,
                            pointRadius: 6, // Handle size
                            label: { display: false },
                            _subType: 't'
                        };
                        updateChart();
                        trendPointA = null;
                        setDrawMode(null);
                        statusDiv.innerText = "";
                    }
                }
            },
            scales: {
                y: { grid: { color: '#e0e0e0' } },
                x: { grid: { display: false } }
            }
        }
    });

    function updateChart() {
        pnlChart.options.plugins.annotation.annotations = annotations;
        pnlChart.update('none');
    }

    // --- HIT DETECTION UTILS ---
    function getDistance(x1, y1, x2, y2) { return Math.sqrt(Math.pow(x2-x1, 2) + Math.pow(y2-y1, 2)); }

    // Unified function to detect hit based on PIXELS
    function getHitAnnotation(px, py) {
        const tol = 10; 

        for (const [id, ann] of Object.entries(annotations)) {
            if (id === 'zeroLine') continue;
            
            // BOX (Scale)
            if(ann.type === 'box') {
                const x1 = pnlChart.scales.x.getPixelForValue(ann.xMin);
                const x2 = pnlChart.scales.x.getPixelForValue(ann.xMax);
                const y1 = pnlChart.scales.y.getPixelForValue(ann.yMin);
                const y2 = pnlChart.scales.y.getPixelForValue(ann.yMax);
                
                if(px >= Math.min(x1,x2)-tol && px <= Math.max(x1,x2)+tol && 
                   py >= Math.min(y1,y2)-tol && py <= Math.max(y1,y2)+tol) {
                       return { id, type: 's' };
                   }
                continue;
            }

            // LINES
            const y1 = pnlChart.scales.y.getPixelForValue(ann.yMin);
            const y2 = pnlChart.scales.y.getPixelForValue(ann.yMax);
            
            if(ann._subType === 'h') {
                if(Math.abs(py - y1) < tol) return { id, type: 'h', handle: 'body' };
            }
            
            if(ann._subType === 't') {
                const x1 = pnlChart.scales.x.getPixelForValue(ann.xMin);
                const x2 = pnlChart.scales.x.getPixelForValue(ann.xMax);

                if (getDistance(px, py, x1, y1) < tol) return { id, type: 't', handle: 'start' };
                if (getDistance(px, py, x2, y2) < tol) return { id, type: 't', handle: 'end' };

                const A = px - x1; const B = py - y1;
                const C = x2 - x1; const D = y2 - y1;
                const dot = A * C + B * D;
                const len_sq = C * C + D * D;
                let param = -1;
                if (len_sq != 0) param = dot / len_sq;
                let xx, yy;
                if (param < 0) { xx = x1; yy = y1; }
                else if (param > 1) { xx = x2; yy = y2; }
                else { xx = x1 + param * C; yy = y1 + param * D; }
                if (getDistance(px, py, xx, yy) < tol) return { id, type: 't', handle: 'body' };
            }
        }
        return null;
    }

    function getHitPencil(px, py) {
        const tol = 10;
        for(let i=0; i<freehandDrawings.length; i++) {
            const path = freehandDrawings[i];
            for(let pt of path) {
                const sx = pnlChart.scales.x.getPixelForValue(pt.x);
                const sy = pnlChart.scales.y.getPixelForValue(pt.y);
                if(getDistance(px, py, sx, sy) < tol) return i;
            }
        }
        return -1;
    }

    // --- MOUSE INTERACTIONS ---

    // 1. CLICK FOR ERASER (Reliable)
    canvas.addEventListener('click', (e) => {
        if(drawMode !== 'e') return;

        const rect = canvas.getBoundingClientRect();
        const px = e.clientX - rect.left;
        const py = e.clientY - rect.top;

        // Check Annotation
        const hitAnn = getHitAnnotation(px, py);
        if(hitAnn) {
            delete annotations[hitAnn.id];
            updateChart();
            return;
        }

        // Check Pencil
        const hitPen = getHitPencil(px, py);
        if(hitPen !== -1) {
            freehandDrawings.splice(hitPen, 1);
            pnlChart.update();
            return;
        }
    });

    // 2. MOUSE DOWN (Drag, Draw, Scale)
    canvas.addEventListener('mousedown', (e) => {
        const rect = canvas.getBoundingClientRect();
        const px = e.clientX - rect.left;
        const py = e.clientY - rect.top;

        // PENCIL START
        if(drawMode === 'p') {
            isDrawingPencil = true;
            currentPencilPath = [];
            currentPencilPath.push({
                x: pnlChart.scales.x.getValueForPixel(px),
                y: pnlChart.scales.y.getValueForPixel(py)
            });
            freehandDrawings.push(currentPencilPath);
            pnlChart.options.plugins.tooltip.enabled = false;
            return;
        }

        // SCALE START
        if(drawMode === 's') { 
            dragStartPos = {
                x: pnlChart.scales.x.getValueForPixel(px),
                y: pnlChart.scales.y.getValueForPixel(py)
            };
            isDragging = true;
            dragTarget = { type: 's' };
            const id = 'scale_' + (annCounter++);
            annotations[id] = {
                type: 'box', xMin: dragStartPos.x, xMax: dragStartPos.x, yMin: dragStartPos.y, yMax: dragStartPos.y,
                backgroundColor: 'rgba(255, 193, 7, 0.2)', borderColor: '#ffc107', borderWidth: 2,
                label: { display: true, content: "Measuring..." }, _subType: 's'
            };
            dragTarget.id = id;
            updateChart();
            return;
        }

        // DRAG EXISTING (Only if not eraser)
        if(drawMode !== 'e' && !drawMode) {
            const hit = getHitAnnotation(px, py);
            if(hit) {
                isDragging = true;
                dragTarget = hit;
                dragStartPos = {
                    x: pnlChart.scales.x.getValueForPixel(px),
                    y: pnlChart.scales.y.getValueForPixel(py)
                };
                pnlChart.options.plugins.tooltip.enabled = false;
            }
        }
    });

    // 3. MOUSE MOVE (Draw, Drag, Cursor)
    canvas.addEventListener('mousemove', (e) => {
        const rect = canvas.getBoundingClientRect();
        const px = e.clientX - rect.left;
        const py = e.clientY - rect.top;
        const currValX = pnlChart.scales.x.getValueForPixel(px);
        const currValY = pnlChart.scales.y.getValueForPixel(py);

        // PENCIL DRAWING
        if(isDrawingPencil && currentPencilPath) {
            currentPencilPath.push({x: currValX, y: currValY});
            pnlChart.update('none');
            return;
        }

        // CURSOR LOGIC
        if (!isDragging && !drawMode) {
            const hit = getHitAnnotation(px, py);
            if(hit) canvas.style.cursor = hit.handle === 'body' ? 'move' : 'crosshair';
            else canvas.style.cursor = 'default';
            return;
        }
        
        // ERASER CURSOR
        if (drawMode === 'e') {
            const hit = getHitAnnotation(px, py) || (getHitPencil(px, py) !== -1);
            canvas.style.cursor = hit ? 'pointer' : 'not-allowed';
            return;
        }

        // DRAGGING ACTIONS
        if (isDragging && dragTarget) {
            const ann = annotations[dragTarget.id];

            if (dragTarget.type === 's') { // Scale
                ann.xMax = currValX; ann.yMax = currValY;
                const diffY = (currValY - dragStartPos.y);
                const diffX = Math.abs(currValX - dragStartPos.x);
                ann.label.content = [`Change: ${(diffY>0?'+':'')+diffY.toFixed(2)}%`, `Duration: ${Math.round(diffX)} Bars`];
                if(diffY > 0) { ann.backgroundColor = 'rgba(36, 161, 72, 0.2)'; ann.borderColor = '#24a148'; }
                else { ann.backgroundColor = 'rgba(218, 30, 40, 0.2)'; ann.borderColor = '#da1e28'; }
            }
            else if (dragTarget.type === 'h') { // Horz Line
                ann.yMin = currValY; ann.yMax = currValY;
                ann.label.content = currValY.toFixed(2) + '%';
            }
            else if (dragTarget.type === 't') { // Trendline
                if (dragTarget.handle === 'start') { ann.xMin = Math.round(currValX); ann.yMin = currValY; } 
                else if (dragTarget.handle === 'end') { ann.xMax = Math.round(currValX); ann.yMax = currValY; } 
                else if (dragTarget.handle === 'body') {
                    const dx = currValX - dragStartPos.x;
                    const dy = currValY - dragStartPos.y;
                    ann.xMin += dx; ann.xMax += dx;
                    ann.yMin += dy; ann.yMax += dy;
                    dragStartPos = { x: currValX, y: currValY };
                }
            }
            updateChart();
        }
    });

    // 4. MOUSE UP
    canvas.addEventListener('mouseup', () => {
        isDrawingPencil = false;
        currentPencilPath = null;
        
        if(isDragging) {
            isDragging = false;
            dragTarget = null;
            dragStartPos = null;
            pnlChart.options.plugins.tooltip.enabled = true; 
            if(drawMode === 's') setDrawMode(null);
        }
        if(!drawMode) pnlChart.options.plugins.tooltip.enabled = true;
    });

    // --- TOOLBAR ---
    function setDrawMode(mode) {
        drawMode = mode;
        trendPointA = null; 
        document.querySelectorAll('.tool-btn').forEach(b => b.classList.remove('active'));
        statusDiv.innerText = "";
        
        if(mode === 'h') document.getElementById('btnH').classList.add('active');
        if(mode === 't') {
            document.getElementById('btnT').classList.add('active');
            statusDiv.innerText = "Trendline: Click start, then click end.";
        }
        if(mode === 's') {
            document.getElementById('btnS').classList.add('active');
            statusDiv.innerText = "Scale: Drag to measure";
        }
        if(mode === 'p') {
            document.getElementById('btnP').classList.add('active');
            statusDiv.innerText = "Pencil: Draw freehand";
        }
        if(mode === 'e') {
            document.getElementById('btnE').classList.add('active');
            statusDiv.innerText = "Eraser: CLICK any line/drawing to delete it.";
            canvas.style.cursor = 'not-allowed';
            return;
        }

        canvas.style.cursor = mode ? 'crosshair' : 'default';
    }

    function clearAll() {
        if(confirm("Delete EVERYTHING (Lines + Drawings)?")) {
            annotations = JSON.parse(JSON.stringify(initialAnnotations));
            freehandDrawings.length = 0; // Clear array
            updateChart();
            statusDiv.innerText = "All cleared.";
        }
    }
    <?php endif; ?>
</script>

</body>
</html>
<?php $conn->close(); ?>