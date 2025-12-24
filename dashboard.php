<?php
// Configuration: Add or Remove dashboard links here
$dashboard_cards = [
    [
        'title' => 'Quad Basket Dashboard',
        'url'   => 'quad-basket-dashboard.php',
        'theme' => 'theme-blue',
        'icon'  => 'fa-solid fa-table-columns',
        'desc'  => 'View multi-site data comparisons'
    ],
    [
        'title' => 'Basket Analytics Pro',
        'url'   => 'index.php',
        'theme' => 'theme-purple',
        'icon'  => 'fa-solid fa-chart-pie',
        'desc'  => 'Professional grade basket analysis'
    ],
    [
        'title' => 'Index & Futures Analytics',
        'url'   => 'nifty-index.php',
        'theme' => 'theme-orange',
        'icon'  => 'fa-solid fa-arrow-trend-up',
        'desc'  => 'Track Index & Futures market movements'
    ],
    [
        'title'       => 'Options Analytics',
        'url'         => 'options-index.php',
        'theme'       => 'theme-blue',
        'icon'        => 'fa-solid fa-bolt',
        'desc'        => 'Track options movements'
    ],
    [
        'title' => 'Options Growth Analytics',
        'url'   => 'http://localhost/projects/php/core/options-growth/public/growth-journey',
        'theme' => 'theme-green',
        'icon'  => 'fa-solid fa-seedling',
        'desc'  => 'Monitor growth journey and strategies'
    ]
];

$page_title = "Market Insight Portal";
$page_description = "Centralized Analytics & Trading Dashboard";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="shortcut icon" href="favicon.ico"> 

    <style>
        body { 
            background-color: #f0f2f5; 
            font-family: 'Segoe UI', sans-serif; 
            min-height: 100vh; 
            display: flex; 
            flex-direction: column; 
        }
        
        /* Header */
        .dashboard-header { background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%); color: white; padding: 2rem 0; margin-bottom: 3rem; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .dashboard-title { text-decoration: none; color: white; font-weight: 700; letter-spacing: 1px; transition: opacity 0.3s; }
        .dashboard-title:hover { color: #e0e0e0; }

        /* Cards */
        .card-box { border: none; border-radius: 15px; background: white; transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1); position: relative; height: 100%; text-decoration: none; display: block; }
        .card-box:hover { transform: translateY(-10px); box-shadow: 0 14px 28px rgba(0,0,0,0.1), 0 10px 10px rgba(0,0,0,0.05); }
        .card-body { padding: 2.5rem 1.5rem; text-align: center; color: #333; }

        /* Icons & Text */
        .icon-circle { width: 70px; height: 70px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.5rem auto; font-size: 1.8rem; transition: transform 0.3s; }
        .card-box:hover .icon-circle { transform: scale(1.1) rotate(5deg); }
        .card-title { font-weight: 600; font-size: 1.25rem; margin-bottom: 0.5rem; color: #2c3e50; }
        .card-text { color: #6c757d; font-size: 0.9rem; }

        /* Themes */
        .theme-blue { background-color: #e3f2fd; color: #0d6efd; }
        .theme-purple { background-color: #f3e5f5; color: #9c27b0; }
        .theme-orange { background-color: #fff3e0; color: #fd7e14; }
        .theme-green { background-color: #e8f5e9; color: #198754; } 
        .theme-teal { background-color: #e0f2f1; color: #00695c; } 
        
        /* Footer */
        footer { margin-top: auto; text-align: center; padding: 1.5rem; color: #6c757d; font-size: 0.85rem; }
    </style>
</head>
<body>

    <!-- Header -->
    <div class="container-fluid dashboard-header text-center">
        <h3>
            <!-- Empty href reloads the page -->
            <a href="" class="dashboard-title">
                <i class="fa-solid fa-bullseye me-2"></i> <?php echo htmlspecialchars($page_title); ?>
            </a>
        </h3>
        <p class="mb-0 opacity-75"><?php echo htmlspecialchars($page_description); ?></p>
    </div>

    <!-- Dynamic Content Loop -->
    <div class="container mb-5">
        <div class="row g-4 justify-content-center">
            
            <?php foreach ($dashboard_cards as $card): ?>
                <div class="col-12 col-md-6 col-lg-2">
                    <a href="<?php echo htmlspecialchars($card['url']); ?>" 
                       target="_blank" 
                       class="card-box" 
                       title="<?php echo htmlspecialchars($card['title']); ?>">
                       
                        <div class="card-body">
                            <!-- Dynamic Theme Class & Icon -->
                            <div class="icon-circle <?php echo htmlspecialchars($card['theme']); ?>">
                                <i class="<?php echo htmlspecialchars($card['icon']); ?>"></i>
                            </div>
                            
                            <h5 class="card-title"><?php echo htmlspecialchars($card['title']); ?></h5>
                            
                            <?php if(!empty($card['desc'])): ?>
                                <p class="card-text"><?php echo htmlspecialchars($card['desc']); ?></p>
                            <?php endif; ?>
                        </div>
                    </a>
                </div>
            <?php endforeach; ?>

        </div>
    </div>

    <footer>
        &copy; <?php echo date("Y"); ?> <?php echo htmlspecialchars($page_title); ?>. All rights reserved.
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>