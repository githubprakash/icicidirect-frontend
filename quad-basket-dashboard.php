<?php
// Configuration: Define your frames here.
// You can easily change the titles or links in this array.

$todayDate = $monthFirstDate = $previousDate = date('Y-m-d');

$baskets = [
    'd_brsh'=>'Daily Bearish',
    'w_brsh'=>'Weekly Bearish',
    'm_brsh'=>'Monthly Bearish',
    'd_bulsh'=>'Daily Bullish',
    'w_bulsh'=>'Weekly Bullish',
    'm_bulsh'=>'Monthly Bullish',
];

$host = "localhost"; $user = "root"; $pass = "root"; $dbname = "options_m_db_by_fut";
$conn = new mysqli($host, $user, $pass, $dbname);
if ($conn->connect_error) { die("Connection failed: " . $conn->connect_error); }

/* Month First Date Fetch From Records */

$sqlMonthFirstDate = "SELECT MIN(DATE(log_time)) AS month_first_date FROM `basket_tracker_log` WHERE MONTH(`log_time`) = MONTH(CURDATE())";
$resMonthFirstDate = $conn->query($sqlMonthFirstDate);

$monthFirstDate = ($resMonthFirstDate && $resMonthFirstDate->num_rows > 0)? $resMonthFirstDate->fetch_assoc()['month_first_date']:$monthFirstDate;

/* Previous Date Fetch From Records */

$sqlPreviousDate = "SELECT DATE(log_time) AS previous_date FROM `basket_tracker_log` WHERE DATE(`log_time`) < CURDATE() ORDER BY `log_time` DESC LIMIT 0,1";
$resPreviousDate = $conn->query($sqlPreviousDate);

$previousDate = ($resPreviousDate && $resPreviousDate->num_rows > 0)? $resPreviousDate->fetch_assoc()['previous_date']:$previousDate;

$frames = [
    [
        'title' => 'Chart View 1',
        'link'  => 'index.php?from_date='.$monthFirstDate.'&to_date='.$todayDate.'&basket[]='.$baskets['d_bulsh'].'&basket[]='.$baskets['w_bulsh'].'&basket[]='.$baskets['m_bulsh'].'&interval=15'
    ],
    [
        'title' => 'Chart View 2',
        'link'  => 'index.php?from_date='.$monthFirstDate.'&to_date='.$todayDate.'&basket[]='.$baskets['d_brsh'].'&basket[]='.$baskets['w_brsh'].'&basket[]='.$baskets['m_brsh'].'&interval=15'
    ],
    [
        'title' => 'Chart View 3',
        'link'  => 'index.php?from_date='.$previousDate.'&to_date='.$todayDate.'&basket[]='.$baskets['d_bulsh'].'&basket[]='.$baskets['w_bulsh'].'&basket[]='.$baskets['m_bulsh'].'&interval=15'
    ],
    [
        'title' => 'Chart View 4',
        'link'  => 'index.php?from_date='.$previousDate.'&to_date='.$todayDate.'&basket[]='.$baskets['d_brsh'].'&basket[]='.$baskets['w_brsh'].'&basket[]='.$baskets['m_brsh'].'&interval=15'
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quad Basket Dashboard</title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 0;
            background-color: #f0f2f5; /* Light background for contrast */
            font-family: 'Segoe UI', Tahoma, sans-serif;
            height: 100vh; /* Full screen height */
            overflow: hidden; /* Hide body scroll if fitting to screen */
        }

        /* The Main Grid Container */
        .grid-container {
            display: grid;
            grid-template-columns: 1fr 1fr; /* Two equal columns */
            grid-template-rows: 1fr 1fr;    /* Two equal rows */
            gap: 10px;                      /* Space between frames */
            height: 100vh;                  /* Fill the screen */
            padding: 10px;                  /* Outer padding */
        }

        /* Individual Frame Wrapper */
        .frame-wrapper {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            overflow: hidden;
            position: relative;
            border: 1px solid #e1e4e8;
            display: flex;
            flex-direction: column;
        }

        /* Header for each frame */
        .frame-header {
            background: #f8f9fa;
            padding: 5px 10px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
            height: 30px;
        }
        
        .frame-title {
            font-size: 11px;
            font-weight: 700;
            color: #555;
            text-transform: uppercase;
        }

        .btn-expand {
            text-decoration: none;
            font-size: 10px;
            background: #333;
            color: #fff;
            padding: 2px 8px;
            border-radius: 4px;
        }

        /* The Iframe itself */
        iframe {
            width: 100%;
            height: 100%;
            border: none;
            display: block;
            flex-grow: 1; /* Fills remaining height */
        }

        /* Responsive: Stack on mobile */
        @media (max-width: 768px) {
            body { overflow: auto; height: auto; }
            .grid-container {
                grid-template-columns: 1fr;
                grid-template-rows: auto;
                height: auto;
                display: block;
            }
            .frame-wrapper {
                height: 600px; /* Fixed height on mobile */
                margin-bottom: 15px;
            }
        }
    </style>
</head>
<body>

    <div class="grid-container">
        
        <?php foreach ($frames as $frame): ?>
            <!-- Dynamic Frame Block -->
            <div class="frame-wrapper">
                <div class="frame-header">
                    <span class="frame-title"><?php echo htmlspecialchars($frame['title']); ?></span>
                    <a href="<?php echo htmlspecialchars($frame['link']); ?>" target="_blank" class="btn-expand">Open New Tab</a>
                </div>
                <iframe src="<?php echo htmlspecialchars($frame['link']); ?>"></iframe>
            </div>
        <?php endforeach; ?>

    </div>

</body>
</html>