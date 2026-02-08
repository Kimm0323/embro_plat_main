<?php
session_start();
require_once '../config/db.php';
require_role('client');

$client_id = $_SESSION['user']['id'];
$unread_notifications = fetch_unread_notification_count($pdo, $client_id);
$success = '';

if(isset($_POST['mark_all_read'])) {
    $mark_stmt = $pdo->prepare("UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL");



    $mark_stmt->execute([$client_id]);
    $unread_notifications = 0;
    $success = 'All notifications marked as read.';
}

$notifications_stmt = $pdo->prepare("
    SELECT *
    FROM notifications
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT 50
");
$notifications_stmt->execute([$client_id]);
$notifications = $notifications_stmt->fetchAll();

function notification_badge($type) {
    $map = [
        'info' => 'badge-info',
        'success' => 'badge-success',
        'warning' => 'badge-warning',
        'danger' => 'badge-danger'
    ];
    $class = $map[$type] ?? 'badge-secondary';
    return '<span class="badge ' . $class . '">' . ucfirst($type) . '</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .notification-card {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 18px;
            background: #fff;
            margin-bottom: 14px;
        }
        .notification-card.unread {
            border-left: 4px solid #4f46e5;
            background: #f8fafc;
        }
        .notification-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar--compact">
        <div class="container d-flex justify-between align-center">
            <a href="dashboard.php" class="navbar-brand">

                <i class="fas fa-user"></i> Client Portal

            </a>
            <ul class="navbar-nav">
                <li><a href="dashboard.php" class="nav-link">Dashboard</a></li>
                <li class="dropdown">
                    <a href="#" class="nav-link dropdown-toggle">
                        <i class="fas fa-clipboard-list"></i> Orders
                    </a>
                    <div class="dropdown-menu">
                        <a href="place_order.php" class="dropdown-item"><i class="fas fa-plus-circle"></i> Place Order</a>
                        <a href="track_order.php" class="dropdown-item"><i class="fas fa-route"></i> Track Orders</a>
                    </div>
                </li>
                <li class="dropdown">
                    <a href="#" class="nav-link dropdown-toggle">
                        <i class="fas fa-layer-group"></i> Services
                    </a>
                    <div class="dropdown-menu">
                        <a href="customize_design.php" class="dropdown-item"><i class="fas fa-paint-brush"></i> Customize Design</a>
                        <a href="rate_provider.php" class="dropdown-item"><i class="fas fa-star"></i> Rate Provider</a>
                        <a href="search_discovery.php" class="dropdown-item"><i class="fas fa-compass"></i> Search &amp; Discovery</a>
                        <a href="design_proofing.php" class="dropdown-item"><i class="fas fa-clipboard-check"></i> Design Proofing &amp; Approval</a>
                        <a href="pricing_quotation.php" class="dropdown-item"><i class="fas fa-calculator"></i> Pricing &amp; Quotation</a>
                        <a href="order_management.php" class="dropdown-item"><i class="fas fa-clipboard-list"></i> Order Management</a>
                        <a href="payment_handling.php" class="dropdown-item"><i class="fas fa-hand-holding-dollar"></i> Payment Handling &amp; Release</a>
                        <a href="client_posting_community.php" class="dropdown-item"><i class="fas fa-comments"></i> Client Posting &amp; Community</a>
                    </div>
                </li>
                <li><a href="messages.php" class="nav-link">Messages</a></li>
                <li><a href="notifications.php" class="nav-link active">Notifications
                    <?php if($unread_notifications > 0): ?>
                        <span class="badge badge-danger"><?php echo $unread_notifications; ?></span>
                    <?php endif; ?>
                </a></li>
                <li class="dropdown">
                    <a href="#" class="nav-link dropdown-toggle">
                        <i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($_SESSION['user']['fullname']); ?>
                    </a>
                    <div class="dropdown-menu">
                        <a href="../auth/logout.php" class="dropdown-item"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </li>

            </ul>
        </div>
    </nav>

    <div class="container">
        <div class="dashboard-header">
            <h2>Updates & Alerts</h2>
            <p class="text-muted">Stay informed about order acceptance, status changes, and completions.</p>
        </div>

        <?php if($success): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div>
        <?php endif; ?>

        <div class="card mb-3">
            <div class="d-flex justify-between align-center">
                <strong><?php echo $unread_notifications; ?> unread notifications</strong>
                <form method="POST">
                    <?php echo csrf_field(); ?>
                    <button type="submit" name="mark_all_read" class="btn btn-outline-primary btn-sm">
                        <i class="fas fa-check-double"></i> Mark all as read
                    </button>
                </form>
            </div>
        </div>

        <?php if(!empty($notifications)): ?>
            <?php foreach($notifications as $notification): ?>
                <div class="notification-card <?php echo $notification['read_at'] ? '' : 'unread'; ?>">
                    <div class="notification-meta">
                        <div>
                            <strong><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $notification['type']))); ?></strong>


                            <?php echo notification_badge($notification['type']); ?>
                        </div>
                        <span class="text-muted small"><?php echo date('M d, Y h:i A', strtotime($notification['created_at'])); ?></span>
                    </div>
                    <p class="text-muted mb-0"><?php echo htmlspecialchars($notification['message']); ?></p>
                    <?php if(!empty($notification['order_id'])): ?>
                        <div class="text-muted small mt-2">Order #<?php echo htmlspecialchars($notification['order_id']); ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="card">
                <div class="text-center p-4">
                    <i class="fas fa-bell-slash fa-3x text-muted mb-3"></i>
                    <h4>No Notifications Yet</h4>
                    <p class="text-muted">Updates will appear here once your orders change status.</p>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>


