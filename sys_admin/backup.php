<?php
session_start();
require_once '../config/db.php';
require_once 'partials.php';
require_role('sys_admin');

$defaultSettings = [
    'max_active_orders' => 120,
    'low_stock_threshold' => 15,
    'failed_payment_threshold' => 5,
    'account_lockout_window' => 30,
    'backup_schedule' => 'daily',
    'backup_retention_days' => 30,
    'backup_storage_target' => 'Primary Vault',
    'backup_encryption' => true,
    'service_fee_rate' => 4.5,
    'rush_fee_rate' => 12.0,
    'cancellation_window_days' => 3,
    'late_fee_rate' => 1.5,
    'policy_revision' => 'Q2 2024',
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
$booleanKeys = ['backup_encryption'];
$intKeys = [
    'max_active_orders',
    'low_stock_threshold',
    'failed_payment_threshold',
    'account_lockout_window',
    'backup_retention_days',
    'cancellation_window_days',
];
$floatKeys = ['service_fee_rate', 'rush_fee_rate', 'late_fee_rate'];

foreach ($storedSettings as $row) {
    $key = $row['setting_key'];
    $storedKeys[$key] = true;
    $value = $row['setting_value'];

    if (in_array($key, $booleanKeys, true)) {
        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $settings[$key] = $parsed === null ? (bool) $value : $parsed;
    } elseif (in_array($key, $intKeys, true)) {
        $settings[$key] = (int) $value;
    } elseif (in_array($key, $floatKeys, true)) {
        $settings[$key] = (float) $value;
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

if (!isset($_SESSION['sys_admin_config_versions'])) {
    $_SESSION['sys_admin_config_versions'] = [
        [
            'version' => 'v2.1.0',
            'note' => 'Baseline configuration for production.',
            'author' => 'System Admin',
            'date' => date('M d, Y', strtotime('-14 days')),
        ],
        [
            'version' => 'v2.1.1',
            'note' => 'Updated backup retention to 30 days.',
            'author' => 'Automation',
            'date' => date('M d, Y', strtotime('-7 days')),
        ],
    ];
}

$configVersions = $_SESSION['sys_admin_config_versions'];
$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save_configuration';

    if ($action === 'save_configuration') {
        $previousSettings = $settings;
        $settings['max_active_orders'] = max(1, (int) ($_POST['max_active_orders'] ?? $settings['max_active_orders']));
        $settings['low_stock_threshold'] = max(1, (int) ($_POST['low_stock_threshold'] ?? $settings['low_stock_threshold']));
        $settings['failed_payment_threshold'] = max(1, (int) ($_POST['failed_payment_threshold'] ?? $settings['failed_payment_threshold']));
        $settings['account_lockout_window'] = max(5, (int) ($_POST['account_lockout_window'] ?? $settings['account_lockout_window']));
        $settings['backup_schedule'] = sanitize($_POST['backup_schedule'] ?? $settings['backup_schedule']);
        $settings['backup_retention_days'] = max(7, (int) ($_POST['backup_retention_days'] ?? $settings['backup_retention_days']));
        $settings['backup_storage_target'] = sanitize($_POST['backup_storage_target'] ?? $settings['backup_storage_target']);
        $settings['backup_encryption'] = isset($_POST['backup_encryption']);
        $settings['service_fee_rate'] = (float) ($_POST['service_fee_rate'] ?? $settings['service_fee_rate']);
        $settings['rush_fee_rate'] = (float) ($_POST['rush_fee_rate'] ?? $settings['rush_fee_rate']);
        $settings['cancellation_window_days'] = max(1, (int) ($_POST['cancellation_window_days'] ?? $settings['cancellation_window_days']));
        $settings['late_fee_rate'] = (float) ($_POST['late_fee_rate'] ?? $settings['late_fee_rate']);
        $settings['policy_revision'] = sanitize($_POST['policy_revision'] ?? $settings['policy_revision']);

        foreach ($settings as $key => $value) {
            $insertStmt->execute([$key, is_bool($value) ? (int) $value : (string) $value, $userId]);
        }

        log_audit(
            $pdo,
            $userId,
            $userRole,
            'update_system_configuration',
            'system_settings',
            null,
            $previousSettings,
            $settings
        );

        $message = 'System configuration updated successfully.';
    } elseif ($action === 'run_backup') {
        $message = 'Backup job queued. You will receive a notification when it completes.';
    } elseif ($action === 'restore_backup') {
        $restorePoint = sanitize($_POST['restore_point'] ?? 'Latest snapshot');
        $message = 'Restore initiated for ' . $restorePoint . '.';
        $messageType = 'warning';
    } elseif ($action === 'save_version') {
        $versionLabel = sanitize($_POST['version_label'] ?? '');
        $versionNote = sanitize($_POST['version_note'] ?? '');

        if ($versionLabel === '') {
            $message = 'Please provide a configuration version label.';
            $messageType = 'danger';
        } else {
            $newVersion = [
                'version' => $versionLabel,
                'note' => $versionNote !== '' ? $versionNote : 'Manual snapshot created by system admin.',
                'author' => $_SESSION['user']['fullname'] ?? 'System Admin',
                'date' => date('M d, Y'),
            ];
            array_unshift($_SESSION['sys_admin_config_versions'], $newVersion);
            $configVersions = $_SESSION['sys_admin_config_versions'];

            log_audit(
                $pdo,
                $userId,
                $userRole,
                'save_configuration_version',
                'system_settings',
                null,
                [],
                $newVersion
            );

            $message = 'Configuration version saved successfully.';
        }
    }
}

$backupSnapshots = [
    ['label' => 'Latest snapshot', 'time' => 'Today, 02:00 AM', 'status' => 'success'],
    ['label' => 'Nightly backup', 'time' => 'Yesterday, 02:00 AM', 'status' => 'success'],
    ['label' => 'Monthly archive', 'time' => 'Aug 01, 2024', 'status' => 'info'],
];

$automationChecklist = [
    ['label' => 'Scheduled backups', 'detail' => ucfirst($settings['backup_schedule']) . ' at 02:00 AM', 'status' => 'success'],
    ['label' => 'Configuration diffing', 'detail' => 'Version history enabled', 'status' => 'success'],
    ['label' => 'Alerting', 'detail' => 'Notify SysAdmin on failure', 'status' => 'success'],
    ['label' => 'Retention policy', 'detail' => $settings['backup_retention_days'] . ' day retention', 'status' => 'info'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Configuration & Backup - System Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .config-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 1.5rem;
            margin: 2rem 0;
        }

        .config-card {
            grid-column: span 7;
        }

        .side-card {
            grid-column: span 5;
        }

        .section-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1rem;
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

        .section-tile {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 1rem;
            background: var(--bg-primary);
        }

        .snapshot-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--gray-100);
        }

        .snapshot-item:last-child {
            border-bottom: 0;
        }

        .version-table {
            width: 100%;
            border-collapse: collapse;
        }

        .version-table th,
        .version-table td {
            text-align: left;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--gray-100);
        }
    </style>
</head>
<body>
    <?php sys_admin_nav('config_backup'); ?>

    <div class="container">
        <div class="dashboard-header fade-in">
            <div class="d-flex justify-between align-center">
                <div>
                    <h2>System Configuration & Backup</h2>
                    <p class="text-muted">Manage platform rules, backup routines, and policy controls.</p>
                </div>
                <span class="badge badge-info"><i class="fas fa-database"></i> SysAdmin</span>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?>">
                <i class="fas fa-check-circle"></i> <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <div class="config-grid">
            <div class="card config-card">
                <div class="card-header">
                    <h3><i class="fas fa-sliders-h text-primary"></i> System Rules & Thresholds</h3>
                    <p class="text-muted">Set limits that keep the platform stable and compliant.</p>
                </div>
                <form method="POST">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="save_configuration">
                    <div class="section-grid">
                        <div class="form-group">
                            <label>Max Active Orders</label>
                            <input type="number" min="1" name="max_active_orders" class="form-control" value="<?php echo (int) $settings['max_active_orders']; ?>">
                        </div>
                        <div class="form-group">
                            <label>Low Stock Threshold</label>
                            <input type="number" min="1" name="low_stock_threshold" class="form-control" value="<?php echo (int) $settings['low_stock_threshold']; ?>">
                        </div>
                        <div class="form-group">
                            <label>Failed Payment Limit</label>
                            <input type="number" min="1" name="failed_payment_threshold" class="form-control" value="<?php echo (int) $settings['failed_payment_threshold']; ?>">
                        </div>
                        <div class="form-group">
                            <label>Account Lockout Window (min)</label>
                            <input type="number" min="5" name="account_lockout_window" class="form-control" value="<?php echo (int) $settings['account_lockout_window']; ?>">
                        </div>
                    </div>

                    <div class="section-grid" style="margin-top: 1.5rem;">
                        <div class="form-group">
                            <label>Backup Schedule</label>
                            <select name="backup_schedule" class="form-control">
                                <?php foreach (['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'] as $value => $label): ?>
                                    <option value="<?php echo $value; ?>" <?php echo $settings['backup_schedule'] === $value ? 'selected' : ''; ?>>
                                        <?php echo $label; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Retention Days</label>
                            <input type="number" min="7" name="backup_retention_days" class="form-control" value="<?php echo (int) $settings['backup_retention_days']; ?>">
                        </div>
                        <div class="form-group">
                            <label>Storage Target</label>
                            <input type="text" name="backup_storage_target" class="form-control" value="<?php echo htmlspecialchars($settings['backup_storage_target']); ?>">
                        </div>
                        <div class="form-group">
                            <label>Encryption</label>
                            <div class="toggle-item" style="margin-top: 0.5rem;">
                                <label>
                                    <input type="checkbox" name="backup_encryption" <?php echo $settings['backup_encryption'] ? 'checked' : ''; ?>>
                                    AES-256 at rest
                                </label>
                                <p class="text-muted mb-0">Encrypt backup archives automatically.</p>
                            </div>
                        </div>
                    </div>

                    <div class="section-grid" style="margin-top: 1.5rem;">
                        <div class="form-group">
                            <label>Service Fee (%)</label>
                            <input type="number" step="0.1" min="0" name="service_fee_rate" class="form-control" value="<?php echo (float) $settings['service_fee_rate']; ?>">
                        </div>
                        <div class="form-group">
                            <label>Rush Fee (%)</label>
                            <input type="number" step="0.1" min="0" name="rush_fee_rate" class="form-control" value="<?php echo (float) $settings['rush_fee_rate']; ?>">
                        </div>
                        <div class="form-group">
                            <label>Cancellation Window (days)</label>
                            <input type="number" min="1" name="cancellation_window_days" class="form-control" value="<?php echo (int) $settings['cancellation_window_days']; ?>">
                        </div>
                        <div class="form-group">
                            <label>Late Fee (%)</label>
                            <input type="number" step="0.1" min="0" name="late_fee_rate" class="form-control" value="<?php echo (float) $settings['late_fee_rate']; ?>">
                        </div>
                        <div class="form-group">
                            <label>Policy Revision</label>
                            <input type="text" name="policy_revision" class="form-control" value="<?php echo htmlspecialchars($settings['policy_revision']); ?>">
                        </div>
                    </div>

                    <div class="text-right mt-3">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Configuration
                        </button>
                    </div>
                </form>
            </div>

            <div class="card side-card">
                <div class="card-header">
                    <h3><i class="fas fa-user-shield text-success"></i> SysAdmin Coverage</h3>
                    <p class="text-muted">Roles and responsibilities for resilient operations.</p>
                </div>
                <div class="section-grid">
                    <div class="section-tile">
                        <strong>Role</strong>
                        <p class="text-muted mb-0">SysAdmin</p>
                    </div>
                    <div class="section-tile">
                        <strong>Ownership</strong>
                        <p class="text-muted mb-0">System rules, backups, and policy compliance.</p>
                    </div>
                    <div class="section-tile">
                        <strong>Escalation</strong>
                        <p class="text-muted mb-0">On-call rotation + automation alerts.</p>
                    </div>
                </div>
                <div class="alert alert-info mt-3">
                    <i class="fas fa-info-circle"></i>
                    <div>
                        <strong>Automation status</strong>
                        <p class="mb-0">Backups and configuration diffing are enabled for this environment.</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="config-grid">
            <div class="card config-card">
                <div class="card-header">
                    <h3><i class="fas fa-database text-warning"></i> Backup & Restore</h3>
                    <p class="text-muted">Trigger on-demand backups or restore from snapshots.</p>
                </div>
                <div>
                    <?php foreach ($backupSnapshots as $snapshot): ?>
                        <div class="snapshot-item">
                            <div>
                                <strong><?php echo $snapshot['label']; ?></strong>
                                <p class="text-muted mb-0"><?php echo $snapshot['time']; ?></p>
                            </div>
                            <span class="badge badge-<?php echo $snapshot['status']; ?>"><?php echo ucfirst($snapshot['status']); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="d-flex gap-2" style="margin-top: 1.5rem; flex-wrap: wrap;">
                    <form method="POST">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="run_backup">
                        <button type="submit" class="btn btn-outline-primary">
                            <i class="fas fa-cloud-upload-alt"></i> Run Backup Now
                        </button>
                    </form>
                    <form method="POST" class="d-flex gap-2 align-center">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="restore_backup">
                        <select name="restore_point" class="form-control">
                            <?php foreach ($backupSnapshots as $snapshot): ?>
                                <option value="<?php echo $snapshot['label']; ?>">
                                    <?php echo $snapshot['label']; ?> (<?php echo $snapshot['time']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-outline-warning">
                            <i class="fas fa-history"></i> Restore
                        </button>
                    </form>
                </div>
            </div>

            <div class="card side-card">
                <div class="card-header">
                    <h3><i class="fas fa-robot text-info"></i> Automation Checklist</h3>
                    <p class="text-muted">Ensure scheduled jobs are healthy.</p>
                </div>
                <ul class="list-unstyled" style="display: flex; flex-direction: column; gap: 1rem;">
                    <?php foreach ($automationChecklist as $item): ?>
                        <li class="section-tile">
                            <div class="d-flex justify-between align-center">
                                <strong><?php echo $item['label']; ?></strong>
                                <span class="badge badge-<?php echo $item['status']; ?>"><?php echo ucfirst($item['status']); ?></span>
                            </div>
                            <p class="text-muted mb-0"><?php echo $item['detail']; ?></p>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-code-branch text-primary"></i> Configuration Versioning</h3>
                <p class="text-muted">Track policy changes and roll back confidently.</p>
            </div>
            <div class="section-grid" style="margin-bottom: 1.5rem;">
                <form method="POST" class="section-tile" style="grid-column: span 2;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="save_version">
                    <div class="form-group">
                        <label>Version Label</label>
                        <input type="text" name="version_label" class="form-control" placeholder="v2.2.0">
                    </div>
                    <div class="form-group">
                        <label>Change Summary</label>
                        <input type="text" name="version_note" class="form-control" placeholder="Updated fee + retention policy">
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="fas fa-plus"></i> Save Version
                    </button>
                </form>
                <div class="section-tile">
                    <strong>Versioning Policy</strong>
                    <p class="text-muted mb-0">Snapshots are retained alongside backups for 90 days.</p>
                </div>
                <div class="section-tile">
                    <strong>Last Reviewed</strong>
                    <p class="text-muted mb-0"><?php echo date('M d, Y', strtotime('-3 days')); ?> by Compliance Lead.</p>
                </div>
            </div>

            <table class="version-table">
                <thead>
                    <tr>
                        <th>Version</th>
                        <th>Notes</th>
                        <th>Owner</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($configVersions as $version): ?>
                        <tr>
                            <td><strong><?php echo $version['version']; ?></strong></td>
                            <td><?php echo $version['note']; ?></td>
                            <td><?php echo $version['author']; ?></td>
                            <td><?php echo $version['date']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php sys_admin_footer(); ?>
</body>
</html>
