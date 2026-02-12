<?php
session_start();
require_once '../config/db.php';
require_once '../includes/analytics_service.php';
require_role('hr');

$hr_name = htmlspecialchars($_SESSION['user']['fullname'] ?? 'HR Lead');

$overview = fetch_order_analytics($pdo);
$staff_count = fetch_staff_count($pdo);
$total_orders = $overview['total_orders'];
$completion_rate = $total_orders > 0 ? ($overview['completed_orders'] / $total_orders) * 100 : 0;

$kpis = [
    [
        'label' => 'Active headcount',
        'value' => number_format($staff_count),
        'note' => 'Active staff across all shops.',
        'icon' => 'fas fa-users',
        'tone' => 'primary',
    ],
    [
        'label' => 'Completion rate',
        'value' => number_format($completion_rate, 1) . '%',
        'note' => 'Orders completed successfully.',
        'icon' => 'fas fa-clipboard-check',
        'tone' => 'warning',
    ],
    [
        'label' => 'Active orders',
        'value' => number_format($overview['active_orders']),
        'note' => 'Accepted or in-progress jobs.',
        'icon' => 'fas fa-graduation-cap',
        'tone' => 'success',
    ],
    [
        'label' => 'Pending orders',
        'value' => number_format($overview['pending_orders']),
        'note' => 'Waiting to be accepted.',
        'icon' => 'fas fa-bell',
        'tone' => 'danger',
    ],
];

$dashboards = [
    [
        'title' => 'Workforce mix',
        'detail' => 'Role distribution, tenure bands, and location split.',
        'icon' => 'fas fa-layer-group',
    ],
    [
        'title' => 'Hiring funnel',
        'detail' => 'Applicants, interviews, offers, and time-to-fill trends.',
        'icon' => 'fas fa-filter-circle-dollar',
    ],
    [
        'title' => 'Engagement pulse',
        'detail' => 'Survey results, sentiment index, and action plans.',
        'icon' => 'fas fa-face-smile',
    ],
];

$scheduled_reports = [
    [
        'name' => 'Weekly HR operations summary',
        'cadence' => 'Every Friday, 5:00 PM',
        'recipients' => 'HR leadership + Owners',
        'status' => 'Scheduled',
    ],
    [
        'name' => 'Monthly compliance checklist',
        'cadence' => 'Last business day of month',
        'recipients' => 'HR + SysAdmin',
        'status' => 'Scheduled',
    ],
    [
        'name' => 'Quarterly talent review',
        'cadence' => 'Quarter close',
        'recipients' => 'Exec stakeholders',
        'status' => 'Drafting',
    ],
];

$performance_alerts = [
    [
        'title' => 'Overtime threshold reached',
        'detail' => '3 teams exceeded weekly overtime limits.',
        'tone' => 'warning',
        'time' => 'Triggered today',
    ],
    [
        'title' => 'Training overdue',
        'detail' => '12 staffs require safety training refresh.',
        'tone' => 'danger',
        'time' => 'Triggered 1d ago',
    ],
    [
        'title' => 'Performance review backlog',
        'detail' => '7 evaluations pending manager feedback.',
        'tone' => 'primary',
        'time' => 'Triggered this week',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics &amp; Reporting - HR</title>
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
    <nav class="navbar navbar--compact">
        <div class="container d-flex justify-between align-center">
            <a href="dashboard.php" class="navbar-brand">
                <i class="fas fa-people-group"></i> <?php echo $hr_name; ?>
            </a>
            <ul class="navbar-nav">
                <li><a href="dashboard.php" class="nav-link">Dashboard</a></li>
                <li><a href="hiring_management.php" class="nav-link">Hiring</a></li>
                <li><a href="staff_productivity_performance.php" class="nav-link">Productivity</a></li>
                <li><a href="payroll_compensation.php" class="nav-link">Payroll</a></li>
                <li><a href="analytics_reporting.php" class="nav-link active">Analytics</a></li>
                <li><a href="../auth/logout.php" class="nav-link">Logout</a></li>
            </ul>
        </div>
    </nav>

    <main class="container">
        <section class="page-header">
            <div>
                <h1>Analytics &amp; Reporting</h1>
                <p class="text-muted">Monitor workforce insights and automate HR performance reporting.</p>
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
                    Provides dashboards and insights on workforce health, hiring velocity, and engagement so HR leaders
                    can act on trends quickly.
                </p>
            </div>

            <div class="card dashboard-card">
                <h2>Insight dashboards</h2>
                <?php foreach ($dashboards as $dashboard): ?>
                    <div class="dashboard-tile">
                        <h3 class="mb-1"><i class="<?php echo $dashboard['icon']; ?> text-primary"></i> <?php echo $dashboard['title']; ?></h3>
                        <p class="text-muted mb-0"><?php echo $dashboard['detail']; ?></p>
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
                                <td>Scheduled report delivery</td>
                                <td>Weekly and monthly cadences</td>
                                <td>Distributes analytics snapshots to stakeholders</td>
                            </tr>
                            <tr>
                                <td>Performance alerting</td>
                                <td>Threshold breaches for engagement or compliance</td>
                                <td>Routes alerts to HR and SysAdmin leads</td>
                            </tr>
                            <tr>
                                <td>Dashboard refresh</td>
                                <td>Hourly data updates</td>
                                <td>Keeps dashboards current for leadership reviews</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </main>
</body>
</html>
