<?php
session_start();
require_once '../config/db.php';
require_role('client');

$client_id = $_SESSION['user']['id'];
$unread_notifications = fetch_unread_notification_count($pdo, $client_id);

$profile_stmt = $pdo->prepare("SELECT fullname, email, phone, created_at, last_login FROM users WHERE id = ? LIMIT 1");
$profile_stmt->execute([$client_id]);
$profile = $profile_stmt->fetch() ?: [];

$full_name = trim($profile['fullname'] ?? '');
$name_parts = preg_split('/\s+/', $full_name, -1, PREG_SPLIT_NO_EMPTY);
$first_name = $name_parts[0] ?? '';
$last_name = '';
$middle_name = '';

if (count($name_parts) >= 2) {
    $last_name = array_pop($name_parts);
    $first_name = array_shift($name_parts) ?? $first_name;
    $middle_name = implode(' ', $name_parts);
}

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
                     <label for="first_name">First name</label>
                    <input id="first_name" class="form-control" type="text" value="<?php echo htmlspecialchars($first_name); ?>" placeholder="Enter first name" required>
                </div>
                <div>
                    <label for="middle_name">Middle name <span class="text-muted">(Optional)</span></label>
                    <input id="middle_name" class="form-control" type="text" value="<?php echo htmlspecialchars($middle_name); ?>" placeholder="Enter middle name">
                </div>
                <div>
                    <label for="last_name">Last name</label>
                    <input id="last_name" class="form-control" type="text" value="<?php echo htmlspecialchars($last_name); ?>" placeholder="Enter last name" required>
                </div>
                <div>
                    <label>Email</label>
                    <div class="form-control bg-light"><?php echo htmlspecialchars($profile['email'] ?? 'Not available'); ?></div>
                </div>
                <div>
                    <label for="phone">Phone</label>
                    <input
                        id="phone"
                        class="form-control"
                        type="tel"
                        inputmode="numeric"
                        value="<?php echo htmlspecialchars($profile['phone'] ?? ''); ?>"
                        placeholder="Enter +63XXXXXXXXXX or 09XXXXXXXXX"
                        pattern="^(\+63\d{10}|09\d{9})$"
                        title="Use +63 followed by 10 digits (12 characters total) or 09 followed by 9 digits (11 characters total)."
                        maxlength="13"
                        required
                    >
                    <small class="text-muted">+63 numbers are limited to 12 digits total (+ sign included). 09 numbers are limited to 11 digits total.</small>
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
                   <input id="country" class="form-control" type="text" placeholder="e.g. Philippines" required>
                </div>
                <div>
                    <label for="province">Province</label>
                    <input id="province" class="form-control" type="text" placeholder="e.g. Cavite" required>
                </div>
                <div>
                    <label for="city">City / Municipality</label>
                    <input id="city" class="form-control" type="text" placeholder="e.g. Dasmariñas City" required>
                </div>
                <div>
                    <label for="barangay">Barangay</label>
                    <input id="barangay" class="form-control" type="text" placeholder="e.g. Salawag" required>
                </div>
           <div>
                    <label for="house_number">House Number / Street</label>
                    <input id="house_number" class="form-control" type="text" placeholder="e.g. Blk 5 Lot 12 Mabini St." required>
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
                    <label class="d-block mb-2"><input type="checkbox" class="payment-method-toggle" name="payment_methods[]" value="gcash"> Enable GCash</label>
                    <input class="form-control mb-2" type="text" maxlength="11" placeholder="GCash number (11 digits)">
                    <button class="btn btn-primary btn-sm" type="button">Verify GCash Number</button>
                    <input class="form-control mt-2" type="text" placeholder="Enter OTP">
                    <label class="d-block mt-2"><input type="radio" name="default_payment_method" value="gcash" class="default-payment-option"> Set as default</label>
                </div>
                <div>
                    <label><strong>Cards (Visa / Mastercard)</strong></label>
                    <label class="d-block mb-2"><input type="checkbox" class="payment-method-toggle" name="payment_methods[]" value="card"> Enable card payment</label>
                    <input class="form-control mb-2" type="text" maxlength="16" placeholder="ATM Card Number (16 digits)">
                    <input class="form-control mb-2" type="email" placeholder="Gmail account for verification">
                    <button class="btn btn-primary btn-sm" type="button">Verify Card via Gmail</button>
                    <label class="d-block mt-2"><input type="radio" name="default_payment_method" value="card" class="default-payment-option"> Set as default</label>
                </div>
            <div>
                    <label><strong>Cash on Delivery (COD)</strong></label>
                    <label class="d-block mb-2"><input type="checkbox" class="payment-method-toggle" name="payment_methods[]" value="cod"> Enable COD</label>
                    <div class="form-control bg-light">Pay cash upon delivery.</div>
                    <label class="d-block mt-2"><input type="radio" name="default_payment_method" value="cod" class="default-payment-option"> Set as default</label>
                </div>
                <div>
                    <label><strong>Pick Up Pay</strong></label>
                    <label class="d-block mb-2"><input type="checkbox" class="payment-method-toggle" name="payment_methods[]" value="pickup"> Enable Pick Up Pay</label>
                    <div class="form-control bg-light">Pay at the counter when picking up your order.</div>
                    <label class="d-block mt-2"><input type="radio" name="default_payment_method" value="pickup" class="default-payment-option"> Set as default</label>
                </div>
            </div>
            <small class="text-muted">You may use Cash on Delivery and Pick Up Pay with or without GCash/Card.</small>
        </div>
    </div>

     <script>
        (function () {
            const phoneInput = document.getElementById('phone');
            const paymentToggles = document.querySelectorAll('.payment-method-toggle');
            const defaultOptions = document.querySelectorAll('.default-payment-option');

            const applyPhoneRules = () => {
                const value = phoneInput.value.trim();

                if (value.startsWith('+63')) {
                    phoneInput.maxLength = 13;
                } else if (value.startsWith('09')) {
                    phoneInput.maxLength = 11;
                } else {
                    phoneInput.maxLength = 13;
                }
            };

            phoneInput.addEventListener('input', () => {
                phoneInput.value = phoneInput.value.replace(/[^\d+]/g, '');
                if (phoneInput.value.indexOf('+') > 0) {
                    phoneInput.value = phoneInput.value.replace(/\+/g, '');
                }
                if (phoneInput.value.startsWith('++')) {
                    phoneInput.value = '+' + phoneInput.value.replace(/\+/g, '');
                }
                applyPhoneRules();
            });

            const syncDefaultPaymentSelection = () => {
                defaultOptions.forEach((option) => {
                    const matchingToggle = document.querySelector(`.payment-method-toggle[value="${option.value}"]`);
                    option.disabled = !matchingToggle?.checked;
                    if (!matchingToggle?.checked && option.checked) {
                        option.checked = false;
                    }
                });
            };

            paymentToggles.forEach((toggle) => {
                toggle.addEventListener('change', syncDefaultPaymentSelection);
            });

            applyPhoneRules();
            syncDefaultPaymentSelection();
        })();
    </script>
</body>
</html>