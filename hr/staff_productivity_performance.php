<?php
session_start();
require_once '../config/db.php';
require_role('hr');

$hr_id = $_SESSION['user']['id'];
$hr_name = htmlspecialchars($_SESSION['user']['fullname'] ?? 'HR Lead');

$hr_stmt = $pdo->prepare("
    SELECT se.shop_id, s.shop_name
    FROM shop_staffs se
    JOIN shops s ON se.shop_id = s.id
    WHERE se.user_id = ? AND se.staff_role = 'hr' AND se.status = 'active'
");
$hr_stmt->execute([$hr_id]);
$hr_shop = $hr_stmt->fetch();

if (!$hr_shop) {
    die("You are not assigned to any shop as HR. Please contact your shop owner.");
}

$shop_id = (int) $hr_shop['shop_id'];
$shop_name = $hr_shop['shop_name'];

$total_orders_stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE shop_id = ?");
$total_orders_stmt->execute([$shop_id]);
$total_orders = (int) $total_orders_stmt->fetchColumn();

$completed_orders_stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE shop_id = ? AND status = 'completed'");
$completed_orders_stmt->execute([$shop_id]);
$completed_orders = (int) $completed_orders_stmt->fetchColumn();

$completion_rate = $total_orders > 0 ? round(($completed_orders / $total_orders) * 100, 1) : 0.0;

$avg_cycle_stmt = $pdo->prepare("
    SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, completed_at))
    FROM orders
    WHERE shop_id = ? AND status = 'completed' AND completed_at IS NOT NULL
");
$avg_cycle_stmt->execute([$shop_id]);
$avg_cycle_hours = $avg_cycle_stmt->fetchColumn();

$failure_stmt = $pdo->prepare("
    SELECT
        SUM(CASE WHEN ofh.status = 'failed' THEN 1 ELSE 0 END) AS failed_count,
        COUNT(*) AS total_count
    FROM order_fulfillment_history ofh
    JOIN order_fulfillments ofl ON ofh.fulfillment_id = ofl.id
    JOIN orders o ON ofl.order_id = o.id
    WHERE o.shop_id = ?
");
$failure_stmt->execute([$shop_id]);
$failure_stats = $failure_stmt->fetch();
$qc_failed = (int) ($failure_stats['failed_count'] ?? 0);
$qc_total = (int) ($failure_stats['total_count'] ?? 0);
$qc_rate = $qc_total > 0 ? round(($qc_failed / $qc_total) * 100, 1) : 0.0;

$output_stmt = $pdo->prepare("SELECT SUM(quantity) FROM orders WHERE shop_id = ? AND status = 'completed'");
$output_stmt->execute([$shop_id]);
$output_volume = (int) ($output_stmt->fetchColumn() ?? 0);

$qc_by_staff_stmt = $pdo->prepare("
    SELECT o.assigned_to AS staff_user_id, COUNT(*) AS qc_failures
    FROM order_fulfillment_history ofh
    JOIN order_fulfillments ofl ON ofh.fulfillment_id = ofl.id
    JOIN orders o ON ofl.order_id = o.id
    WHERE o.shop_id = ?
      AND ofh.status = 'failed'
      AND o.assigned_to IS NOT NULL
    GROUP BY o.assigned_to
");
$qc_by_staff_stmt->execute([$shop_id]);
$qc_by_staff = $qc_by_staff_stmt->fetchAll();
$qc_map = [];
foreach ($qc_by_staff as $row) {
    $qc_map[(int) $row['staff_user_id']] = (int) $row['qc_failures'];
}

$performance_stmt = $pdo->prepare("
    SELECT u.id AS user_id,
           u.fullname,
           COUNT(CASE WHEN o.status = 'completed' THEN 1 END) AS completed_orders,
           COUNT(CASE WHEN o.status IN ('pending', 'accepted', 'in_progress') THEN 1 END) AS active_orders,
           AVG(CASE WHEN o.status = 'completed' AND o.completed_at IS NOT NULL THEN TIMESTAMPDIFF(HOUR, o.created_at, o.completed_at) END) AS avg_cycle_hours,
           SUM(CASE WHEN o.status = 'completed' THEN o.quantity ELSE 0 END) AS output_qty
    FROM shop_staffs ss
    JOIN users u ON ss.user_id = u.id
    LEFT JOIN orders o ON o.assigned_to = u.id AND o.shop_id = ?
    WHERE ss.shop_id = ?
      AND ss.staff_role = 'staff'
      AND ss.status = 'active'
    GROUP BY u.id
    ORDER BY completed_orders DESC, output_qty DESC
");
$performance_stmt->execute([$shop_id, $shop_id]);
$team_performance = $performance_stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Productivity &amp; Performance Module</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .performance-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 1.5rem;
            margin: 2rem 0;
        }

        .performance-kpi {
            grid-column: span 3;
        }

        .performance-kpi .metric {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }

        .performance-kpi .metric i {
            font-size: 1.5rem;
        }

        .purpose-card,
        .workflow-card {
            grid-column: span 12;
        }

        .team-performance-card {
            grid-column: span 8;
        }

        .insights-card,
        .anomalies-card {
            grid-column: span 4;
        }

        .automation-card {
            grid-column: span 6;
        }

        .metric-row {
            display: flex;
            gap: 1rem;
            justify-content: space-between;
            border-bottom: 1px solid var(--gray-200);
            padding: 0.75rem 0;
        }

        .metric-row:last-child {
            border-bottom: none;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar--compact">
        <div class="container d-flex justify-between align-center">
            <a href="dashboard.php" class="navbar-brand">
                <i class="fas fa-people-group"></i> <?php echo $hr_name; ?>
            </a>
            <ul class="navbar-nav">
                <li><a href="dashboard.php" class="nav-link">Dashboard</a></li>
                <li><a href="hiring_management.php" class="nav-link">Hiring</a></li>
                <li><a href="staff_productivity_performance.php" class="nav-link active">Productivity</a></li>
                <li><a href="payroll_compensation.php" class="nav-link">Payroll</a></li>
                <li><a href="analytics_reporting.php" class="nav-link">Analytics</a></li>
                <li><a href="../auth/logout.php" class="nav-link">Logout</a></li>
            </ul>
        </div>
    </nav>

    <main class="container">
        <section class="page-header">
            <div>
                <h1>Staff Productivity &amp; Performance</h1>
                <p class="text-muted">Operational productivity snapshot for <?php echo htmlspecialchars($shop_name); ?>.</p>
            </div>
            <span class="badge">Logged in as <?php echo $hr_name; ?></span>
        </section>

        <section class="performance-grid">
            <div class="card performance-kpi">
                <div class="metric">
                    <div>
                        <h3>Completion rate</h3>
                        <p class="value"><?php echo $completion_rate; ?>%</p>
                        <p class="text-muted"><?php echo $completed_orders; ?> of <?php echo $total_orders; ?> orders</p>
                    </div>
                    <i class="fas fa-check-double text-success"></i>
                </div>
            </div>
            <div class="card performance-kpi">
                <div class="metric">
                    <div>
                        <h3>Avg. cycle time</h3>
                        <p class="value"><?php echo $avg_cycle_hours !== null ? number_format((float) $avg_cycle_hours, 1) : '—'; ?> hrs</p>
                        <p class="text-muted">Completed orders</p>
                    </div>
                    <i class="fas fa-stopwatch text-primary"></i>
                </div>
            </div>
            <div class="card performance-kpi">
                <div class="metric">
                    <div>
                        <h3>QC failure rate</h3>
                        <p class="value"><?php echo $qc_rate; ?>%</p>
                        <p class="text-muted"><?php echo $qc_failed; ?> failures logged</p>
                    </div>
                    <i class="fas fa-triangle-exclamation text-warning"></i>
                </div>
            </div>
            <div class="card performance-kpi">
                <div class="metric">
                    <div>
                        <h3>Output volume</h3>
                        <p class="value"><?php echo number_format($output_volume); ?> pcs</p>
                        <p class="text-muted">Completed units</p>
                    </div>
                    <i class="fas fa-box-open text-info"></i>
                </div>
            </div>

            <div class="card team-performance-card">
                <h2>Orders completed per staff</h2>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Staff</th>
                                <th>Completed orders</th>
                                <th>Active orders</th>
                                <th>Avg. cycle time (hrs)</th>
                                <th>Output volume</th>
                                <th>QC failures</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($team_performance)): ?>
                                <tr>
                                    <td colspan="6">No staff performance data available.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($team_performance as $member): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($member['fullname']); ?></td>
                                        <td><?php echo (int) $member['completed_orders']; ?></td>
                                        <td><?php echo (int) $member['active_orders']; ?></td>
                                        <td><?php echo $member['avg_cycle_hours'] !== null ? number_format((float) $member['avg_cycle_hours'], 1) : '—'; ?></td>
                                        <td><?php echo number_format((int) ($member['output_qty'] ?? 0)); ?></td>
                                        <td><?php echo $qc_map[(int) $member['user_id']] ?? 0; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card insights-card">
                <h2>Performance highlights</h2>
                <div class="metric-row">
                    <div>
                        <strong>Top output staff</strong>
                        <p class="text-muted">Based on completed order volume.</p>
                    </div>
                <div>
                        <?php echo !empty($team_performance) ? htmlspecialchars($team_performance[0]['fullname']) : '—'; ?>
                    </div>
                </div>
                <div class="metric-row">
                    <div>
                        <strong>QC watchlist</strong>
                        <p class="text-muted">Staff with most QC failures.</p>
                    </div>
                    <div>
                        <?php
                        $qc_watch_name = '—';
                        $qc_watch_count = -1;
                        foreach ($team_performance as $member) {
                            $failures = $qc_map[(int) $member['user_id']] ?? 0;
                            if ($failures > $qc_watch_count) {
                                $qc_watch_count = $failures;
                                $qc_watch_name = $member['fullname'];
                            }
                        }
                        echo htmlspecialchars($qc_watch_name);
                        ?>
                    </div>
                </div>
                <div class="metric-row">
                    <div>
                        <strong>Average cycle time</strong>
                        <p class="text-muted">Across completed orders.</p>
                    </div>
                    <div><?php echo $avg_cycle_hours !== null ? number_format((float) $avg_cycle_hours, 1) . ' hrs' : '—'; ?></div>
                </div>
            </div>

            <div class="card anomalies-card">
                <h2>Operational notes</h2>
                <ul class="list">
                    <li>Review staff with zero completed orders to balance assignments.</li>
                    <li>QC failures are based on failed fulfillment history entries.</li>
                    <li>Cycle times use order creation to completion timestamps.</li>
                </ul>
            </div>
        </section>
    </main>
</body>
</html>
