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

$expire_stmt = $pdo->prepare("
    UPDATE hiring_posts
    SET status = 'expired'
    WHERE shop_id = ?
      AND expires_at IS NOT NULL
      AND expires_at < NOW()
      AND status IN ('draft', 'live')
");
$expire_stmt->execute([$shop_id]);

$open_posts_stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM hiring_posts
    WHERE shop_id = ?
      AND status = 'live'
      AND (expires_at IS NULL OR expires_at >= NOW())
");
$open_posts_stmt->execute([$shop_id]);
$open_hiring_posts = (int) $open_posts_stmt->fetchColumn();

$payroll_pending_stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM payroll p
    JOIN staffs s ON p.staff_id = s.id
    JOIN users u ON s.user_id = u.id
    JOIN shop_staffs ss ON ss.user_id = u.id
    WHERE ss.shop_id = ?
      AND ss.status = 'active'
      AND p.status = 'pending'
");
$payroll_pending_stmt->execute([$shop_id]);
$payroll_pending = (int) $payroll_pending_stmt->fetchColumn();

$low_stock_stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM raw_materials
    WHERE shop_id = ?
      AND status = 'active'
      AND min_stock_level IS NOT NULL
      AND current_stock <= min_stock_level
");
$low_stock_stmt->execute([$shop_id]);
$low_stock_alerts = (int) $low_stock_stmt->fetchColumn();

$staff_snapshot_stmt = $pdo->prepare("
    SELECT u.fullname,
           COUNT(o.id) AS completed_orders,
           AVG(TIMESTAMPDIFF(HOUR, o.created_at, o.completed_at)) AS avg_cycle_hours
    FROM orders o
    JOIN users u ON o.assigned_to = u.id
    JOIN shop_staffs ss ON ss.user_id = u.id
    WHERE o.shop_id = ?
      AND o.status = 'completed'
      AND ss.shop_id = ?
      AND ss.status = 'active'
    GROUP BY u.id
    ORDER BY completed_orders DESC
    LIMIT 5
");
$staff_snapshot_stmt->execute([$shop_id, $shop_id]);
$staff_snapshot = $staff_snapshot_stmt->fetchAll();

$pending_payroll_value_stmt = $pdo->prepare("
    SELECT SUM(p.net_salary)
    FROM payroll p
    JOIN staffs s ON p.staff_id = s.id
    JOIN users u ON s.user_id = u.id
    JOIN shop_staffs ss ON ss.user_id = u.id
    WHERE ss.shop_id = ?
      AND ss.status = 'active'
      AND p.status = 'pending'
");
$pending_payroll_value_stmt->execute([$shop_id]);
$pending_payroll_value = $pending_payroll_value_stmt->fetchColumn();
$pending_payroll_value = $pending_payroll_value ? number_format((float) $pending_payroll_value, 2) : '0.00';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HR Dashboard</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 1.5rem;
            margin: 2rem 0;
        }

        .kpi-card {
            grid-column: span 3;
        }

        .kpi-card .metric {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }

        .kpi-card .metric i {
            font-size: 1.5rem;
        }

        .snapshot-card {
            grid-column: span 7;
        }

        .alerts-card {
            grid-column: span 5;
        }

        .snapshot-row {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--gray-200);
        }

        .snapshot-row:last-child {
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
                <li><a href="dashboard.php" class="nav-link active">Dashboard</a></li>
                <li><a href="hiring_management.php" class="nav-link">Hiring</a></li>
                <li><a href="staff_productivity_performance.php" class="nav-link">Productivity</a></li>
                <li><a href="payroll_compensation.php" class="nav-link">Payroll</a></li>
                <li><a href="analytics_reporting.php" class="nav-link">Analytics</a></li>
                <li><a href="../auth/logout.php" class="nav-link">Logout</a></li>
            </ul>
        </div>
    </nav>

    <main class="container">
        <section class="page-header">
            <div>
                <h1>HR Dashboard</h1>
                <p class="text-muted">Live signals for <?php echo htmlspecialchars($shop_name); ?> hiring, payroll, and staff performance.</p>
            </div>
            <span class="badge">Logged in as <?php echo $hr_name; ?></span>
        </section>

        <section class="dashboard-grid">
            <div class="card kpi-card">
                <div class="metric">
                    <div>
                        <h3>Open hiring posts</h3>
                        <p class="value"><?php echo $open_hiring_posts; ?></p>
                        <p class="text-muted">Live listings</p>
                    </div>
                    <i class="fas fa-briefcase text-primary"></i>
                </div>
            </div>
            <div class="card kpi-card">
                <div class="metric">
                    <div>
                        <h3>Payroll pending</h3>
                        <p class="value"><?php echo $payroll_pending; ?></p>
                        <p class="text-muted">Total ₱<?php echo $pending_payroll_value; ?></p>
                    </div>
                    <i class="fas fa-coins text-warning"></i>
                </div>
            </div>
            <div class="card kpi-card">
                <div class="metric">
                    <div>
                        <h3>Low-stock alerts</h3>
                        <p class="value"><?php echo $low_stock_alerts; ?></p>
                        <p class="text-muted">Materials below minimum</p>
                    </div>
                    <i class="fas fa-triangle-exclamation text-danger"></i>
                </div>
            </div>
            <div class="card kpi-card">
                <div class="metric">
                    <div>
                        <h3>Top performers</h3>
                        <p class="value"><?php echo count($staff_snapshot); ?></p>
                        <p class="text-muted">Staff snapshot</p>
                    </div>
                    <i class="fas fa-star text-success"></i>
                </div>
            </div>

            <div class="card snapshot-card">
                <h2>Staff performance snapshot</h2>
                <?php if (empty($staff_snapshot)): ?>
                    <p class="text-muted">No completed orders tracked yet.</p>
                <?php else: ?>
                    <?php foreach ($staff_snapshot as $staff): ?>
                        <div class="snapshot-row">
                            <div>
                                <strong><?php echo htmlspecialchars($staff['fullname']); ?></strong>
                                <div class="text-muted"><?php echo (int) $staff['completed_orders']; ?> completed orders</div>
                            </div>
                            <div>
                                <strong><?php echo $staff['avg_cycle_hours'] !== null ? number_format((float) $staff['avg_cycle_hours'], 1) : '—'; ?> hrs</strong>
                                <div class="text-muted">Avg cycle</div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="card alerts-card">
                <h2>Quick actions</h2>
                <ul class="list">
                    <li><a href="hiring_management.php">Review hiring posts</a></li>
                    <li><a href="payroll_compensation.php">Generate payroll for new period</a></li>
                    <li><a href="staff_productivity_performance.php">Check staff productivity</a></li>
                </ul>
            </div>
        </section>
    </main>
</body>
</html>
