<?php
session_start();
require_once '../config/db.php';
require_once 'partials.php';
require_role('sys_admin');

$moderation_kpis = [
    [
        'label' => 'Open reports',
        'value' => 24,
        'trend' => '↓ 8% vs last week',
        'icon' => 'fas fa-flag',
        'tone' => 'warning',
    ],
    [
        'label' => 'Resolved today',
        'value' => 18,
        'trend' => '↑ 12% vs yesterday',
        'icon' => 'fas fa-check-circle',
        'tone' => 'success',
    ],
    [
        'label' => 'Auto-hidden items',
        'value' => 6,
        'trend' => 'AI-assisted',
        'icon' => 'fas fa-eye-slash',
        'tone' => 'info',
    ],
    [
        'label' => 'Escalations',
        'value' => 3,
        'trend' => 'High priority',
        'icon' => 'fas fa-arrow-up-right-dots',
        'tone' => 'danger',
    ],
];

$report_queue = [
    [
        'id' => 'REP-2107',
        'content' => 'Request post #8942',
        'reason' => 'Harassment / abusive language',
        'reporter' => 'Client - Maria Lopez',
        'status' => 'Pending review',
        'priority' => 'High',
        'submitted' => 'Today, 10:34 AM',
    ],
    [
        'id' => 'REP-2108',
        'content' => 'Shop update #457',
        'reason' => 'Spam / promotional',
        'reporter' => 'Owner - Stitch & Co.',
        'status' => 'Under review',
        'priority' => 'Medium',
        'submitted' => 'Today, 9:58 AM',
    ],
    [
        'id' => 'REP-2109',
        'content' => 'Community reply #3321',
        'reason' => 'Off-topic content',
        'reporter' => 'Client - Aiden Cruz',
        'status' => 'Pending review',
        'priority' => 'Low',
        'submitted' => 'Yesterday, 6:12 PM',
    ],
];

$moderation_actions = [
    [
        'title' => 'Hide & notify',
        'detail' => 'Temporarily hide content while notifying the author with policy links.',
        'icon' => 'fas fa-eye-slash',
    ],
    [
        'title' => 'Remove & archive',
        'detail' => 'Permanently remove content and archive evidence for compliance.',
        'icon' => 'fas fa-trash-can',
    ],
    [
        'title' => 'Issue warning',
        'detail' => 'Send an official warning tied to the user account and report history.',
        'icon' => 'fas fa-triangle-exclamation',
    ],
    [
        'title' => 'Escalate to legal',
        'detail' => 'Route severe incidents to senior leadership and legal review.',
        'icon' => 'fas fa-scale-balanced',
    ],
];

$role_matrix = [
    [
        'role' => 'System admin',
        'scope' => 'Global visibility, policy edits, final enforcement.',
        'access' => 'Approve removals, restore content, assign moderators.',
    ],
    [
        'role' => 'Content moderator',
        'scope' => 'Queue triage, report resolution, user communication.',
        'access' => 'Hide/remove posts, issue warnings, tag patterns.',
    ],
    [
        'role' => 'Shop owner',
        'scope' => 'Own shop posts and replies only.',
        'access' => 'Remove own content, respond to reports, appeal outcomes.',
    ],
    [
        'role' => 'Client',
        'scope' => 'Personal posts and comments.',
        'access' => 'Report content, edit/delete own posts, track report status.',
    ],
];

$workflow_steps = [
    [
        'title' => 'Report intake',
        'detail' => 'Capture reason, policy tag, and supporting evidence with reporter metadata.',
    ],
    [
        'title' => 'Triage & risk score',
        'detail' => 'Auto-prioritize reports by severity, reach, and repeat offenses.',
    ],
    [
        'title' => 'Moderator decision',
        'detail' => 'Hide, remove, or restore content with a documented resolution.',
    ],
    [
        'title' => 'Follow-up & audit',
        'detail' => 'Notify users, log decisions, and update transparency metrics.',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Content Moderation &amp; Reporting - System Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .moderation-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 1.5rem;
            margin: 2rem 0;
        }

        .moderation-kpi {
            grid-column: span 3;
        }

        .moderation-kpi .metric {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }

        .moderation-kpi .metric i {
            font-size: 1.75rem;
        }

        .queue-card {
            grid-column: span 8;
        }

        .workflow-card {
            grid-column: span 4;
        }

        .actions-card {
            grid-column: span 6;
        }

        .roles-card {
            grid-column: span 6;
        }

        .workflow-step {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 1rem;
            background: white;
        }

        .workflow-step + .workflow-step {
            margin-top: 1rem;
        }

        .moderation-actions {
            display: grid;
            gap: 1rem;
        }

        .moderation-action {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 1rem;
            background: white;
            display: flex;
            gap: 1rem;
            align-items: flex-start;
        }

        .moderation-action i {
            color: var(--primary-600);
            margin-top: 0.25rem;
        }

        .role-table td {
            vertical-align: top;
        }
    </style>
</head>
<body>
    <?php sys_admin_nav('content_moderation'); ?>

    <div class="container">
        <div class="dashboard-header fade-in">
            <div class="d-flex justify-between align-center">
                <div>
                    <h2>Content Moderation &amp; Reporting</h2>
                    <p class="text-muted">Maintain platform safety with reporting workflows, enforcement actions, and role-based controls.</p>
                </div>
                <span class="badge badge-primary"><i class="fas fa-shield-halved"></i> Module 21</span>
            </div>
        </div>

        <div class="moderation-grid">
            <?php foreach ($moderation_kpis as $kpi): ?>
                <div class="card moderation-kpi">
                    <div class="metric">
                        <div>
                            <p class="text-muted mb-1"><?php echo $kpi['label']; ?></p>
                            <h3 class="mb-1"><?php echo $kpi['value']; ?></h3>
                            <small class="text-muted"><?php echo $kpi['trend']; ?></small>
                        </div>
                        <span class="badge badge-<?php echo $kpi['tone']; ?>">
                            <i class="<?php echo $kpi['icon']; ?>"></i>
                        </span>
                    </div>
                </div>
            <?php endforeach; ?>

            <div class="card queue-card">
                <div class="card-header">
                    <h3><i class="fas fa-clipboard-list text-warning"></i> Report Queue</h3>
                    <p class="text-muted">Latest reports awaiting moderation decisions.</p>
                </div>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Report ID</th>
                            <th>Content</th>
                            <th>Reason</th>
                            <th>Reporter</th>
                            <th>Status</th>
                            <th>Priority</th>
                            <th>Submitted</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report_queue as $report): ?>
                            <tr>
                                <td><?php echo $report['id']; ?></td>
                                <td><?php echo htmlspecialchars($report['content']); ?></td>
                                <td><?php echo htmlspecialchars($report['reason']); ?></td>
                                <td><?php echo htmlspecialchars($report['reporter']); ?></td>
                                <td><span class="badge badge-info"><?php echo $report['status']; ?></span></td>
                                <td>
                                    <span class="badge badge-<?php echo $report['priority'] === 'High' ? 'danger' : ($report['priority'] === 'Medium' ? 'warning' : 'secondary'); ?>">
                                        <?php echo $report['priority']; ?>
                                    </span>
                                </td>
                                <td class="text-muted"><?php echo $report['submitted']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="card workflow-card">
                <div class="card-header">
                    <h3><i class="fas fa-route text-primary"></i> Moderation Workflow</h3>
                    <p class="text-muted">Standard steps for every reported item.</p>
                </div>
                <?php foreach ($workflow_steps as $index => $step): ?>
                    <div class="workflow-step">
                        <div class="d-flex align-center gap-2">
                            <span class="badge badge-primary"><?php echo $index + 1; ?></span>
                            <strong><?php echo $step['title']; ?></strong>
                        </div>
                        <p class="text-muted mb-0 mt-2"><?php echo $step['detail']; ?></p>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="card actions-card">
                <div class="card-header">
                    <h3><i class="fas fa-gavel text-success"></i> Enforcement Actions</h3>
                    <p class="text-muted">Recommended actions based on severity and policy.</p>
                </div>
                <div class="moderation-actions">
                    <?php foreach ($moderation_actions as $action): ?>
                        <div class="moderation-action">
                            <i class="<?php echo $action['icon']; ?>"></i>
                            <div>
                                <strong><?php echo $action['title']; ?></strong>
                                <p class="text-muted mb-0"><?php echo $action['detail']; ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card roles-card">
                <div class="card-header">
                    <h3><i class="fas fa-user-shield text-info"></i> Role-based Moderation</h3>
                    <p class="text-muted">Define what each role can see and enforce.</p>
                </div>
                <table class="table role-table">
                    <thead>
                        <tr>
                            <th>Role</th>
                            <th>Scope</th>
                            <th>Access</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($role_matrix as $role): ?>
                            <tr>
                                <td><strong><?php echo $role['role']; ?></strong></td>
                                <td><?php echo $role['scope']; ?></td>
                                <td><?php echo $role['access']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php sys_admin_footer(); ?>
</body>
</html>
