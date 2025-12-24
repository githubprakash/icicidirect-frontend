<?php
ini_set('memory_limit', '1024M');
set_time_limit(300);

// ==========================================
// 1. DB & HISTORY FETCH
// ==========================================
$host = "localhost";
$user = "root";
$pass = "root";
$dbname = "options_m_db_by_fut";
$conn = new mysqli($host, $user, $pass, $dbname);
if ($conn->connect_error) { die("Connection failed: " . $conn->connect_error); }

// Fetch Daily OHLC for Levels Calculation
$sqlHistory = "SELECT DATE(trade_date) as t_date, SUBSTRING_INDEX(GROUP_CONCAT(close_price ORDER BY trade_date DESC), ',', 1) as day_close, MAX(high_price) as day_high, MIN(low_price) as day_low FROM nifty_ohlc_30m GROUP BY DATE(trade_date) ORDER BY t_date ASC";
$resHistory = $conn->query($sqlHistory);
$dailyHistory = []; $datesList = [];
while($row = $resHistory->fetch_assoc()) {
    $d = $row['t_date'];
    $dailyHistory[$d] = ['close' => floatval($row['day_close']), 'high' => floatval($row['day_high']), 'low' => floatval($row['day_low'])];
    $datesList[] = $d;
}
// Sort Dates List (Latest to Oldest for Scanning loop, though we scan chronological inside)
rsort($datesList); 

// ==========================================
// 2. SCANNING LOGIC
// ==========================================
$allSignals = [];
$stats = ['total'=>0, 'win'=>0, 'loss'=>0, 'filtered'=>0];

// Settings
$WICK_RATIO = 1.5; 
$MIN_ZONE_GAP = 80; // Only trade if room is > 80 points
$TARGET_POINTS = 40;

foreach ($datesList as $currentDate) {
    
    // --- STEP A: IDENTIFY FRESH LEVELS FOR THIS DAY ---
    // (Levels that existed BEFORE this morning)
    $todaysFreshLevels = [];
    foreach ($dailyHistory as $histDate => $data) {
        if ($histDate >= $currentDate) continue; // Future levels ignore
        $level = $data['close'];
        
        // Check if broken historically
        $broken = false;
        foreach ($dailyHistory as $interimDate => $iData) {
            if ($interimDate <= $histDate || $interimDate >= $currentDate) continue;
            if (($level > $iData['close'] && $iData['high'] >= $level) || ($level < $iData['close'] && $iData['low'] <= $level)) {
                $broken = true; break;
            }
        }
        if (!$broken) $todaysFreshLevels[] = $level;
    }
    sort($todaysFreshLevels); // Sort for easy search

    // --- STEP B: FETCH INTRADAY DATA ---
    $sql = "SELECT * FROM nifty_ohlc_30m WHERE DATE(trade_date) = '$currentDate' ORDER BY trade_date ASC";
    $res = $conn->query($sql);
    $candles = [];
    while($row = $res->fetch_assoc()) { $candles[] = $row; }
    if(count($candles) < 5) continue;

    $dayMax = 0; $dayMin = 999999;

    // --- STEP C: CANDLE LOOP ---
    for ($i = 0; $i < count($candles); $i++) {
        $c = $candles[$i];
        $price = floatval($c['close_price']);
        $high = floatval($c['high_price']);
        $low = floatval($c['low_price']);
        $open = floatval($c['open_price']);
        $time = date('H:i', strtotime($c['trade_date']));

        // Track Day High/Low (Before Logic)
        if ($i == 0) { $dayMax = $high; $dayMin = $low; continue; }

        $bodySize = abs($price - $open);
        $upperWick = $high - max($price, $open);
        $lowerWick = min($price, $open) - $low;
        
        // 1. DETECT REJECTION SIGNAL
        $signalType = "";
        if ($high >= $dayMax && $upperWick > ($bodySize * $WICK_RATIO)) $signalType = "RESISTANCE REJECTION";
        if ($low <= $dayMin && $lowerWick > ($bodySize * $WICK_RATIO)) $signalType = "SUPPORT REJECTION";

        // 2. APPLY ZONE GAP FILTER
        if ($signalType != "") {
            
            // Find Nearest Fresh Resistance & Support relative to Current Price
            // IMPORTANT: We must check if these levels were broken TODAY by $dayMax or $dayMin
            
            $nearestRes = 999999;
            $nearestSup = 0;

            foreach ($todaysFreshLevels as $lvl) {
                // If level is above price AND NOT broken today yet
                if ($lvl > $price) {
                    if ($dayMax < $lvl) { // Safe
                        if ($lvl < $nearestRes) $nearestRes = $lvl;
                    }
                }
                // If level is below price AND NOT broken today yet
                if ($lvl < $price) {
                    if ($dayMin > $lvl) { // Safe
                        if ($lvl > $nearestSup) $nearestSup = $lvl;
                    }
                }
            }

            // Calculate Gap
            // If no resistance found (ATH), assume infinity (Safe)
            $resVal = ($nearestRes == 999999) ? $price + 500 : $nearestRes;
            // If no support found (ATL), assume infinity (Safe)
            $supVal = ($nearestSup == 0) ? $price - 500 : $nearestSup;

            $zoneGap = $resVal - $supVal;

            // --- FILTER CHECK ---
            if ($zoneGap < $MIN_ZONE_GAP) {
                $stats['filtered']++; // Too tight, skip
                // Update Day Levels and continue
                if ($high > $dayMax) $dayMax = $high;
                if ($low < $dayMin) $dayMin = $low;
                continue;
            }

            // 3. CALCULATE RESULT (Strategy Executed)
            $futureMove = 0;
            for ($j = 1; $j <= 4; $j++) {
                if (isset($candles[$i + $j])) {
                    $moveUp = floatval($candles[$i + $j]['high_price']) - $price;
                    $moveDown = $price - floatval($candles[$i + $j]['low_price']);
                    $currentMaxMove = max($moveUp, $moveDown);
                    if ($currentMaxMove > $futureMove) $futureMove = $currentMaxMove;
                }
            }

            $stats['total']++;
            $resClass = 'loss';
            if ($futureMove >= 80) { $resClass = 'jackpot'; $stats['win']++; }
            elseif ($futureMove >= $TARGET_POINTS) { $resClass = 'win'; $stats['win']++; }
            else { $stats['loss']++; }

            $allSignals[] = [
                'date' => $currentDate,
                'time' => $time,
                'type' => $signalType,
                'entry' => $price,
                'gap' => $zoneGap,
                'levels' => "S: ".($nearestSup==0?'None':$nearestSup)." | R: ".($nearestRes==999999?'None':$nearestRes),
                'move' => $futureMove,
                'class' => $resClass
            ];
        }

        // Update Day High/Low
        if ($high > $dayMax) $dayMax = $high;
        if ($low < $dayMin) $dayMin = $low;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Gap Filtered Straddle</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f4f7f6; padding: 20px; }
        .container { max-width: 1100px; margin: 0 auto; }
        
        .dashboard { display: flex; gap: 15px; margin-bottom: 20px; }
        .card { flex: 1; background: white; padding: 15px; border-radius: 8px; text-align: center; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .card h3 { margin: 0; font-size: 24px; color: #333; }
        .card small { color: #777; font-weight: bold; font-size: 11px; text-transform: uppercase; }

        table { width: 100%; background: white; border-collapse: collapse; box-shadow: 0 2px 10px rgba(0,0,0,0.05); border-radius: 8px; overflow: hidden; }
        th { background: #37474f; color: white; padding: 12px; text-align: left; font-size: 13px; }
        td { padding: 10px 12px; border-bottom: 1px solid #eee; font-size: 13px; color: #333; }
        tr:hover { background: #f1f8e9; }

        .badge { padding: 3px 6px; border-radius: 4px; font-size: 10px; font-weight: bold; }
        .b-res { background: #ffebee; color: #b71c1c; border: 1px solid #ef9a9a; }
        .b-sup { background: #e8f5e9; color: #1b5e20; border: 1px solid #a5d6a7; }

        .win { color: #2e7d32; font-weight: bold; }
        .loss { color: #c62828; }
        .jackpot { color: #d81b60; font-weight: bold; text-shadow: 0 0 2px rgba(216, 27, 96, 0.2); }
        
        .gap-good { color: #2e7d32; font-weight: bold; }
        .gap-tight { color: #ef6c00; font-weight: bold; }
    </style>
</head>
<body>

<div class="container">
    <h2 style="color:#37474f; text-align:center;">🛡️ Safe Straddle Scanner (Min 80pt Gap)</h2>
    
    <div class="dashboard">
        <div class="card">
            <h3><?php echo $stats['total']; ?></h3>
            <small>Trades Taken</small>
        </div>
        <div class="card">
            <h3 style="color:#ef6c00"><?php echo $stats['filtered']; ?></h3>
            <small>Trades Avoided (Tight Zone)</small>
        </div>
        <div class="card">
            <h3 style="color:#2e7d32"><?php echo $stats['win']; ?></h3>
            <small>Profitable</small>
        </div>
        <div class="card">
            <?php 
                $rate = ($stats['total'] > 0) ? round(($stats['win'] / $stats['total']) * 100, 1) : 0; 
                $color = ($rate > 60) ? '#2e7d32' : (($rate > 40) ? '#fbc02d' : '#c62828');
            ?>
            <h3 style="color:<?php echo $color; ?>"><?php echo $rate; ?>%</h3>
            <small>Win Rate</small>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Date & Time</th>
                <th>Signal</th>
                <th>Zone Analysis (Gap)</th>
                <th>Result (2hr)</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($allSignals as $sig): ?>
                <tr>
                    <td>
                        <b><?php echo date('d M', strtotime($sig['date'])); ?></b> <span style="color:#777"><?php echo $sig['time']; ?></span>
                    </td>
                    <td>
                        <?php if(strpos($sig['type'], 'RESISTANCE') !== false): ?>
                            <span class="badge b-res">RES REJECTION</span>
                        <?php else: ?>
                            <span class="badge b-sup">SUP REJECTION</span>
                        <?php endif; ?>
                        <br> <small>Entry: <?php echo $sig['entry']; ?></small>
                    </td>
                    <td>
                        <span class="gap-good">Gap: <?php echo number_format($sig['gap'], 0); ?> pts</span>
                        <br>
                        <small style="color:#888;"><?php echo $sig['levels']; ?></small>
                    </td>
                    <td>
                        <?php if($sig['class'] == 'jackpot'): ?>
                            <span class="jackpot">🚀 +<?php echo number_format($sig['move'], 1); ?></span>
                        <?php elseif($sig['class'] == 'win'): ?>
                            <span class="win">✅ +<?php echo number_format($sig['move'], 1); ?></span>
                        <?php else: ?>
                            <span class="loss">❌ +<?php echo number_format($sig['move'], 1); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

</body>
</html>
<?php $conn->close(); ?>