<?php
session_start();
require_once '../config/db.php';
require_once 'partials.php';
require_role('sys_admin');

$notification_kpis = [
    [
        'label' => 'Active notification flows',
        'value' => '12',
        'note' => 'Approval, production, and hiring coverage.',
        'icon' => 'fas fa-bell',
        'tone' => 'primary',
    ],
    [
        'label' => 'Pending approvals',
        'value' => '7',
        'note' => 'Awaiting owner + client sign-off.',
        'icon' => 'fas fa-clipboard-check',
        'tone' => 'warning',
    ],
    [
        'label' => 'Production alerts',
        'value' => '3',
        'note' => 'Live alerts routed to production leads.',
        'icon' => 'fas fa-triangle-exclamation',
        'tone' => 'danger',
    ],
    [
        'label' => 'Hiring notifications',
        'value' => '5',
        'note' => 'New applicants and interview reminders.',
        'icon' => 'fas fa-user-group',
        'tone' => 'success',
    ],
];

$delivery_channels = [
    [
        'title' => 'Multi-channel delivery',
        'detail' => 'Email, SMS, and in-app alerts with time-zone aware scheduling.',
        'icon' => 'fas fa-paper-plane',
    ],
    [
        'title' => 'Escalation paths',
        'detail' => 'Auto-escalate overdue approvals to supervisors after 24 hours.',
        'icon' => 'fas fa-arrow-up-right-dots',
    ],
    [
        'title' => 'Quiet hours',
        'detail' => 'Suppress low-priority notices outside business hours.',
        'icon' => 'fas fa-moon',
    ],
];

$reminder_streams = [
    [
        'title' => 'Approval reminders',
        'detail' => 'Notify clients and owners when proofs or quotes are awaiting action.',
        'tone' => 'primary',
        'time' => 'Next run in 30 min',
    ],
    [
        'title' => 'Production alerts',
        'detail' => 'Flag late-stage orders and machine downtime to floor leads.',
        'tone' => 'danger',
        'time' => 'Last alert 15 min ago',
    ],
    [
        'title' => 'Hiring notifications',
        'detail' => 'Share applicant updates and interview schedules with HR.',
        'tone' => 'success',
        'time' => 'Next run at 4:00 PM',
    ],
];

$notification_queue = [
    [
        'recipient' => 'Owner leadership group',
        'event' => 'Quote approval reminder (Order #A-1884)',
        'channel' => 'Email + In-app',
        'status' => 'Scheduled',
    ],
    [
        'recipient' => 'Production supervisor',
        'event' => 'Production delay alert (Batch 19-Delta)',
        'channel' => 'SMS + In-app',
        'status' => 'Sending',
    ],
    [
        'recipient' => 'HR recruiting team',
        'event' => 'Candidate interview reminder (J. Santos)',
        'channel' => 'Email + Calendar',
        'status' => 'Queued',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notification &amp; Reminder Module - System Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .notification-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 1.5rem;
            margin: 2rem 0;
        }

        .kpi-card {
            grid-column: span 3;
        }

        .purpose-card,
        .automation-card {
            grid-column: span 12;
        }

        .channel-card,
        .reminder-card,
        .queue-card {
            grid-column: span 4;
        }

        .channel-tile,
        .reminder-item,
        .queue-item {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 1rem;
            background: white;
        }

        .channel-tile + .channel-tile,
        .reminder-item + .reminder-item,
        .queue-item + .queue-item {
            margin-top: 1rem;
        }

        .queue-item .meta {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem 1rem;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <?php sys_admin_nav('notification_reminder'); ?>

    <main class="container">
        <section class="page-header">
            <div>
                <h1>Notification &amp; Reminder Module</h1>
                <p class="text-muted">Keeps users informed across workflows with automated reminders and alerts.</p>
            </div>
            <span class="badge badge-primary"><i class="fas fa-bell"></i> Module 31</span>
        </section>

        <section class="notification-grid">
            <?php foreach ($notification_kpis as $kpi): ?>
                <div class="card kpi-card">
                    <div class="metric">
                        <div>
                            <p class="text-muted mb-1"><?php echo $kpi['label']; ?></p>
                            <h3 class="mb-1"><?php echo $kpi['value']; ?></h3>
                            <small class="text-muted"><?php echo $kpi['note']; ?></small>
                        </div>
                        <div class="icon-circle bg-<?php echo $kpi['tone']; ?> text-white">
                            <i class="<?php echo $kpi['icon']; ?>"></i>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

            <div class="card purpose-card">
                <h2>Purpose</h2>
                <p class="text-muted mb-0">
                    Centralizes notification automation so approvals, production progress, and hiring workflows
                    stay on time with the right stakeholders.
                </p>
            </div>

            <div class="card channel-card">
                <h2>Delivery controls</h2>
                <?php foreach ($delivery_channels as $channel): ?>
                    <div class="channel-tile">
                        <h3 class="mb-1"><i class="<?php echo $channel['icon']; ?> text-primary"></i> <?php echo $channel['title']; ?></h3>
                        <p class="text-muted mb-0"><?php echo $channel['detail']; ?></p>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="card reminder-card">
                <h2>Live reminder streams</h2>
                <?php foreach ($reminder_streams as $stream): ?>
                    <div class="reminder-item">
                        <div class="d-flex justify-between align-center">
                            <h3 class="mb-1"><?php echo $stream['title']; ?></h3>
                            <span class="badge badge-<?php echo $stream['tone']; ?>"><?php echo $stream['time']; ?></span>
                        </div>
                        <p class="text-muted mb-0"><?php echo $stream['detail']; ?></p>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="card queue-card">
                <h2>Upcoming notifications</h2>
                <?php foreach ($notification_queue as $item): ?>
                    <div class="queue-item">
                        <h3 class="mb-1"><?php echo $item['event']; ?></h3>
                        <div class="meta text-muted">
                            <span><i class="fas fa-user"></i> <?php echo $item['recipient']; ?></span>
                            <span><i class="fas fa-satellite-dish"></i> <?php echo $item['channel']; ?></span>
                            <span class="badge badge-outline"><?php echo $item['status']; ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="card automation-card">
                <h2>Automation coverage</h2>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Automation</th>
                                <th>Trigger</th>
                                <th>Outcome</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Approval reminders</td>
                                <td>Proofs, quotes, or invoices pending beyond SLA</td>
                                <td>Notifies clients and owners with escalation after 24 hours</td>
                            </tr>
                            <tr>
                                <td>Production alerts</td>
                                <td>Delays, QA failures, or capacity shortfalls</td>
                                <td>Routes alerts to production managers and floor supervisors</td>
                            </tr>
                            <tr>
                                <td>Hiring notifications</td>
                                <td>New applicant or interview schedule updates</td>
                                <td>Prompts HR teams to confirm and track hiring stages</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </main>

    <?php sys_admin_footer(); ?>
</body>
</html>
