<?php
session_start();
require_once '../config/db.php';
require_once 'partials.php';
require_role('sys_admin');

$defaultSettings = [
    'maintenance_mode' => false,
    'new_registrations' => true,
    'auto_approvals' => false,
    'email_notifications' => true,
    'backup_schedule' => 'daily',
    'alert_threshold' => 80,
];

$settings = $defaultSettings;
$settingKeys = array_keys($defaultSettings);
$placeholders = implode(',', array_fill(0, count($settingKeys), '?'));
$settingsStmt = $pdo->prepare("
    SELECT setting_key, setting_value
    FROM system_settings
    WHERE setting_key IN ($placeholders)
");
$settingsStmt->execute($settingKeys);
$storedSettings = $settingsStmt->fetchAll();
$storedKeys = [];

foreach ($storedSettings as $row) {
    $key = $row['setting_key'];
    $storedKeys[$key] = true;
    $value = $row['setting_value'];

    if (in_array($key, ['maintenance_mode', 'new_registrations', 'auto_approvals', 'email_notifications'], true)) {
        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $settings[$key] = $parsed === null ? (bool) $value : $parsed;
    } elseif ($key === 'alert_threshold') {
        $settings[$key] = (int) $value;
    } else {
        $settings[$key] = $value;
    }
}

$userId = $_SESSION['user']['id'] ?? null;
$userRole = $_SESSION['user']['role'] ?? null;

$insertStmt = $pdo->prepare("
    INSERT INTO system_settings (setting_key, setting_value, updated_by)
    VALUES (?, ?, ?)
    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)
");

foreach ($defaultSettings as $key => $value) {
    if (!isset($storedKeys[$key])) {
        $insertStmt->execute([$key, is_bool($value) ? (int) $value : (string) $value, $userId]);
    }
}

$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'update_settings';

    if ($action === 'update_settings') {
        $previousSettings = $settings;
        $settings['maintenance_mode'] = isset($_POST['maintenance_mode']);
        $settings['new_registrations'] = isset($_POST['new_registrations']);
        $settings['auto_approvals'] = isset($_POST['auto_approvals']);
        $settings['email_notifications'] = isset($_POST['email_notifications']);
        $settings['backup_schedule'] = sanitize($_POST['backup_schedule'] ?? 'daily');
        $settings['alert_threshold'] = (int) ($_POST['alert_threshold'] ?? 80);

        foreach ($settings as $key => $value) {
            $insertStmt->execute([$key, is_bool($value) ? (int) $value : (string) $value, $userId]);
        }

        log_audit(
            $pdo,
            $userId,
            $userRole,
            'update_system_settings',
            'system_settings',
            null,
            $previousSettings,
            $settings
        );
        $message = 'System controls updated successfully.';
    } else {
        log_audit(
            $pdo,
            $userId,
            $userRole,
            'run_system_task',
            'system_task',
            null,
            [],
            ['task' => $action]
        );
        $message = 'System task queued: ' . ucfirst(str_replace('_', ' ', $action)) . '.';
    }
}

$systemStatus = [
    ['label' => 'Authentication Service', 'status' => 'Operational', 'badge' => 'success'],
    ['label' => 'Order Processing', 'status' => 'Operational', 'badge' => 'success'],
    ['label' => 'Notification Queue', 'status' => 'Monitoring', 'badge' => 'warning'],
    ['label' => 'Payment Gateway', 'status' => 'Operational', 'badge' => 'success'],
];

$monitoringOverview = [
    ['label' => 'Platform Uptime', 'value' => '99.96%', 'trend' => 'Stable', 'status' => 'success'],
    ['label' => 'API Latency', 'value' => '182 ms', 'trend' => 'Within SLA', 'status' => 'info'],
    ['label' => 'Error Rate', 'value' => '0.9%', 'trend' => 'Down 0.3%', 'status' => 'success'],
    ['label' => 'Queue Backlog', 'value' => '37 jobs', 'trend' => 'Needs attention', 'status' => 'warning'],
];

$controlCoverage = [
    ['area' => 'User Access', 'coverage' => 'Complete', 'detail' => 'RBAC enforced, MFA pending rollout.', 'status' => 'success'],
    ['area' => 'Order & Fulfillment', 'coverage' => 'Complete', 'detail' => 'Automated alerts on stalled orders.', 'status' => 'success'],
    ['area' => 'Payments', 'coverage' => 'Partial', 'detail' => 'Monitor chargebacks weekly.', 'status' => 'warning'],
    ['area' => 'Infrastructure', 'coverage' => 'Partial', 'detail' => 'Add proactive scaling triggers.', 'status' => 'warning'],
    ['area' => 'Data Protection', 'coverage' => 'Complete', 'detail' => 'Backup + retention policies active.', 'status' => 'success'],
];

$activeAlerts = [
    ['title' => 'Queue backlog above threshold', 'detail' => 'Current backlog is 37 jobs (limit 30).', 'time' => '10 mins ago', 'status' => 'warning'],
    ['title' => 'Payment gateway latency spike', 'detail' => 'Latency peaked at 420 ms at 9:40 AM.', 'time' => '1 hour ago', 'status' => 'info'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Control - System Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .control-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 1.5rem;
            margin: 2rem 0;
        }

        .control-card {
            grid-column: span 7;
        }

        .status-card {
            grid-column: span 5;
        }

        .toggle-group {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1rem;
            margin-top: 1.5rem;
        }

        .toggle-item {
            background: var(--bg-primary);
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 1rem;
        }

        .toggle-item label {
            font-weight: 600;
        }

        .system-tasks {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .system-task {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            background: white;
        }
        
        .monitoring-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .monitoring-card {
            grid-column: span 7;
        }

        .coverage-card {
            grid-column: span 5;
        }

        .monitoring-metrics {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
        }

        .metric-tile {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 1rem;
            background: var(--bg-primary);
        }

        .metric-tile .metric-value {
            font-size: 1.35rem;
            font-weight: 700;
            margin: 0.25rem 0;
        }

        .coverage-table {
            width: 100%;
            border-collapse: collapse;
        }

        .coverage-table th,
        .coverage-table td {
            text-align: left;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--gray-100);
        }

        .alert-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .alert-item {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 1rem;
            background: white;
        }
    </style>
</head>
<body>
    <?php sys_admin_nav('system_control'); ?>

    <div class="container">
        <div class="dashboard-header fade-in">
            <div class="d-flex justify-between align-center">
                <div>
                    <h2>System Control Center</h2>
                    <p class="text-muted">Configure platform-wide settings and run system tasks.</p>
                </div>
                <span class="badge badge-info"><i class="fas fa-shield-alt"></i> Protected</span>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?>">
                <i class="fas fa-check-circle"></i> <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <div class="control-grid">
            <div class="card control-card">
                <div class="card-header">
                    <h3><i class="fas fa-sliders-h text-primary"></i> Core Controls</h3>
                    <p class="text-muted">Manage access, notifications, and automation.</p>
                </div>
                <form method="POST">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="update_settings">
                    <div class="toggle-group">
                        <div class="toggle-item">
                            <label>
                                <input type="checkbox" name="maintenance_mode" <?php echo $settings['maintenance_mode'] ? 'checked' : ''; ?>>
                                Maintenance Mode
                            </label>
                            <p class="text-muted mb-0">Temporarily disable public access.</p>
                        </div>
                        <div class="toggle-item">
                            <label>
                                <input type="checkbox" name="new_registrations" <?php echo $settings['new_registrations'] ? 'checked' : ''; ?>>
                                New Registrations
                            </label>
                            <p class="text-muted mb-0">Allow new user sign-ups.</p>
                        </div>
                        <div class="toggle-item">
                            <label>
                                <input type="checkbox" name="auto_approvals" <?php echo $settings['auto_approvals'] ? 'checked' : ''; ?>>
                                Auto Approvals
                            </label>
                            <p class="text-muted mb-0">Approve members automatically.</p>
                        </div>
                        <div class="toggle-item">
                            <label>
                                <input type="checkbox" name="email_notifications" <?php echo $settings['email_notifications'] ? 'checked' : ''; ?>>
                                Email Notifications
                            </label>
                            <p class="text-muted mb-0">Send system alerts via email.</p>
                        </div>
                    </div>

                    <div class="row" style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                        <div class="form-group" style="flex: 1;">
                            <label>Backup Schedule</label>
                            <select name="backup_schedule" class="form-control">
                                <?php foreach (['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'] as $value => $label): ?>
                                    <option value="<?php echo $value; ?>" <?php echo $settings['backup_schedule'] === $value ? 'selected' : ''; ?>>
                                        <?php echo $label; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="flex: 1;">
                            <label>Alert Threshold (%)</label>
                            <input type="number" name="alert_threshold" class="form-control" min="50" max="100" value="<?php echo (int) $settings['alert_threshold']; ?>">
                        </div>
                    </div>

                    <div class="text-right mt-3">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Settings
                        </button>
                    </div>
                </form>
            </div>

            <div class="card status-card">
                <div class="card-header">
                    <h3><i class="fas fa-server text-success"></i> Service Status</h3>
                    <p class="text-muted">Overview of critical platform services.</p>
                </div>
                <div class="d-flex flex-column gap-3">
                    <?php foreach ($systemStatus as $status): ?>
                        <div class="d-flex justify-between align-center">
                            <div>
                                <strong><?php echo $status['label']; ?></strong>
                                <p class="text-muted mb-0">Last check: <?php echo date('h:i A'); ?></p>
                            </div>
                            <span class="badge badge-<?php echo $status['badge']; ?>"><?php echo $status['status']; ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="monitoring-grid">
            <div class="card monitoring-card">
                <div class="card-header">
                    <h3><i class="fas fa-chart-line text-info"></i> Platform Monitoring</h3>
                    <p class="text-muted">Real-time service health and alert thresholds.</p>
                </div>
                <div class="monitoring-metrics">
                    <?php foreach ($monitoringOverview as $metric): ?>
                        <div class="metric-tile">
                            <div class="d-flex justify-between align-center">
                                <strong><?php echo $metric['label']; ?></strong>
                                <span class="badge badge-<?php echo $metric['status']; ?>"><?php echo $metric['trend']; ?></span>
                            </div>
                            <div class="metric-value"><?php echo $metric['value']; ?></div>
                            <p class="text-muted mb-0">Alert threshold: <?php echo (int) $settings['alert_threshold']; ?>%</p>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="mt-3">
                    <h4 class="mb-2">Active Alerts</h4>
                    <div class="alert-list">
                        <?php foreach ($activeAlerts as $alert): ?>
                            <div class="alert-item">
                                <div class="d-flex justify-between align-center">
                                    <strong><?php echo $alert['title']; ?></strong>
                                    <span class="badge badge-<?php echo $alert['status']; ?>"><?php echo ucfirst($alert['status']); ?></span>
                                </div>
                                <p class="text-muted mb-0"><?php echo $alert['detail']; ?></p>
                                <small class="text-muted">Last updated <?php echo $alert['time']; ?></small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="card coverage-card">
                <div class="card-header">
                    <h3><i class="fas fa-shield-alt text-success"></i> Controls Coverage</h3>
                    <p class="text-muted">Consistency across operational domains.</p>
                </div>
                <table class="coverage-table">
                    <thead>
                        <tr>
                            <th>Area</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($controlCoverage as $coverage): ?>
                            <tr>
                                <td>
                                    <strong><?php echo $coverage['area']; ?></strong>
                                    <p class="text-muted mb-0"><?php echo $coverage['detail']; ?></p>
                                </td>
                                <td>
                                    <span class="badge badge-<?php echo $coverage['status']; ?>">
                                        <?php echo $coverage['coverage']; ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="alert alert-info mt-3">
                    <i class="fas fa-info-circle"></i>
                    <div>
                        <strong>Next steps</strong>
                        <p class="mb-0">Align payment and infrastructure playbooks to reach full coverage.</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-tools text-warning"></i> System Tasks</h3>
                <p class="text-muted">Run quick maintenance tasks without leaving the dashboard.</p>
            </div>
            <div class="system-tasks">
                <form method="POST" class="system-task">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="clear_cache">
                    <i class="fas fa-broom text-primary"></i>
                    <div>
                        <strong>Clear Cache</strong>
                        <p class="text-muted mb-0">Flush cached views and assets.</p>
                    </div>
                    <button class="btn btn-outline-primary btn-sm" type="submit">Run</button>
                </form>
                <form method="POST" class="system-task">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="restart_queue">
                    <i class="fas fa-redo text-info"></i>
                    <div>
                        <strong>Restart Queue</strong>
                        <p class="text-muted mb-0">Reboot background workers.</p>
                    </div>
                    <button class="btn btn-outline-info btn-sm" type="submit">Run</button>
                </form>
                <form method="POST" class="system-task">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="sync_search">
                    <i class="fas fa-sync-alt text-success"></i>
                    <div>
                        <strong>Sync Search</strong>
                        <p class="text-muted mb-0">Reindex catalog data.</p>
                    </div>
                    <button class="btn btn-outline-success btn-sm" type="submit">Run</button>
                </form>
                <form method="POST" class="system-task">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="generate_backup">
                    <i class="fas fa-database text-warning"></i>
                    <div>
                        <strong>Generate Backup</strong>
                        <p class="text-muted mb-0">Trigger a manual backup.</p>
                    </div>
                    <button class="btn btn-outline-warning btn-sm" type="submit">Run</button>
                </form>
            </div>
        </div>
    </div>

    <?php sys_admin_footer(); ?>
</body>
</html>
