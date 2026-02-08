<?php
session_start();
require_once '../config/db.php';
require_role(['staff','employee']);

$staff_id = $_SESSION['user']['id'];

// Get staff shop info
$emp_stmt = $pdo->prepare("
    SELECT se.*, s.shop_name, s.logo 
    FROM shop_staffs se 
    JOIN shops s ON se.shop_id = s.id 
    WHERE se.user_id = ? AND se.status = 'active'
");
$emp_stmt->execute([$staff_id]);
$staff = $emp_stmt->fetch();

// If not associated with any shop
if(!$staff) {
    die("You are not assigned to any shop. Please contact your shop owner.");
}

$shop_id = $staff['shop_id'];

$staff_permissions = fetch_staff_permissions($pdo, $staff_id);
$can_view_jobs = !empty($staff_permissions['view_jobs']);
$can_update_status = !empty($staff_permissions['update_status']);
$can_upload_photos = !empty($staff_permissions['upload_photos']);

if ($can_view_jobs) {
    // Get assigned jobs
    $jobs_stmt = $pdo->prepare("
        SELECT 
            o.*,
            u.fullname as client_name,
            COALESCE(js.scheduled_date, o.scheduled_date) as schedule_date,
            js.scheduled_time as schedule_time 
        FROM orders o 
        JOIN users u ON o.client_id = u.id 
        LEFT JOIN job_schedule js ON js.order_id = o.id AND js.staff_id = ?
        WHERE (o.assigned_to = ? OR js.staff_id = ?)
          AND o.status IN ('accepted', 'in_progress')
        ORDER BY schedule_date ASC, js.scheduled_time ASC
    ");
    $jobs_stmt->execute([$staff_id, $staff_id, $staff_id]);
    $assigned_jobs = $jobs_stmt->fetchAll();

    // Get today's schedule
    $schedule_stmt = $pdo->prepare("
        SELECT 
            o.id as order_id,
            o.order_number,
            o.service_type,
            o.status as order_status,
            u.fullname as client_name,
            COALESCE(js.scheduled_date, o.scheduled_date) as schedule_date,
            js.scheduled_time as schedule_time,
            COALESCE(js.status, o.status) as schedule_status,
            js.task_description
        FROM orders o
        JOIN users u ON o.client_id = u.id
        LEFT JOIN job_schedule js ON js.order_id = o.id AND js.staff_id = ?
        WHERE (o.assigned_to = ? OR js.staff_id = ?)
          AND COALESCE(js.scheduled_date, o.scheduled_date) = CURDATE()
        ORDER BY schedule_time ASC
    ");
    $schedule_stmt->execute([$staff_id, $staff_id, $staff_id]);
    $today_schedule = $schedule_stmt->fetchAll();

    // Get job statistics
    $stats_stmt = $pdo->prepare("
        SELECT 
            (SELECT COUNT(*) FROM orders o WHERE (o.assigned_to = ? OR EXISTS (SELECT 1 FROM job_schedule js WHERE js.order_id = o.id AND js.staff_id = ?)) AND o.status = 'in_progress') as in_progress,
            (SELECT COUNT(*) FROM orders o WHERE (o.assigned_to = ? OR EXISTS (SELECT 1 FROM job_schedule js WHERE js.order_id = o.id AND js.staff_id = ?)) AND o.status = 'completed') as completed,
            (SELECT COUNT(*) FROM orders o WHERE (o.assigned_to = ? OR EXISTS (SELECT 1 FROM job_schedule js WHERE js.order_id = o.id AND js.staff_id = ?)) AND COALESCE((SELECT js2.scheduled_date FROM job_schedule js2 WHERE js2.order_id = o.id AND js2.staff_id = ? LIMIT 1), o.scheduled_date) = CURDATE()) as today_tasks,
            (SELECT AVG(o.rating) FROM orders o WHERE (o.assigned_to = ? OR EXISTS (SELECT 1 FROM job_schedule js WHERE js.order_id = o.id AND js.staff_id = ?)) AND o.rating IS NOT NULL) as avg_rating
    ");
    $stats_stmt->execute([$staff_id, $staff_id, $staff_id, $staff_id, $staff_id, $staff_id, $staff_id, $staff_id, $staff_id]);
    $stats = $stats_stmt->fetch();
} else {
    $assigned_jobs = [];
    $today_schedule = [];
    $stats = [
        'in_progress' => 0,
        'completed' => 0,
        'today_tasks' => 0,
        'avg_rating' => 0,
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>staff Dashboard - <?php echo htmlspecialchars($staff['shop_name']); ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .staff-header {
            background: linear-gradient(135deg, #00b09b 0%, #96c93d 100%);
            color: white;
            padding: 25px;
            border-radius: 10px;
            margin-bottom: 25px;
        }
        .task-list {
            list-style: none;
            padding: 0;
        }
        .task-item {
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 10px;
            transition: all 0.3s;
        }
        .task-item:hover {
            border-color: #4361ee;
            box-shadow: 0 3px 10px rgba(67, 97, 238, 0.1);
        }
        .progress-bar-container {
            width: 100%;
            background: #e9ecef;
            border-radius: 5px;
            overflow: hidden;
            height: 10px;
        }
        .progress-fill {
            height: 100%;
            background: #4361ee;
            border-radius: 5px;
        }
        .job-card {
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar">
        <div class="container d-flex justify-between align-center">
            <a href="dashboard.php" class="navbar-brand">
                <i class="fas fa-user-tie"></i> staff Dashboard
            </a>
            <ul class="navbar-nav">
                <li><a href="dashboard.php" class="nav-link active">Dashboard</a></li>
                <?php if($can_view_jobs): ?>
                    <li><a href="assigned_jobs.php" class="nav-link">My Jobs</a></li>
                    <li><a href="schedule.php" class="nav-link">Schedule</a></li>
                <?php endif; ?>
                <?php if($can_update_status): ?>
                    <li><a href="update_status.php" class="nav-link">Update Status</a></li>
                <?php endif; ?>
                <?php if($can_upload_photos): ?>
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
        <!-- staff Header -->
        <div class="staff-header">
            <div class="d-flex justify-between align-center">
                <div>
                    <h2>Welcome, <?php echo htmlspecialchars($_SESSION['user']['fullname']); ?>!</h2>
                    <p class="mb-0">
                        <i class="fas fa-store"></i> <?php echo htmlspecialchars($staff['shop_name']); ?>
                        | <i class="fas fa-briefcase"></i> <?php echo htmlspecialchars($staff['position']); ?>
                    </p>
                </div>
                <div class="text-right">
                    <div class="d-flex" style="gap: 20px;">
                        <div>
                            <div class="stat-number" style="color: white; font-size: 2rem;"><?php echo $stats['in_progress']; ?></div>
                            <div class="stat-label" style="color: rgba(255,255,255,0.8);">Active Jobs</div>
                        </div>
                        <div>
                            <div class="stat-number" style="color: white; font-size: 2rem;"><?php echo $stats['completed']; ?></div>
                            <div class="stat-label" style="color: rgba(255,255,255,0.8);">Completed</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon text-primary">
                    <i class="fas fa-tasks"></i>
                </div>
                <div class="stat-number"><?php echo $stats['in_progress']; ?></div>
                <div class="stat-label">Active Jobs</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon text-success">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-number"><?php echo $stats['completed']; ?></div>
                <div class="stat-label">Completed</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon text-warning">
                    <i class="fas fa-calendar-check"></i>
                </div>
                <div class="stat-number"><?php echo $stats['today_tasks']; ?></div>
                <div class="stat-label">Today's Tasks</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon text-info">
                    <i class="fas fa-star"></i>
                </div>
                <div class="stat-number"><?php echo number_format($stats['avg_rating'] ?? 0, 1); ?></div>
                <div class="stat-label">Avg Rating</div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="card mb-4">
            <h3>Quick Actions</h3>
            <div class="d-flex flex-wrap" style="gap: 10px;">
                <?php if($can_view_jobs): ?>
                    <a href="assigned_jobs.php" class="btn btn-primary">
                        <i class="fas fa-list"></i> View All Jobs
                    </a>
                <?php endif; ?>
                <?php if($can_update_status): ?>
                    <a href="update_status.php" class="btn btn-outline-primary">
                        <i class="fas fa-edit"></i> Update Job Status
                    </a>
                <?php endif; ?>
                <?php if($can_upload_photos): ?>
                    <a href="upload_photos.php" class="btn btn-outline-success">
                        <i class="fas fa-camera"></i> Upload Photos
                    </a>
                <?php endif; ?>
                <?php if($can_view_jobs): ?>
                    <a href="schedule.php" class="btn btn-outline-warning">
                        <i class="fas fa-calendar"></i> View Schedule
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <div class="row" style="display: flex; gap: 20px;">
            <!-- Today's Schedule -->
            <div style="flex: 1;">
                <div class="card">
                    <h3><i class="fas fa-calendar-day"></i> Today's Schedule (<?php echo date('F j, Y'); ?>)</h3>
                    <?php if(!$can_view_jobs): ?>
                        <div class="text-center p-4">
                            <i class="fas fa-lock fa-3x text-muted mb-3"></i>
                            <h4>Schedule Access Restricted</h4>
                            <p class="text-muted">Your permissions do not allow schedule access. Contact your shop owner.</p>
                        </div>
                    <?php elseif(!empty($today_schedule)): ?>
                        <ul class="task-list">
                            <?php foreach($today_schedule as $task): ?>
                            <li class="task-item">
                                <div class="d-flex justify-between align-center">
                                    <div>
                                        <h5 class="mb-1"><?php echo htmlspecialchars($task['service_type']); ?></h5>
                                        <p class="mb-1 text-muted">
                                            <i class="fas fa-user"></i> <?php echo htmlspecialchars($task['client_name']); ?>
                                        </p>
                                        <p class="mb-0">
                                            <i class="fas fa-clock"></i> 
                                            <?php if(!empty($task['schedule_time'])): ?>
                                                <?php echo date('h:i A', strtotime($task['schedule_time'])); ?>
                                            <?php else: ?>
                                                <span class="text-muted">Time TBD</span>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                    <div>
                                        <span class="badge badge-<?php echo $task['schedule_status'] == 'completed' ? 'success' : 'info'; ?>">
                                            <?php echo ucfirst($task['schedule_status']); ?>
                                        </span>
                                    </div>
                                </div>
                                <?php if($task['task_description']): ?>
                                    <p class="mt-2 mb-0"><small><?php echo htmlspecialchars($task['task_description']); ?></small></p>
                                <?php endif; ?>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <div class="text-center p-4">
                            <i class="fas fa-calendar-times fa-3x text-muted mb-3"></i>
                            <h4>No Schedule for Today</h4>
                            <p class="text-muted">You have no tasks scheduled for today.</p>
                        </div>
                    <?php endif; ?>
                    <div class="text-center mt-3">
                        <a href="schedule.php" class="btn btn-outline-primary">View Full Schedule</a>
                    </div>
                </div>
            </div>

            <!-- Assigned Jobs -->
            <div style="flex: 1;">
                <div class="card">
                    <h3><i class="fas fa-tasks"></i> Currently Assigned Jobs</h3>
                    <?php if(!$can_view_jobs): ?>
                        <div class="text-center p-4">
                            <i class="fas fa-lock fa-3x text-muted mb-3"></i>
                            <h4>Jobs Access Restricted</h4>
                            <p class="text-muted">Your permissions do not allow job details. Contact your shop owner.</p>
                        </div>
                    <?php elseif(!empty($assigned_jobs)): ?>
                        <?php foreach($assigned_jobs as $job): ?>
                        <div class="job-card">
                            <div class="d-flex justify-between align-center">
                                <div>
                                    <h5 class="mb-1"><?php echo htmlspecialchars($job['service_type']); ?></h5>
                                    <p class="mb-1 text-muted">
                                        <i class="fas fa-user"></i> <?php echo htmlspecialchars($job['client_name']); ?>
                                    </p>
                                    <div class="d-flex align-center">
                                        <div class="progress-bar-container" style="width: 100px;">
                                            <div class="progress-fill" style="width: <?php echo $job['progress']; ?>%;"></div>
                                        </div>
                                        <span class="ml-2"><?php echo $job['progress']; ?>%</span>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <div class="mb-2">
                                        <small class="text-muted">Order #<?php echo $job['order_number']; ?></small>
                                    </div>
                                    <?php if($can_update_status): ?>
                                        <a href="update_status.php?order_id=<?php echo $job['id']; ?>" 
                                           class="btn btn-sm btn-primary">
                                            <i class="fas fa-edit"></i> Update
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if($job['schedule_date']): ?>
                                <div class="mt-2">
                                    <small class="text-muted">
                                        <i class="fas fa-calendar"></i> 
                                        Scheduled: <?php echo date('M d, Y', strtotime($job['schedule_date'])); ?>
                                        <?php if(!empty($job['schedule_time'])): ?>
                                            <span class="ml-1"><i class="fas fa-clock"></i> <?php echo date('h:i A', strtotime($job['schedule_time'])); ?></span>
                                        <?php endif; ?>
                                    </small>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                        <div class="text-center mt-3">
                            <a href="assigned_jobs.php" class="btn btn-outline-primary">View All Jobs</a>
                        </div>
                    <?php else: ?>
                        <div class="text-center p-4">
                            <i class="fas fa-clipboard-list fa-3x text-muted mb-3"></i>
                            <h4>No Assigned Jobs</h4>
                            <p class="text-muted">You don't have any assigned jobs at the moment.</p>
                            <a href="schedule.php" class="btn btn-primary">View Schedule</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Recent Activity -->
        <div class="card mt-4">
            <h3><i class="fas fa-history"></i> Recent Activity</h3>
            <div class="row" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 15px;">
                <div class="alert alert-info">
                    <h6><i class="fas fa-info-circle"></i> Performance Summary</h6>
                    <p class="mb-1">Completion Rate: 
                        <?php 
                        $total_jobs = $stats['in_progress'] + $stats['completed'];
                        $completion_rate = $total_jobs > 0 ? ($stats['completed'] / $total_jobs * 100) : 0;
                        echo round($completion_rate, 1);
                        ?>%
                    </p>
                    <p class="mb-0">Average Rating: <?php echo number_format($stats['avg_rating'] ?? 0, 1); ?>/5</p>
                </div>
                
                <div class="alert alert-success">
                    <h6><i class="fas fa-trophy"></i> Achievement</h6>
                    <p class="mb-0">
                        <?php if($stats['completed'] >= 10): ?>
                            <i class="fas fa-star text-warning"></i> You've completed 10+ jobs!
                        <?php elseif($stats['completed'] >= 5): ?>
                            <i class="fas fa-star text-warning"></i> You've completed 5+ jobs!
                        <?php else: ?>
                            Keep up the good work! Complete more jobs to earn achievements.
                        <?php endif; ?>
                    </p>
                </div>
                
                <div class="alert alert-warning">
                    <h6><i class="fas fa-bell"></i> Notifications</h6>
                    <p class="mb-0">
                        <?php echo $stats['today_tasks']; ?> tasks scheduled for today.
                        <?php if($stats['today_tasks'] == 0): ?>
                            No tasks for today.
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <p>&copy; 2024 <?php echo htmlspecialchars($staff['shop_name']); ?> - staff Portal</p>
            <small class="text-muted">staff ID: <?php echo $staff['id']; ?> | Position: <?php echo $staff['position']; ?></small>
        </div>
    </footer>

    <script>
        // Auto-refresh every 30 seconds
        setTimeout(function() {
            location.reload();
        }, 30000);
    </script>
</body>
</html>