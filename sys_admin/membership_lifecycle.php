<?php
session_start();
require_once '../config/db.php';
require_once 'partials.php';
require_role('sys_admin');

$coreFunctions = [
    [
        'title' => 'Client self-registration',
        'detail' => 'Capture client account details, validate email, and start onboarding.',
        'icon' => 'fas fa-user-plus',
        'tone' => 'primary',
    ],
    [
        'title' => 'Shop Owner business registration',
        'detail' => 'Collect business documents, shop profile, and ownership verification.',
        'icon' => 'fas fa-store',
        'tone' => 'success',
    ],
    [
        'title' => 'HR account creation (Owner-only)',
        'detail' => 'Enable owners to invite HR leads with scoped permissions.',
        'icon' => 'fas fa-user-tie',
        'tone' => 'info',
    ],
    [
        'title' => 'Staff account creation (HR-only)',
        'detail' => 'Allow HR to onboard staff and assign roles per shop.',
        'icon' => 'fas fa-users',
        'tone' => 'warning',
    ],
    [
        'title' => 'Verification and approval workflows',
        'detail' => 'Route onboarding steps through automated checks and admin review.',
        'icon' => 'fas fa-user-check',
        'tone' => 'secondary',
    ],
];

$coreProcess = [
    'Account created (self or delegated)',
    'Verification (email / OTP / documents)',
    'Approval workflow',
    'Account activation',
    'Lifecycle monitoring',
];

$automation = [
    [
        'title' => 'Verification reminders',
        'detail' => 'Automated nudges for pending email, OTP, or document submissions.',
        'icon' => 'fas fa-bell',
    ],
    [
        'title' => 'Auto-approval rules',
        'detail' => 'Policy-driven approvals when verification requirements are satisfied.',
        'icon' => 'fas fa-bolt',
    ],
    [
        'title' => 'Dormant account detection',
        'detail' => 'Flags idle accounts for follow-up or deactivation workflows.',
        'icon' => 'fas fa-user-clock',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Lifecycle Module - System Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .lifecycle-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 1.5rem;
            margin: 2rem 0;
        }

        .overview-card {
            grid-column: span 12;
        }

        .functions-card {
            grid-column: span 7;
        }

        .process-card {
            grid-column: span 5;
        }

        .automation-card {
            grid-column: span 12;
        }

        .function-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1rem;
        }

        .function-item {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 1rem;
            background: var(--bg-primary);
        }

        .function-item .icon {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: var(--radius-full);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 0.75rem;
            color: white;
        }

        .bg-primary { background: var(--primary-600); }
        .bg-success { background: var(--success-600); }
        .bg-info { background: var(--info-600); }
        .bg-warning { background: var(--warning-600); }
        .bg-secondary { background: var(--secondary-600); }

        .process-step {
            display: flex;
            gap: 1rem;
            align-items: flex-start;
        }

        .process-step .badge {
            width: 2rem;
            height: 2rem;
            border-radius: var(--radius-full);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            background: var(--primary-100);
            color: var(--primary-700);
        }

        .automation-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1rem;
        }

        .automation-item {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 1rem;
            background: white;
        }

        .automation-item i {
            color: var(--primary-600);
        }
    </style>
</head>
<body>
    <?php sys_admin_nav('membership_lifecycle'); ?>

    <div class="container">
        <div class="dashboard-header fade-in">
            <div class="d-flex justify-between align-center">
                <div>
                    <h2>User & Membership Lifecycle Management</h2>
                    <p class="text-muted">End-to-end onboarding, verification, approval, and lifecycle coverage.</p>
                </div>
                <span class="badge badge-primary"><i class="fas fa-users-cog"></i> Module 2</span>
            </div>
        </div>

        <div class="lifecycle-grid">
            <div class="card overview-card">
                <div class="card-header">
                    <h3><i class="fas fa-sitemap text-primary"></i> Purpose</h3>
                </div>
                <p class="text-muted mb-0">
                    Manages registration, verification, onboarding, and account lifecycle for clients,
                    shop owners, HR leaders, and staff members.
                </p>
            </div>

            <div class="card functions-card">
                <div class="card-header">
                    <h3><i class="fas fa-list-check text-primary"></i> Core Functions</h3>
                    <p class="text-muted">Key capabilities supported by the module.</p>
                </div>
                <div class="function-list">
                    <?php foreach ($coreFunctions as $function): ?>
                        <div class="function-item">
                            <span class="icon bg-<?php echo $function['tone']; ?>">
                                <i class="<?php echo $function['icon']; ?>"></i>
                            </span>
                            <h4><?php echo $function['title']; ?></h4>
                            <p class="text-muted mb-0"><?php echo $function['detail']; ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card process-card">
                <div class="card-header">
                    <h3><i class="fas fa-route text-primary"></i> Core Process</h3>
                    <p class="text-muted">Lifecycle stages that every account follows.</p>
                </div>
                <div class="d-flex flex-column gap-3">
                    <?php foreach ($coreProcess as $index => $step): ?>
                        <div class="process-step">
                            <span class="badge"><?php echo $index + 1; ?></span>
                            <div>
                                <strong><?php echo $step; ?></strong>
                                <?php if ($index === 0): ?>
                                    <p class="text-muted mb-0">Capture identity and account intent at entry.</p>
                                <?php elseif ($index === 1): ?>
                                    <p class="text-muted mb-0">Ensure proof-of-ownership and contact verification.</p>
                                <?php elseif ($index === 2): ?>
                                    <p class="text-muted mb-0">Route approvals to admins or auto-rules.</p>
                                <?php elseif ($index === 3): ?>
                                    <p class="text-muted mb-0">Activate permissions and notify stakeholders.</p>
                                <?php else: ?>
                                    <p class="text-muted mb-0">Monitor activity, compliance, and engagement.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card automation-card">
                <div class="card-header">
                    <h3><i class="fas fa-robot text-primary"></i> Automation</h3>
                    <p class="text-muted">System-driven workflows to reduce manual effort.</p>
                </div>
                <div class="automation-grid">
                    <?php foreach ($automation as $rule): ?>
                        <div class="automation-item">
                            <h4 class="d-flex align-center gap-2">
                                <i class="<?php echo $rule['icon']; ?>"></i>
                                <?php echo $rule['title']; ?>
                            </h4>
                            <p class="text-muted mb-0"><?php echo $rule['detail']; ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <?php sys_admin_footer(); ?>
</body>
</html>
