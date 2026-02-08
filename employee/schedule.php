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

if(!$staff) {
    die("You are not assigned to any shop. Please contact your shop owner.");
}

$staff_permissions = fetch_staff_permissions($pdo, $staff_id);
require_staff_permission($pdo, $staff_id, 'view_jobs');

$schedule_stmt = $pdo->prepare("
    SELECT 
        o.id as order_id,
        o.order_number,
        o.service_type,
        u.fullname as client_name,
        COALESCE(js.scheduled_date, o.scheduled_date) as schedule_date,
        js.scheduled_time as schedule_time,
        COALESCE(js.status, o.status) as schedule_status,
        js.task_description
    FROM orders o
    JOIN users u ON o.client_id = u.id
    LEFT JOIN job_schedule js ON js.order_id = o.id AND js.staff_id = ?
    WHERE (o.assigned_to = ? OR js.staff_id = ?)
      AND COALESCE(js.scheduled_date, o.scheduled_date) IS NOT NULL
    ORDER BY schedule_date ASC, schedule_time ASC
");
$schedule_stmt->execute([$staff_id, $staff_id, $staff_id]);
$schedule = $schedule_stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Schedule - <?php echo htmlspecialchars($staff['shop_name']); ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .schedule-card {
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            padding: 18px;
            margin-bottom: 12px;
            background: #fff;
        }
        .schedule-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            color: #6c757d;
            font-size: 0.9rem;
        }
        .schedule-date {
            font-weight: 600;
            color: #4361ee;
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
                <?php if(!empty($staff_permissions['view_jobs'])): ?>
                    <li><a href="assigned_jobs.php" class="nav-link">My Jobs</a></li>
                    <li><a href="schedule.php" class="nav-link active">Schedule</a></li>
                <?php endif; ?>
                <?php if(!empty($staff_permissions['update_status'])): ?>
                    <li><a href="update_status.php" class="nav-link">Update Status</a></li>
                <?php endif; ?>
                <?php if(!empty($staff_permissions['upload_photos'])): ?>
                    <li><a href="upload_photos.php" class="nav-link">Upload Photos</a></li>
                <?php endif; ?>
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

    <div class="container">
        <div class="dashboard-header">
            <h2>My Schedule</h2>
            <p class="text-muted">Upcoming tasks and appointments assigned to you.</p>
        </div>

        <?php if(!empty($schedule)): ?>
            <?php foreach($schedule as $item): ?>
                <div class="schedule-card">
                    <div class="d-flex justify-between align-center">
                        <div>
                            <h4 class="mb-1"><?php echo htmlspecialchars($item['service_type']); ?></h4>
                            <div class="schedule-meta">
                                <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($item['client_name']); ?></span>
                                <span><i class="fas fa-hashtag"></i> Order #<?php echo htmlspecialchars($item['order_number']); ?></span>
                            </div>
                        </div>
                        <div class="text-right">
                            <div class="schedule-date">
                                <?php echo date('M d, Y', strtotime($item['schedule_date'])); ?>
                            </div>
                            <?php if(!empty($item['schedule_time'])): ?>
                                <div class="text-muted">
                                    <i class="fas fa-clock"></i> <?php echo date('h:i A', strtotime($item['schedule_time'])); ?>
                                </div>
                            <?php else: ?>
                                <div class="text-muted">
                                    <i class="fas fa-clock"></i> Time TBD
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if(!empty($item['task_description'])): ?>
                        <p class="mt-2 mb-0 text-muted"><?php echo htmlspecialchars($item['task_description']); ?></p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="card">
                <div class="text-center p-4">
                    <i class="fas fa-calendar-times fa-3x text-muted mb-3"></i>
                    <h4>No Scheduled Tasks</h4>
                    <p class="text-muted">You don't have any tasks scheduled yet.</p>
                    <a href="dashboard.php" class="btn btn-primary">Back to Dashboard</a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
