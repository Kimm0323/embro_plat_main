<?php
session_start();
require_once '../config/db.php';
require_role('hr');

$hr_id = $_SESSION['user']['id'];
$hr_name = htmlspecialchars($_SESSION['user']['fullname'] ?? 'HR Lead');

$hr_stmt = $pdo->prepare("
    SELECT se.shop_id, s.shop_name
    FROM shop_staffs se
    JOIN shops s ON se.shop_id = s.id
    WHERE se.user_id = ? AND se.staff_role = 'hr' AND se.status = 'active'
");
$hr_stmt->execute([$hr_id]);
$hr_shop = $hr_stmt->fetch();

if (!$hr_shop) {
    die("You are not assigned to any shop as HR. Please contact your shop owner.");
}

$shop_id = (int) $hr_shop['shop_id'];
$shop_name = $hr_shop['shop_name'];

$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-7 days'));
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$errors = [];

$start_dt = DateTime::createFromFormat('Y-m-d', $start_date);
$end_dt = DateTime::createFromFormat('Y-m-d', $end_date);

if (!$start_dt || $start_dt->format('Y-m-d') !== $start_date) {
    $errors[] = 'Please provide a valid start date.';
}

if (!$end_dt || $end_dt->format('Y-m-d') !== $end_date) {
    $errors[] = 'Please provide a valid end date.';
}

if (empty($errors) && $start_dt > $end_dt) {
    $errors[] = 'Start date cannot be after end date.';
}

$logs = [];
if (empty($errors)) {
    $logs_stmt = $pdo->prepare("
        SELECT al.clock_in,
               al.clock_out,
               al.method,
               u.fullname
        FROM attendance_logs al
        JOIN users u ON al.staff_user_id = u.id
        WHERE al.shop_id = ?
          AND DATE(al.clock_in) BETWEEN ? AND ?
        ORDER BY al.clock_in DESC
    ");
    $logs_stmt->execute([$shop_id, $start_date, $end_date]);
    $logs = $logs_stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Management</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .filter-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
        }
        .filter-grid label {
            font-weight: 600;
            margin-bottom: 0.35rem;
            display: block;
        }
        .attendance-table {
            width: 100%;
            border-collapse: collapse;
        }
        .attendance-table th,
        .attendance-table td {
            padding: 0.75rem;
            border-bottom: 1px solid #e5e7eb;
            text-align: left;
        }
        .attendance-table th {
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #6b7280;
        }
        .method-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.2rem 0.6rem;
            border-radius: 999px;
            background: #e0f2fe;
            color: #0369a1;
            font-size: 0.8rem;
            font-weight: 600;
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
                <li><a href="analytics_reporting.php" class="nav-link">Analytics</a></li>
                <li><a href="attendance_management.php" class="nav-link active">Attendance</a></li>
                <li><a href="../auth/logout.php" class="nav-link">Logout</a></li>
            </ul>
        </div>
    </nav>

    <main class="container">
        <section class="page-header">
            <div>
                <h1>Attendance Management</h1>
                <p class="text-muted">Review attendance logs for <?php echo htmlspecialchars($shop_name); ?> staff.</p>
            </div>
            <span class="badge">Logged in as <?php echo $hr_name; ?></span>
        </section>

        <div class="card mb-3">
            <div class="d-flex justify-between align-center">
                <div>
                    <h3 class="mb-1">Attendance → Productivity Link</h3>
                    <p class="text-muted mb-0">Jump to performance to see how attendance impacts output and delivery cadence.</p>
                </div>
                <a class="btn btn-outline-primary" href="staff_productivity_performance.php?start_date=<?php echo htmlspecialchars($start_date); ?>&end_date=<?php echo htmlspecialchars($end_date); ?>">
                    View Productivity
                </a>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form class="filter-card" method="get">
            <div class="filter-grid">
                <div>
                    <label for="start_date">Start date</label>
                    <input type="date" id="start_date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($start_date); ?>" required>
                </div>
                <div>
                    <label for="end_date">End date</label>
                    <input type="date" id="end_date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($end_date); ?>" required>
                </div>
                <div style="display:flex; align-items:flex-end;">
                    <button type="submit" class="btn btn-primary" style="width:100%;">
                        <i class="fas fa-filter"></i> Apply Filters
                    </button>
                </div>
            </div>
        </form>

        <div class="card">
            <div class="card-header">
                <h3>Attendance Logs</h3>
            </div>
            <div class="card-body">
                <?php if (!empty($logs)): ?>
                    <table class="attendance-table">
                        <thead>
                            <tr>
                                <th>Staff Member</th>
                                <th>Clock In</th>
                                <th>Clock Out</th>
                                <th>Method</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($log['fullname']); ?></td>
                                    <td><?php echo htmlspecialchars(date('M d, Y h:i A', strtotime($log['clock_in']))); ?></td>
                                    <td>
                                        <?php if ($log['clock_out']): ?>
                                            <?php echo htmlspecialchars(date('M d, Y h:i A', strtotime($log['clock_out']))); ?>
                                        <?php else: ?>
                                            <span class="badge badge-warning">Open</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="method-pill"><?php echo htmlspecialchars(ucfirst($log['method'] ?? 'self')); ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p class="text-muted">No attendance logs found for the selected date range.</p>
                <?php endif; ?>
            </div>
        </div>
    </main>
</body>
</html>
