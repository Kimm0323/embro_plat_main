<?php
session_start();
require_once '../config/db.php';

$message = '';
$error = '';
$show_verify_link = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = sanitize($_POST['email']);

    if (empty($email)) {
        $error = 'Please enter your email address.';
    } else {
        $userStmt = $pdo->prepare("SELECT id, email, role FROM users WHERE email = ?");
        $userStmt->execute([$email]);
        $user = $userStmt->fetch();

        if ($user) {
            $otpCode = (string) random_int(100000, 999999);
            $expiresAt = (new DateTime('+10 minutes'))->format('Y-m-d H:i:s');

            $cleanupStmt = $pdo->prepare("
                DELETE FROM otp_verifications
                WHERE email = ? AND type = 'reset' AND verified = 0
            ");
            $cleanupStmt->execute([$user['email']]);

            $otpStmt = $pdo->prepare("
                INSERT INTO otp_verifications (user_id, email, otp_code, type, expires_at, verified)
                VALUES (?, ?, ?, 'reset', ?, 0)
            ");
            $otpStmt->execute([$user['id'], $user['email'], $otpCode, $expiresAt]);
            
            log_audit(
                $pdo,
                (int) $user['id'],
                $user['role'],
                'password_reset_requested',
                'users',
                (int) $user['id'],
                [],
                ['email' => $user['email']]
            );
        }

        $show_verify_link = true;
        $message = 'If an account exists for that email, a reset code has been sent. Please enter the code to continue.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Embroidery Platform</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="auth-container">
    <div class="auth-card">
        <div class="auth-logo">
            <div class="auth-logo-icon">
                <i class="fas fa-key"></i>
            </div>
            <h3>Reset Your Password</h3>
            <p class="text-muted">Enter your email to receive a reset link.</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <?php if ($message): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo $message; ?>
                <div class="d-flex gap-2 mt-2">
                    <?php if ($show_verify_link): ?>
                        <a href="verify_otp.php" class="btn btn-sm btn-primary">Verify Code</a>
                    <?php endif; ?>
                    <a href="login.php" class="btn btn-sm btn-outline-primary">Return to Login</a>
                </div>
            </div>
        <?php else: ?>
            <form method="POST">
                <?php echo csrf_field(); ?>
                <div class="form-group">
                    <label>Email Address *</label>
                    <input type="email" name="email" class="form-control" required placeholder="Enter your email">
                </div>

                <button type="submit" class="btn btn-primary w-full">
                    <i class="fas fa-paper-plane"></i> Send Reset Link
                </button>
            </form>
        <?php endif; ?>

        <p class="text-center mt-3">
            Remembered your password? <a href="login.php">Sign in</a>
        </p>
    </div>
</body>
</html>
