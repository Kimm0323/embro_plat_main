<?php
session_start();
require_once '../config/db.php';
require_once 'partials.php';
require_role('sys_admin');

$overview = $pdo->query("
    SELECT
        (SELECT COUNT(*) FROM users) as total_users,
        (SELECT COUNT(*) FROM shops) as total_shops,
        (SELECT COUNT(*) FROM orders) as total_orders,
        (SELECT COUNT(*) FROM orders WHERE status = 'completed' AND completed_at IS NOT NULL) as completed_orders,
        (SELECT COUNT(*) FROM orders WHERE status = 'pending') as pending_orders,
        (SELECT COUNT(*) FROM orders WHERE status IN ('accepted', 'in_progress')) as active_orders,
        (SELECT COUNT(*) FROM orders WHERE status = 'cancelled') as cancelled_orders,
        (SELECT COUNT(*) FROM orders WHERE payment_status = 'paid') as paid_orders,
        (SELECT COUNT(*) FROM orders WHERE payment_status = 'pending') as pending_payments,
        (SELECT COALESCE(SUM(price), 0) FROM orders WHERE payment_status = 'paid') as total_revenue,
        (SELECT COALESCE(AVG(price), 0) FROM orders WHERE status = 'completed' AND price IS NOT NULL) as avg_order_value
")->fetch();

$dailyOrdersStmt = $pdo->query("
    SELECT DATE(created_at) as day, COUNT(*) as total
    FROM orders
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(created_at)
    ORDER BY day
");
$dailyOrdersRaw = $dailyOrdersStmt->fetchAll();

$dailyCompletedStmt = $pdo->query("
    SELECT DATE(completed_at) as day, COUNT(*) as total
    FROM orders
    WHERE status = 'completed'
      AND completed_at IS NOT NULL
      AND completed_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(completed_at)
    ORDER BY day
");
$dailyCompletedRaw = $dailyCompletedStmt->fetchAll();

$dailyRevenueStmt = $pdo->query("
    SELECT DATE(completed_at) as day, COALESCE(SUM(price), 0) as total
    FROM orders
    WHERE payment_status = 'paid'
      AND completed_at IS NOT NULL
      AND completed_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(completed_at)
    ORDER BY day
");
$dailyRevenueRaw = $dailyRevenueStmt->fetchAll();

$rangeDays = [];
for ($i = 6; $i >= 0; $i--) {
    $rangeDays[] = date('Y-m-d', strtotime("-$i days"));
}

$dailyOrders = [];
$dailyCompleted = [];
$dailyRevenue = [];
foreach ($rangeDays as $day) {
    $dailyOrders[$day] = 0;
    $dailyCompleted[$day] = 0;
    $dailyRevenue[$day] = 0;
}

foreach ($dailyOrdersRaw as $row) {
    $dailyOrders[$row['day']] = (int) $row['total'];
}

foreach ($dailyRevenueRaw as $row) {
    $dailyRevenue[$row['day']] = (float) $row['total'];
}

foreach ($dailyCompletedRaw as $row) {
    $dailyCompleted[$row['day']] = (int) $row['total'];
}

$statusBreakdown = $pdo->query("SELECT status, COUNT(*) as total FROM orders GROUP BY status")->fetchAll();
$statusMap = [];
foreach ($statusBreakdown as $row) {
    $statusMap[$row['status']] = $row['total'];
}

$topServices = $pdo->query("
    SELECT service_type, COUNT(*) as total
    FROM orders
    GROUP BY service_type
    ORDER BY total DESC
    LIMIT 5
")->fetchAll();

$total_orders = (int) ($overview['total_orders'] ?? 0);
$completion_rate = $total_orders > 0 ? ($overview['completed_orders'] / $total_orders) * 100 : 0;
$cancellation_rate = $total_orders > 0 ? (($overview['cancelled_orders'] ?? 0) / $total_orders) * 100 : 0;
$payment_rate = $total_orders > 0 ? (($overview['paid_orders'] ?? 0) / $total_orders) * 100 : 0;

$orderLifecycle = $pdo->query("
    SELECT
        COUNT(*) as total_orders,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_orders,
        AVG(CASE WHEN status = 'completed' AND completed_at IS NOT NULL THEN TIMESTAMPDIFF(HOUR, created_at, completed_at) END) as avg_completion_hours
    FROM orders
")->fetch();

$shopPerformance = $pdo->query("
    SELECT
        s.id,
        s.shop_name,
        COUNT(o.id) as total_orders,
        SUM(CASE WHEN o.status = 'completed' THEN 1 ELSE 0 END) as completed_orders,
        SUM(CASE WHEN o.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_orders,
        COALESCE(SUM(CASE WHEN o.status = 'completed' THEN o.price ELSE 0 END), 0) as revenue,
        AVG(CASE WHEN o.status = 'completed' AND o.completed_at IS NOT NULL THEN TIMESTAMPDIFF(HOUR, o.created_at, o.completed_at) END) as avg_completion_hours
    FROM shops s
    LEFT JOIN orders o ON s.id = o.shop_id
    GROUP BY s.id, s.shop_name
    ORDER BY revenue DESC, completed_orders DESC
    LIMIT 5
")->fetchAll();

$staffProductivity = $pdo->query("
    SELECT
        u.id,
        u.fullname,
        COUNT(o.id) as total_assigned,
        SUM(CASE WHEN o.status = 'completed' THEN 1 ELSE 0 END) as completed_orders,
        SUM(CASE WHEN o.status IN ('accepted', 'in_progress') THEN 1 ELSE 0 END) as active_orders,
        SUM(CASE WHEN o.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_orders,
        AVG(CASE WHEN o.status = 'completed' AND o.completed_at IS NOT NULL THEN TIMESTAMPDIFF(HOUR, o.created_at, o.completed_at) END) as avg_completion_hours
    FROM users u
    LEFT JOIN orders o ON o.assigned_to = u.id
    WHERE u.role = 'staff'
    GROUP BY u.id, u.fullname
    ORDER BY completed_orders DESC, active_orders DESC
    LIMIT 5
")->fetchAll();

$totalOrders = (int) ($orderLifecycle['total_orders'] ?? 0);
$cancelledOrders = (int) ($orderLifecycle['cancelled_orders'] ?? 0);
$cancellationRate = $totalOrders > 0 ? ($cancelledOrders / $totalOrders) * 100 : 0;
$avgCompletionHours = $orderLifecycle['avg_completion_hours'] !== null ? (float) $orderLifecycle['avg_completion_hours'] : 0;

$turnaroundSummary = $pdo->query("
    SELECT
        AVG(TIMESTAMPDIFF(HOUR, created_at, completed_at)) as avg_hours,
        MIN(TIMESTAMPDIFF(HOUR, created_at, completed_at)) as min_hours,
        MAX(TIMESTAMPDIFF(HOUR, created_at, completed_at)) as max_hours
    FROM orders
    WHERE status = 'completed' AND completed_at IS NOT NULL
")->fetch();

$turnaroundByService = $pdo->query("
    SELECT
        service_type,
        COUNT(*) as total_orders,
        AVG(TIMESTAMPDIFF(HOUR, created_at, completed_at)) as avg_hours
    FROM orders
    WHERE status = 'completed' AND completed_at IS NOT NULL
    GROUP BY service_type
    ORDER BY avg_hours ASC
    LIMIT 5
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics - System Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .analytics-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 1.5rem;
            margin: 2rem 0;
        }

        .analytics-card {
            grid-column: span 4;
        }

        .chart-card {
            grid-column: span 8;
        }

        .data-card {
            grid-column: span 4;
        }

        .half-card {
            grid-column: span 6;
        }

        .full-card {
            grid-column: span 12;
        }


        .chart-bars {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 0.75rem;
            align-items: flex-end;
            height: 200px;
        }

        .chart-bar {
            background: var(--primary-100);
            border-radius: var(--radius);
            position: relative;
            overflow: hidden;
        }

        .chart-bar .total-bar {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: var(--primary-500);
            border-radius: var(--radius);
        }

        .chart-bar .completed-bar {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: var(--success-500);
            border-radius: var(--radius);
            opacity: 0.85;
        }

        .metric-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--gray-100);
        }

        .metric-row:last-child {
            border-bottom: none;
        }

        .analytics-table {
            width: 100%;
            border-collapse: collapse;
        }

        .analytics-table th,
        .analytics-table td {
            padding: 0.75rem;
            border-bottom: 1px solid var(--gray-100);
            text-align: left;
        }
    </style>
</head>
<body>
    <?php sys_admin_nav('analytics'); ?>

    <div class="container">
        <div class="dashboard-header fade-in">
            <div class="d-flex justify-between align-center">
                <div>
                    <h2>Analytics Overview</h2>
                    <p class="text-muted">Track growth, activity, and revenue trends across the platform.</p>
                </div>
                <div class="d-flex align-center gap-2">
                    <a class="btn btn-outline-primary" href="export_analytics.php?type=orders_turnaround">
                        <i class="fas fa-file-export"></i> Orders CSV
                    </a>
                    <a class="btn btn-outline-primary" href="export_analytics.php?type=staff_performance">
                        <i class="fas fa-file-export"></i> Staff CSV
                    </a>
                    <a class="btn btn-outline-primary" href="export_analytics.php?type=shop_performance">
                        <i class="fas fa-file-export"></i> Shops CSV
                    </a>
                    <span class="badge badge-success"><i class="fas fa-chart-line"></i> Live Metrics</span>
                </div>
                <span class="badge badge-success"><i class="fas fa-chart-line"></i> Live Metrics</span>
            </div>
        </div>

        <div class="analytics-grid">
            <div class="card analytics-card">
                <h4>Total Users</h4>
                <h2><?php echo number_format($overview['total_users'] ?? 0); ?></h2>
                <p class="text-muted">Active accounts across roles.</p>
            </div>
            <div class="card analytics-card">
                <h4>Total Shops</h4>
                <h2><?php echo number_format($overview['total_shops'] ?? 0); ?></h2>
                <p class="text-muted">Registered service providers.</p>
            </div>
            <div class="card analytics-card">
                <h4>Revenue</h4>
                <h2>₱<?php echo number_format($overview['total_revenue'] ?? 0, 2); ?></h2>
                <p class="text-muted">Daily totals (blue) with completed overlays (green).</p>
            </div>

            <div class="card chart-card">
                <div class="card-header">
                    <h3><i class="fas fa-chart-bar text-primary"></i> Orders (Last 7 Days)</h3>
                    <p class="text-muted">Daily completed and total orders.</p>
                </div>
                <div class="chart-bars">
                    <?php foreach ($rangeDays as $day): ?>
                        <?php
                            $total_height = $dailyOrders[$day] > 0 ? min(200, 20 + $dailyOrders[$day] * 10) : 20;
                            $completed_height = $dailyCompleted[$day] > 0 ? min(200, 20 + $dailyCompleted[$day] * 10) : 0;
                        ?>
                        <div class="chart-bar" title="Total: <?php echo $dailyOrders[$day]; ?> | Completed: <?php echo $dailyCompleted[$day]; ?>">
                            <span class="total-bar" style="height: <?php echo $total_height; ?>px;"></span>
                            <?php if($completed_height > 0): ?>
                                <span class="completed-bar" style="height: <?php echo $completed_height; ?>px;"></span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="d-flex justify-between mt-3 text-muted">
                    <?php foreach ($rangeDays as $day): ?>
                        <small><?php echo date('D', strtotime($day)); ?></small>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card data-card">
                <div class="card-header">
                    <h3><i class="fas fa-layer-group text-info"></i> Order Status</h3>
                    <p class="text-muted">Current distribution by status.</p>
                </div>
                <div>
                    <?php foreach (['pending', 'accepted', 'in_progress', 'completed', 'cancelled'] as $status): ?>
                        <div class="metric-row">
                            <span><?php echo ucfirst(str_replace('_', ' ', $status)); ?></span>
                            <strong><?php echo $statusMap[$status] ?? 0; ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card chart-card">
                <div class="card-header">
                    <h3><i class="fas fa-coins text-success"></i> Revenue Trend</h3>
                    <p class="text-muted">Daily revenue from paid orders.</p>
                </div>
                <div class="chart-bars">
                    <?php foreach ($rangeDays as $day): ?>
                        <?php $height = $dailyRevenue[$day] > 0 ? min(200, 20 + ($dailyRevenue[$day] / 100)) : 20; ?>
                        <div class="chart-bar" title="₱<?php echo number_format($dailyRevenue[$day], 2); ?>">
                            <span class="total-bar" style="height: <?php echo $height; ?>px; background: var(--success-500);"></span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="d-flex justify-between mt-3 text-muted">
                    <?php foreach ($rangeDays as $day): ?>
                        <small><?php echo date('D', strtotime($day)); ?></small>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card data-card">
                <div class="card-header">
                    <h3><i class="fas fa-clipboard-check text-success"></i> Order Health</h3>
                    <p class="text-muted">Completion and cancellation accuracy.</p>
                </div>
                <div>
                    <div class="metric-row">
                        <span>Completion Rate</span>
                        <strong><?php echo number_format($completion_rate, 1); ?>%</strong>
                    </div>
                    <div class="metric-row">
                        <span>Cancellation Rate</span>
                        <strong><?php echo number_format($cancellation_rate, 1); ?>%</strong>
                    </div>
                    <div class="metric-row">
                        <span>Avg. Order Value</span>
                        <strong>₱<?php echo number_format($overview['avg_order_value'] ?? 0, 2); ?></strong>
                    </div>
                </div>
            </div>

            <div class="card data-card">
                <div class="card-header">
                    <h3><i class="fas fa-credit-card text-primary"></i> Payments Snapshot</h3>
                    <p class="text-muted">Manual payment verification status.</p>
                </div>
                <div>
                    <div class="metric-row">
                        <span>Paid Orders</span>
                        <strong><?php echo $overview['paid_orders'] ?? 0; ?></strong>
                    </div>
                    <div class="metric-row">
                        <span>Pending Payments</span>
                        <strong><?php echo $overview['pending_payments'] ?? 0; ?></strong>
                    </div>
                    <div class="metric-row">
                        <span>Payment Rate</span>
                        <strong><?php echo number_format($payment_rate, 1); ?>%</strong>
                    </div>
                </div>
            </div>

            <div class="card data-card">
                <div class="card-header">
                    <h3><i class="fas fa-star text-warning"></i> Top Services</h3>
                    <p class="text-muted">Most requested services.</p>
                </div>
                <?php if (empty($topServices)): ?>
                    <p class="text-muted">No services data yet.</p>
                <?php else: ?>
                    <?php foreach ($topServices as $service): ?>
                        <div class="metric-row">
                            <span><?php echo htmlspecialchars($service['service_type'] ?? 'N/A'); ?></span>
                            <strong><?php echo (int) $service['total']; ?></strong>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <div class="card data-card">
                <div class="card-header">
                    <h3><i class="fas fa-route text-primary"></i> Order Lifecycle</h3>
                    <p class="text-muted">Completion time and cancellation insights.</p>
                </div>
                <div>
                    <div class="metric-row">
                        <span>Avg. Completion Time</span>
                        <strong><?php echo number_format($avgCompletionHours, 1); ?> hrs</strong>
                    </div>
                    <div class="metric-row">
                        <span>Cancellation Rate</span>
                        <strong><?php echo number_format($cancellationRate, 1); ?>%</strong>
                    </div>
                    <div class="metric-row">
                        <span>Cancelled Orders</span>
                        <strong><?php echo number_format($cancelledOrders); ?></strong>
                    </div>
                </div>
            </div>
            <div class="card data-card">
                <div class="card-header">
                    <h3><i class="fas fa-stopwatch text-info"></i> Turnaround Time</h3>
                    <p class="text-muted">Speed highlights for completed orders.</p>
                </div>
                <div>
                    <div class="metric-row">
                        <span>Average</span>
                        <strong><?php echo $turnaroundSummary['avg_hours'] !== null ? number_format($turnaroundSummary['avg_hours'], 1) : '—'; ?> hrs</strong>
                    </div>
                    <div class="metric-row">
                        <span>Fastest</span>
                        <strong><?php echo $turnaroundSummary['min_hours'] !== null ? number_format($turnaroundSummary['min_hours'], 1) : '—'; ?> hrs</strong>
                    </div>
                    <div class="metric-row">
                        <span>Slowest</span>
                        <strong><?php echo $turnaroundSummary['max_hours'] !== null ? number_format($turnaroundSummary['max_hours'], 1) : '—'; ?> hrs</strong>
                    </div>
                </div>
            </div>

            <div class="card half-card">
                <div class="card-header">
                    <h3><i class="fas fa-store text-success"></i> Top Shop Performance</h3>
                    <p class="text-muted">Revenue and completion rate by shop.</p>
                </div>
                <?php if (empty($shopPerformance)): ?>
                    <p class="text-muted">No shop performance data yet.</p>
                <?php else: ?>
                    <table class="analytics-table">
                        <thead>
                            <tr>
                                <th>Shop</th>
                                <th>Orders</th>
                                <th>Completed</th>
                                <th>Revenue</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($shopPerformance as $shop): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($shop['shop_name']); ?></td>
                                    <td><?php echo number_format($shop['total_orders']); ?></td>
                                    <td><?php echo number_format($shop['completed_orders']); ?></td>
                                    <td>₱<?php echo number_format($shop['revenue'], 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <div class="card half-card">
                <div class="card-header">
                    <h3><i class="fas fa-user-clock text-info"></i> Staff Performance</h3>
                    <p class="text-muted">Top staffs by output and workload.</p>
                </div>
                <?php if (empty($staffProductivity)): ?>
                    <p class="text-muted">No staff productivity data yet.</p>
                <?php else: ?>
                    <table class="analytics-table">
                        <thead>
                            <tr>
                                <th>staff</th>
                                <th>Assigned</th>
                                <th>Active</th>
                                <th>Completed</th>
                                <th>Cancelled</th>
                                <th>Avg. Completion (hrs)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($staffProductivity as $staff): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($staff['fullname']); ?></td>
                                    <td><?php echo number_format($staff['total_assigned']); ?></td>
                                    <td><?php echo number_format($staff['active_orders']); ?></td>
                                    <td><?php echo number_format($staff['completed_orders']); ?></td>
                                    <td><?php echo number_format($staff['cancelled_orders']); ?></td>
                                    <td><?php echo $staff['avg_completion_hours'] !== null ? number_format($staff['avg_completion_hours'], 1) : '—'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            
            <div class="card full-card">
                <div class="card-header">
                    <h3><i class="fas fa-clock text-primary"></i> Turnaround by Service</h3>
                    <p class="text-muted">Average completion time for the most common services.</p>
                </div>
                <?php if (empty($turnaroundByService)): ?>
                    <p class="text-muted">No turnaround data yet.</p>
                <?php else: ?>
                    <table class="analytics-table">
                        <thead>
                            <tr>
                                <th>Service</th>
                                <th>Completed Orders</th>
                                <th>Avg. Turnaround (hrs)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($turnaroundByService as $service): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($service['service_type'] ?? 'N/A'); ?></td>
                                    <td><?php echo number_format($service['total_orders']); ?></td>
                                    <td><?php echo $service['avg_hours'] !== null ? number_format($service['avg_hours'], 1) : '—'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php sys_admin_footer(); ?>
</body>
</html>
