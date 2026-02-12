<?php
session_start();
require_once '../config/db.php';
require_once 'partials.php';
require_role('sys_admin');

$kpis = [
    [
        'label' => 'Platform health score',
        'value' => '94%',
        'note' => 'Stable uptime and service performance.',
        'icon' => 'fas fa-heart-pulse',
        'tone' => 'success',
    ],
    [
        'label' => 'Active decision reports',
        'value' => '18',
        'note' => 'Scheduled weekly and monthly insights.',
        'icon' => 'fas fa-file-lines',
        'tone' => 'primary',
    ],
    [
        'label' => 'Critical alerts',
        'value' => '3',
        'note' => 'Requires policy or ops review.',
        'icon' => 'fas fa-triangle-exclamation',
        'tone' => 'danger',
    ],
    [
        'label' => 'Stakeholder subscribers',
        'value' => '126',
        'note' => 'SysAdmin, Owner, HR distribution lists.',
        'icon' => 'fas fa-users',
        'tone' => 'info',
    ],
];

$dashboard_tiles = [
    [
        'title' => 'Revenue pulse',
        'detail' => 'Daily order value, refunds, and payout readiness.',
        'icon' => 'fas fa-chart-line',
    ],
    [
        'title' => 'Operational throughput',
        'detail' => 'Orders in progress, SLA compliance, and backlog aging.',
        'icon' => 'fas fa-stopwatch',
    ],
    [
        'title' => 'Risk & compliance',
        'detail' => 'Fraud flags, audit log anomalies, and moderation escalations.',
        'icon' => 'fas fa-shield-halved',
    ],
    [
        'title' => 'People performance',
        'detail' => 'Staff productivity, overtime, and training completion.',
        'icon' => 'fas fa-people-group',
    ],
];

$scheduled_reports = [
    [
        'name' => 'Weekly executive summary',
        'cadence' => 'Every Monday, 7:00 AM',
        'recipients' => 'SysAdmin + Owner leadership',
        'status' => 'Scheduled',
    ],
    [
        'name' => 'Monthly compliance snapshot',
        'cadence' => '1st of every month',
        'recipients' => 'SysAdmin + HR',
        'status' => 'Scheduled',
    ],
    [
        'name' => 'Quarterly growth review',
        'cadence' => 'Quarterly close',
        'recipients' => 'Exec stakeholder list',
        'status' => 'Drafting',
    ],
];

$performance_alerts = [
    [
        'title' => 'Order backlog spike',
        'detail' => 'Backlog above 12% for 3 consecutive days in Metro Manila.',
        'tone' => 'warning',
        'time' => 'Triggered 2h ago',
    ],
    [
        'title' => 'Payout delays',
        'detail' => '7 owner payouts pending beyond 72 hours.',
        'tone' => 'danger',
        'time' => 'Triggered yesterday',
    ],
    [
        'title' => 'Moderation volume',
        'detail' => 'User reports increased by 18% week over week.',
        'tone' => 'primary',
        'time' => 'Triggered today',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics &amp; Reporting - System Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .reporting-grid {
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

        .dashboard-card,
        .reports-card,
        .alerts-card {
            grid-column: span 4;
        }

        .dashboard-tile,
        .report-item,
        .alert-item {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 1rem;
            background: white;
        }

        .dashboard-tile + .dashboard-tile,
        .report-item + .report-item,
        .alert-item + .alert-item {
            margin-top: 1rem;
        }

        .report-item .meta {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem 1rem;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <?php sys_admin_nav('analytics_reporting'); ?>

    <main class="container">
        <section class="page-header">
            <div>
                <h1>Analytics &amp; Reporting</h1>
                <p class="text-muted">Deliver executive-grade dashboards and proactive alerts across the platform.</p>
            </div>
            <span class="badge badge-primary"><i class="fas fa-chart-pie"></i> Module 30</span>
        </section>

        <section class="reporting-grid">
            <?php foreach ($kpis as $kpi): ?>
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
                    Provides a unified analytics hub that turns operational data into dashboards, scheduled reports,
                    and real-time performance alerts for leadership teams.
                </p>
            </div>

            <div class="card dashboard-card">
                <h2>Insight dashboards</h2>
                <?php foreach ($dashboard_tiles as $tile): ?>
                    <div class="dashboard-tile">
                        <h3 class="mb-1"><i class="<?php echo $tile['icon']; ?> text-primary"></i> <?php echo $tile['title']; ?></h3>
                        <p class="text-muted mb-0"><?php echo $tile['detail']; ?></p>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="card reports-card">
                <h2>Scheduled reports</h2>
                <?php foreach ($scheduled_reports as $report): ?>
                    <div class="report-item">
                        <h3 class="mb-1"><?php echo $report['name']; ?></h3>
                        <div class="meta text-muted">
                            <span><i class="fas fa-calendar"></i> <?php echo $report['cadence']; ?></span>
                            <span><i class="fas fa-user-group"></i> <?php echo $report['recipients']; ?></span>
                            <span class="badge badge-outline"><?php echo $report['status']; ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="card alerts-card">
                <h2>Performance alerts</h2>
                <?php foreach ($performance_alerts as $alert): ?>
                    <div class="alert-item">
                        <div class="d-flex justify-between align-center">
                            <h3 class="mb-1"><?php echo $alert['title']; ?></h3>
                            <span class="badge badge-<?php echo $alert['tone']; ?>"><?php echo $alert['time']; ?></span>
                        </div>
                        <p class="text-muted mb-0"><?php echo $alert['detail']; ?></p>
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
                                <td>Scheduled report pipeline</td>
                                <td>Weekly, monthly, and quarterly cadences</td>
                                <td>Delivers curated PDFs and dashboards to stakeholders</td>
                            </tr>
                            <tr>
                                <td>Performance alerting</td>
                                <td>Threshold breaches for SLA, revenue, or risk</td>
                                <td>Routes alerts to SysAdmin, Owner, and HR leads</td>
                            </tr>
                            <tr>
                                <td>Insight refresh</td>
                                <td>Hourly data refresh</td>
                                <td>Keeps dashboards current for decision-making</td>
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
