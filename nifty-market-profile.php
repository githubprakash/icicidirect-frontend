<?php
/**
 * NIFTY ANALYSIS MASTER PRO - v2.0 (THE ULTIMATE VERSION)
 * EVERYTHING RESTORED: Filters, View Switcher, 2x VWAP, 4x POC, AI Report, Scale
 */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

// 1. DATABASE CONNECTION
$host = "localhost"; $user = "root"; $pass = "root"; $dbname = "options_m_db_by_fut";
$conn = new mysqli($host, $user, $pass, $dbname);
if ($conn->connect_error) { die("Connection failed: " . $conn->connect_error); }

// 2. FILTERS & VIEW MODE
$fMonth = $_GET['f_month'] ?? date('m'); $fYear = $_GET['f_year'] ?? date('Y');
$tMonth = $_GET['t_month'] ?? date('m'); $tYear = $_GET['t_year'] ?? date('Y');
$targetDate = $_GET['target_date'] ?? ''; 
$viewMode = $_GET['view_mode'] ?? 'daily';

$fromDate = "$fYear-$fMonth-01 00:00:00";
$toDate   = date("Y-m-t 23:59:59", strtotime("$tYear-$tMonth-01"));

// 3. FETCH 30M DATA
$tableName = "nifty_futures_30m_ohlc"; 
$sql = "SELECT trade_date, open_price, high_price, low_price, close_price, volume 
        FROM $tableName WHERE trade_date BETWEEN '$fromDate' AND '$toDate' ORDER BY trade_date ASC";
$res = $conn->query($sql);

$dailyAgg = []; $weeklyAgg = []; $wVData = []; $mVData = []; $wsPocData = [];
$quarters = []; $months = []; $weeks = []; $days = [];

$wSumPV = 0; $wSumV = 0; $currW = ""; 
$mSumPV = 0; $mSumV = 0; $currM = "";
$runWMaxV = 0; $runWPOC = 0;

while($row = $res->fetch_assoc()) {
    $ts = strtotime($row['trade_date']); $tsMS = $ts * 1000;
    $dayK = date('Y-m-d', $ts); $wkK = date('Y-W', $ts); $mkK = date('Y-m', $ts);
    $o = (float)$row['open_price']; $h = (float)$row['high_price'];
    $l = (float)$row['low_price']; $c = (float)$row['close_price'];
    $v = (float)($row['volume'] ?? 0);

    // Resets for W & M Intervals
    if($wkK != $currW) { $runWMaxV = 0; $runWPOC = 0; $wSumPV = 0; $wSumV = 0; $currW = $wkK; }
    if($mkK != $currM) { $mSumPV = 0; $mSumV = 0; $currM = $mkK; }
    
    // Aggregations
    if(!isset($dailyAgg[$dayK])) $dailyAgg[$dayK] = ['x'=>strtotime($dayK.' 09:15:00')*1000,'o'=>$o,'h'=>$h,'l'=>$l,'c'=>$c];
    else { $dailyAgg[$dayK]['h']=max($dailyAgg[$dayK]['h'],$h); $dailyAgg[$dayK]['l']=min($dailyAgg[$dayK]['l'],$l); $dailyAgg[$dayK]['c']=$c; }

    if(!isset($weeklyAgg[$wkK])) $weeklyAgg[$wkK] = ['x'=>$tsMS,'o'=>$o,'h'=>$h,'l'=>$l,'c'=>$c];
    else { $weeklyAgg[$wkK]['h']=max($weeklyAgg[$wkK]['h'],$h); $weeklyAgg[$wkK]['l']=min($weeklyAgg[$wkK]['l'],$l); $weeklyAgg[$wkK]['c']=$c; }

    // VWAP & Shift POC
    $tp = ($h + $l + $c) / 3; $pv = $tp * $v;
    $wSumPV += $pv; $wSumV += $v; $mSumPV += $pv; $mSumV += $v;
    $wVData[] = ['x' => $tsMS, 'y' => ($wSumV>0?$wSumPV/$wSumV:$c), 'day' => $dayK];
    $mVData[] = ['x' => $tsMS, 'y' => ($mSumV>0?$mSumPV/$mSumV:$c), 'day' => $dayK];
    
    if($v > $runWMaxV) { $runWMaxV = $v; $runWPOC = $c; }
    $wsPocData[] = ['x' => $tsMS, 'y' => $runWPOC, 'day' => $dayK];

    // Hierarchy Structure
    $wNum = ceil((int)date('d', $ts) / 7); $mNum = ((int)date('m', $ts)-1)%3+1;
    $pArr = [
        'q'=>[&$quarters, "Q".ceil((int)date('m',$ts)/3)."-".date('Y',$ts), "Q".ceil((int)date('m',$ts)/3)],
        'm'=>[&$months, $mkK, "M$mNum"],
        'w'=>[&$weeks, $wkK, "W$wNum"],
        'd'=>[&$days, $dayK, date('D', $ts)]
    ];
    foreach($pArr as $type => $pd) {
        $a = &$pd[0]; $k = $pd[1];
        if(!isset($a[$k])) { $a[$k] = ['s'=>$tsMS,'e'=>$tsMS,'h'=>$h,'l'=>$l,'poc'=>$c,'maxV'=>$v,'label'=>$pd[2]]; }
        else { $a[$k]['e']=$tsMS; $a[$k]['h']=max($a[$k]['h'],$h); $a[$k]['l']=min($a[$k]['l'],$l); if($v > $a[$k]['maxV']){ $a[$k]['maxV']=$v; $a[$k]['poc']=$c; } }
    }
}

// Current View Data
$ohlcFinal = ($viewMode === 'weekly') ? array_values($weeklyAgg) : array_values($dailyAgg);

// AI Focus Logic
$focusK = (!empty($targetDate) && isset($days[$targetDate])) ? $targetDate : (array_key_last($days) ?: '');
$ai = ['date' => $focusK, 'price' => $dailyAgg[$focusK]['c'] ?? 0, 'dpoc' => $days[$focusK]['poc'] ?? 0, 'wpoc' => 0, 'pwpoc' => 0, 'vwap' => 0, 'wsPoc' => 0];
if(!empty($focusK)) {
    $fWk = date('Y-W', strtotime($focusK));
    $ai['wpoc'] = $weeks[$fWk]['poc'] ?? 0;
    $allW = array_keys($weeks); $idx = array_search($fWk, $allW);
    if($idx > 0) $ai['pwpoc'] = $weeks[$allW[$idx-1]]['poc'];
    foreach(array_reverse($wVData) as $vd) { if($vd['day'] == $focusK) { $ai['vwap'] = $vd['y']; break; } }
    foreach(array_reverse($wsPocData) as $pd) { if($pd['day'] == $focusK) { $ai['wsPoc'] = $pd['y']; break; } }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Nifty Analysis Elite v2.0</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/luxon@3.0.1/build/global/luxon.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-luxon@1.2.0/dist/chartjs-adapter-luxon.min.js"></script>
    <script src="https://www.chartjs.org/chartjs-chart-financial/chartjs-chart-financial.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-annotation@2.0.1/dist/chartjs-plugin-annotation.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-zoom@1.2.1/dist/chartjs-plugin-zoom.min.js"></script>
    <style>
        :root { --bg: #0b0e11; --nav: #1e222d; --accent: #2962ff; --text: #d1d4dc; }
        body { background: var(--bg); color: var(--text); font-family: 'Segoe UI', sans-serif; margin:0; padding:10px; overflow:hidden; }
        .nav { background: var(--nav); padding: 8px 12px; border-radius: 6px; display: flex; justify-content: space-between; border: 1px solid #2a2e39; margin-bottom: 5px; align-items: center;}
        .filter-section { display: flex; gap: 8px; align-items: center; }
        select, button, input { background: #131722; color: #b2b5be; border: 1px solid #363a45; padding: 5px 10px; border-radius: 4px; cursor: pointer; font-size: 11px; font-weight: bold;}
        button:hover { background: var(--accent); color: #fff; }
        .active-btn { background: var(--accent) !important; color: white !important; }
        .chart-wrap { background: #000; border-radius: 4px; height: 72vh; border: 1px solid #2a2e39; position: relative; overflow: hidden; cursor: crosshair; }
        
        #aiPanel { background: var(--nav); border: 1px solid var(--accent); border-radius: 6px; padding: 12px; margin-top: 5px; display: none; }
        .ai-grid { display: grid; grid-template-columns: 1fr 1fr 1.5fr; gap: 15px; }
        .ai-card { border-left: 3px solid #363a45; padding-left: 12px; }
        .ai-card h4 { margin: 0; font-size: 10px; color: var(--accent); text-transform: uppercase; }
        .ai-card p { margin: 4px 0 0 0; font-size: 12px; font-weight: bold; color: #fff; line-height: 1.4; }
        
        #scaleBox { position: absolute; background: rgba(41, 98, 255, 0.1); border: 1px solid var(--accent); pointer-events: none; display: none; z-index: 100; }
        #scalePoint { position: absolute; width: 10px; height: 10px; background: #ff9800; border-radius: 50%; display: none; transform: translate(-50%, -50%); border: 2px solid #fff; z-index: 101; }
        #scaleLabel { position: absolute; background: var(--accent); color: white; padding: 3px 8px; border-radius: 4px; font-size: 11px; display: none; z-index: 102; white-space: nowrap; }
    </style>
</head>
<body>

<div class="nav">
    <form method="GET" style="display:flex; gap:10px; align-items:center;">
        <input type="hidden" name="view_mode" value="<?= $viewMode ?>">
        <div class="filter-section">
            <select name="f_month"><?php for($m=1;$m<=12;$m++){ $v=str_pad((string)$m,2,'0',STR_PAD_LEFT); echo "<option value='$v' ".($fMonth==$v?'selected':'').">".date('M',mktime(0,0,0,$m,10))."</option>"; } ?></select>
            <select name="f_year"><?php foreach(['2024','2025'] as $yr) echo "<option value='$yr' ".($fYear==$yr?'selected':'').">$yr</option>"; ?></select>
            <span style="color:#5d606b; font-size:10px;">to</span>
            <select name="t_month"><?php for($m=1;$m<=12;$m++){ $v=str_pad((string)$m,2,'0',STR_PAD_LEFT); echo "<option value='$v' ".($tMonth==$v?'selected':'').">".date('M',mktime(0,0,0,$m,10))."</option>"; } ?></select>
            <select name="t_year"><?php foreach(['2024','2025'] as $yr) echo "<option value='$yr' ".($tYear==$yr?'selected':'').">$yr</option>"; ?></select>
            <input type="date" name="target_date" value="<?= $targetDate ?>">
            <button type="submit" style="background:var(--accent); color:#fff; border:none;">UPDATE</button>
        </div>
        <div class="filter-section" style="background:#131722; padding:2px; border-radius:4px; border:1px solid #363a45;">
            <button type="button" class="<?= $viewMode=='daily'?'active-btn':'' ?>" onclick="setView('daily')" style="padding:4px 8px;">D</button>
            <button type="button" class="<?= $viewMode=='weekly'?'active-btn':'' ?>" onclick="setView('weekly')" style="padding:4px 8px;">W</button>
        </div>
    </form>
    
    <div style="display:flex; gap:3px;">
        <button id="aiBtn" onclick="toggleAI()">🤖 AI ANALYSIS</button>
        <button id="wvBtn" onclick="toggleTgl('wvBtn','wv_en',1)">W-VWAP</button>
        <button id="mvBtn" onclick="toggleTgl('mvBtn','mv_en',2)">M-VWAP</button>
        <button id="wsBtn" onclick="toggleTgl('wsBtn','ws_en',3)">WS-POC</button>
        <button id="wpBtn" onclick="toggleAnnos('wpBtn','wp_en')">W-POC</button>
        <button id="mpBtn" onclick="toggleAnnos('mpBtn','mp_en')">M-POC</button>
        <button id="dpBtn" onclick="toggleAnnos('dpBtn','dp_en')">D-POC</button>
        <button id="scBtn" onclick="toggleScale()">📏 SCALE</button>
        <button onclick="chart.resetZoom()">RESET</button>
    </div>
</div>

<div class="chart-wrap" id="chartDiv">
    <div id="scaleBox"></div><div id="scalePoint"></div><div id="scaleLabel"></div>
    <canvas id="niftyChart"></canvas>
</div>

<div id="aiPanel">
    <div class="ai-grid">
        <div class="ai-card" id="trendCard"><h4>Market Explanation (<?= $ai['date'] ?>)</h4><p>-</p></div>
        <div class="ai-card" id="migCard"><h4>Value Flow Status</h4><p>-</p></div>
        <div class="ai-card" id="strategyCard" style="border-left-color: var(--accent);"><h4>Execution Strategy</h4><p>-</p></div>
    </div>
</div>

<script>
    const ohlc = <?php echo json_encode($ohlcFinal); ?>;
    const wVData = <?php echo json_encode($wVData); ?>, mVData = <?php echo json_encode($mVData); ?>;
    const wsPData = <?php echo json_encode($wsPocData); ?>;
    const qD = <?php echo json_encode(array_values($quarters)); ?>, mD = <?php echo json_encode(array_values($months)); ?>;
    const wD = <?php echo json_encode(array_values($weeks)); ?>, dD = <?php echo json_encode(array_values($days)); ?>;
    const aiData = <?php echo json_encode($ai); ?>;

    function runAI() {
        if(!aiData.date) return;
        let isBull = aiData.price > aiData.pwpoc;
        let bias = isBull ? `📈 BULLISH: Focused on ${aiData.date}. Price is above LW-POC (${aiData.pwpoc}).` : `📉 BEARISH: Price is below LW-POC (${aiData.pwpoc}).`;
        let flow = (aiData.wsPoc > aiData.pwpoc) ? "🚀 UPWARD: Value is shifting higher this week." : "🔻 DOWNWARD: Value flow is weakening.";
        let strategy = isBull ? `💎 BUY ON DIPS: Strategy near D-POC (${aiData.dpoc}) or LW-POC (${aiData.pwpoc}).` : `🔥 SELL ON RISE: Strategy near VWAP (${aiData.vwap.toFixed(0)}).`;
        
        document.getElementById('trendCard').querySelector('p').innerHTML = bias;
        document.getElementById('migCard').querySelector('p').innerHTML = flow;
        document.getElementById('strategyCard').querySelector('p').innerHTML = strategy;
    }

    function toggleAI() {
        const p = document.getElementById('aiPanel'), b = document.getElementById('aiBtn');
        const isOff = p.style.display==='none'||p.style.display==='';
        p.style.display = isOff?'block':'none'; b.classList.toggle('active-btn', isOff);
        if(isOff) runAI();
    }

    function setView(mode) {
        const url = new URL(window.location);
        url.searchParams.set('view_mode', mode);
        window.location.href = url.href;
    }

    function getAnnos() {
        const a = {};
        // Quarterly & Monthly Boxes (Pure Transparent)
        qD.forEach((b,i)=>{ a['q'+i]={type:'box',drawTime:'beforeDatasetsDraw',xMin:b.s,xMax:b.e,yMin:b.l,yMax:b.h,borderColor:'rgba(255,255,255,0.25)',borderWidth:3,backgroundColor:'transparent',label:{display:true,content:b.label,position:'start',color:'rgba(255,255,255,0.2)',font:{size:32}}}; });
        mD.forEach((b,i)=>{ a['m'+i]={type:'box',drawTime:'beforeDatasetsDraw',xMin:b.s,xMax:b.e,yMin:b.l,yMax:b.h,borderColor:'rgba(156,39,176,0.6)',borderWidth:1.5,backgroundColor:'transparent',label:{display:true,content:b.label,position:'start',color:'rgba(156,39,176,0.5)',font:{size:14}}}; });
        wD.forEach((b,i)=>{ a['w'+i]={type:'box',drawTime:'beforeDatasetsDraw',xMin:b.s,xMax:b.e,yMin:b.l,yMax:b.h,borderColor:'rgba(3,169,244,0.7)',borderWidth:1.5,backgroundColor:'transparent',label:{display:true,content:b.label,position:'end',color:'rgba(3,169,244,0.8)',font:{size:11,weight:'bold'}}}; });
        dD.forEach((b,i)=>{ a['d'+i]={type:'box',drawTime:'beforeDatasetsDraw',xMin:b.s,xMax:b.e,yMin:b.l,yMax:b.h,borderColor:'rgba(255,255,255,0.08)',borderWidth:1,borderDash:[4,4],backgroundColor:'transparent',label:{display:true,content:new Date(b.s).toLocaleDateString('en-US',{weekday:'short'}),position:{x:'center',y:'start'},color:'#5d606b',font:{size:10}}}; });
        
        // POC Lines with FULL PRICE LABELS
        if(localStorage.getItem('mp_en')==='true') mD.forEach((b,i)=>{ a['mp'+i]={type:'line',xMin:b.s,xMax:b.e,yMin:b.poc,yMax:b.poc,borderColor:'#ff9800',borderWidth:2,borderDash:[6,2],label:{display:true,content:'M-POC: '+b.poc,position:'start',backgroundColor:'#ff9800',color:'#000',font:{size:10,weight:'bold'}}}; });
        if(localStorage.getItem('wp_en')==='true') wD.forEach((b,i)=>{ a['wp'+i]={type:'line',xMin:b.s,xMax:b.e,yMin:b.poc,yMax:b.poc,borderColor:'#f23645',borderWidth:1.5,borderDash:[4,4],label:{display:true,content:'W-POC: '+b.poc,position:'end',backgroundColor:'#f23645',color:'#fff',font:{size:9}}}; });
        if(localStorage.getItem('dp_en')==='true') dD.forEach((b,i)=>{ a['dp'+i]={type:'line',xMin:b.s,xMax:b.e,yMin:b.poc,yMax:b.poc,borderColor:'#00ff88',borderWidth:1,borderDash:[2,2],label:{display:true,content:'D-POC: '+b.poc,position:'center',backgroundColor:'#00ff88',color:'#000',font:{size:8}}}; });
        return a;
    }

    const ctx = document.getElementById('niftyChart').getContext('2d');
    const chart = new Chart(ctx, {
        type: 'candlestick',
        data: { datasets: [
            { label:'Price', data:ohlc, color:{up:'#26a69a', down:'#ef5350'} },
            { type:'line', label:'W-VWAP', data:wVData, borderColor:'#00bcd4', borderWidth:1.5, pointRadius:0, hidden:localStorage.getItem('wv_en')!=='true' },
            { type:'line', label:'M-VWAP', data:mVData, borderColor:'#e91e63', borderWidth:2, pointRadius:0, hidden:localStorage.getItem('mv_en')!=='true' },
            { type:'line', label:'WS-POC', data:wsPData, borderColor:'#ffff00', borderWidth:1.5, pointRadius:0, hidden:localStorage.getItem('ws_en')!=='true', stepped:true }
        ]},
        options: { 
            responsive:true, maintainAspectRatio:false, 
            scales: { x:{type:'time', time:{unit: '<?= $viewMode=='weekly'?'week':'day' ?>'}, grid:{color:'#242b33'}}, y:{position:'right', grid:{color:'#242b33'}} }, 
            plugins:{ legend:{display:false}, annotation:{annotations:getAnnos()}, zoom:{pan:{enabled:true,mode:'x'},zoom:{wheel:{enabled:true},mode:'x'}} } 
        }
    });

    // Control Toggles
    function toggleAnnos(id, key) { let s = localStorage.getItem(key)==='true'; s=!s; localStorage.setItem(key, s); chart.options.plugins.annotation.annotations = getAnnos(); chart.update(); document.getElementById(id).classList.toggle('active-btn', s); }
    function toggleTgl(id, key, idx) { let s = !chart.isDatasetVisible(idx); localStorage.setItem(key, s); chart.setDatasetVisibility(idx, s); chart.update(); document.getElementById(id).classList.toggle('active-btn', s); }

    // Scale Logic
    let scM=false,sx,sy,sp; const box=document.getElementById('scaleBox'),pt=document.getElementById('scalePoint'),lbl=document.getElementById('scaleLabel');
    function toggleScale(){ scM=!scM; document.getElementById('scBtn').classList.toggle('active-btn',scM); chart.options.plugins.zoom.pan.enabled=!scM; if(!scM) box.style.display=pt.style.display=lbl.style.display='none'; chart.update(); }
    const cvs=document.getElementById('niftyChart');
    cvs.onmousedown=(e)=>{ if(!scM)return; const r=cvs.getBoundingClientRect(); sx=e.clientX-r.left; sy=e.clientY-r.top; sp=chart.scales.y.getValueForPixel(sy); pt.style.left=sx+"px"; pt.style.top=sy+"px"; pt.style.display=box.style.display=lbl.style.display="block"; box.style.left=sx+"px"; box.style.top=sy+"px"; box.style.width=box.style.height="0px"; };
    cvs.onmousemove=(e)=>{ if(!scM||!sp)return; const r=cvs.getBoundingClientRect(); const cx=e.clientX-r.left,cy=e.clientY-r.top,cp=chart.scales.y.getValueForPixel(cy); box.style.width=Math.abs(cx-sx)+"px"; box.style.height=Math.abs(cy-sy)+"px"; box.style.left=Math.min(cx,sx)+"px"; box.style.top=Math.min(cy,sy)+"px"; lbl.innerHTML=(cp-sp).toFixed(2); lbl.style.left=(cx+15)+"px"; lbl.style.top=(cy-15)+"px"; };
    window.onmouseup=()=>sp=null;

    ['wvBtn','mvBtn','wsBtn','wpBtn','mpBtn','dpBtn'].forEach(id => { if(localStorage.getItem(id.replace('Btn','')+'_en')==='true') document.getElementById(id).classList.add('active-btn'); });
</script>
</body>
</html>