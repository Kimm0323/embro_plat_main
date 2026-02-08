<?php
session_start();
require_once '../config/db.php';

$userId = $_SESSION['password_reset_user_id'] ?? null;
$email = $_SESSION['password_reset_email'] ?? null;

if (!$userId || !$email) {
    header('Location: forgot_password.php');
    exit();
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    $hasLower = preg_match('/[a-z]/', $password);
    $hasUpper = preg_match('/[A-Z]/', $password);
    $hasDigit = preg_match('/[0-9]/', $password);
    $hasSpecial = preg_match('/[^a-zA-Z0-9]/', $password);

    if (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } elseif (!$hasLower || !$hasUpper || !$hasDigit || !$hasSpecial) {
        $error = 'Password must include uppercase, lowercase, number, and special character.';
    } else {
        $userStmt = $pdo->prepare("SELECT id, role FROM users WHERE id = ? AND email = ?");
        $userStmt->execute([$userId, $email]);
        $user = $userStmt->fetch();

        if (!$user) {
            $error = 'Unable to verify your account. Please restart the reset process.';
        } else {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $updateStmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $updateStmt->execute([$hashed, $userId]);

            $cleanupStmt = $pdo->prepare("
                DELETE FROM otp_verifications
                WHERE user_id = ? AND type = 'reset'
            ");
            $cleanupStmt->execute([$userId]);

            log_audit(
                $pdo,
                (int) $userId,
                $user['role'] ?? null,
                'password_reset_completed',
                'users',
                (int) $userId,
                [],
                ['email' => $email]
            );

            unset($_SESSION['password_reset_user_id'], $_SESSION['password_reset_email']);
            $message = 'Password updated successfully. You can now log in.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - Embroidery Platform</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="auth-container">
    <div class="auth-card">
        <div class="auth-logo">
            <div class="auth-logo-icon">
                <i class="fas fa-lock"></i>
            </div>
            <h3>Set a New Password</h3>
            <p class="text-muted">Choose a strong password for your account.</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <?php if ($message): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo $message; ?>
                <p class="mt-2"><a href="login.php" class="btn btn-sm btn-primary">Return to Login</a></p>
            </div>
        <?php else: ?>
            <form method="POST">
                <?php echo csrf_field(); ?>
                <div class="form-group">
                    <label>New Password *</label>
                    <input type="password" name="password" class="form-control" required
                           placeholder="Enter a new password"
                           pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9])(?=.*[^a-zA-Z0-9]).{8,}">
                </div>

                <div class="form-group">
                    <label>Confirm Password *</label>
                    <input type="password" name="confirm_password" class="form-control" required
                           placeholder="Confirm your password" minlength="8">
                </div>

                <button type="submit" class="btn btn-primary w-full">
                    <i class="fas fa-save"></i> Update Password
                </button>
            </form>
        <?php endif; ?>

        <p class="text-center mt-3">
            Back to <a href="login.php">Sign in</a>
        </p>
    </div>
</body>
</html>
