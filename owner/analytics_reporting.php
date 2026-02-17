<?php
session_start();
require_once '../config/db.php';
require_once '../includes/analytics_service.php';
require_role('owner');

$owner_id = $_SESSION['user']['id'];
$shop_stmt = $pdo->prepare("SELECT id, shop_name FROM shops WHERE owner_id = ?");
$shop_stmt->execute([$owner_id]);
$shops = $shop_stmt->fetchAll();
$shop = $shops[0] ?? null;
$shop_ids = array_column($shops, 'id');

$overview = fetch_order_analytics($pdo, $shop_ids);
$staff_count = fetch_staff_count($pdo, $shop_ids);
$total_orders = $overview['total_orders'];
$completion_rate = $total_orders > 0 ? ($overview['completed_orders'] / $total_orders) * 100 : 0;

$kpis = [
    [
        'label' => 'Total revenue',
        'value' => '₱' . number_format($overview['total_revenue'], 2),
        'note' => 'Paid orders for your shop.',
        'icon' => 'fas fa-peso-sign',
        'tone' => 'success',
    ],
    [
        'label' => 'Completion rate',
        'value' => number_format($completion_rate, 1) . '%',
        'note' => 'Orders completed successfully.',
        'icon' => 'fas fa-clock',
        'tone' => 'primary',
    ],
    [
        'label' => 'Active orders',
        'value' => number_format($overview['active_orders']),
        'note' => 'Accepted or in-progress jobs.',
        'icon' => 'fas fa-clipboard-list',
        'tone' => 'info',
    ],
    [
        'label' => 'Active staff',
        'value' => number_format($staff_count),
        'note' => 'Currently assigned to your shop.',
        'icon' => 'fas fa-bell',
        'tone' => 'warning',
    ],
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics &amp; Reporting - Owner</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .reporting-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 1.5rem;
            margin: 2rem 0;
        }

        .kpi-card {
            grid-column: span 3;
        }

        .empty-state-card {
            grid-column: span 12;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar--compact">
        <div class="container d-flex justify-between align-center">
            <a href="dashboard.php" class="navbar-brand">
                <i class="fas fa-store"></i> <?php echo htmlspecialchars($shop['shop_name'] ?? 'Shop Owner'); ?>
            </a>
            <ul class="navbar-nav">
                <li><a href="dashboard.php" class="nav-link">Dashboard</a></li>
                <li><a href="shop_profile.php" class="nav-link">Shop Profile</a></li>
                <li><a href="manage_staff.php" class="nav-link">Staff</a></li>
                <li><a href="shop_orders.php" class="nav-link">Orders</a></li>
                <li><a href="messages.php" class="nav-link">Messages</a></li>
                 <li class="dropdown">
                    <a href="#" class="nav-link dropdown-toggle">
                        <i class="fas fa-coins"></i> Finance
                    </a>
                    <div class="dropdown-menu">
                        <a href="payment_verifications.php" class="dropdown-item"><i class="fas fa-receipt"></i> Payments</a>
                        <a href="earnings.php" class="dropdown-item"><i class="fas fa-wallet"></i> Earnings</a>
                    </div>
                </li>
                <li><a href="analytics_reporting.php" class="nav-link active">Analytics</a></li>
                <li class="dropdown">
                    <a href="#" class="nav-link dropdown-toggle">
                        <i class="fas fa-user"></i> <?php echo htmlspecialchars($_SESSION['user']['fullname']); ?>
                    </a>
                    <div class="dropdown-menu">
                        <a href="profile.php" class="dropdown-item"><i class="fas fa-user-cog"></i> Profile</a>
                        <a href="../auth/logout.php" class="dropdown-item"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </li>
            </ul>
        </div>
    </nav>

    <main class="container">
        <section class="page-header">
            <div>
                <h1>Analytics &amp; Reporting</h1>
                <p class="text-muted">Follow shop performance with dashboards, scheduled insights, and alerts.</p>
            </div>
            <span class="badge badge-primary"><i class="fas fa-chart-pie"></i> Module 30</span>
        </section>

        <section class="reporting-grid">
            <?php foreach ($kpis as $kpi): ?>
                <div class="card kpi-card">
                    <div class="metric">
                        <div>
                            <p class="text-muted mb-1"><?php echo $kpi['label']; ?></p>
                            <h3 class="mb-1"><?php echo $kpi['value']; ?></h3>
                            <small class="text-muted"><?php echo $kpi['note']; ?></small>
                        </div>
                        <div class="icon-circle bg-<?php echo $kpi['tone']; ?> text-white">
                            <i class="<?php echo $kpi['icon']; ?>"></i>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

            <div class="card empty-state-card">
                <h2>Reporting panels</h2>
                <p class="text-muted mb-0">
                    PANELLLLLSSSS
            </div>
        </section>
    </main>
</body>
</html>
