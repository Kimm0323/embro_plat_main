<?php
session_start();
require_once '../config/db.php';
require_role(['staff','employee']);

$staff_id = $_SESSION['user']['id'];

$emp_stmt = $pdo->prepare("
    SELECT se.*, s.shop_name, s.logo
    FROM shop_staffs se
    JOIN shops s ON se.shop_id = s.id
    WHERE se.user_id = ? AND se.status = 'active'
");
$emp_stmt->execute([$staff_id]);
$staff = $emp_stmt->fetch();

if (!$staff) {
    die("You are not assigned to any shop. Please contact your shop owner.");
}

$shop_id = (int) $staff['shop_id'];
$staff_permissions = fetch_staff_permissions($pdo, $staff_id);

$message = null;
$message_type = 'success';

$open_log_stmt = $pdo->prepare("
    SELECT id, clock_in
    FROM attendance_logs
    WHERE shop_id = ? AND staff_user_id = ? AND clock_out IS NULL
    ORDER BY clock_in DESC
    LIMIT 1
");
$open_log_stmt->execute([$shop_id, $staff_id]);
$open_log = $open_log_stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'clock_in') {
        if ($open_log) {
            $message_type = 'danger';
            $message = 'You already have an open shift. Please clock out before starting a new one.';
        } else {
            $insert_stmt = $pdo->prepare("
                INSERT INTO attendance_logs (shop_id, staff_user_id, clock_in, method)
                VALUES (?, ?, NOW(), 'self')
            ");
            $insert_stmt->execute([$shop_id, $staff_id]);
            $message = 'Clock-in recorded successfully.';
        }
    } elseif ($action === 'clock_out') {
        if (!$open_log) {
            $message_type = 'danger';
            $message = 'No active clock-in found. Please clock in first.';
        } else {
            $update_stmt = $pdo->prepare("
                UPDATE attendance_logs
                SET clock_out = NOW()
                WHERE id = ?
            ");
            $update_stmt->execute([$open_log['id']]);
            $message = 'Clock-out recorded successfully.';
        }
    }

    $open_log_stmt->execute([$shop_id, $staff_id]);
    $open_log = $open_log_stmt->fetch();
}

$recent_logs_stmt = $pdo->prepare("
    SELECT clock_in, clock_out, method
    FROM attendance_logs
    WHERE shop_id = ? AND staff_user_id = ?
    ORDER BY clock_in DESC
    LIMIT 10
");
$recent_logs_stmt->execute([$shop_id, $staff_id]);
$recent_logs = $recent_logs_stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance - <?php echo htmlspecialchars($staff['shop_name']); ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .attendance-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.5rem;
            margin-top: 1.5rem;
        }
        .attendance-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 1.5rem;
        }
        .attendance-card h3 {
            margin-bottom: 0.5rem;
        }
        .attendance-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            margin-top: 1rem;
        }
        .attendance-actions button {
            border: none;
            cursor: pointer;
        }
        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.25rem 0.6rem;
            border-radius: 999px;
            background: #e0f2fe;
            color: #0369a1;
            font-size: 0.85rem;
            font-weight: 600;
        }
        .status-pill--active {
            background: #dcfce7;
            color: #166534;
        }
        .status-pill--inactive {
            background: #fee2e2;
            color: #991b1b;
        }
        .logs-table {
            width: 100%;
            border-collapse: collapse;
        }
        .logs-table th,
        .logs-table td {
            padding: 0.75rem;
            border-bottom: 1px solid #e5e7eb;
            text-align: left;
        }
        .logs-table th {
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            color: #6b7280;
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="container d-flex justify-between align-center">
            <a href="dashboard.php" class="navbar-brand">
                <i class="fas fa-user-tie"></i> staff Dashboard
            </a>
            <ul class="navbar-nav">
                <li><a href="dashboard.php" class="nav-link">Dashboard</a></li>
                <?php if (!empty($staff_permissions['view_jobs'])): ?>
                    <li><a href="assigned_jobs.php" class="nav-link">My Jobs</a></li>
                    <li><a href="schedule.php" class="nav-link">Schedule</a></li>
                <?php endif; ?>
                <?php if (!empty($staff_permissions['update_status'])): ?>
                    <li><a href="update_status.php" class="nav-link">Update Status</a></li>
                <?php endif; ?>
                <?php if (!empty($staff_permissions['upload_photos'])): ?>
                    <li><a href="upload_photos.php" class="nav-link">Upload Photos</a></li>
                <?php endif; ?>
                <li><a href="attendance.php" class="nav-link active">Attendance</a></li>
                <li class="dropdown">
                    <a href="#" class="nav-link dropdown-toggle">
                        <i class="fas fa-user"></i> <?php echo $_SESSION['user']['fullname']; ?>
                    </a>
                    <div class="dropdown-menu">
                        <a href="profile.php" class="dropdown-item"><i class="fas fa-user-cog"></i> Profile</a>
                        <a href="../auth/logout.php" class="dropdown-item"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </li>
            </ul>
        </div>
    </nav>

    <main class="container">
        <section class="page-header">
            <div>
                <h1>Time &amp; Attendance</h1>
                <p class="text-muted">Clock in and out for <?php echo htmlspecialchars($staff['shop_name']); ?> shifts.</p>
            </div>
            <span class="badge">Logged in as <?php echo htmlspecialchars($_SESSION['user']['fullname']); ?></span>
        </section>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo htmlspecialchars($message_type); ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <div class="attendance-grid">
            <div class="attendance-card">
                <h3>Current Status</h3>
                <?php if ($open_log): ?>
                    <span class="status-pill status-pill--active"><i class="fas fa-circle"></i> Clocked in</span>
                    <p class="mt-2 text-muted">Started at <?php echo htmlspecialchars(date('M d, Y h:i A', strtotime($open_log['clock_in']))); ?></p>
                <?php else: ?>
                    <span class="status-pill status-pill--inactive"><i class="fas fa-circle"></i> Clocked out</span>
                    <p class="mt-2 text-muted">You have no active shift.</p>
                <?php endif; ?>
            </div>

            <div class="attendance-card">
                <h3>Actions</h3>
                <p class="text-muted">Record your shift time with a single tap.</p>
                <div class="attendance-actions">
                    <form method="post">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="clock_in">
                        <button type="submit" class="btn btn-primary" <?php echo $open_log ? 'disabled' : ''; ?>>
                            <i class="fas fa-play"></i> Clock In
                        </button>
                    </form>
                    <form method="post">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="clock_out">
                        <button type="submit" class="btn btn-danger" <?php echo $open_log ? '' : 'disabled'; ?>>
                            <i class="fas fa-stop"></i> Clock Out
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="card" style="margin-top: 2rem;">
            <div class="card-header">
                <h3>Recent Attendance</h3>
            </div>
            <div class="card-body">
                <?php if (!empty($recent_logs)): ?>
                    <table class="logs-table">
                        <thead>
                            <tr>
                                <th>Clock In</th>
                                <th>Clock Out</th>
                                <th>Method</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_logs as $log): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars(date('M d, Y h:i A', strtotime($log['clock_in']))); ?></td>
                                    <td>
                                        <?php if ($log['clock_out']): ?>
                                            <?php echo htmlspecialchars(date('M d, Y h:i A', strtotime($log['clock_out']))); ?>
                                        <?php else: ?>
                                            <span class="badge badge-warning">Open</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars(ucfirst($log['method'] ?? 'self')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p class="text-muted">No attendance logs yet.</p>
                <?php endif; ?>
            </div>
        </div>
    </main>
</body>
</html>
