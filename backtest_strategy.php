<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('Asia/Kolkata');

$conn = new mysqli("localhost", "root", "root", "options_m_db_by_fut");
$conn->query("SET time_zone = '+05:30'");

// --- 1. फिल्टर लॉजिक (Filter Logic) ---
$historyRange = $_GET['history_range'] ?? '1'; 
$fromDate = $_GET['from_date'] ?? '';
$toDate = $_GET['to_date'] ?? '';

$dateQueryPart = "";
if (!empty($fromDate) && !empty($toDate)) {
    $dateQueryPart = " AND DATE(datetime) BETWEEN '$fromDate' AND '$toDate'";
} else {
    $months = (int)$historyRange;
    $cutoff = date('Y-m-d', strtotime("-$months months"));
    $dateQueryPart = " AND DATE(datetime) >= '$cutoff'";
}

/**
 * POC लेवल निकालने का फंक्शन
 */
function getPocLevel($conn, $date, $strike, $expiry, $endTime) {
    $sql = "SELECT ROUND(close) as poc_p, SUM(volume) as v 
            FROM option_prices_5m_combined 
            WHERE DATE(datetime) = '$date' AND strikePrice = '$strike' AND expiryDate = '$expiry'
            AND TIME(datetime) BETWEEN '09:15:00' AND '$endTime'
            GROUP BY poc_p ORDER BY v DESC LIMIT 1";
    $res = $conn->query($sql);
    return ($res && $res->num_rows > 0) ? $res->fetch_assoc()['poc_p'] : null;
}

/**
 * एनालिसिस फंक्शन (Breakout & High tracking)
 */
function analyzeStrategy($conn, $date, $strike, $expiry, $pocPrice, $startTime) {
    $entry = $pocPrice + $strike;
    $sql = "SELECT high, close, datetime FROM option_prices_5m_combined 
            WHERE DATE(datetime) = '$date' AND strikePrice = '$strike' AND expiryDate = '$expiry'
            AND TIME(datetime) > '$startTime' ORDER BY datetime ASC";
    $res = $conn->query($sql);
    
    $maxH = 0; $breakT = null; $isBroken = false;
    if ($res && $res->num_rows > 0) {
        while($row = $res->fetch_assoc()) {
            $curC = $row['close'] + $strike;
            $curH = $row['high'] + $strike;
            if (!$isBroken) {
                if ($curC > $entry) { 
                    $isBroken = true; $breakT = date('H:i', strtotime($row['datetime'])); 
                    $maxH = $curH;
                }
            } else {
                if ($curH > $maxH) $maxH = $curH;
            }
        }
    }
    if (!$isBroken) return null;
    $pts = $maxH - $entry;
    return ['time' => $breakT, 'entry' => $entry, 'high' => $maxH, 'pts' => round($pts, 2), 'perc' => round(($pts/$entry)*100, 2)];
}

// --- 2. डेटा प्रोसेसिंग (Summary & Table) ---
$summary = ['setups' => 0, 'wins' => 0, 'losses' => 0, 'pts' => 0, 'max_win' => 0, 'max_loss' => 0];
$tableRows = "";

$sSql = "SELECT DISTINCT strikePrice, expiryDate FROM option_prices_5m_combined WHERE 1=1 $dateQueryPart ORDER BY expiryDate DESC, CAST(strikePrice AS UNSIGNED) ASC";
$sRes = $conn->query($sSql);

if ($sRes && $sRes->num_rows > 0) {
    while($sRow = $sRes->fetch_assoc()) {
        $strike = $sRow['strikePrice']; $expiry = $sRow['expiryDate'];
        $dRes = $conn->query("SELECT DISTINCT DATE(datetime) as d FROM option_prices_5m_combined WHERE strikePrice = '$strike' AND expiryDate = '$expiry' $dateQueryPart ORDER BY d DESC");
        
        $strikeHasData = false;
        $tempRows = "";

        while ($todayData = $dRes->fetch_assoc()) {
            $today = $todayData['d'];
            if ($today > $expiry) continue;

            $yRes = $conn->query("SELECT MAX(DATE(datetime)) as p_d FROM option_prices_5m_combined WHERE strikePrice = '$strike' AND expiryDate = '$expiry' AND DATE(datetime) < '$today'");
            $yesterday = $yRes->fetch_assoc()['p_d'] ?? null;
            if (!$yesterday) continue;

            $yC = $conn->query("SELECT SUBSTRING_INDEX(GROUP_CONCAT(open ORDER BY datetime ASC), ',', 1) as op, SUBSTRING_INDEX(GROUP_CONCAT(close ORDER BY datetime DESC), ',', 1) as cl FROM option_prices_5m_combined WHERE DATE(datetime) = '$yesterday' AND strikePrice = '$strike' AND expiryDate = '$expiry'")->fetch_assoc();
            
            // नियम: कल ग्रीन कैंडल होनी चाहिए
            if (!$yC || $yC['cl'] <= $yC['op']) continue; 

            $summary['setups']++;
            $strikeHasData = true;

            // 30m & 1h Analysis
            $p30 = getPocLevel($conn, $today, $strike, $expiry, '09:45:00');
            $r30 = $p30 ? analyzeStrategy($conn, $today, $strike, $expiry, $p30, '09:45:00') : null;
            
            $p60 = getPocLevel($conn, $today, $strike, $expiry, '10:15:00');
            $r60 = $p60 ? analyzeStrategy($conn, $today, $strike, $expiry, $p60, '10:15:00') : null;

            // Stats update (Using 30m as primary benchmark)
            if ($r30) {
                if ($r30['pts'] > 0) $summary['wins']++; else $summary['losses']++;
                $summary['pts'] += $r30['pts'];
                if ($r30['pts'] > $summary['max_win']) $summary['max_win'] = $r30['pts'];
                if ($r30['pts'] < $summary['max_loss']) $summary['max_loss'] = $r30['pts'];
            } else {
                $summary['losses']++;
            }

            $tempRows .= "<tr>
                <td style='font-weight:bold;'>".date('d M Y', strtotime($today))."</td>
                <td style='color:#16a34a; font-weight:bold;'>GREEN</td>
                <td class='blue'>".($r30 ? $r30['entry'] : '-')."</td>
                <td>".($r30 ? $r30['time'] : 'No Break')."</td>
                <td class='profit'>".($r30 ? "+".$r30['pts']." (".$r30['perc']."%)" : "-")."</td>
                <td class='blue'>".($r60 ? $r60['entry'] : '-')."</td>
                <td>".($r60 ? $r60['time'] : 'No Break')."</td>
                <td class='profit'>".($r60 ? "+".$r60['pts']." (".$r60['perc']."%)" : "-")."</td>
            </tr>";
        }
        if ($strikeHasData) {
            $tableRows .= "<tr class='strike-row'><td colspan='8'>STRIKE: $strike | EXPIRY: ".date('d M Y', strtotime($expiry))."</td></tr>" . $tempRows;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Strategy Master Dashboard</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f1f5f9; padding: 20px; margin: 0; }
        .summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .stat-card { background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); text-align: center; border-top: 4px solid #2563eb; }
        .stat-card h3 { margin: 0; font-size: 11px; color: #64748b; text-transform: uppercase; letter-spacing: 1px; }
        .stat-card p { margin: 10px 0 0; font-size: 22px; font-weight: bold; color: #1e293b; }
        .win { color: #16a34a !important; } .loss { color: #dc2626 !important; }
        
        .filter-bar { background: white; padding: 15px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); margin-bottom: 20px; display: flex; gap: 20px; align-items: flex-end; justify-content: center; }
        .container { background: white; padding: 20px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        th, td { border: 1px solid #e2e8f0; padding: 10px; text-align: center; }
        th { background: #0f172a; color: white; position: sticky; top: 0; }
        .strike-row { background: #f1f5f9; font-weight: bold; color: #2563eb; text-align: left; padding-left: 15px; }
        .blue { color: #2563eb; font-weight: bold; }
        .profit { background: #f0fdf4; color: #16a34a; font-weight: bold; }
        button { background: #2563eb; color: white; border: none; padding: 8px 20px; border-radius: 5px; cursor: pointer; font-weight: bold; }
    </style>
</head>
<body>

<!-- 1. Consolidated Summary (Top) -->
<div class="summary-grid">
    <div class="stat-card"><h3>Total Setup Days</h3><p><?php echo $summary['setups']; ?></p></div>
    <div class="stat-card"><h3>Profit Days</h3><p class="win"><?php echo $summary['wins']; ?></p></div>
    <div class="stat-card"><h3>Loss/No Break</h3><p class="loss"><?php echo $summary['losses']; ?></p></div>
    <div class="stat-card"><h3>Total Points Gained</h3><p class="win">+<?php echo $summary['pts']; ?></p></div>
    <div class="stat-card" style="border-top-color: #16a34a;"><h3>Max Day Profit</h3><p class="win">+<?php echo $summary['max_win']; ?></p></div>
    <div class="stat-card" style="border-top-color: #dc2626;"><h3>Max Day Loss</h3><p class="loss"><?php echo $summary['max_loss']; ?></p></div>
</div>

<!-- 2. Filters (Middle) -->
<div class="filter-bar">
    <form method="GET" style="display:flex; gap:15px; align-items:flex-end;">
        <div style="display:flex; flex-direction:column; gap:5px;">
            <label style="font-size:11px; font-weight:bold;">History Range</label>
            <select name="history_range" style="padding:7px;">
                <option value="1" <?php echo $historyRange == '1' ? 'selected' : ''; ?>>1 Month</option>
                <option value="3" <?php echo $historyRange == '3' ? 'selected' : ''; ?>>3 Months</option>
                <option value="6" <?php echo $historyRange == '6' ? 'selected' : ''; ?>>6 Months</option>
            </select>
        </div>
        <div style="display:flex; flex-direction:column; gap:5px;">
            <label style="font-size:11px; font-weight:bold;">From Date</label>
            <input type="date" name="from_date" value="<?php echo $fromDate; ?>" style="padding:5px;">
        </div>
        <div style="display:flex; flex-direction:column; gap:5px;">
            <label style="font-size:11px; font-weight:bold;">To Date</label>
            <input type="date" name="to_date" value="<?php echo $toDate; ?>" style="padding:5px;">
        </div>
        <button type="submit">Update Report</button>
        <a href="?" style="font-size:11px; color:#64748b; text-decoration:none; padding-bottom:8px;">Clear</a>
    </form>
</div>

<!-- 3. Detailed Log (Bottom) -->
<div class="container">
    <h2 style="text-align:center; color:#1e293b; margin-top:0;">📋 Strategy Detailed Log (Synthetic Spot)</h2>
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Yesterday</th>
                <th style="background:#2563eb">30m Entry</th>
                <th style="background:#2563eb">30m Break</th>
                <th style="background:#2563eb">30m Profit</th>
                <th style="background:#7c3aed">1h Entry</th>
                <th style="background:#7c3aed">1h Break</th>
                <th style="background:#7c3aed">1h Profit</th>
            </tr>
        </thead>
        <tbody>
            <?php echo (!empty($tableRows)) ? $tableRows : "<tr><td colspan='8'>No data found for selected filters.</td></tr>"; ?>
        </tbody>
    </table>
</div>

</body>
</html>