<?php
session_start();
require_once '../config/db.php';
require_role('owner');

$owner_id = $_SESSION['user']['id'];
$success = '';

$notification_options = [
    'order_status' => 'Order status updates',
    'payment' => 'Payment updates',
    'info' => 'General information',
    'success' => 'Success confirmations',
    'warning' => 'Warnings & issues',
    'danger' => 'Critical alerts',
    'security_alert' => 'Security alerts'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $enabled_keys = $_POST['preferences'] ?? [];
    foreach ($notification_options as $event_key => $label) {
        $enabled = in_array($event_key, $enabled_keys, true) ? 1 : 0;
        $pref_stmt = $pdo->prepare("SELECT id FROM notification_preferences WHERE user_id = ? AND event_key = ? AND channel = 'in_app' LIMIT 1");
        $pref_stmt->execute([$owner_id, $event_key]);
        $pref_id = $pref_stmt->fetchColumn();

        if ($pref_id) {
            $update_stmt = $pdo->prepare("UPDATE notification_preferences SET enabled = ? WHERE id = ?");
            $update_stmt->execute([$enabled, $pref_id]);
        } else {
            $insert_stmt = $pdo->prepare("INSERT INTO notification_preferences (user_id, event_key, channel, enabled) VALUES (?, ?, 'in_app', ?)");
            $insert_stmt->execute([$owner_id, $event_key, $enabled]);
        }
    }
    $success = 'Notification preferences updated.';
}

$preferences = array_fill_keys(array_keys($notification_options), 1);
$pref_stmt = $pdo->prepare("SELECT event_key, enabled FROM notification_preferences WHERE user_id = ? AND channel = 'in_app'");
$pref_stmt->execute([$owner_id]);
foreach ($pref_stmt->fetchAll() as $row) {
    if (array_key_exists($row['event_key'], $preferences)) {
        $preferences[$row['event_key']] = (int) $row['enabled'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notification Preferences</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .preference-card {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 18px;
            background: #fff;
            margin-bottom: 14px;
        }
        .toggle {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 26px;
        }
        .toggle input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .slider {
            position: absolute;
            cursor: pointer;
            inset: 0;
            background-color: #cbd5f5;
            transition: 0.2s;
            border-radius: 30px;
        }
        .slider:before {
            position: absolute;
            content: '';
            height: 20px;
            width: 20px;
            left: 4px;
            bottom: 3px;
            background-color: #fff;
            transition: 0.2s;
            border-radius: 50%;
        }
        .toggle input:checked + .slider {
            background-color: #4f46e5;
        }
        .toggle input:checked + .slider:before {
            transform: translateX(22px);
        }
    </style>
</head>
<body>
    <nav class="navbar navbar--compact">
        <div class="container d-flex justify-between align-center">
            <a href="dashboard.php" class="navbar-brand">
                <i class="fas fa-store"></i> Owner Portal
            </a>
            <ul class="navbar-nav">
                <li><a href="dashboard.php" class="nav-link">Dashboard</a></li>
                <li><a href="notification_preferences.php" class="nav-link active">Preferences</a></li>
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
            <h2>Notification Preferences</h2>
            <p class="text-muted">Control the alerts and updates shown in your owner workspace.</p>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div>
        <?php endif; ?>

        <form method="POST">
            <?php echo csrf_field(); ?>
            <?php foreach ($notification_options as $event_key => $label): ?>
                <div class="preference-card d-flex justify-between align-center">
                    <div>
                        <strong><?php echo htmlspecialchars($label); ?></strong>
                        <p class="text-muted mb-0">Control whether <?php echo htmlspecialchars(strtolower($label)); ?> are shown.</p>
                    </div>
                    <label class="toggle">
                        <input type="checkbox" name="preferences[]" value="<?php echo htmlspecialchars($event_key); ?>" <?php echo $preferences[$event_key] ? 'checked' : ''; ?>>
                        <span class="slider"></span>
                    </label>
                </div>
            <?php endforeach; ?>
            <button type="submit" class="btn btn-primary">Save preferences</button>
        </form>
    </div>
</body>
</html>
