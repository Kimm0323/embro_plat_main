<?php
session_start();
require_once '../config/db.php';
require_once '../config/constants.php';
require_once '../includes/media_manager.php';
require_role('client');

$client_id = $_SESSION['user']['id'];
$unread_notifications = fetch_unread_notification_count($pdo, $client_id);
$error = '';
$success = '';
$max_upload_mb = (int) ceil(MAX_FILE_SIZE / (1024 * 1024));


$shops_stmt = $pdo->query("SELECT id, shop_name, address, rating FROM shops WHERE status = 'active' ORDER BY rating DESC, total_orders DESC, shop_name ASC");
$shops = $shops_stmt->fetchAll();

        $selected_custom_order = null;
$prefill_order_id = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;
if ($prefill_order_id > 0) {
    $prefill_stmt = $pdo->prepare("SELECT o.id, o.order_number, o.shop_id, o.service_type, o.design_description, o.design_file, o.client_notes, s.shop_name
        FROM orders o
        JOIN shops s ON s.id = o.shop_id
        WHERE o.id = ? AND o.client_id = ?");
    $prefill_stmt->execute([$prefill_order_id, $client_id]);
    $selected_custom_order = $prefill_stmt->fetch();
}
        

        if (isset($_POST['request_quote'])) {
    $shop_id = (int) ($_POST['shop_id'] ?? 0);
    $service_type = sanitize($_POST['service_type'] ?? '');
    $design_description = sanitize($_POST['design_description'] ?? '');
    $customize_order_id = (int) ($_POST['customize_order_id'] ?? 0);

        if ($shop_id <= 0) {
        $error = 'Please select the shop where you want to request design proofing and quotation.';
    } elseif ($service_type === '') {
        $error = 'Please choose a service type for quotation.';
    } elseif (mb_strlen(trim($design_description)) < 20) {
        $error = 'Please provide at least 20 characters describing your design requirements.';
    }

         $shop_stmt = $pdo->prepare("SELECT id, owner_id, shop_name FROM shops WHERE id = ? AND status = 'active' LIMIT 1");
    $shop_stmt->execute([$shop_id]);
    $shop = $shop_stmt->fetch();

    if ($error === '' && !$shop) {
        $error = 'Selected shop is not available.';
    }

        $uploaded_design_file = null;
    if ($error === '' && isset($_FILES['design_file']) && $_FILES['design_file']['error'] !== UPLOAD_ERR_NO_FILE) {
        $allowed_extensions = array_merge(ALLOWED_IMAGE_TYPES, ALLOWED_DOC_TYPES);
        $upload = save_uploaded_media(
            $_FILES['design_file'],
            $allowed_extensions,
            MAX_FILE_SIZE,
            'designs',
            'quote',
            (string) $client_id
        );

        if (!$upload['success']) {
            $error = $upload['error'] === 'File size exceeds the limit.'
                ? 'Uploaded file is too large. Maximum size is ' . $max_upload_mb . 'MB.'
                : 'Unsupported file format. Please upload JPG, PNG, GIF, PDF, DOC, or DOCX.';
        } else {
            $uploaded_design_file = $upload['filename'];
        }
    }

         if ($error === '' && !$uploaded_design_file && $customize_order_id > 0) {
        $customized_stmt = $pdo->prepare("SELECT id, design_file FROM orders WHERE id = ? AND client_id = ? LIMIT 1");
        $customized_stmt->execute([$customize_order_id, $client_id]);
        $customized_order = $customized_stmt->fetch();
        if ($customized_order) {
            $uploaded_design_file = $customized_order['design_file'] ?: null;
        }
        }

    if ($error === '') {
        $order_number = 'ORD-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
        $quote_details = [
            'requested_from_services' => true,
            'customize_order_id' => $customize_order_id > 0 ? $customize_order_id : null,
            'requested_at' => date('c'),
        ];

        $insert_stmt = $pdo->prepare("INSERT INTO orders (
                order_number, client_id, shop_id, service_type, design_description,
                quantity, price, client_notes, quote_details, design_file, status, design_approved
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 0)");

        $insert_stmt->execute([
            $order_number,
            $client_id,
            $shop_id,
            $service_type,
            $design_description,
            1,
            null,
            'Quote request submitted via Services page.',
            json_encode($quote_details),
            $uploaded_design_file,
        ]);

        $order_id = (int) $pdo->lastInsertId();
        $message = 'New design proofing and quotation request #' . $order_number . ' from ' . ($_SESSION['user']['fullname'] ?? 'a client') . '.';
        if (!empty($shop['owner_id'])) {
            create_notification($pdo, (int) $shop['owner_id'], $order_id, 'order_status', $message);
        }

        $staff_stmt = $pdo->prepare("SELECT user_id FROM shop_staffs WHERE shop_id = ? AND status = 'active'");
        $staff_stmt->execute([$shop_id]);
        foreach ($staff_stmt->fetchAll(PDO::FETCH_COLUMN) as $staff_id) {
            create_notification($pdo, (int) $staff_id, $order_id, 'order_status', $message);
        }
        create_notification($pdo, $client_id, $order_id, 'success', 'Your design proofing and quotation request has been sent to ' . $shop['shop_name'] . '.');
        cleanup_media($pdo);
        $success = 'Request submitted! The selected shop will prepare your design proofing and price quotation shortly.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Design Proofing &amp; Price Quotation</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .quotation-layout { display: grid; grid-template-columns: 1.15fr .85fr; gap: 1.5rem; }
        .service-note { border: 1px solid #dbeafe; background: #eff6ff; border-radius: 12px; padding: 1rem; }
        .upload-preview { border: 1px dashed #cbd5e1; border-radius: 10px; padding: .8rem; margin-top: .7rem; font-size: .92rem; }
        .upload-preview button { margin-top: .5rem; }
        @media (max-width: 900px) { .quotation-layout { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/includes/customer_navbar.php'; ?>

    <div class="container">
        <div class="dashboard-header fade-in">
            <h2>Design Proofing and Price Quotation</h2>
            <p class="text-muted">Request proofing and quotation directly, or continue from your customized design.</p>
        </div>

        <?php if($error): ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>
        <?php if($success): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>

            <<div class="quotation-layout">
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-file-signature text-primary"></i> Request Design Proofing &amp; Quote</h3>
                    <p class="text-muted">Upload your preferred design, change it anytime before submitting, then choose your target shop.</p>
                </div>
                 <form method="POST" enctype="multipart/form-data">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="customize_order_id" value="<?php echo (int) ($selected_custom_order['id'] ?? 0); ?>">

                    <div class="form-group">
                        <label>Select Shop</label>
                        <select class="form-control" name="shop_id" required>
                            <option value="">Choose where you want to buy</option>
                            <?php foreach ($shops as $shop): ?>
                                <option value="<?php echo (int) $shop['id']; ?>" <?php echo isset($selected_custom_order['shop_id']) && (int) $selected_custom_order['shop_id'] === (int) $shop['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($shop['shop_name']); ?>
                                    <?php if (!empty($shop['address'])): ?> — <?php echo htmlspecialchars($shop['address']); ?><?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Service Type</label>
                        <input class="form-control" name="service_type" required value="<?php echo htmlspecialchars($selected_custom_order['service_type'] ?? 'Custom Embroidery Design'); ?>">
                    </div>

                    <div class="form-group">
                        <label>Design Description</label>
                        <textarea class="form-control" name="design_description" rows="5" required placeholder="Explain desired layout, size, colors, and preferred output..."><?php echo htmlspecialchars($selected_custom_order['design_description'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label>Upload Design File (Optional)</label>
                        <input type="file" class="form-control" name="design_file" id="designFileInput" accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx">
                        <small class="text-muted">You can upload directly here even if you did not use Customize Design. Max <?php echo $max_upload_mb; ?>MB.</small>
                        <div class="upload-preview" id="uploadPreview" style="display:none;"></div>
                    </div>

                    <button type="submit" name="request_quote" class="btn btn-primary btn-block">
                        <i class="fas fa-paper-plane"></i> Request Design Proofing and Price Quotation
                    </button>
                </form>
            </div>

            <div class="d-flex flex-column gap-3">
                <?php if($selected_custom_order): ?>
                    <div class="service-note">
                        <h4 class="mb-1"><i class="fas fa-link"></i> From Customize Design</h4>
                        <p class="mb-1">Order: <strong>#<?php echo htmlspecialchars($selected_custom_order['order_number']); ?></strong> from <?php echo htmlspecialchars($selected_custom_order['shop_name']); ?>.</p>
                        <p class="text-muted mb-0">You can keep that design, upload a new one, or remove the new upload before sending the request.</p>
                    </div>
                <?php endif; ?>
                <div class="card">
                    <h4><i class="fas fa-circle-info text-primary"></i> What happens next?</h4>
                    <ol class="text-muted mb-0" style="padding-left:1rem;">
                        <li>Shop receives your request and reviews your design.</li>
                        <li>They prepare a proof and an initial quotation.</li>
                        <li>You review and approve inside Track Orders / Design Proofing.</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>
    <script>
        const designFileInput = document.getElementById('designFileInput');
        const uploadPreview = document.getElementById('uploadPreview');

        function refreshUploadPreview() {
            if (!designFileInput || !uploadPreview) return;
            const file = designFileInput.files && designFileInput.files[0];
            if (!file) {
                uploadPreview.style.display = 'none';
                uploadPreview.innerHTML = '';
                return;
            }

            uploadPreview.style.display = 'block';
            uploadPreview.innerHTML = `<strong>Selected file:</strong> ${file.name} (${Math.max(1, Math.round(file.size / 1024))} KB)<br><button type="button" class="btn btn-outline-danger btn-sm" id="removeUploadBtn"><i class="fas fa-trash"></i> Remove selected design</button>`;
            const removeBtn = document.getElementById('removeUploadBtn');
            if (removeBtn) {
                removeBtn.addEventListener('click', () => {
                    designFileInput.value = '';
                    refreshUploadPreview();
                });
            }
        }

        if (designFileInput) {
            designFileInput.addEventListener('change', refreshUploadPreview);
        }
    </script>
</body>
</html>
