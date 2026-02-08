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

$dashboards = [
    [
        'title' => 'Sales momentum',
        'detail' => 'Daily bookings, average order value, and quote conversion.',
        'icon' => 'fas fa-chart-line',
    ],
    [
        'title' => 'Production velocity',
        'detail' => 'Machine utilization, output per shift, and backlog aging.',
        'icon' => 'fas fa-industry',
    ],
    [
        'title' => 'Customer experience',
        'detail' => 'Ratings, response time, and repeat order insights.',
        'icon' => 'fas fa-star',
    ],
];

$scheduled_reports = [
    [
        'name' => 'Weekly shop health report',
        'cadence' => 'Every Monday, 8:00 AM',
        'recipients' => 'Owner leadership team',
        'status' => 'Scheduled',
    ],
    [
        'name' => 'Monthly profitability review',
        'cadence' => 'End of month',
        'recipients' => 'Owner + Finance',
        'status' => 'Scheduled',
    ],
    [
        'name' => 'Quarterly customer insights',
        'cadence' => 'Quarter close',
        'recipients' => 'Owner + Marketing',
        'status' => 'Drafting',
    ],
];

$performance_alerts = [
    [
        'title' => 'Late-stage order risk',
        'detail' => '4 orders are within 24 hours of SLA breach.',
        'tone' => 'danger',
        'time' => 'Triggered today',
    ],
    [
        'title' => 'Low inventory signal',
        'detail' => 'Thread colors below safety stock levels.',
        'tone' => 'warning',
        'time' => 'Triggered 3h ago',
    ],
    [
        'title' => 'Client response delay',
        'detail' => '2 quotes awaiting reply for more than 48 hours.',
        'tone' => 'primary',
        'time' => 'Triggered yesterday',
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

        .purpose-card,
        .automation-card {
            grid-column: span 12;
        }

        .dashboard-card,
        .reports-card,
        .alerts-card {
            grid-column: span 4;
        }

        .dashboard-tile,
        .report-item,
        .alert-item {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 1rem;
            background: white;
        }

        .dashboard-tile + .dashboard-tile,
        .report-item + .report-item,
        .alert-item + .alert-item {
            margin-top: 1rem;
        }

        .report-item .meta {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem 1rem;
            font-size: 0.9rem;
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
                <li><a href="payment_verifications.php" class="nav-link">Payments</a></li>
                <li><a href="earnings.php" class="nav-link">Earnings</a></li>
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

            <div class="card purpose-card">
                <h2>Purpose</h2>
                <p class="text-muted mb-0">
                    Provides dashboards and insights to help owners steer production, revenue, and customer satisfaction
                    with confidence.
                </p>
            </div>

            <div class="card dashboard-card">
                <h2>Insight dashboards</h2>
                <?php foreach ($dashboards as $dashboard): ?>
                    <div class="dashboard-tile">
                        <h3 class="mb-1"><i class="<?php echo $dashboard['icon']; ?> text-primary"></i> <?php echo $dashboard['title']; ?></h3>
                        <p class="text-muted mb-0"><?php echo $dashboard['detail']; ?></p>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="card reports-card">
                <h2>Scheduled reports</h2>
                <?php foreach ($scheduled_reports as $report): ?>
                    <div class="report-item">
                        <h3 class="mb-1"><?php echo $report['name']; ?></h3>
                        <div class="meta text-muted">
                            <span><i class="fas fa-calendar"></i> <?php echo $report['cadence']; ?></span>
                            <span><i class="fas fa-user-group"></i> <?php echo $report['recipients']; ?></span>
                            <span class="badge badge-outline"><?php echo $report['status']; ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="card alerts-card">
                <h2>Performance alerts</h2>
                <?php foreach ($performance_alerts as $alert): ?>
                    <div class="alert-item">
                        <div class="d-flex justify-between align-center">
                            <h3 class="mb-1"><?php echo $alert['title']; ?></h3>
                            <span class="badge badge-<?php echo $alert['tone']; ?>"><?php echo $alert['time']; ?></span>
                        </div>
                        <p class="text-muted mb-0"><?php echo $alert['detail']; ?></p>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="card automation-card">
                <h2>Automation coverage</h2>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Automation</th>
                                <th>Trigger</th>
                                <th>Outcome</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Scheduled report delivery</td>
                                <td>Weekly and monthly cadences</td>
                                <td>Shares curated insights with owners and leaders</td>
                            </tr>
                            <tr>
                                <td>Performance alerting</td>
                                <td>Order, inventory, or SLA thresholds</td>
                                <td>Flags risk items for immediate follow-up</td>
                            </tr>
                            <tr>
                                <td>Insight refresh</td>
                                <td>Hourly data sync</td>
                                <td>Updates dashboards with the latest metrics</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </main>
</body>
</html>
