<?php
session_start();
require_once '../config/db.php';
require_once 'partials.php';
require_role('sys_admin');

$defaultDss = [
    'data_retention_days' => 365,
    'two_factor_required' => true,
    'log_retention_days' => 90,
    'auto_logout_minutes' => 30,
    'access_audit' => true,
];

$dss = $defaultDss;
$dssKeys = array_keys($defaultDss);
$placeholders = implode(',', array_fill(0, count($dssKeys), '?'));
$dssStmt = $pdo->prepare("
    SELECT config_key, config_value
    FROM dss_configurations
    WHERE config_type = 'system'
      AND config_key IN ($placeholders)
");
$dssStmt->execute($dssKeys);
$storedConfigs = $dssStmt->fetchAll();
$storedKeys = [];

foreach ($storedConfigs as $row) {
    $key = $row['config_key'];
    $storedKeys[$key] = true;
    $value = $row['config_value'];

    if (in_array($key, ['two_factor_required', 'access_audit'], true)) {
        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $dss[$key] = $parsed === null ? (bool) $value : $parsed;
    } else {
        $dss[$key] = (int) $value;
    }
}

$userId = $_SESSION['user']['id'] ?? null;
$userRole = $_SESSION['user']['role'] ?? null;
$insertStmt = $pdo->prepare("
    INSERT INTO dss_configurations (config_key, config_value, config_type, description, created_by)
    VALUES (?, ?, 'system', ?, ?)
    ON DUPLICATE KEY UPDATE config_value = VALUES(config_value), created_by = VALUES(created_by)
");

foreach ($defaultDss as $key => $value) {
    if (!isset($storedKeys[$key])) {
        $insertStmt->execute([
            $key,
            is_bool($value) ? (int) $value : (string) $value,
            'System security configuration',
            $userId
        ]);
    }
}
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $previousDss = $dss;
    $dss['data_retention_days'] = (int) ($_POST['data_retention_days'] ?? $dss['data_retention_days']);
    $dss['log_retention_days'] = (int) ($_POST['log_retention_days'] ?? $dss['log_retention_days']);
    $dss['auto_logout_minutes'] = (int) ($_POST['auto_logout_minutes'] ?? $dss['auto_logout_minutes']);
    $dss['two_factor_required'] = isset($_POST['two_factor_required']);
    $dss['access_audit'] = isset($_POST['access_audit']);

    foreach ($dss as $key => $value) {
        $insertStmt->execute([
            $key,
            is_bool($value) ? (int) $value : (string) $value,
            'System security configuration',
            $userId
        ]);
    }

    log_audit(
        $pdo,
        $userId,
        $userRole,
        'update_security_config',
        'dss_configurations',
        null,
        $previousDss,
        $dss
    );
    $message = 'Security configuration saved.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Security Configuration - System Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .security-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 1.5rem;
            margin: 2rem 0;
        }

        .security-card {
            grid-column: span 7;
        }

        .summary-card {
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
    </style>
</head>
<body>
    <?php sys_admin_nav('dss_config'); ?>

    <div class="container">
        <div class="dashboard-header fade-in">
            <div class="d-flex justify-between align-center">
                <div>
                    <h2>Security & Data Controls</h2>
                    <p class="text-muted">Configure retention, access, and auditing policies.</p>
                </div>
                <span class="badge badge-danger"><i class="fas fa-shield-alt"></i> Sensitive</span>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <div class="security-grid">
            <div class="card security-card">
                <div class="card-header">
                    <h3><i class="fas fa-user-shield text-primary"></i> Policy Settings</h3>
                    <p class="text-muted">Manage data security and compliance controls.</p>
                </div>
                <form method="POST">
                    <?php echo csrf_field(); ?>
                    <div class="row" style="display: flex; gap: 1rem;">
                        <div class="form-group" style="flex: 1;">
                            <label>Data Retention (days)</label>
                            <input type="number" name="data_retention_days" class="form-control" min="30" max="1825" value="<?php echo (int) $dss['data_retention_days']; ?>">
                        </div>
                        <div class="form-group" style="flex: 1;">
                            <label>Log Retention (days)</label>
                            <input type="number" name="log_retention_days" class="form-control" min="7" max="365" value="<?php echo (int) $dss['log_retention_days']; ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Auto Logout (minutes)</label>
                        <input type="number" name="auto_logout_minutes" class="form-control" min="5" max="120" value="<?php echo (int) $dss['auto_logout_minutes']; ?>">
                    </div>

                    <div class="toggle-group">
                        <div class="toggle-item">
                            <label>
                                <input type="checkbox" name="two_factor_required" <?php echo $dss['two_factor_required'] ? 'checked' : ''; ?>>
                                Require Two-Factor Authentication
                            </label>
                            <p class="text-muted mb-0">Protect admin and owner accounts.</p>
                        </div>
                        <div class="toggle-item">
                            <label>
                                <input type="checkbox" name="access_audit" <?php echo $dss['access_audit'] ? 'checked' : ''; ?>>
                                Enable Access Auditing
                            </label>
                            <p class="text-muted mb-0">Log security-relevant activity.</p>
                        </div>
                    </div>

                    <div class="text-right mt-3">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Policies
                        </button>
                    </div>
                </form>
            </div>

            <div class="card summary-card">
                <div class="card-header">
                    <h3><i class="fas fa-lock text-success"></i> Security Summary</h3>
                    <p class="text-muted">Current safeguards in effect.</p>
                </div>
                <div class="d-flex flex-column gap-3">
                    <div>
                        <strong>Data Retention</strong>
                        <p class="text-muted mb-0"><?php echo (int) $dss['data_retention_days']; ?> days</p>
                    </div>
                    <div>
                        <strong>Log Retention</strong>
                        <p class="text-muted mb-0"><?php echo (int) $dss['log_retention_days']; ?> days</p>
                    </div>
                    <div>
                        <strong>Auto Logout</strong>
                        <p class="text-muted mb-0"><?php echo (int) $dss['auto_logout_minutes']; ?> minutes</p>
                    </div>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        <div>
                            <strong>Reminder</strong>
                            <p class="mb-0">Review security policies every quarter.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php sys_admin_footer(); ?>
</body>
</html>
