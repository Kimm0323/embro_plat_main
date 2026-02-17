<?php
session_start();
require_once '../config/db.php';
require_role('client');

$client_id = $_SESSION['user']['id'];
$unread_notifications = fetch_unread_notification_count($pdo, $client_id);

$profile_stmt = $pdo->prepare("SELECT fullname, email, phone, created_at, last_login FROM users WHERE id = ? LIMIT 1");
$profile_stmt->execute([$client_id]);
$profile = $profile_stmt->fetch() ?: [];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Profile</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <?php require_once __DIR__ . '/includes/customer_navbar.php'; ?>

    <div class="container">
        <div class="dashboard-header">
            <h2>Customer Profile</h2>
             <p class="text-muted">Review your personal information, delivery address, and payment methods for your future orders.</p>
        </div>

        <div class="card mb-3">
            <div class="card-header">
                <h3><i class="fas fa-id-card text-primary"></i> Personal Information</h3>
            </div>
            <div class="form-grid">
                <div>
                    <label>Full name</label>
                    <div class="form-control bg-light"><?php echo htmlspecialchars($profile['fullname'] ?? 'Not available'); ?></div>
                </div>
                <div>
                    <label>Email</label>
                    <div class="form-control bg-light"><?php echo htmlspecialchars($profile['email'] ?? 'Not available'); ?></div>
                </div>
                <div>
                    <label>Phone</label>
                    <div class="form-control bg-light"><?php echo htmlspecialchars($profile['phone'] ?? 'Not yet provided'); ?></div>
                </div>
                <div>
                    <label>Member since</label>
                    <div class="form-control bg-light"><?php echo !empty($profile['created_at']) ? date('M d, Y', strtotime($profile['created_at'])) : 'Not available'; ?></div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">
               <h3><i class="fas fa-truck text-primary"></i> Delivery Address</h3>
            </div>
             <p class="text-muted">Set your recipient address so shops can deliver your orders accurately.</p>
            <div class="form-grid">
                <div>
                    <label for="country">Country</label>
                    <input id="country" class="form-control" type="text" placeholder="e.g. Philippines">
                </div>
                <div>
                    <label for="province">Province</label>
                    <input id="province" class="form-control" type="text" placeholder="e.g. Cavite">
                </div>
                <div>
                    <label for="city">City / Municipality</label>
                    <input id="city" class="form-control" type="text" placeholder="e.g. Dasmariñas City">
                </div>
                <div>
                    <label for="barangay">Barangay</label>
                    <input id="barangay" class="form-control" type="text" placeholder="e.g. Salawag">
                </div>
           <div>
                    <label for="house_number">House Number / Street</label>
                    <input id="house_number" class="form-control" type="text" placeholder="e.g. Blk 5 Lot 12 Mabini St.">
                </div>
                <div>
                    <label for="other_info">Other House Information</label>
                    <input id="other_info" class="form-control" type="text" placeholder="e.g. Near chapel, blue gate">
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-credit-card text-primary"></i> Payment Methods</h3>
            </div>
            <p class="text-muted">Choose how you want to pay your orders.</p>
            <div class="form-grid">
                <div>
                    <label><strong>GCash</strong></label>
                    <input class="form-control mb-2" type="text" maxlength="11" placeholder="GCash number (11 digits)">
                    <button class="btn btn-primary btn-sm" type="button">Verify GCash Number</button>
                    <input class="form-control mt-2" type="text" placeholder="Enter OTP">
                </div>
                <div>
                    <label><strong>Cards (Visa / Mastercard)</strong></label>
                    <input class="form-control mb-2" type="text" maxlength="16" placeholder="ATM Card Number (16 digits)">
                    <input class="form-control mb-2" type="email" placeholder="Gmail account for verification">
                    <button class="btn btn-primary btn-sm" type="button">Verify Card via Gmail</button>
                </div>
            <div>
                    <label><strong>Cash on Delivery (COD)</strong></label>
                    <div class="form-control bg-light">Pay cash upon delivery.</div>
                </div>
                <div>
                    <label><strong>Pick Up Pay</strong></label>
                    <div class="form-control bg-light">Pay at the counter when picking up your order.</div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>