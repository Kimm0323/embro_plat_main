<?php
session_start();
require_once '../config/db.php';
require_role('owner');

$owner_id = $_SESSION['user']['id'];
$shop_stmt = $pdo->prepare("SELECT * FROM shops WHERE owner_id = ?");
$shop_stmt->execute([$owner_id]);
$shop = $shop_stmt->fetch();

if(!$shop) {
    header("Location: create_shop.php");
    exit();
}

$shop_id = $shop['id'];

$summary_stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as completed_orders,
        SUM(price) as total_earnings,
        AVG(price) as average_order
    FROM orders
    WHERE shop_id = ? AND status = 'completed' AND completed_at IS NOT NULL
");
$summary_stmt->execute([$shop_id]);
$summary = $summary_stmt->fetch();

$monthly_stmt = $pdo->prepare("
    SELECT DATE_FORMAT(created_at, '%Y-%m') as month, SUM(price) as total
    FROM orders
    WHERE shop_id = ? AND status = 'completed' AND completed_at IS NOT NULL
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
    ORDER BY month DESC
    LIMIT 6
");
$monthly_stmt->execute([$shop_id]);
$monthly = $monthly_stmt->fetchAll();

$recent_stmt = $pdo->prepare("
    SELECT o.*, u.fullname as client_name
    FROM orders o
    JOIN users u ON o.client_id = u.id
    WHERE o.shop_id = ? AND o.status = 'completed' AND o.completed_at IS NOT NULL
    ORDER BY o.completed_at DESC, o.created_at DESC
    LIMIT 10
");
$recent_stmt->execute([$shop_id]);
$recent_orders = $recent_stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Earnings - <?php echo htmlspecialchars($shop['shop_name']); ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        .summary-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }
        .summary-card h3 {
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="container d-flex justify-between align-center">
            <a href="dashboard.php" class="navbar-brand">
                <i class="fas fa-store"></i> <?php echo htmlspecialchars($shop['shop_name']); ?>
            </a>
            <ul class="navbar-nav">
                <li><a href="dashboard.php" class="nav-link">Dashboard</a></li>
                <li><a href="shop_profile.php" class="nav-link">Shop Profile</a></li>
                <li><a href="manage_staff.php" class="nav-link">Staff</a></li>
                <li><a href="shop_orders.php" class="nav-link">Orders</a></li>
                <li><a href="reviews.php" class="nav-link">Reviews</a></li>
                <li><a href="messages.php" class="nav-link">Messages</a></li>
                <li><a href="payment_verifications.php" class="nav-link">Payments</a></li>
                <li><a href="earnings.php" class="nav-link active">Earnings</a></li>
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

    <div class="container">
        <div class="dashboard-header">
            <h2>Earnings Overview</h2>
            <p class="text-muted">Track completed orders and revenue performance.</p>
        </div>

        <div class="summary-grid">
            <div class="summary-card">
                <h3>Total Earnings</h3>
                <p class="stat-number">₱<?php echo number_format($summary['total_earnings'] ?? 0, 2); ?></p>
                <p class="text-muted">From completed orders</p>
            </div>
            <div class="summary-card">
                <h3>Completed Orders</h3>
                <p class="stat-number"><?php echo $summary['completed_orders'] ?? 0; ?></p>
                <p class="text-muted">Total fulfilled requests</p>
            </div>
            <div class="summary-card">
                <h3>Average Order Value</h3>
                <p class="stat-number">₱<?php echo number_format($summary['average_order'] ?? 0, 2); ?></p>
                <p class="text-muted">Based on completed orders</p>
            </div>
        </div>

        <div class="card mb-4">
            <h3>Recent Earnings (Last 6 Months)</h3>
            <?php if(!empty($monthly)): ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Month</th>
                            <th>Total Earnings</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($monthly as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars(date('F Y', strtotime($row['month'] . '-01'))); ?></td>
                                <td>₱<?php echo number_format($row['total'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="text-muted">No completed orders yet to summarize.</p>
            <?php endif; ?>
        </div>

        <div class="card">
            <h3>Completed Orders</h3>
            <?php if(!empty($recent_orders)): ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Order #</th>
                            <th>Client</th>
                            <th>Service</th>
                            <th>Amount</th>
                            <th>Completed</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($recent_orders as $order): ?>
                            <tr>
                                <td>#<?php echo htmlspecialchars($order['order_number']); ?></td>
                                <td><?php echo htmlspecialchars($order['client_name']); ?></td>
                                <td><?php echo htmlspecialchars($order['service_type']); ?></td>
                                <td>₱<?php echo number_format($order['price'], 2); ?></td>
                                <td><?php echo date('M d, Y', strtotime($order['completed_at'] ?? $order['created_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="text-center p-4">
                    <i class="fas fa-chart-line fa-3x text-muted mb-3"></i>
                    <h4>No Earnings Yet</h4>
                    <p class="text-muted">Completed orders will appear here once delivered.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
