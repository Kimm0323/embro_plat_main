<?php
session_start();
require_once '../config/db.php';
require_once 'partials.php';
require_role('sys_admin');

$userId = $_SESSION['user']['id'];

$profileStmt = $pdo->prepare("SELECT id, fullname, email, phone, role, status FROM users WHERE id = ?");
$profileStmt->execute([$userId]);
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
            $emailCheck->execute([$email, $userId]);

            if ($emailCheck->fetch()) {
                $message = 'That email is already in use.';
                $messageType = 'danger';
            } else {
                $updateStmt = $pdo->prepare("UPDATE users SET fullname = ?, email = ?, phone = ? WHERE id = ?");
                $updateStmt->execute([$fullname, $email, $phone, $userId]);

                if ($password) {
                    $hashed = password_hash($password, PASSWORD_DEFAULT);
                    $passStmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $passStmt->execute([$hashed, $userId]);
                }

                $_SESSION['user']['fullname'] = $fullname;
                $_SESSION['user']['email'] = $email;
                $message = 'Profile updated successfully.';
            }
        } catch (PDOException $e) {
            $message = 'Update failed: ' . $e->getMessage();
            $messageType = 'danger';
        }
    }

    $profileStmt->execute([$userId]);
    $profile = $profileStmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile Settings - System Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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
    <?php sys_admin_nav('profile'); ?>

    <div class="container">
        <div class="dashboard-header fade-in">
            <div class="d-flex justify-between align-center">
                <div>
                    <h2>Profile Settings</h2>
                    <p class="text-muted">Manage your administrator profile and credentials.</p>
                </div>
                <span class="badge badge-info"><i class="fas fa-user-cog"></i> Admin Profile</span>
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
                    <h3><i class="fas fa-shield-alt text-success"></i> Profile Summary</h3>
                    <p class="text-muted">Quick overview of your admin account.</p>
                </div>
                <div class="d-flex flex-column gap-3">
                    <div>
                        <strong>Role</strong>
                        <p class="text-muted mb-0"><?php echo ucfirst($profile['role'] ?? 'sys_admin'); ?></p>
                    </div>
                    <div>
                        <strong>Status</strong>
                        <p class="text-muted mb-0"><?php echo ucfirst($profile['status'] ?? 'active'); ?></p>
                    </div>
                    <div>
                        <strong>Last Login</strong>
                        <p class="text-muted mb-0"><?php echo date('F j, Y, g:i a'); ?></p>
                    </div>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        <div>
                            <strong>Security Tip</strong>
                            <p class="mb-0">Review admin access monthly and update credentials regularly.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php sys_admin_footer(); ?>
</body>
</html>
