<?php
session_start();
require_once '../config/db.php';
require_role('owner');

$ownerId = $_SESSION['user']['id'];

$shopStmt = $pdo->prepare("SELECT id, shop_name FROM shops WHERE owner_id = ?");
$shopStmt->execute([$ownerId]);
$shop = $shopStmt->fetch();

if (!$shop) {
    header('Location: create_shop.php');
    exit();
}

$profileStmt = $pdo->prepare("SELECT id, fullname, email, phone, role, status, last_login FROM users WHERE id = ?");
$profileStmt->execute([$ownerId]);
$profile = $profileStmt->fetch();

$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullname = sanitize($_POST['fullname'] ?? '');
    $email = sanitize($_POST['email'] ?? '');
    $phone = sanitize($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (!$fullname || !$email) {
        $message = 'Full name and email are required.';
        $messageType = 'danger';
    } elseif ($password && strlen($password) !== 8) {
        $message = 'Password must be exactly 8 characters.';
        $messageType = 'danger';
        } elseif ($password) {
        $hasLower = preg_match('/[a-z]/', $password);
        $hasUpper = preg_match('/[A-Z]/', $password);
        $hasDigit = preg_match('/[0-9]/', $password);
        $hasSpecial = preg_match('/[^a-zA-Z0-9]/', $password);

        if (!$hasLower || !$hasUpper || !$hasDigit || !$hasSpecial) {
            $message = 'Password must include at least one uppercase letter, one lowercase letter, one number, and one special character.';
            $messageType = 'danger';
        }
    } elseif ($password && $password !== $confirm) {
        $message = 'Passwords do not match.';
        $messageType = 'danger';
    } else {
        try {
            $emailCheck = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $emailCheck->execute([$email, $ownerId]);

            if ($emailCheck->fetch()) {
                $message = 'That email is already in use.';
                $messageType = 'danger';
            } else {
                $updateStmt = $pdo->prepare("UPDATE users SET fullname = ?, email = ?, phone = ? WHERE id = ?");
                $updateStmt->execute([$fullname, $email, $phone, $ownerId]);

                if ($password) {
                    $hashed = password_hash($password, PASSWORD_DEFAULT);
                    $passStmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $passStmt->execute([$hashed, $ownerId]);
                }

                $_SESSION['user']['fullname'] = $fullname;
                $_SESSION['user']['email'] = $email;
                $_SESSION['user']['phone'] = $phone;
                $message = 'Profile updated successfully.';
            }
        } catch (PDOException $e) {
            $message = 'Update failed: ' . $e->getMessage();
            $messageType = 'danger';
        }
    }

    $profileStmt->execute([$ownerId]);
    $profile = $profileStmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile Settings - <?php echo htmlspecialchars($shop['shop_name']); ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .profile-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 1.5rem;
            margin: 2rem 0;
        }

        .profile-card {
            grid-column: span 7;
        }

        .summary-card {
            grid-column: span 5;
        }
    </style>
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
                <li><a href="manage_staff.php" class="nav-link">Staff</a></li>
                <li><a href="shop_orders.php" class="nav-link">Orders</a></li>
                <li><a href="reviews.php" class="nav-link">Reviews</a></li>
                <li><a href="messages.php" class="nav-link">Messages</a></li>
                <li><a href="payment_verifications.php" class="nav-link">Payments</a></li>
                <li><a href="earnings.php" class="nav-link">Earnings</a></li>
                <li class="dropdown">
                    <a href="#" class="nav-link dropdown-toggle active">
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
        <div class="dashboard-header fade-in">
            <div class="d-flex justify-between align-center">
                <div>
                    <h2>Profile Settings</h2>
                    <p class="text-muted">Manage your account details and login credentials.</p>
                </div>
                <span class="badge badge-info"><i class="fas fa-user-cog"></i> Shop Owner</span>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?>">
                <i class="fas fa-info-circle"></i> <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <div class="profile-grid">
            <div class="card profile-card">
                <div class="card-header">
                    <h3><i class="fas fa-id-badge text-primary"></i> Account Details</h3>
                    <p class="text-muted">Keep your contact information up to date.</p>
                </div>
                <form method="POST">
                    <?php echo csrf_field(); ?>
                    <div class="form-group">
                        <label>Full Name</label>
                        <input type="text" name="fullname" class="form-control" value="<?php echo htmlspecialchars($profile['fullname'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Email Address</label>
                        <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($profile['email'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Phone Number</label>
                        <input type="text" name="phone" class="form-control" value="<?php echo htmlspecialchars($profile['phone'] ?? ''); ?>">
                    </div>

                    <div class="alert alert-info">
                        <h6><i class="fas fa-key"></i> Password Update</h6>
                        <p class="mb-0">Leave blank to keep your current password.</p>
                    </div>

                    <div class="row" style="display: flex; gap: 1rem;">
                        <div class="form-group" style="flex: 1;">
                            <label>New Password</label>
                            <input type="password" name="password" class="form-control" placeholder="********"
                                   minlength="8"
                                   pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9])(?=.*[^a-zA-Z0-9]).{8,}">
                        </div>
                        <div class="form-group" style="flex: 1;">
                            <label>Confirm Password</label>
                            <input type="password" name="confirm_password" class="form-control" placeholder="********"
                                   minlength="8">
                        </div>
                    </div>

                    <div class="text-right mt-3">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>

            <div class="card summary-card">
                <div class="card-header">
                    <h3><i class="fas fa-store text-success"></i> Profile Summary</h3>
                    <p class="text-muted">Quick overview of your owner account.</p>
                </div>
                <div class="d-flex flex-column gap-3">
                    <div>
                        <strong>Role</strong>
                        <p class="text-muted mb-0"><?php echo ucfirst($profile['role'] ?? 'owner'); ?></p>
                    </div>
                    <div>
                        <strong>Status</strong>
                        <p class="text-muted mb-0"><?php echo ucfirst($profile['status'] ?? 'active'); ?></p>
                    </div>
                    <div>
                        <strong>Last Login</strong>
                        <p class="text-muted mb-0"><?php echo $profile['last_login'] ? date('F j, Y, g:i a', strtotime($profile['last_login'])) : '—'; ?></p>
                    </div>
                    <div class="alert alert-warning">
                        <i class="fas fa-shield-alt"></i>
                        <div>
                            <strong>Security Tip</strong>
                            <p class="mb-0">Update your password regularly to keep your shop secure.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
