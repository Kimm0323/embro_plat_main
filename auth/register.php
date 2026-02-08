<?php
session_start();
require_once '../config/db.php';
require_once '../config/constants.php';
require_once '../includes/media_manager.php';

$error = '';
$success = '';
$type = isset($_GET['type']) ? $_GET['type'] : 'client';
$registrationsOpen = true;

$settingsStmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'new_registrations' LIMIT 1");
$settingsStmt->execute();
$registrationsValue = $settingsStmt->fetchColumn();
if ($registrationsValue !== false) {
    $registrationsOpen = filter_var($registrationsValue, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($registrationsOpen === null) {
        $registrationsOpen = (bool) $registrationsValue;
    }
}
$registrationDisabled = !$registrationsOpen;

if($_SERVER['REQUEST_METHOD'] == 'POST') {
    if ($registrationDisabled) {
        $error = "Registrations are currently disabled by system administrators.";
    } else {
    $fullname = sanitize($_POST['fullname']);
    $email = sanitize($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $phone = sanitize($_POST['phone']);
    $user_type = sanitize($_POST['type']);
    $allowed = ['client', 'owner'];
    if (!in_array($user_type, $allowed, true)) {
        $user_type = 'client';
    }
    
    // Validation
    $hasLower = preg_match('/[a-z]/', $password);
    $hasUpper = preg_match('/[A-Z]/', $password);
    $hasDigit = preg_match('/[0-9]/', $password);
    $hasSpecial = preg_match('/[^a-zA-Z0-9]/', $password);

    if (strlen($password) < 8) {
        $error = "Password must be exactly 8 characters long!";
    } elseif (!$hasLower || !$hasUpper || !$hasDigit || !$hasSpecial) {
        $error = "Password must include at least one uppercase letter, one lowercase letter, one number, and one special character!";
    } elseif($password !== $confirm_password) {
        $error = "Passwords do not match!";
    } else {
        $permitFilename = null;
        $permitNumber = '';
        if ($user_type === 'owner') {
            $permitNumber = sanitize($_POST['business_permit'] ?? '');
            $permitFile = $_FILES['business_permit_file'] ?? null;
            if (!$permitFile || $permitFile['error'] !== UPLOAD_ERR_OK) {
                $error = "Please upload a valid business permit file.";
            } else {
                $allowed_ext = ['jpg', 'jpeg', 'png', 'pdf'];
                $upload = save_uploaded_media(
                    $permitFile,
                    $allowed_ext,
                    MAX_FILE_SIZE,
                    'permits',
                    'permit',
                    'owner'
                );
                if (!$upload['success']) {
                    $error = $upload['error'] === 'File size exceeds the limit.'
                        ? "Business permit files must be smaller than 5MB."
                        : "Business permit files must be JPG, PNG, or PDF files.";
                } else {
                    $permitFilename = $upload['filename'];
                }
            }
        }
        if (!$error) {
            try {
                // Check if email exists
                $check_stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                $check_stmt->execute([$email]);
                
                if($check_stmt->rowCount() > 0) {
                    delete_media_file('permits', $permitFilename);
                    $error = "Email already registered!";
                } else {
                    // Hash password
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    
                    // Insert user

                 $user_status = $user_type === 'owner' ? 'pending' : 'active';
                    $stmt = $pdo->prepare("
                        INSERT INTO users (fullname, email, password, phone, role, status) 
                         VALUES (?, ?, ?, ?, ?, ?)

               
                ");
                    
                    $stmt->execute([
                        $fullname, 
                        $email, 
                        $hashed_password, 
                        $phone,
                        $user_type,
                        $user_status


                ]);
                    
                    $user_id = $pdo->lastInsertId();
                    
                    // If registering as owner, create shop entry
                    if($user_type == 'owner') {
                        $shop_name = $fullname . "'s Shop";
                        $shop_stmt = $pdo->prepare("
                            INSERT INTO shops (owner_id, shop_name, status, business_permit, permit_file) 
                            VALUES (?, ?, 'pending', ?, ?)
                        ");
                        $shop_stmt->execute([$user_id, $shop_name, $permitNumber, $permitFilename]);
                    }
                    
                    log_audit(
                        $pdo,
                        (int) $user_id,
                        $user_type,
                        'register_user',
                        'users',
                        (int) $user_id,
                        [],
                        [
                            'email' => $email,
                            'role' => $user_type,
                            'status' => $user_status,
                        ]
                    );
                    

                $success = $user_status === 'pending'
                        ? "Registration successful! Your account is pending approval."
                        : "Registration successful! You can now log in.";
                        }
            } catch(PDOException $e) {
                delete_media_file('permits', $permitFilename);
                $error = "Registration failed: " . $e->getMessage();

            }
        }
    }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Embroidery Platform</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body class="auth-container">
    <div class="auth-card">
        <div class="auth-logo">
            <div class="auth-logo-icon">
                <i class="fas fa-threads"></i>
            </div>
            <h3>Create Account</h3>
            <p class="text-muted">
                <?php echo $type == 'owner' ? 'Register as Shop Owner' : 'Register as Client'; ?>
            </p>
        </div>
        
        <div class="auth-body">
            <?php if($error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                </div>
                
                <?php endif; ?>
            
            <?php if($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                    <p class="mt-2"><a href="login.php" class="btn btn-sm btn-primary">Login Now</a></p>
                </div>
                
                <?php else: ?>
                    <?php if ($registrationDisabled): ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-ban"></i> Registrations are currently disabled by system administrators.
                        </div>
                    <?php else: ?>
                <form method="POST" enctype="multipart/form-data">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="type" value="<?php echo $type; ?>">
                    
                    <div class="form-group">
                        <label class="form-label" for="fullname">Full Name *</label>
                        <input type="text" name="fullname" class="form-control" required 
                               placeholder="Enter your full name" id="fullname">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="email">Email Address *</label>
                        <input type="email" name="email" class="form-control" required 
                               placeholder="Enter your email" id="email">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="phone">Phone Number *</label>
                        <input type="tel" name="phone" class="form-control" required 
                               placeholder="Enter your phone number" id="phone">
                </div>

                <div class="form-group">
                        <label class="form-label" for="password">Password *</label>
                        <input type="password" name="password" class="form-control" required 
                               placeholder="At least 8 characters with upper/lower/number/special" minlength="8"
                               pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9])(?=.*[^a-zA-Z0-9]).{8,}" id="password">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="confirm_password">Confirm Password *</label>
                    <input type="password" name="confirm_password" class="form-control" required 
                           placeholder="Confirm your password" minlength="8" id="confirm_password">
                </div>
                
                <?php if($type == 'owner'): ?>
                    <div class="form-group">
                            <label class="form-label" for="business_permit">Business Permit Number (optional)</label>
                            <input type="text" name="business_permit" class="form-control"
                                   placeholder="Enter business permit number" id="business_permit">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="business_permit_file">Business Permit File *</label>
                            <input type="file" name="business_permit_file" class="form-control" required
                                   accept=".jpg,.jpeg,.png,.pdf" id="business_permit_file">
                            <small class="text-muted">Upload a clear photo or PDF of your business permit (max 5MB).</small>
                        </div>
                        <div class="alert alert-info">
                            <h6><i class="fas fa-info-circle"></i> Shop Owner Registration</h6>
                            <p class="mb-0">Your business permit will be reviewed by administrators before approval.</p>
                        </div>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary btn-lg w-100">
                        <i class="fas fa-user-plus"></i> Create Account
                    </button>
                </form>
                    <?php endif; ?>
            <?php endif; ?>
            
            <div class="auth-footer">
                <p class="text-muted">Already have an account? <a href="login.php" class="text-primary">Login here</a></p>
                <p>
                    Register as: 
                    <a href="register.php?type=client" class="btn btn-sm btn-outline-primary">Client</a>
                    <a href="register.php?type=owner" class="btn btn-sm btn-outline-primary">Shop Owner</a>
                </p>
                <p><a href="../index.php" class="text-muted">Back to Home</a></p>
            </div>
        </div>
    </div>
</body>
</html>