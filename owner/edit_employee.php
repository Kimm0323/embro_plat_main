<?php
session_start();
require_once '../config/db.php';
require_role('owner');

$owner_id = $_SESSION['user']['id'];
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

$shop_stmt = $pdo->prepare("SELECT * FROM shops WHERE owner_id = ?");
$shop_stmt->execute([$owner_id]);
$shop = $shop_stmt->fetch();

if(!$shop) {
    header("Location: create_shop.php");
    exit();
}

$shop_id = $shop['id'];
$staff_id = (int) ($_GET['id'] ?? 0);
$message = '';
$message_type = 'success';

$staff_stmt = $pdo->prepare("
    SELECT se.*, u.fullname, u.email, u.phone
    FROM shop_staffs se
    JOIN users u ON se.user_id = u.id
    WHERE se.id = ? AND se.shop_id = ?
");
$staff_stmt->execute([$staff_id, $shop_id]);
$staff = $staff_stmt->fetch();

if(!$staff) {
    $message = 'staff not found for this shop.';
    $message_type = 'danger';
}

if($staff && isset($_POST['update_staff'])) {
    $fullname = sanitize($_POST['fullname'] ?? '');
    $email = sanitize($_POST['email'] ?? '');
    $phone = sanitize($_POST['phone'] ?? '');
    $position = sanitize($_POST['position'] ?? '');
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

    if(!$fullname || !$email || !$position) {
        $message = 'Full name, email, and position are required.';
        $message_type = 'danger';
    } elseif($max_active_orders < 1) {
        $message = 'Please set a staff capacity of at least 1 active order.';
        $message_type = 'danger';
    } elseif(empty($selected_days)) {
        $message = 'Please select at least one availability day.';
        $message_type = 'danger';
    } elseif($availability_start && $availability_end && $availability_start >= $availability_end) {
        $message = 'Availability end time must be later than start time.';
        $message_type = 'danger';
    } else {
        try {
            $email_stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $email_stmt->execute([$email, $staff['user_id']]);

            if($email_stmt->fetch()) {
                $message = 'That email is already in use.';
                $message_type = 'danger';
            } else {
                $update_user = $pdo->prepare("UPDATE users SET fullname = ?, email = ?, phone = ? WHERE id = ?");
                $update_user->execute([$fullname, $email, $phone, $staff['user_id']]);

                $update_staff = $pdo->prepare("
                    UPDATE shop_staffs 
                    SET position = ?, permissions = ?, availability_days = ?, availability_start = ?, availability_end = ?, max_active_orders = ?
                    WHERE id = ? AND shop_id = ?
                ");
                $update_staff->execute([
                    $position,
                    $permissions,
                    $availability_days_json,
                    $availability_start ?: null,
                    $availability_end ?: null,
                    $max_active_orders,
                    $staff_id,
                    $shop_id
                ]);

                $message = 'staff updated successfully!';
                $message_type = 'success';

                $staff_stmt->execute([$staff_id, $shop_id]);
                $staff = $staff_stmt->fetch();
            }
        } catch(PDOException $e) {
            $message = 'Failed to update staff: ' . $e->getMessage();
            $message_type = 'danger';
        }
    }
}

$current_permissions = [];
if($staff && !empty($staff['permissions'])) {
    $decoded_permissions = json_decode($staff['permissions'], true);
    if(is_array($decoded_permissions)) {
        $current_permissions = $decoded_permissions;
    }
}

foreach ($available_permissions as $permission_key => $permission_label) {
    if(!array_key_exists($permission_key, $current_permissions)) {
        $current_permissions[$permission_key] = false;
    }
}

$current_availability_days = [];
if($staff && !empty($staff['availability_days'])) {
    $decoded_days = json_decode($staff['availability_days'], true);
    if(is_array($decoded_days)) {
        $current_availability_days = array_map('intval', $decoded_days);
    }
}
if(empty($current_availability_days)) {
    $current_availability_days = array_keys($availability_days);
}
$availability_start_value = $staff['availability_start'] ?? '09:00';
$availability_end_value = $staff['availability_end'] ?? '18:00';
$max_active_orders_value = $staff['max_active_orders'] ?? 3;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Staff - <?php echo htmlspecialchars($shop['shop_name']); ?></title>
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
            <h2>Edit staff</h2>
            <p class="text-muted">Update staff details and permissions.</p>
        </div>

        <?php if($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <?php if($staff): ?>
                <form method="POST">
                    <?php echo csrf_field(); ?>
                    <div class="form-group">
                        <label>staff Full Name *</label>
                        <input type="text" name="fullname" class="form-control" required
                               value="<?php echo htmlspecialchars($staff['fullname']); ?>">
                    </div>

                    <div class="form-group">
                        <label>staff Email *</label>
                        <input type="email" name="email" class="form-control" required
                               value="<?php echo htmlspecialchars($staff['email']); ?>">
                    </div>

                    <div class="form-group">
                        <label>Phone Number</label>
                        <input type="tel" name="phone" class="form-control"
                               value="<?php echo htmlspecialchars($staff['phone']); ?>">
                    </div>

                    <div class="form-group">
                        <label>Position *</label>
                        <select name="position" class="form-control" required>
                            <option value="">Select position</option>
                            <?php
                            $positions = [
                                'Designer',
                                'Embroidery Technician',
                                'Quality Control',
                                'Production Manager'
                            ];
                            foreach($positions as $role):
                            ?>
                                <option value="<?php echo $role; ?>" <?php echo $staff['position'] === $role ? 'selected' : ''; ?>>
                                    <?php echo $role; ?>
                                </option>
                            <?php endforeach; ?>
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
                                        <?php echo in_array($day_number, $current_availability_days, true) ? 'checked' : ''; ?>
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
                            <input type="time" name="availability_start" class="form-control" value="<?php echo htmlspecialchars($availability_start_value); ?>">
                        </div>
                        <div style="flex: 1;">
                            <label>Availability End Time</label>
                            <input type="time" name="availability_end" class="form-control" value="<?php echo htmlspecialchars($availability_end_value); ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Max Active Orders *</label>
                        <input type="number" name="max_active_orders" class="form-control" min="1" required value="<?php echo (int) $max_active_orders_value; ?>">
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
                                    <?php echo !empty($current_permissions[$permission_key]) ? 'checked' : ''; ?>
                                >
                                <label class="form-check-label" for="permission_<?php echo $permission_key; ?>">
                                    <?php echo htmlspecialchars($permission_label); ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="d-flex justify-between align-center" style="margin-top: 1.5rem;">
                        <a href="manage_staff.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Back
                        </a>
                        <button type="submit" name="update_staff" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                    </div>
                </form>
            <?php else: ?>
                <div class="text-center p-4">
                    <i class="fas fa-user-times fa-3x text-muted mb-3"></i>
                    <h4>staff not found</h4>
                    <p class="text-muted">Return to staff management to pick another staff.</p>
                    <a href="manage_staff.php" class="btn btn-primary">
                        <i class="fas fa-arrow-left"></i> Back to Staff
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
