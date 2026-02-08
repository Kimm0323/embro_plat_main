<?php
session_start();
require_once '../config/db.php';
require_once '../config/constants.php';
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

$jobs_stmt = $pdo->prepare("
    SELECT 
        o.*,
        u.fullname as client_name,
        s.shop_name,
        COALESCE(js.scheduled_date, o.scheduled_date) as schedule_date,
        js.scheduled_time as schedule_time
    FROM orders o 
    JOIN users u ON o.client_id = u.id 
    JOIN shops s ON o.shop_id = s.id
    LEFT JOIN job_schedule js ON js.order_id = o.id AND js.staff_id = ?
    WHERE (o.assigned_to = ? OR js.staff_id = ?)
    ORDER BY schedule_date ASC, js.scheduled_time ASC, o.created_at DESC
");
$jobs_stmt->execute([$staff_id, $staff_id, $staff_id]);
$assigned_jobs = $jobs_stmt->fetchAll();

function job_status_badge($status) {
    $map = [
        'accepted' => 'badge-primary',
        'in_progress' => 'badge-warning',
        'completed' => 'badge-success',
        'pending' => 'badge-secondary',
        'cancelled' => 'badge-danger'
    ];
    $class = $map[$status] ?? 'badge-secondary';
    return '<span class="badge ' . $class . '">' . ucfirst(str_replace('_', ' ', $status)) . '</span>';
}

function is_design_image(?string $filename): bool {
    if(!$filename) {
        return false;
    }
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($extension, ALLOWED_IMAGE_TYPES, true);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assigned Jobs - <?php echo htmlspecialchars($staff['shop_name']); ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .job-card {
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
        }
        .job-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 10px;
            margin-top: 15px;
        }
        .job-meta div {
            font-size: 0.9rem;
            color: #6c757d;
        }
        .progress-bar-container {
            width: 120px;
            background: #e9ecef;
            border-radius: 5px;
            overflow: hidden;
            height: 8px;
            display: inline-block;
            vertical-align: middle;
            margin-right: 8px;
        }
        .progress-fill {
            height: 100%;
            background: #4361ee;
        }
        .status-label {
            font-size: 0.75rem;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: #6c757d;
            margin-bottom: 6px;
        }
        .job-progress-label {
            font-size: 0.8rem;
            color: #6c757d;
            margin-bottom: 6px;
        }
        .design-preview {
            margin-top: 12px;
        }
        .design-preview img {
            width: 100%;
            max-width: 320px;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
        }
        .design-file {
            margin-top: 10px;
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
                    <li><a href="assigned_jobs.php" class="nav-link active">My Jobs</a></li>
                    <li><a href="schedule.php" class="nav-link">Schedule</a></li>
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
            <h2>Assigned Jobs</h2>
            <p class="text-muted">Track all jobs assigned to you and their current progress.</p>
        </div>

        <?php if(!empty($assigned_jobs)): ?>
            <?php foreach($assigned_jobs as $job): ?>
                <?php $payment_hold = payment_hold_status($job['status'] ?? STATUS_PENDING, $job['payment_status'] ?? 'unpaid'); ?>
                <div class="job-card">
                    <div class="d-flex justify-between align-center">
                        <div>
                            <h4 class="mb-1"><?php echo htmlspecialchars($job['service_type']); ?></h4>
                            <p class="mb-1 text-muted">
                                <i class="fas fa-user"></i> <?php echo htmlspecialchars($job['client_name']); ?>
                                <span class="ml-2">Order #<?php echo htmlspecialchars($job['order_number']); ?></span>
                            </p>
                        </div>
                        <div class="text-right">
                            <div class="status-label">Order Status</div>
                            <?php echo job_status_badge($job['status']); ?>
                            <div class="mt-2">
                                <?php if(!empty($staff_permissions['update_status'])): ?>
                                    <a href="update_status.php?order_id=<?php echo $job['id']; ?>" class="btn btn-sm btn-primary">
                                        <i class="fas fa-edit"></i> Update
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="mt-3">
                        <div class="job-progress-label">Job Steps Progress</div>
                        <div class="d-flex align-center">
                            <div class="progress-bar-container">
                                <div class="progress-fill" style="width: <?php echo $job['progress']; ?>%;"></div>
                            </div>
                            <strong><?php echo $job['progress']; ?>%</strong>
                        </div>
                    </div>

                    <div class="job-meta">
                        <div><i class="fas fa-store"></i> <?php echo htmlspecialchars($job['shop_name']); ?></div>
                        <div>
                            <i class="fas fa-hand-holding-dollar"></i>
                            <span class="hold-pill <?php echo htmlspecialchars($payment_hold['class']); ?>">
                                Hold: <?php echo htmlspecialchars($payment_hold['label']); ?>
                            </span>
                        </div>
                        <?php if(!empty($job['schedule_date'])): ?>
                            <div>
                                <i class="fas fa-calendar"></i> Scheduled: <?php echo date('M d, Y', strtotime($job['schedule_date'])); ?>
                                <?php if(!empty($job['schedule_time'])): ?>
                                    <span class="ml-1"><i class="fas fa-clock"></i> <?php echo date('h:i A', strtotime($job['schedule_time'])); ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <?php if(!empty($job['design_description'])): ?>
                            <div><i class="fas fa-clipboard"></i> <?php echo htmlspecialchars($job['design_description']); ?></div>
                        <?php endif; ?>
                    </div>
                    <?php if(!empty($job['design_file'])): ?>
                        <div class="design-file">
                            <a href="../assets/uploads/designs/<?php echo htmlspecialchars($job['design_file']); ?>" target="_blank" rel="noopener noreferrer">
                                <i class="fas fa-paperclip"></i> View design file
                            </a>
                        </div>
                        <?php if(is_design_image($job['design_file'])): ?>
                            <div class="design-preview">
                                <img src="../assets/uploads/designs/<?php echo htmlspecialchars($job['design_file']); ?>" alt="Client design upload">
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="card">
                <div class="text-center p-4">
                    <i class="fas fa-clipboard-list fa-3x text-muted mb-3"></i>
                    <h4>No Assigned Jobs</h4>
                    <p class="text-muted">You don't have any assigned jobs at the moment.</p>
                    <a href="dashboard.php" class="btn btn-primary">Back to Dashboard</a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
