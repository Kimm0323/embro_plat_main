<?php
session_start();
require_once '../config/db.php';
require_role('hr');

$hr_id = $_SESSION['user']['id'];
$available_permissions = [
    'view_jobs' => 'View assigned jobs',
    'update_status' => 'Update job status',
    'upload_photos' => 'Upload output photos',
];
$availability_days = [
    1 => 'Monday',
    2 => 'Tuesday',
    3 => 'Wednesday',
    4 => 'Thursday',
    5 => 'Friday',
    6 => 'Saturday',
    7 => 'Sunday',
];

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

$shop_id = $hr_shop['shop_id'];

if (isset($_POST['add_staff'])) {
    $fullname = sanitize($_POST['fullname']);
    $email = sanitize($_POST['email']);
    $phone = sanitize($_POST['phone']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $position = sanitize($_POST['position']);
    $selected_days = array_map('intval', $_POST['availability_days'] ?? []);
    $availability_start = $_POST['availability_start'] ?? null;
    $availability_end = $_POST['availability_end'] ?? null;
    $max_active_orders = isset($_POST['max_active_orders']) ? (int) $_POST['max_active_orders'] : 0;
    $selected_permissions = $_POST['permissions'] ?? [];
    $permissions_map = [];
    foreach ($available_permissions as $permission_key => $permission_label) {
        $permissions_map[$permission_key] = in_array($permission_key, $selected_permissions, true);
    }
    $permissions = json_encode($permissions_map);
    $availability_days_json = !empty($selected_days) ? json_encode(array_values($selected_days)) : null;

    try {
        if ($password !== $confirm_password) {
            $error = "Passwords do not match!";
        } elseif (strlen($password) < 8) {
            $error = "Password must be at least 8 characters long!";
        } elseif ($max_active_orders < 1) {
            $error = "Please set a staff capacity of at least 1 active order.";
        } elseif (empty($selected_days)) {
            $error = "Please select at least one availability day.";
        } elseif ($availability_start && $availability_end && $availability_start >= $availability_end) {
            $error = "Availability end time must be later than start time.";
        } else {
            $user_stmt = $pdo->prepare("SELECT id, role FROM users WHERE email = ?");
            $user_stmt->execute([$email]);
            $user = $user_stmt->fetch();

            if ($user) {
                if ($user['role'] !== 'staff') {
                    $error = "Staff accounts must be created separately. Existing client, owner, or HR accounts cannot be promoted to staff.";
                } else {
                    $check_stmt = $pdo->prepare("SELECT id, status FROM shop_staffs WHERE user_id = ? AND shop_id = ?");
                    $check_stmt->execute([$user['id'], $shop_id]);
                    $existing = $check_stmt->fetch();

                    if (!$existing) {
                        $add_stmt = $pdo->prepare("
                            INSERT INTO shop_staffs (shop_id, user_id, staff_role, position, permissions, availability_days, availability_start, availability_end, max_active_orders, hired_date)
                            VALUES (?, ?, 'staff', ?, ?, ?, ?, ?, ?, CURDATE())
                        ");
                        $add_stmt->execute([
                            $shop_id,
                            $user['id'],
                            $position,
                            $permissions,
                            $availability_days_json,
                            $availability_start ?: null,
                            $availability_end ?: null,
                            $max_active_orders
                        ]);

                        $success = "Staff added successfully!";
                    } elseif ($existing['status'] === 'inactive') {
                        $reactivate_stmt = $pdo->prepare("
                            UPDATE shop_staffs
                            SET status = 'active', staff_role = 'staff', position = ?, permissions = ?, availability_days = ?, availability_start = ?, availability_end = ?, max_active_orders = ?, hired_date = CURDATE()
                            WHERE id = ? AND shop_id = ?
                        ");
                        $reactivate_stmt->execute([
                            $position,
                            $permissions,
                            $availability_days_json,
                            $availability_start ?: null,
                            $availability_end ?: null,
                            $max_active_orders,
                            $existing['id'],
                            $shop_id
                        ]);

                        $success = "Staff reactivated successfully!";
                    } else {
                        $error = "User is already an active staff for this shop!";
                    }
                }
            } else {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $user_stmt = $pdo->prepare("
                    INSERT INTO users (fullname, email, password, phone, role, status)
                    VALUES (?, ?, ?, ?, 'staff', 'active')
                ");
                $user_stmt->execute([$fullname, $email, $hashed_password, $phone]);
                $user_id = $pdo->lastInsertId();

                $add_stmt = $pdo->prepare("
                    INSERT INTO shop_staffs (shop_id, user_id, staff_role, position, permissions, availability_days, availability_start, availability_end, max_active_orders, hired_date)
                    VALUES (?, ?, 'staff', ?, ?, ?, ?, ?, ?, CURDATE())
                ");
                $add_stmt->execute([
                    $shop_id,
                    $user_id,
                    $position,
                    $permissions,
                    $availability_days_json,
                    $availability_start ?: null,
                    $availability_end ?: null,
                    $max_active_orders
                ]);

                $success = "Staff account created and added successfully!";
            }
        }
    } catch (PDOException $e) {
        $error = "Failed to add staff: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Staff - <?php echo htmlspecialchars($hr_shop['shop_name']); ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <nav class="navbar navbar--compact">
        <div class="container d-flex justify-between align-center">
            <a href="dashboard.php" class="navbar-brand">
                <i class="fas fa-people-group"></i> <?php echo htmlspecialchars($_SESSION['user']['fullname'] ?? 'HR'); ?>
            </a>
            <ul class="navbar-nav">
                <li><a href="dashboard.php" class="nav-link">Dashboard</a></li>
                <li><a href="hiring_management.php" class="nav-link">Hiring</a></li>
                <li><a href="create_staff.php" class="nav-link active">Create Staff</a></li>
                <li><a href="staff_productivity_performance.php" class="nav-link">Productivity</a></li>
                <li><a href="payroll_compensation.php" class="nav-link">Payroll</a></li>
                <li><a href="analytics_reporting.php" class="nav-link">Analytics</a></li>
                <li><a href="../auth/logout.php" class="nav-link">Logout</a></li>
            </ul>
        </div>
    </nav>

    <div class="container">
        <div class="dashboard-header">
            <div>
                <h2>Create Staff</h2>
                <p class="text-muted">HR accounts can add staff members and set availability, permissions, and capacity.</p>
            </div>
        </div>

        <?php if(isset($error)): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>

        <?php if(isset($success)): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>

        <div class="card" style="max-width: 720px;">
            <h3>Staff Details</h3>
            <form method="POST">
                <?php echo csrf_field(); ?>
                <div class="form-group">
                    <label>Staff Full Name *</label>
                    <input type="text" name="fullname" class="form-control" required
                           placeholder="Enter staff full name">
                </div>

                <div class="form-group">
                    <label>Staff Email *</label>
                    <input type="email" name="email" class="form-control" required
                           placeholder="Enter staff email">
                    <small class="text-muted">Staff accounts must use a unique email address.</small>
                </div>

                <div class="form-group">
                    <label>Phone Number *</label>
                    <input type="tel" name="phone" class="form-control" required
                           placeholder="Enter staff phone number">
                </div>

                <div class="form-group">
                    <label>Password *</label>
                    <input type="password" name="password" class="form-control" required minlength="8"
                           placeholder="At least 8 characters">
                </div>

                <div class="form-group">
                    <label>Confirm Password *</label>
                    <input type="password" name="confirm_password" class="form-control" required minlength="8"
                           placeholder="Confirm password">
                </div>

                <div class="form-group">
                    <label>Position *</label>
                    <select name="position" class="form-control" required>
                        <option value="">Select position</option>
                        <option value="Designer">Designer</option>
                        <option value="Embroidery Technician">Embroidery Technician</option>
                        <option value="Quality Control">Quality Control</option>
                        <option value="Production Manager">Production Manager</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Availability Days *</label>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($availability_days as $day_number => $day_label): ?>
                            <label class="form-check mr-3" style="min-width: 120px;">
                                <input
                                    type="checkbox"
                                    class="form-check-input"
                                    name="availability_days[]"
                                    value="<?php echo $day_number; ?>"
                                    checked
                                >
                                <span class="form-check-label"><?php echo htmlspecialchars($day_label); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <small class="text-muted">Select working days for this staff member.</small>
                </div>

                <div class="form-group d-flex justify-between align-center" style="gap: 16px;">
                    <div style="flex: 1;">
                        <label>Availability Start Time</label>
                        <input type="time" name="availability_start" class="form-control" value="09:00">
                    </div>
                    <div style="flex: 1;">
                        <label>Availability End Time</label>
                        <input type="time" name="availability_end" class="form-control" value="18:00">
                    </div>
                </div>

                <div class="form-group">
                    <label>Max Active Orders *</label>
                    <input type="number" name="max_active_orders" class="form-control" min="1" value="3" required>
                    <small class="text-muted">Orders above this limit cannot be assigned automatically.</small>
                </div>

                <div class="form-group">
                    <label>Permissions</label>
                    <p class="text-muted mb-2">Select the permissions this staff member should have.</p>
                    <?php foreach ($available_permissions as $permission_key => $permission_label): ?>
                        <div class="form-check">
                            <input
                                type="checkbox"
                                class="form-check-input"
                                id="permission_<?php echo $permission_key; ?>"
                                name="permissions[]"
                                value="<?php echo $permission_key; ?>"
                                checked
                            >
                            <label class="form-check-label" for="permission_<?php echo $permission_key; ?>">
                                <?php echo htmlspecialchars($permission_label); ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="d-flex justify-between" style="gap: 12px;">
                    <button type="submit" name="add_staff" class="btn btn-primary">Create Staff</button>
                    <a href="hiring_management.php" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
