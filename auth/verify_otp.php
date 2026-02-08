<?php
session_start();
require_once '../config/db.php';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = sanitize($_POST['email'] ?? '');
    $otp = sanitize($_POST['otp'] ?? '');

    if (empty($email) || empty($otp)) {
        $error = 'Please enter your email and verification code.';
    } else {
        $otpStmt = $pdo->prepare("
            SELECT id, user_id, expires_at
            FROM otp_verifications
            WHERE email = ? AND otp_code = ? AND type = 'reset' AND verified = 0
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $otpStmt->execute([$email, $otp]);
        $record = $otpStmt->fetch();

        if (!$record) {
            $error = 'Invalid verification code. Please try again.';
        } elseif (strtotime($record['expires_at']) < time()) {
            $error = 'This verification code has expired. Please request a new one.';
        } else {
            $verifyStmt = $pdo->prepare("UPDATE otp_verifications SET verified = 1 WHERE id = ?");
            $verifyStmt->execute([$record['id']]);

            $userStmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
            $userStmt->execute([(int) $record['user_id']]);
            $userRole = $userStmt->fetchColumn() ?: null;

            log_audit(
                $pdo,
                (int) $record['user_id'],
                $userRole,
                'password_reset_verified',
                'users',
                (int) $record['user_id'],
                [],
                ['email' => $email]
            );

            $_SESSION['password_reset_user_id'] = (int) $record['user_id'];
            $_SESSION['password_reset_email'] = $email;

            header('Location: reset_password.php');
            exit();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Code - Embroidery Platform</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="auth-container">
    <div class="auth-card">
        <div class="auth-logo">
            <div class="auth-logo-icon">
                <i class="fas fa-shield-check"></i>
            </div>
            <h3>Verify Your Code</h3>
            <p class="text-muted">Enter the verification code sent to your email.</p>
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
                    <label>Email Address *</label>
                    <input type="email" name="email" class="form-control" required placeholder="Enter your email">
                </div>
                <div class="form-group">
                    <label>Verification Code *</label>
                    <input type="text" name="otp" class="form-control" required placeholder="Enter code">
                </div>

                <button type="submit" class="btn btn-primary w-full">
                    <i class="fas fa-check"></i> Verify Code
                </button>
            </form>
        <?php endif; ?>

        <p class="text-center mt-3">
            Need a new code? <a href="forgot_password.php">Resend code</a>
        </p>
    </div>
</body>
</html>
