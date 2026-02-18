<?php
session_start();
require_once '../config/db.php';
require_once '../includes/media_manager.php';
require_role('owner');

$owner_id = $_SESSION['user']['id'];
$shop_stmt = $pdo->prepare("SELECT * FROM shops WHERE owner_id = ?");
$shop_stmt->execute([$owner_id]);
$shop = $shop_stmt->fetch();

if(!$shop) {
    header("Location: create_shop.php");
    exit();
}

$success = '';
$error = '';

if($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'submit_business_information';

    try {
        if ($action === 'submit_business_information') {
            $individual_registered_name = sanitize($_POST['individual_registered_name'] ?? '');
            $business_trade_name = sanitize($_POST['business_trade_name'] ?? '');
            $country = sanitize($_POST['country'] ?? '');
            $province = sanitize($_POST['province'] ?? '');
            $city_municipality = sanitize($_POST['city_municipality'] ?? '');
            $barangay = sanitize($_POST['barangay'] ?? '');
            $house_street = sanitize($_POST['house_street'] ?? '');
            $other_address_information = sanitize($_POST['other_address_information'] ?? '');

            if ($individual_registered_name === '') {
                throw new RuntimeException('Individual registered name is required.');
            }
            if ($business_trade_name === '') {
                throw new RuntimeException('Business name/trade name is required.');
            }
            if ($country === '' || $province === '' || $city_municipality === '' || $barangay === '' || $house_street === '') {
                throw new RuntimeException('Please complete all required address fields.');
            }

        $primary_business_document_type = sanitize($_POST['primary_business_document_type'] ?? '');
            if ($primary_business_document_type === '') {
                throw new RuntimeException('Primary business document type is required.');
            }

            $business_email = sanitize($_POST['business_email'] ?? '');
            if ($business_email === '') {
                throw new RuntimeException('Business email is required.');
            }


            $business_phone_raw = trim((string) ($_POST['business_phone'] ?? ''));
            if (!preg_match('/^\+?\d+$/', $business_phone_raw)) {
                throw new RuntimeException('Business phone number must contain numbers only.');
            }
            if (str_starts_with($business_phone_raw, '+63')) {
                if (strlen($business_phone_raw) !== 12) {
                    throw new RuntimeException('If business phone starts with +63, it must be exactly 12 characters.');
              }
            } elseif (str_starts_with($business_phone_raw, '09')) {
                if (strlen($business_phone_raw) !== 11) {
                    throw new RuntimeException('If business phone starts with 09, it must be exactly 11 digits.');
                }
            } else {
               throw new RuntimeException('Business phone must start with +63 or 09.');
            }
             $business_phone = sanitize($business_phone_raw);

            $tax_payer_identification_number = sanitize($_POST['tax_payer_identification_number'] ?? '');
            if ($tax_payer_identification_number === '') {
                throw new RuntimeException('Tax Payer Identification Number is required.');
            }

            $vat_registration = sanitize($_POST['vat_registration'] ?? '');
            if (!in_array($vat_registration, ['vat_registered', 'non_vat_registered'], true)) {
                throw new RuntimeException('Please select VAT registration type.');
            }
            $submit_sworn_declaration = sanitize($_POST['submit_sworn_declaration'] ?? '');
            if (!in_array($submit_sworn_declaration, ['yes', 'no'], true)) {
                throw new RuntimeException('Please choose yes or no for sworn declaration.');
            }

            if (!isset($_POST['agree_terms_conditions']) || !isset($_POST['agree_data_privacy'])) {
                throw new RuntimeException('You must agree to terms and conditions and data privacy.');
            }

            $required_upload_fields = [
                'primary_business_document_photo' => 'primary business document photo',
                'government_id_front_photo' => 'government ID front photo',
                'government_id_back_photo' => 'government ID back photo',
                'bir_certificate_registration_photo' => 'BIR certificate of registration photo',
            ];

            $uploaded_documents = [];
            foreach ($required_upload_fields as $field => $label) {
                if (empty($_FILES[$field]['name'])) {
                    throw new RuntimeException('Please upload ' . $label . '.');
                }

                $upload_result = save_uploaded_media(
                    $_FILES[$field],
                    ['jpg', 'jpeg', 'png', 'webp'],
                    MAX_FILE_SIZE,
                    'shops',
                    'documents',
                    (string) $shop['id']
                );

                if (!$upload_result['success']) {
                    throw new RuntimeException($upload_result['error']);
                }

                $uploaded_documents[$field] = $upload_result['path'];
            }

             $address = implode("\n", [
                'Country: ' . $country,
                'Province: ' . $province,
                'City / Municipality: ' . $city_municipality,
                'Barangay: ' . $barangay,
                'House Number / Street: ' . $house_street,
                'Other Address Information: ' . ($other_address_information !== '' ? $other_address_information : 'N/A'),
            ]);

            $business_information_payload = [
                'individual_registered_name' => $individual_registered_name,
                'primary_business_document_type' => $primary_business_document_type,
                'tax_payer_identification_number' => $tax_payer_identification_number,
                'vat_registration' => $vat_registration,
                'submit_sworn_declaration' => $submit_sworn_declaration,
                'documents' => $uploaded_documents,
            ];


            $update_stmt = $pdo->prepare("
                UPDATE shops
                SET shop_name = ?, address = ?, phone = ?, email = ?, business_permit = ?, permit_file = ?
                WHERE id = ?
            ");
            $update_stmt->execute([
                $business_trade_name,
                $address,
                 $business_phone,
                $business_email,
                json_encode($business_information_payload),
                $uploaded_documents['primary_business_document_photo'],
                $shop['id'],
            ]);

            $shop_stmt->execute([$owner_id]);
            $shop = $shop_stmt->fetch();
            $success = 'Business information submitted successfully.';
        }
    } catch(RuntimeException $e) {
        $error = $e->getMessage();
    } catch(PDOException $e) {
         $error = 'Failed to submit business information: ' . $e->getMessage();
         }
}
    
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shop Profile - <?php echo htmlspecialchars($shop['shop_name']); ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
     <style>
        .profile-card {
            max-width: 980px;
            margin: 0 auto 2rem;
        }
        .profile-section-title {
            margin-top: 1.25rem;
            margin-bottom: 0.25rem;
        }

        .profile-form-grid {
            display: grid;
            gap: 15px;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        }

        .checkbox-stack {
            display: grid;
            gap: 8px;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar--compact">
        <div class="container d-flex justify-between align-center">
            <a href="dashboard.php" class="navbar-brand">
                <i class="fas fa-store"></i> <?php echo htmlspecialchars($shop['shop_name']); ?>
            </a>
            <ul class="navbar-nav">
                <li><a href="dashboard.php" class="nav-link">Dashboard</a></li>
                <li><a href="shop_profile.php" class="nav-link active">Shop Profile</a></li>
                <li><a href="pricing_management.php" class="nav-link">Pricing</a></li>
                <li><a href="manage_staff.php" class="nav-link">Staff</a></li>
                <li><a href="shop_orders.php" class="nav-link">Orders</a></li>
                <li><a href="reviews.php" class="nav-link">Reviews</a></li>
                <li><a href="messages.php" class="nav-link">Messages</a></li>
                <li class="dropdown">
                    <a href="#" class="nav-link dropdown-toggle">
                        <i class="fas fa-coins"></i> Finance
                    </a>
                    <div class="dropdown-menu">
                        <a href="payment_verifications.php" class="dropdown-item"><i class="fas fa-receipt"></i> Payments</a>
                        <a href="earnings.php" class="dropdown-item"><i class="fas fa-wallet"></i> Earnings</a>
                    </div>
                </li>
                <li class="dropdown">
                    <a href="#" class="nav-link dropdown-toggle">
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
        <div class="dashboard-header">
            <h2>Shop Profile</h2>
            <p class="text-muted">Business information submission requirements.</p>
        </div>

        <?php if($error): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>

        <?php if($success): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>

        <div class="card profile-card">
            <form method="POST" enctype="multipart/form-data">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="submit_business_information">

                 <h4 class="profile-section-title">Business Information</h4>
                <p class="text-muted">Provide all required business details and legal documents.</p>

                     <div class="form-group">
                    <label>Individual Registered Name *</label>
                    <input type="text" name="individual_registered_name" class="form-control" required>
                </div>

                <div class="form-group">
                    <label>Business Name / Trade Name *</label>
                    <input type="text" name="business_trade_name" class="form-control" required
                           value="<?php echo htmlspecialchars($shop['shop_name']); ?>">
                </div>

                 <h5 class="profile-section-title">Address *</h5>
                 <div class="profile-form-grid">
                    <div class="form-group">
                        <label>Country *</label>
                        <input type="text" name="country" class="form-control" required>
                    </div>
                     <div class="form-group">
                        <label>Province *</label>
                        <input type="text" name="province" class="form-control" required>
                    </div>
                <div class="form-group">
                        <label>City / Municipality *</label>
                        <input type="text" name="city_municipality" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Barangay *</label>
                        <input type="text" name="barangay" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>House Number / Street *</label>
                        <input type="text" name="house_street" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Other Address Information</label>
                        <input type="text" name="other_address_information" class="form-control">
                    </div>
                </div>

                <div class="form-group">
                    <label>Primary Business Document Type *</label>
                    <input type="text" name="primary_business_document_type" class="form-control" required placeholder="DTI Certificate">
                </div>
            <div class="form-group">
                    <label>Upload Primary Business Document (DTI Certificate) *</label>
                    <input type="file" name="primary_business_document_photo" class="form-control" accept=".jpg,.jpeg,.png,.webp" required>
                </div>


                 <div class="profile-form-grid">
                    <div class="form-group">
                        <label>Government ID - Front Photo *</label>
                        <input type="file" name="government_id_front_photo" class="form-control" accept=".jpg,.jpeg,.png,.webp" required>
                    </div>
                     <div class="form-group">
                        <label>Government ID - Back Photo *</label>
                        <input type="file" name="government_id_back_photo" class="form-control" accept=".jpg,.jpeg,.png,.webp" required>
                    </div>
                    < </div>

                <div class="profile-form-grid">
                    <div class="form-group">
                        <label>Business Email *</label>
                        <input type="email" name="business_email" class="form-control" required
                               value="<?php echo htmlspecialchars($shop['email']); ?>">
                    </div>
                     <div class="form-group">
                        <label>Business Phone Number *</label>
                        <input type="text" name="business_phone" class="form-control" required maxlength="12" inputmode="numeric" pattern="^(\+63\d{9}|09\d{9})$"
                               value="<?php echo htmlspecialchars($shop['phone']); ?>">
                        <small class="text-muted">Use <strong>+63</strong> then 9 digits, or <strong>09</strong> then 9 digits.</small>
                    </div>
                </div>

            <div class="form-group">
                    <label>Tax Payer Identification Number *</label>
                    <input type="text" name="tax_payer_identification_number" class="form-control" required>
                </div>

                <div class="form-group">
                    <label>VAT Registration *</label>
                    <select name="vat_registration" class="form-control" required>
                        <option value="">Select VAT registration</option>
                        <option value="vat_registered">VAT Registered</option>
                        <option value="non_vat_registered">Non VAT Registered</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>BIR Certificate of Registration - Upload Photo *</label>
                    <input type="file" name="bir_certificate_registration_photo" class="form-control" accept=".jpg,.jpeg,.png,.webp" required>
                </div>

                <div class="form-group">
                    <label>Submit Sworn Declaration *</label>
                    <select name="submit_sworn_declaration" class="form-control" required>
                        <option value="">Select option</option>
                        <option value="yes">Yes</option>
                        <option value="no">No</option>
                    </select>
                </div>

                 <div class="form-group checkbox-stack">
                    <label style="display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" name="agree_terms_conditions" value="1" required>
                        <span>I agree to the Terms and Conditions.</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" name="agree_data_privacy" value="1" required>
                        <span>I agree to the Data Privacy Policy.</span>
                    </label>
                </div>
            <div class="text-center mt-4">
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="fas fa-paper-plane"></i> Submit
                    </button>
                </div>
            </form>
        </div>
    </div>
    <script>
         const businessPhoneInput = document.querySelector('input[name="business_phone"]');
        if (businessPhoneInput) {
            businessPhoneInput.addEventListener('input', () => {
                let value = businessPhoneInput.value.replace(/[^\d+]/g, '');
                if (value.indexOf('+') > 0) {
                    value = value.replace(/\+/g, '');
                }

                if (value.startsWith('+63')) {
                    value = value.slice(0, 12);
                } else if (value.startsWith('09')) {
                    value = value.slice(0, 11);
                } else {
                    value = value.startsWith('+') ? '+' : value.replace(/\+/g, '');
                    value = value.slice(0, 12);
                }
                 businessPhoneInput.value = value;


            });
            businessPhoneInput.addEventListener('blur', () => {
                businessPhoneInput.reportValidity();
            });
        }
    </script>
</body>
</html>
