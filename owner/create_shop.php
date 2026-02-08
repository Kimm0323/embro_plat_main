<?php
session_start();
require_once '../config/db.php';
require_role('owner');

$owner_id = $_SESSION['user']['id'];

// Check if already has a shop
$check_stmt = $pdo->prepare("SELECT * FROM shops WHERE owner_id = ?");
$check_stmt->execute([$owner_id]);
$existing_shop = $check_stmt->fetch();

if($existing_shop) {
    header("Location: dashboard.php");
    exit();
}

$error = '';
$success = '';

if($_SERVER['REQUEST_METHOD'] == 'POST') {
    $shop_name = sanitize($_POST['shop_name']);
    $shop_description = sanitize($_POST['shop_description']);
    $address = sanitize($_POST['address']);
    $phone = sanitize($_POST['phone']);
    $business_permit = sanitize($_POST['business_permit']);
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO shops (owner_id, shop_name, shop_description, address, phone, business_permit, status) 
            VALUES (?, ?, ?, ?, ?, ?, 'pending')
        ");
        
        $stmt->execute([$owner_id, $shop_name, $shop_description, $address, $phone, $business_permit]);
        
        // Update user status
        $update_stmt = $pdo->prepare("UPDATE users SET status = 'active' WHERE id = ?");
        $update_stmt->execute([$owner_id]);
        
        header("Location: dashboard.php?success=shop_created");
        exit();
        
    } catch(PDOException $e) {
        $error = "Failed to create shop: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Your Shop - Embroidery Platform</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <nav class="navbar">
        <div class="container d-flex justify-between align-center">
            <a href="dashboard.php" class="navbar-brand">
                <i class="fas fa-store"></i> Create Your Shop
            </a>
            <ul class="navbar-nav">
                <li><a href="../auth/logout.php" class="nav-link">Logout</a></li>
            </ul>
        </div>
    </nav>

    <div class="container">
        <div class="card mt-4" style="max-width: 800px; margin: 0 auto;">
            <div class="card-header">
                <h2><i class="fas fa-store"></i> Set Up Your Embroidery Shop</h2>
                <p class="text-muted">Complete your shop profile to start accepting orders</p>
            </div>
            
            <?php if($error): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <form method="POST">
                <?php echo csrf_field(); ?>
                <div class="form-group">
                    <label>Shop Name *</label>
                    <input type="text" name="shop_name" class="form-control" required 
                           placeholder="Enter your shop name">
                </div>
                
                <div class="form-group">
                    <label>Shop Description *</label>
                    <textarea name="shop_description" class="form-control" rows="4" required
                              placeholder="Describe your embroidery services..."></textarea>
                </div>
                
                <div class="form-group">
                    <label>Business Address *</label>
                    <textarea name="address" class="form-control" rows="3" required
                              placeholder="Enter your business address"></textarea>
                </div>
                
                <div class="row" style="display: flex; gap: 15px;">
                    <div class="form-group" style="flex: 1;">
                        <label>Contact Phone *</label>
                        <input type="tel" name="phone" class="form-control" required 
                               placeholder="Enter contact number">
                    </div>
                    
                    <div class="form-group" style="flex: 1;">
                        <label>Business Permit Number</label>
                        <input type="text" name="business_permit" class="form-control" 
                               placeholder="Enter business permit number">
                    </div>
                </div>
                
                <div class="alert alert-info">
                    <h6><i class="fas fa-info-circle"></i> Important Information</h6>
                    <p class="mb-0">Your shop will be reviewed by our system administrators before it becomes active. You'll receive a notification once approved.</p>
                </div>
                
                <div class="text-center mt-4">
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="fas fa-check-circle"></i> Create Shop
                    </button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>