<?php
session_start();
require_once '../config/db.php';
require_once '../includes/media_manager.php';
require_role('owner');

$owner_id = $_SESSION['user']['id'];
$shop_stmt = $pdo->prepare("SELECT * FROM shops WHERE owner_id = ?");
$shop_stmt->execute([$owner_id]);
$shop = $shop_stmt->fetch();
$shop_posts = [];

$pending_notice = $_SESSION['owner_pending_notice'] ?? '';
unset($_SESSION['owner_pending_notice']);

function build_work_post_description(string $embroidery_size, string $canvas_used, string $description): ?string {
    $metadata_lines = [
        'Embroidery Size: ' . $embroidery_size,
        'Canvas Used: ' . $canvas_used,
    ];

    $base_description = trim($description);
    if ($base_description !== '') {
        $metadata_lines[] = '';
        $metadata_lines[] = $base_description;
    }

    return implode("\n", $metadata_lines);
}

function parse_work_post_description(?string $description): array {
    $result = [
        'embroidery_size' => '',
        'canvas_used' => '',
        'description' => trim((string) $description),
    ];

    if ($description === null || trim($description) === '') {
        return $result;
    }

    $lines = preg_split('/\r\n|\r|\n/', $description);
    if (!$lines) {
        return $result;
    }

    $first_line = trim($lines[0] ?? '');
    $second_line = trim($lines[1] ?? '');

    if (str_starts_with($first_line, 'Embroidery Size: ') && str_starts_with($second_line, 'Canvas Used: ')) {
        $result['embroidery_size'] = trim(substr($first_line, strlen('Embroidery Size: ')));
        $result['canvas_used'] = trim(substr($second_line, strlen('Canvas Used: ')));

        $remaining_lines = array_slice($lines, 2);
        if (!empty($remaining_lines) && trim((string) $remaining_lines[0]) === '') {
            array_shift($remaining_lines);
        }
        $result['description'] = trim(implode("\n", $remaining_lines));
    }

    return $result;
}
if(!$shop) {
    header("Location: create_shop.php");
    exit();
}

$success = '';
$error = '';

$business_information = json_decode((string) ($shop['business_permit'] ?? ''), true);
if (!is_array($business_information)) {
    $business_information = [];
}

$business_form_values = [
    'individual_registered_name' => (string) ($business_information['individual_registered_name'] ?? ''),
    'business_trade_name' => (string) ($shop['shop_name'] ?? ''),
    'shop_description' => (string) ($shop['shop_description'] ?? ''),
    'country' => '',
    'province' => '',
    'city_municipality' => '',
    'barangay' => '',
    'house_street' => '',
    'other_address_information' => '',
    'primary_business_document_type' => (string) ($business_information['primary_business_document_type'] ?? ''),
    'business_email' => (string) ($shop['email'] ?? ''),
    'business_phone' => (string) ($shop['phone'] ?? ''),
    'tax_payer_identification_number' => (string) ($business_information['tax_payer_identification_number'] ?? ''),
    'vat_registration' => (string) ($business_information['vat_registration'] ?? ''),
    'submit_sworn_declaration' => (string) ($business_information['submit_sworn_declaration'] ?? ''),
    'agree_terms_conditions' => false,
    'agree_data_privacy' => false,
];

$address_lines = preg_split('/\r\n|\r|\n/', (string) ($shop['address'] ?? ''));
foreach ($address_lines as $line) {
    $line = trim($line);
    if (str_starts_with($line, 'Country: ')) {
        $business_form_values['country'] = trim(substr($line, strlen('Country: ')));
    } elseif (str_starts_with($line, 'Province: ')) {
        $business_form_values['province'] = trim(substr($line, strlen('Province: ')));
    } elseif (str_starts_with($line, 'City / Municipality: ')) {
        $business_form_values['city_municipality'] = trim(substr($line, strlen('City / Municipality: ')));
    } elseif (str_starts_with($line, 'Barangay: ')) {
        $business_form_values['barangay'] = trim(substr($line, strlen('Barangay: ')));
    } elseif (str_starts_with($line, 'House Number / Street: ')) {
        $business_form_values['house_street'] = trim(substr($line, strlen('House Number / Street: ')));
    } elseif (str_starts_with($line, 'Other Address Information: ')) {
        $other_address_information = trim(substr($line, strlen('Other Address Information: ')));
        $business_form_values['other_address_information'] = $other_address_information === 'N/A' ? '' : $other_address_information;
    }
}

if($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'submit_business_information';

    if ($action === 'submit_business_information') {
        $business_form_values['individual_registered_name'] = sanitize($_POST['individual_registered_name'] ?? '');
        $business_form_values['business_trade_name'] = sanitize($_POST['business_trade_name'] ?? '');
        $business_form_values['shop_description'] = sanitize($_POST['shop_description'] ?? '');
        $business_form_values['country'] = sanitize($_POST['country'] ?? '');
        $business_form_values['province'] = sanitize($_POST['province'] ?? '');
        $business_form_values['city_municipality'] = sanitize($_POST['city_municipality'] ?? '');
        $business_form_values['barangay'] = sanitize($_POST['barangay'] ?? '');
        $business_form_values['house_street'] = sanitize($_POST['house_street'] ?? '');
        $business_form_values['other_address_information'] = sanitize($_POST['other_address_information'] ?? '');
        $business_form_values['primary_business_document_type'] = sanitize($_POST['primary_business_document_type'] ?? '');
        $business_form_values['business_email'] = sanitize($_POST['business_email'] ?? '');
        $business_form_values['business_phone'] = trim((string) ($_POST['business_phone'] ?? ''));
        $business_form_values['tax_payer_identification_number'] = sanitize($_POST['tax_payer_identification_number'] ?? '');
        $business_form_values['vat_registration'] = sanitize($_POST['vat_registration'] ?? '');
        $business_form_values['submit_sworn_declaration'] = sanitize($_POST['submit_sworn_declaration'] ?? '');
        $business_form_values['agree_terms_conditions'] = isset($_POST['agree_terms_conditions']);
        $business_form_values['agree_data_privacy'] = isset($_POST['agree_data_privacy']);
    }

    try {
        if ($action === 'submit_business_information') {
           $individual_registered_name = $business_form_values['individual_registered_name'];
            $business_trade_name = $business_form_values['business_trade_name'];
            $shop_description = $business_form_values['shop_description'];
            $country = $business_form_values['country'];
            $province = $business_form_values['province'];
            $city_municipality = $business_form_values['city_municipality'];
            $barangay = $business_form_values['barangay'];
            $house_street = $business_form_values['house_street'];
            $other_address_information = $business_form_values['other_address_information'];

            if ($individual_registered_name === '') {
                throw new RuntimeException('Individual registered name is required.');
            }
            if ($business_trade_name === '') {
                throw new RuntimeException('Business name/trade name is required.');
            }
             if ($shop_description === '') {
                throw new RuntimeException('Shop description is required.');
            }
            if ($country === '' || $province === '' || $city_municipality === '' || $barangay === '' || $house_street === '') {
                throw new RuntimeException('Please complete all required address fields.');
            }

            $primary_business_document_type = $business_form_values['primary_business_document_type'];
            if ($primary_business_document_type === '') {
                throw new RuntimeException('Primary business document type is required.');
            }

            $business_email = $business_form_values['business_email'];
            if ($business_email === '') {
                throw new RuntimeException('Business email is required.');
            }


            $business_phone_raw = $business_form_values['business_phone'];
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

             $tax_payer_identification_number = $business_form_values['tax_payer_identification_number'];
            if ($tax_payer_identification_number === '') {
                throw new RuntimeException('Tax Payer Identification Number is required.');
            }

            $vat_registration = $business_form_values['vat_registration'];
            if (!in_array($vat_registration, ['vat_registered', 'non_vat_registered'], true)) {
                throw new RuntimeException('Please select VAT registration type.');
            }
            $submit_sworn_declaration = $business_form_values['submit_sworn_declaration'];
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
                SET shop_name = ?, shop_description = ?, address = ?, phone = ?, email = ?, business_permit = ?, permit_file = ?
                WHERE id = ?
            ");
            $update_stmt->execute([
                $business_trade_name,
                 $shop_description,
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
            $shop_posts = [];

            $pending_notice = $_SESSION['owner_pending_notice'] ?? '';
            unset($_SESSION['owner_pending_notice']);
            } elseif ($action === 'submit_work_post') {
            $post_title = sanitize($_POST['post_title'] ?? '');
            $post_description = sanitize($_POST['post_description'] ?? '');
            $post_embroidery_size = sanitize($_POST['post_embroidery_size'] ?? '');
            $post_canvas_used = sanitize($_POST['post_canvas_used'] ?? '');
            $post_price = (float) ($_POST['post_price'] ?? 0);

            if ($post_title === '') {
                throw new RuntimeException('Work title is required.');
            }

            if ($post_price < 0) {
                throw new RuntimeException('Starting price cannot be negative.');
            }
            if ($post_embroidery_size === '') {
                throw new RuntimeException('Specific embroidery size is required.');
            }

            if ($post_canvas_used === '') {
                throw new RuntimeException('Canvas used is required.');
            }


            if (empty($_FILES['post_image']['name'])) {
                throw new RuntimeException('Please upload a work image.');
            }

            $upload_result = save_uploaded_media(
                $_FILES['post_image'],
                ['jpg', 'jpeg', 'png', 'webp'],
                MAX_FILE_SIZE,
                'portfolio',
                'work_post',
                (string) $shop['id']
            );

            if (!$upload_result['success']) {
                throw new RuntimeException($upload_result['error']);
            }

            $insert_post_stmt = $pdo->prepare(
                "INSERT INTO shop_portfolio (shop_id, title, description, price, image_path) VALUES (?, ?, ?, ?, ?)"
            );
            $insert_post_stmt->execute([
                $shop['id'],
                $post_title,
                build_work_post_description($post_embroidery_size, $post_canvas_used, $post_description),
                $post_price,
                $upload_result['path'],
            ]);

            $success = 'Work posted successfully. It is now visible on the client dashboard.';
        }
    } catch(RuntimeException $e) {
        $error = $e->getMessage();
    } catch(PDOException $e) {
         $error = 'Failed to submit business information: ' . $e->getMessage();
         }
}

$posts_stmt = $pdo->prepare("SELECT id, title, description, price, image_path, created_at FROM shop_portfolio WHERE shop_id = ? ORDER BY created_at DESC LIMIT 6");
$posts_stmt->execute([$shop['id']]);
$shop_posts = $posts_stmt->fetchAll(PDO::FETCH_ASSOC);
    
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

         .work-post-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(302px, 302px));
            justify-content: center;
            gap: 12px;
            margin-top: 14px;
        }

        .work-post-card {
            width: 302px;
            min-height: 265px;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 12px;
            background: var(--bg-primary);
        }

        .work-post-card img {
            width: 100%;
            height: 130px;
            object-fit: cover;
            border-radius: 10px;
            border: 1px solid var(--gray-200);
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
    <?php include __DIR__ . "/includes/owner_navbar.php"; ?>



    <div class="container">
        <div class="dashboard-header">
            <h2>Shop Profile</h2>
            <p class="text-muted">Business information submission requirements.</p>
        </div>

        <?php if($pending_notice): ?>
            <div class="alert alert-warning"><?php echo htmlspecialchars($pending_notice); ?></div>
        <?php endif; ?>

        <?php if($error): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>

        <?php if($success): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>

        <div class="card profile-card">
            <form method="POST" enctype="multipart/form-data" id="business-information-form">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="submit_business_information">

                 <h4 class="profile-section-title">Business Information</h4>
                <p class="text-muted">Provide all required business details and legal documents.</p>

                     <div class="form-group">
                    <label>Individual Registered Name *</label>
                    <input type="text" name="individual_registered_name" class="form-control" required
                           value="<?php echo htmlspecialchars($business_form_values['individual_registered_name']); ?>">
                </div>

                <div class="form-group">
                    <label>Business Name / Trade Name *</label>
                    <input type="text" name="business_trade_name" class="form-control" required
                           value="<?php echo htmlspecialchars($business_form_values['business_trade_name']); ?>">
                </div>

                <div class="form-group">
                    <label>Shop Description *</label>
                    <textarea name="shop_description" class="form-control" rows="4" required placeholder="Describe your embroidery specialties, turnaround, and service highlights."><?php echo htmlspecialchars($business_form_values['shop_description']); ?></textarea>
                </div>

                 <h5 class="profile-section-title">Address *</h5>
                 <div class="profile-form-grid">
                    <div class="form-group">
                        <label>Country *</label>
                        <input type="text" name="country" class="form-control" required
                               value="<?php echo htmlspecialchars($business_form_values['country']); ?>">
                    </div>
                     <div class="form-group">
                        <label>Province *</label>
                        <input type="text" name="province" class="form-control" required
                               value="<?php echo htmlspecialchars($business_form_values['province']); ?>">
                    </div>
                <div class="form-group">
                        <label>City / Municipality *</label>
                        <input type="text" name="city_municipality" class="form-control" required
                               value="<?php echo htmlspecialchars($business_form_values['city_municipality']); ?>">
                    </div>
                    <div class="form-group">
                        <label>Barangay *</label>
                        <input type="text" name="barangay" class="form-control" required
                               value="<?php echo htmlspecialchars($business_form_values['barangay']); ?>">
                    </div>
                    <div class="form-group">
                        <label>House Number / Street *</label>
                        <input type="text" name="house_street" class="form-control" required
                               value="<?php echo htmlspecialchars($business_form_values['house_street']); ?>">
                    </div>
                    <div class="form-group">
                        <label>Other Address Information</label>
                        <input type="text" name="other_address_information" class="form-control"
                               value="<?php echo htmlspecialchars($business_form_values['other_address_information']); ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label>Primary Business Document Type *</label>
                    <input type="text" name="primary_business_document_type" class="form-control" required placeholder="DTI Certificate"
                           value="<?php echo htmlspecialchars($business_form_values['primary_business_document_type']); ?>">
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
                    </div>

                <div class="profile-form-grid">
                    <div class="form-group">
                        <label>Business Email *</label>
                        <input type="email" name="business_email" class="form-control" required
                               value="<?php echo htmlspecialchars($business_form_values['business_email']); ?>">
                    </div>
                     <div class="form-group">
                        <label>Business Phone Number *</label>
                        <input type="text" name="business_phone" class="form-control" required maxlength="12" inputmode="numeric" pattern="^(\+63\d{9}|09\d{9})$"
                               value="<?php echo htmlspecialchars($business_form_values['business_phone']); ?>">
                        <small class="text-muted">Use <strong>+63</strong> then 9 digits, or <strong>09</strong> then 9 digits.</small>
                    </div>
                </div>

            <div class="form-group">
                    <label>Tax Payer Identification Number *</label>
                     <input type="text" name="tax_payer_identification_number" class="form-control" required
                           value="<?php echo htmlspecialchars($business_form_values['tax_payer_identification_number']); ?>">
                </div>

                <div class="form-group">
                    <label>VAT Registration *</label>
                    <select name="vat_registration" class="form-control" required>
                        <option value="">Select VAT registration</option>
                        <option value="vat_registered" <?php echo $business_form_values['vat_registration'] === 'vat_registered' ? 'selected' : ''; ?>>VAT Registered</option>
                        <option value="non_vat_registered" <?php echo $business_form_values['vat_registration'] === 'non_vat_registered' ? 'selected' : ''; ?>>Non VAT Registered</option>
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
                        <option value="yes" <?php echo $business_form_values['submit_sworn_declaration'] === 'yes' ? 'selected' : ''; ?>>Yes</option>
                        <option value="no" <?php echo $business_form_values['submit_sworn_declaration'] === 'no' ? 'selected' : ''; ?>>No</option>
                    </select>
                </div>

                 <div class="form-group checkbox-stack">
                    <label style="display: flex; align-items: center; gap: 8px;">
                         <input type="checkbox" name="agree_terms_conditions" value="1" required <?php echo $business_form_values['agree_terms_conditions'] ? 'checked' : ''; ?>>
                        <span>I agree to the Terms and Conditions.</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" name="agree_data_privacy" value="1" required <?php echo $business_form_values['agree_data_privacy'] ? 'checked' : ''; ?>>
                        <span>I agree to the Data Privacy Policy.</span>
                    </label>
                </div>
            <div class="text-center mt-4">
                <button type="button" class="btn btn-secondary btn-lg" id="business-info-reset">
                        <i class="fas fa-undo"></i> Reset Fields
                    </button>
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="fas fa-paper-plane"></i> Submit
                    </button>
                </div>
            </form>
        </div>
         <div class="card profile-card">
            <form method="POST" enctype="multipart/form-data">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="submit_work_post">

                <h4 class="profile-section-title">Post Your Works</h4>
                <p class="text-muted">Add your latest output so clients can discover it from their dashboard.</p>

                <div class="profile-form-grid">
                    <div class="form-group">
                        <label>Work Title *</label>
                        <input type="text" name="post_title" class="form-control" required placeholder="Custom Polo Logo Embroidery">
                    </div>
                    <div class="form-group">
                        <label>Starting Price (₱)</label>
                        <input type="number" name="post_price" class="form-control" min="0" step="0.01" value="0">
                    </div>
                </div>

                <div class="form-group">
                    <label>Description</label>
                    <textarea name="post_description" class="form-control" rows="3" maxlength="255" placeholder="Share stitch type, fabric, turnaround, or package details."></textarea>
                </div>

                <div class="profile-form-grid">
                    <div class="form-group">
                        <label>Specific Embroidery Size *</label>
                        <input type="text" name="post_embroidery_size" class="form-control" required placeholder="e.g. 4 x 4 inches">
                    </div>
                    <div class="form-group">
                        <label>Canvas Used *</label>
                        <input type="text" name="post_canvas_used" class="form-control" required placeholder="e.g. Cotton twill fabric">
                    </div>
                </div>

                <div class="form-group">
                    <label>Work Image *</label>
                    <input type="file" name="post_image" class="form-control" accept=".jpg,.jpeg,.png,.webp" required>
                </div>

                <div class="text-center mt-3">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-image"></i> Publish Work Post
                    </button>
                </div>
            </form>

            <h5 class="profile-section-title">Latest Posted Works</h5>
            <?php if(!empty($shop_posts)): ?>
                <div class="work-post-grid">
                    <?php foreach($shop_posts as $post): ?>
                        <?php $post_details = parse_work_post_description($post['description'] ?? null); ?>
                        <div class="work-post-card">
                            <img src="../assets/uploads/<?php echo htmlspecialchars($post['image_path']); ?>" alt="<?php echo htmlspecialchars($post['title']); ?>">
                            <h5 class="mb-1"><?php echo htmlspecialchars($post['title']); ?></h5>
                            <small class="text-muted d-block mb-1"><?php echo date('M d, Y', strtotime($post['created_at'])); ?></small>
                            <p class="mb-1"><strong>₱<?php echo number_format((float) $post['price'], 2); ?></strong></p>
                            <?php if($post_details['embroidery_size'] !== ''): ?>
                                <p class="mb-1"><strong>Embroidery Size:</strong> <?php echo htmlspecialchars($post_details['embroidery_size']); ?></p>
                            <?php endif; ?>
                            <?php if($post_details['canvas_used'] !== ''): ?>
                                <p class="mb-1"><strong>Canvas Used:</strong> <?php echo htmlspecialchars($post_details['canvas_used']); ?></p>
                            <?php endif; ?>
                            <?php if($post_details['description'] !== ''): ?>
                                <p class="text-muted mb-0"><?php echo nl2br(htmlspecialchars($post_details['description'])); ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="text-muted mb-0">No posted works yet. Publish your first post to appear on the client dashboard.</p>
            <?php endif; ?>
        </div>
    </div>
    <script>
        const businessInformationForm = document.getElementById('business-information-form');
        const businessInfoResetButton = document.getElementById('business-info-reset');

        if (businessInformationForm && businessInfoResetButton) {
            const fieldsToReset = [
                'individual_registered_name',
                'primary_business_document_type',
                'tax_payer_identification_number',
                'vat_registration',
                'submit_sworn_declaration',
                'primary_business_document_photo',
                'government_id_front_photo',
                'government_id_back_photo',
                'bir_certificate_registration_photo'
            ];

            businessInfoResetButton.addEventListener('click', () => {
                fieldsToReset.forEach((fieldName) => {
                    const field = businessInformationForm.elements[fieldName];
                    if (!field) {
                        return;
                    }

                    if (field.type === 'file') {
                        field.value = '';
                    } else if (field.type === 'checkbox' || field.type === 'radio') {
                        field.checked = false;
                    } else {
                        field.value = '';
                    }
                });
            });
        }

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
