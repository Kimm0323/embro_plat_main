<?php
session_start();
require_once '../config/db.php';
require_once 'partials.php';
require_role('sys_admin');

$defaultPreferences = [
    'timezone' => 'Asia/Manila',
    'theme' => 'light',
    'digest_frequency' => 'daily',
    'alerts' => true,
    'weekly_summary' => true,
];

if (!isset($_SESSION['sys_admin_preferences'])) {
    $_SESSION['sys_admin_preferences'] = $defaultPreferences;
}

$preferences = $_SESSION['sys_admin_preferences'];
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $previousPreferences = $preferences;
    $preferences['timezone'] = sanitize($_POST['timezone'] ?? $preferences['timezone']);
    $preferences['theme'] = sanitize($_POST['theme'] ?? $preferences['theme']);
    $preferences['digest_frequency'] = sanitize($_POST['digest_frequency'] ?? $preferences['digest_frequency']);
    $preferences['alerts'] = isset($_POST['alerts']);
    $preferences['weekly_summary'] = isset($_POST['weekly_summary']);

    $_SESSION['sys_admin_preferences'] = $preferences;
    $message = 'System preferences updated successfully.';
    
    $actorId = $_SESSION['user']['id'] ?? null;
    $actorRole = $_SESSION['user']['role'] ?? 'sys_admin';
    log_audit(
        $pdo,
        $actorId ? (int) $actorId : null,
        $actorRole,
        'update_admin_preferences',
        'system_settings',
        null,
        $previousPreferences,
        $preferences
    );
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings - System Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .settings-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 1.5rem;
            margin: 2rem 0;
        }

        .settings-card {
            grid-column: span 7;
        }

        .tips-card {
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
    <?php sys_admin_nav('settings'); ?>

    <div class="container">
        <div class="dashboard-header fade-in">
            <div class="d-flex justify-between align-center">
                <div>
                    <h2>System Settings</h2>
                    <p class="text-muted">Fine-tune admin preferences and notification rules.</p>
                </div>
                <span class="badge badge-primary"><i class="fas fa-cog"></i> Preferences</span>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <div class="settings-grid">
            <div class="card settings-card">
                <div class="card-header">
                    <h3><i class="fas fa-sliders-h text-primary"></i> Admin Preferences</h3>
                    <p class="text-muted">These settings apply to your admin workspace.</p>
                </div>
                <form method="POST">
                    <?php echo csrf_field(); ?>
                    <div class="form-group">
                        <label>Timezone</label>
                        <select name="timezone" class="form-control">
                            <?php foreach (['Asia/Manila', 'Asia/Singapore', 'UTC', 'America/New_York'] as $zone): ?>
                                <option value="<?php echo $zone; ?>" <?php echo $preferences['timezone'] === $zone ? 'selected' : ''; ?>>
                                    <?php echo $zone; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Theme</label>
                        <select name="theme" class="form-control">
                            <option value="light" <?php echo $preferences['theme'] === 'light' ? 'selected' : ''; ?>>Light</option>
                            <option value="dark" <?php echo $preferences['theme'] === 'dark' ? 'selected' : ''; ?>>Dark</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Digest Frequency</label>
                        <select name="digest_frequency" class="form-control">
                            <option value="daily" <?php echo $preferences['digest_frequency'] === 'daily' ? 'selected' : ''; ?>>Daily</option>
                            <option value="weekly" <?php echo $preferences['digest_frequency'] === 'weekly' ? 'selected' : ''; ?>>Weekly</option>
                            <option value="monthly" <?php echo $preferences['digest_frequency'] === 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                        </select>
                    </div>

                    <div class="toggle-group">
                        <div class="toggle-item">
                            <label>
                                <input type="checkbox" name="alerts" <?php echo $preferences['alerts'] ? 'checked' : ''; ?>>
                                Enable Critical Alerts
                            </label>
                            <p class="text-muted mb-0">Immediate alerts for system issues.</p>
                        </div>
                        <div class="toggle-item">
                            <label>
                                <input type="checkbox" name="weekly_summary" <?php echo $preferences['weekly_summary'] ? 'checked' : ''; ?>>
                                Weekly Summary Email
                            </label>
                            <p class="text-muted mb-0">Receive digest every Monday.</p>
                        </div>
                    </div>

                    <div class="text-right mt-3">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Preferences
                        </button>
                    </div>
                </form>
            </div>

            <div class="card tips-card">
                <div class="card-header">
                    <h3><i class="fas fa-lightbulb text-warning"></i> Recommendations</h3>
                    <p class="text-muted">Best practices for system administrators.</p>
                </div>
                <ul class="list-unstyled" style="display: flex; flex-direction: column; gap: 1rem;">
                    <li>
                        <strong>Review approvals daily.</strong>
                        <p class="text-muted mb-0">Keep onboarding fast for new clients and shop owners.</p>
                    </li>
                    <li>
                        <strong>Schedule backups weekly.</strong>
                        <p class="text-muted mb-0">Ensure data resilience for critical tables.</p>
                    </li>
                    <li>
                        <strong>Monitor performance trends.</strong>
                        <p class="text-muted mb-0">Use analytics to detect spikes early.</p>
                    </li>
                </ul>
                <div class="alert alert-info mt-3">
                    <i class="fas fa-info-circle"></i>
                    <div>
                        <strong>Tip</strong>
                        <p class="mb-0">Dark mode styling is reserved for the next UI release.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php sys_admin_footer(); ?>
</body>
</html>
