<?php
session_start();
require_once '../config/db.php';

$error = '';
$success = '';



if(isset($_SESSION['user'])) {
    redirect_based_on_role($_SESSION['user']['role']);
}

if($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = sanitize($_POST['email']);
    $password = $_POST['password'];
    
    try {

        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");

        
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if($user && password_verify($password, $user['password'])) {

                if ($user['status'] !== 'active') {
                $status = $user['status'] ?? 'inactive';
                $error = $status === 'pending'
                    ? "Your account is pending approval. Please wait for activation."
                    : "Your account is not active. Please contact support.";
                log_audit(
                    $pdo,
                    (int) $user['id'],
                    $user['role'],
                    'login_blocked',
                    'users',
                    (int) $user['id'],
                    ['status' => $user['status']],
                    ['status' => $user['status']]
                );
            } else {


            unset($user['password']);
            $_SESSION['user'] = $user;
            $_SESSION['last_activity'] = time();
            
            $update_stmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
            $update_stmt->execute([$user['id']]);
            
            log_audit(
                $pdo,
                (int) $user['id'],
                $user['role'],
                'login',
                'users',
                (int) $user['id'],
                [],
                ['last_login' => date('Y-m-d H:i:s')]
            );
            
            redirect_based_on_role($user['role']);
            

             }


        } else {
            $error = "Invalid email or password!";
            $failedUserId = $user ? (int) $user['id'] : null;
            $failedUserRole = $user ? $user['role'] : null;
            log_audit(
                $pdo,
                $failedUserId,
                $failedUserRole,
                'login_failed',
                'users',
                $failedUserId,
                [],
                ['email' => $email]
            );
            
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
            $failureThreshold = 5;
            $windowMinutes = 15;
            $ipFailures = 0;
            $emailFailures = 0;

            if ($ipAddress) {
                $ipStmt = $pdo->prepare("
                    SELECT COUNT(*)
                    FROM audit_logs
                    WHERE action = 'login_failed'
                      AND ip_address = ?
                      AND created_at >= (NOW() - INTERVAL $windowMinutes MINUTE)
                ");
                $ipStmt->execute([$ipAddress]);
                $ipFailures = (int) $ipStmt->fetchColumn();
            }

            if ($email !== '') {
                $emailStmt = $pdo->prepare("
                    SELECT COUNT(*)
                    FROM audit_logs
                    WHERE action = 'login_failed'
                      AND new_values LIKE ?
                      AND created_at >= (NOW() - INTERVAL $windowMinutes MINUTE)
                ");
                $emailStmt->execute(['%' . $email . '%']);
                $emailFailures = (int) $emailStmt->fetchColumn();
            }

            $failureCount = max($ipFailures, $emailFailures);
            if ($failureCount >= $failureThreshold) {
                $alertStmt = $pdo->prepare("
                    SELECT COUNT(*)
                    FROM audit_logs
                    WHERE action = 'suspicious_activity'
                      AND created_at >= (NOW() - INTERVAL 1 HOUR)
                      AND (
                        (? IS NOT NULL AND ip_address = ?)
                        OR (? != '' AND new_values LIKE ?)
                      )
                ");
                $emailLike = '%' . $email . '%';
                $alertStmt->execute([$ipAddress, $ipAddress, $email, $emailLike]);
                $recentAlerts = (int) $alertStmt->fetchColumn();

                if ($recentAlerts === 0) {
                    log_suspicious_activity(
                        $pdo,
                        $failedUserId,
                        $failedUserRole,
                        'Repeated failed login attempts',
                        [
                            'email' => $email,
                            'ip_address' => $ipAddress,
                            'failure_count' => $failureCount,
                            'window_minutes' => $windowMinutes
                        ]
                    );
                }
            }
        }
    } catch(PDOException $e) {
        $error = "Login failed: " . $e->getMessage();
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Embroidery Platform</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .login-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 2rem;
            position: relative;
            overflow: hidden;
        }
        
        .login-container::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
        }
        
        .login-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: var(--radius-xl);
            padding: 2.75rem;
            width: 100%;
            max-width: 420px;
            box-shadow: 0 20px 45px rgba(15, 23, 42, 0.18);
            border: 1px solid rgba(255, 255, 255, 0.6);
            position: relative;
            z-index: 1;
            animation: slideUp 0.6s ease-out;
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 2.5rem;
        }
        
        .login-header h1 {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }

        .login-header p {
            margin-bottom: 0;
        }

        .logo-icon {
            font-size: 4rem;
            margin-bottom: 1rem;
            background: linear-gradient(135deg, var(--primary-500), var(--secondary-500));
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .role-preview {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin: 2rem 0;
        }
        
        .role-preview-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            background: white;
            padding: 1rem;
            border-radius: var(--radius);
            border: 1px solid var(--gray-200);
            text-align: left;
            text-decoration: none;
            color: inherit;
            transition: all 0.3s;
        }
        
        .role-preview-item:hover {
            border-color: var(--primary-500);
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }
        
        .role-icon {
            font-size: 1.5rem;
            color: var(--primary-500);
            margin-bottom: 0.5rem;
        }
        
        .input-group {
            position: relative;
            margin-bottom: 1.5rem;
        }
        
        .input-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-400);
        }
        
        .input-group input {
            padding-left: 3rem;
            width: 100%;
        }
        
        .remember-forgot {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.75rem;
        }
        
        .divider {
            text-align: center;
            margin: 2rem 0;
            position: relative;
        }
        
        .divider::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            height: 1px;
            background: var(--gray-200);
        }
        
        .divider span {
            background: white;
            padding: 0 1rem;
            color: var(--gray-500);
            position: relative;
        }
        
        .demo-credentials {
            background: var(--primary-50);
            border-radius: var(--radius);
            padding: 1rem;
            margin-top: 2rem;
            border-left: 4px solid var(--primary-500);
        }
        
        @media (max-width: 640px) {
            .login-container {
                padding: 1.5rem;
            }

            .login-card {
                padding: 2rem;
            }

            .role-preview {
                grid-template-columns: 1fr;
            }

            .remember-forgot {
                flex-direction: column;
                align-items: flex-start;
            }
        }

        @media (max-width: 420px) {
            .login-card {
                padding: 1.5rem;
            }
        }
    </style>
</head>
<body class="login-container">
    <div class="login-card">
        <div class="login-header">
            <div class="logo-icon">
                <i class="fas fa-threads"></i>
            </div>
            <h1>Welcome Back</h1>
            <p class="text-muted">Sign in to your Embroidery Platform account</p>
        </div>
        
        <?php if($error): ?>
            <div class="alert alert-danger fade-in">
                <i class="fas fa-exclamation-circle"></i>
                <div><?php echo $error; ?></div>
            </div>
        <?php endif; ?>
        
        <form method="POST">
            <?php echo csrf_field(); ?>
            <div class="input-group">
                <i class="fas fa-envelope input-icon"></i>
                <input type="email" name="email" class="form-control" required 
                       placeholder="Enter your email" autocomplete="email">
            </div>
            
            <div class="input-group">
                <i class="fas fa-lock input-icon"></i>
                <input type="password" name="password" class="form-control" required 
                       placeholder="Enter your password" autocomplete="current-password">
            </div>
            
            <div class="remember-forgot">
                <label class="d-flex align-center gap-2">
                    <input type="checkbox" class="form-check-input">
                    <span class="text-muted">Remember me</span>
                </label>
                <a href="forgot_password.php" class="text-primary">Forgot password?</a>
            </div>
            
            <button type="submit" class="btn btn-primary w-100 btn-lg">
                <i class="fas fa-sign-in-alt"></i> Sign In
            </button>
        </form>
        
        <div class="divider">
            <span>Or continue with</span>
        </div>
        
        <div class="role-preview">
            <a href="../auth/register.php?type=client" class="role-preview-item">
                <div class="role-icon">
                    <i class="fas fa-user"></i>
                </div>
                <div>
                    <strong>Client</strong>
                    <p class="text-muted small mb-0">Order services</p>
                </div>
            </a>
            
            <a href="../auth/register.php?type=owner" class="role-preview-item">
                <div class="role-icon">
                    <i class="fas fa-store"></i>
                </div>
                <div>
                    <strong>Shop Owner</strong>
                    <p class="text-muted small mb-0">Provide services</p>
                </div>
            </a>
        </div>
        
        <div class="text-center mt-4">
            <p class="text-muted">
                Don't have an account? 
                <a href="register.php" class="text-primary font-semibold">Sign up now</a>
            </p>
            <a href="../index.php" class="btn btn-outline-primary btn-sm">
                <i class="fas fa-arrow-left"></i> Back to Home
            </a>
        </div>
        
        <div class="demo-credentials mt-4">
            <h6 class="mb-2"><i class="fas fa-info-circle"></i> Demo Access</h6>
            <p class="text-sm mb-1"><strong>System Admin:</strong> admin@embroidery.com</p>
            <p class="text-sm mb-0"><strong>Password:</strong> password</p>
        </div>
    </div>

    <script>
        // Add form validation feedback
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.querySelector('form');
            const inputs = form.querySelectorAll('input[required]');
            
            inputs.forEach(input => {
                input.addEventListener('blur', function() {
                    if(this.value.trim() === '') {
                        this.style.borderColor = 'var(--danger-500)';
                    } else {
                        this.style.borderColor = 'var(--primary-500)';
                    }
                });
                
                input.addEventListener('focus', function() {
                    this.style.borderColor = 'var(--primary-500)';
                });
            });
            
            // Add loading state to submit button
            form.addEventListener('submit', function() {
                const submitBtn = this.querySelector('button[type="submit"]');
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Signing in...';
                submitBtn.disabled = true;
            });
            
            // Animate elements on load
            const elements = document.querySelectorAll('.role-preview-item, .alert');
            elements.forEach((el, index) => {
                el.style.opacity = '0';
                el.style.transform = 'translateY(20px)';
                
                setTimeout(() => {
                    el.style.transition = 'all 0.5s ease';
                    el.style.opacity = '1';
                    el.style.transform = 'translateY(0)';
                }, 100 * index);
            });
        });
    </script>
</body>
</html>