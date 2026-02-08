<?php
session_start();
require_once '../config/db.php';
require_role('owner');

$owner_id = $_SESSION['user']['id'];
$availability_days = [
    1 => 'Monday',
    2 => 'Tuesday',
    3 => 'Wednesday',
    4 => 'Thursday',
    5 => 'Friday',
    6 => 'Saturday',
    7 => 'Sunday',
];

// Get shop details
$shop_stmt = $pdo->prepare("SELECT * FROM shops WHERE owner_id = ?");
$shop_stmt->execute([$owner_id]);
$shop = $shop_stmt->fetch();

if(!$shop) {
    header("Location: create_shop.php");
    exit();
}

$shop_id = $shop['id'];

// Deactivate staff
if(isset($_GET['deactivate'])) {
    $emp_id = (int) $_GET['deactivate'];
    $staff_stmt = $pdo->prepare("SELECT user_id FROM shop_staffs WHERE id = ? AND shop_id = ?");
    $staff_stmt->execute([$emp_id, $shop_id]);
    $staff = $staff_stmt->fetch();

    if($staff) {
        $deactivate_stmt = $pdo->prepare("UPDATE shop_staffs SET status = 'inactive' WHERE id = ? AND shop_id = ?");
        $deactivate_stmt->execute([$emp_id, $shop_id]);

        $unassign_stmt = $pdo->prepare("
            UPDATE orders 
            SET assigned_to = NULL 
            WHERE shop_id = ? AND assigned_to = ? AND status IN ('pending', 'accepted', 'in_progress')
        ");
        $unassign_stmt->execute([$shop_id, $staff['user_id']]);

        $success = "staff deactivated successfully!";
    } else {
        $error = "staff not found for this shop.";
    }
}

// Reactivate staff
if(isset($_GET['reactivate'])) {
    $emp_id = (int) $_GET['reactivate'];
    $staff_stmt = $pdo->prepare("SELECT user_id FROM shop_staffs WHERE id = ? AND shop_id = ?");
    $staff_stmt->execute([$emp_id, $shop_id]);
    $staff = $staff_stmt->fetch();

    if($staff) {
        $reactivate_stmt = $pdo->prepare("UPDATE shop_staffs SET status = 'active' WHERE id = ? AND shop_id = ?");
        $reactivate_stmt->execute([$emp_id, $shop_id]);

        $success = "staff reactivated successfully!";
    } else {
        $error = "staff not found for this shop.";
    }
}

// Get all staffs
$staffs_stmt = $pdo->prepare("
    SELECT 
        se.*, 
        u.fullname, 
        u.email, 
        u.phone, 
        u.created_at as joined_date,
        (
            SELECT COUNT(*) 
            FROM orders o 
            WHERE o.shop_id = se.shop_id 
              AND o.assigned_to = se.user_id 
              AND o.status IN ('pending', 'accepted', 'in_progress')
        ) as active_orders
    FROM shop_staffs se 
    JOIN users u ON se.user_id = u.id 
    WHERE se.shop_id = ? 
    ORDER BY se.created_at DESC
");
$staffs_stmt->execute([$shop_id]);
$staffs = $staffs_stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Staff - <?php echo htmlspecialchars($shop['shop_name']); ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <nav class="navbar">
        <div class="container d-flex justify-between align-center">
            <a href="dashboard.php" class="navbar-brand">
                <i class="fas fa-store"></i> <?php echo htmlspecialchars($shop['shop_name']); ?>
            </a>
            <ul class="navbar-nav">
                <li><a href="dashboard.php" class="nav-link">Dashboard</a></li>
                <li><a href="shop_profile.php" class="nav-link">Shop Profile</a></li>
                <li><a href="manage_staff.php" class="nav-link active">Staff</a></li>
                <li><a href="shop_orders.php" class="nav-link">Orders</a></li>
                <li><a href="reviews.php" class="nav-link">Reviews</a></li>
                <li><a href="messages.php" class="nav-link">Messages</a></li>
                <li><a href="payment_verifications.php" class="nav-link">Payments</a></li>
                <li><a href="earnings.php" class="nav-link">Earnings</a></li>
                <li class="dropdown">
                    <a href="#" class="nav-link dropdown-toggle">
                        <i class="fas fa-user"></i> <?php echo htmlspecialchars($_SESSION['user']['fullname']); ?>
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
            <h2>Manage Staff</h2>
            <p class="text-muted">View staff and HR members for your shop.</p>
            <a class="btn btn-primary" href="create_hr.php">
                <i class="fas fa-user-plus"></i> Create HR
            </a>
        </div>

        <?php if(isset($error)): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <?php if(isset($success)): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>

        <!-- Staff & HR Table -->
        <div class="card">
            <h3>Current Team Members (<?php echo count($staffs); ?>)</h3>
            <?php if(!empty($staffs)): ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Member</th>
                            <th>Role</th>
                            <th>Position</th>
                            <th>Contact</th>
                            <th>Joined</th>
                            <th>Status</th>
                            <th>Availability</th>
                            <th>Capacity</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($staffs as $emp): ?>
                        <?php
                            $availability_summary = 'Not set';
                            $availability_days_list = [];
                            if($emp['staff_role'] === 'hr') {
                                $availability_summary = 'Not required';
                            } elseif(!empty($emp['availability_days'])) {
                                $decoded_days = json_decode($emp['availability_days'], true);
                                if(is_array($decoded_days)) {
                                    foreach ($decoded_days as $day_number) {
                                        if(isset($availability_days[$day_number])) {
                                            $availability_days_list[] = $availability_days[$day_number];
                                        }
                                    }
                                }
                            }
                            if(!empty($availability_days_list)) {
                                $availability_summary = implode(', ', $availability_days_list);
                            }
                            if(!empty($emp['availability_start']) || !empty($emp['availability_end'])) {
                                $start_time = !empty($emp['availability_start']) ? date('h:i A', strtotime($emp['availability_start'])) : 'Any time';
                                $end_time = !empty($emp['availability_end']) ? date('h:i A', strtotime($emp['availability_end'])) : 'Any time';
                                $availability_summary .= '<br><small class="text-muted">' . $start_time . ' - ' . $end_time . '</small>';
                            }
                            $max_capacity = (int) ($emp['max_active_orders'] ?? 0);
                            $active_orders = (int) ($emp['active_orders'] ?? 0);
                            $display_position = $emp['position'];
                            if($emp['staff_role'] === 'hr' && empty($display_position)) {
                                $display_position = 'HR';
                            }
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($emp['fullname']); ?></strong><br>
                                <small class="text-muted">ID: <?php echo $emp['id']; ?></small>
                            </td>
                            <td><?php echo strtoupper(htmlspecialchars($emp['staff_role'])); ?></td>
                            <td><?php echo htmlspecialchars($display_position); ?></td>
                            <td>
                                <?php echo htmlspecialchars($emp['email']); ?><br>
                                <small><?php echo $emp['phone']; ?></small>
                            </td>
                            <td><?php echo date('M d, Y', strtotime($emp['joined_date'])); ?></td>
                            <td>
                                <?php if($emp['status'] === 'active'): ?>
                                    <span class="badge badge-success">Active</span>
                                <?php else: ?>
                                    <span class="badge badge-warning">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $availability_summary; ?></td>
                            <td>
                                <?php if($max_capacity > 0 && $emp['staff_role'] !== 'hr'): ?>
                                    <span class="badge <?php echo $active_orders >= $max_capacity ? 'badge-danger' : 'badge-info'; ?>">
                                        <?php echo $active_orders; ?> / <?php echo $max_capacity; ?>
                                    </span>
                                    <div><small class="text-muted">Active orders</small></div>
                                <?php else: ?>
                                    <span class="text-muted">Not set</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if($emp['status'] === 'active'): ?>
                                    <a href="manage_staff.php?deactivate=<?php echo $emp['id']; ?>" 
                                       class="btn btn-sm btn-outline-danger"
                                       onclick="return confirm('Deactivate this staff and unassign active orders?')">Deactivate</a>
                                <?php else: ?>
                                    <a href="manage_staff.php?reactivate=<?php echo $emp['id']; ?>" 
                                       class="btn btn-sm btn-outline-success"
                                       onclick="return confirm('Reactivate this staff for the shop?')">Reactivate</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="text-center p-4">
                    <i class="fas fa-users fa-3x text-muted mb-3"></i>
                    <h4>No Team Members Yet</h4>
                    <p class="text-muted">Create your first HR lead to get started.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Staff Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-number"><?php echo count($staffs); ?></div>
                <div class="stat-label">Total Members</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-user-check"></i>
                </div>
                <div class="stat-number">
                    <?php 
                    $active = $pdo->prepare("SELECT COUNT(*) as count FROM shop_staffs WHERE shop_id = ? AND status = 'active'");
                    $active->execute([$shop_id]);
                    echo $active->fetch()['count'];
                    ?>
                </div>
                <div class="stat-label">Active</div>
            </div>
        </div>
    </div>
</body>
</html>