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
        'danger' => 'badge-danger',
        'system' => 'badge-info',
        'system_update' => 'badge-info',
        'battery_warning' => 'badge-warning'
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
     <?php require_once __DIR__ . '/includes/customer_navbar.php'; ?>

    <div class="container">
        <div class="dashboard-header">
            <h2>Updates & Alerts</h2>
            <p class="text-muted">See customer activity notifications and system alerts like updates and battery warnings.</p>
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


