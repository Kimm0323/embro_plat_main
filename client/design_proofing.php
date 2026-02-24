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

function is_design_image(?string $filename): bool {
    if(!$filename) {
        return false;
    }
    $path = parse_url($filename, PHP_URL_PATH);
    $extension = strtolower(pathinfo((string) $path, PATHINFO_EXTENSION));
    return in_array($extension, ALLOWED_IMAGE_TYPES, true);
}

function proof_file_url(?string $proof_file): ?string {
    if(!$proof_file) {
        return null;
    }

    $normalized = ltrim(trim($proof_file), '/');
    if($normalized === '') {
        return null;
    }

    if(str_starts_with($normalized, 'assets/uploads/')) {
        return '../' . $normalized;
    }

    if(str_starts_with($normalized, 'uploads/')) {
        return '../assets/' . $normalized;
    }

    if(str_contains($normalized, '/')) {
        return '../' . $normalized;
    }

    return '../assets/uploads/designs/' . $normalized;
}

function shop_preview_description(?string $description): string {
    $clean = trim((string) $description);
    if($clean === '') {
        return 'No description provided by this shop yet.';
    }

    if(function_exists('mb_strimwidth')) {
        return mb_strimwidth($clean, 0, 120, '...');
    }

    return strlen($clean) > 120 ? substr($clean, 0, 117) . '...' : $clean;
}

function notify_shop_staff(PDO $pdo, int $shop_id, int $order_id, string $type, string $message): void {
    $staff_stmt = $pdo->prepare("SELECT user_id FROM shop_staffs WHERE shop_id = ? AND status = 'active'");
    $staff_stmt->execute([$shop_id]);
    $staff_ids = $staff_stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach($staff_ids as $staff_id) {
        create_notification($pdo, (int) $staff_id, $order_id, $type, $message);
    }
}

function update_quote_details(PDO $pdo, int $order_id, array $payload): void {
    $details_stmt = $pdo->prepare("SELECT quote_details FROM orders WHERE id = ? LIMIT 1");
    $details_stmt->execute([$order_id]);
    $existing_details = $details_stmt->fetchColumn();
    $quote_details = [];

    if(is_string($existing_details) && $existing_details !== '') {
        $decoded = json_decode($existing_details, true);
        if(is_array($decoded)) {
            $quote_details = $decoded;
        }
    }

    $quote_details = array_merge($quote_details, $payload);
    $update_stmt = $pdo->prepare("UPDATE orders SET quote_details = ?, updated_at = NOW() WHERE id = ?");
    $update_stmt->execute([json_encode($quote_details), $order_id]);
}

$shops_stmt = $pdo->query("SELECT id, shop_name, shop_description, address, rating FROM shops WHERE status = 'active' ORDER BY rating DESC, total_orders DESC, shop_name ASC");
$shops = $shops_stmt->fetchAll();

$selected_custom_order = null;
$prefill_order_id = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;
if($prefill_order_id > 0) {
    $prefill_stmt = $pdo->prepare("SELECT o.id, o.order_number, o.shop_id, o.service_type, o.design_description, o.design_file, o.client_notes, s.shop_name
        FROM orders o
        JOIN shops s ON s.id = o.shop_id
        WHERE o.id = ? AND o.client_id = ?");
    $prefill_stmt->execute([$prefill_order_id, $client_id]);
    $selected_custom_order = $prefill_stmt->fetch();
}

if(isset($_POST['request_quote'])) {
    $shop_id = (int) ($_POST['shop_id'] ?? 0);
    $service_type = sanitize($_POST['service_type'] ?? '');
    $design_description = sanitize($_POST['design_description'] ?? '');
    $customize_order_id = (int) ($_POST['customize_order_id'] ?? 0);

    if($shop_id <= 0) {
        $error = 'Please select the shop where you want to request design proofing and quotation.';
    } elseif($service_type === '') {
        $error = 'Please choose a service type for quotation.';
    } elseif($design_description === '') {
        $error = 'Please provide your design requirements so the shop can prepare proofing and quotation.';
    }

    $shop_stmt = $pdo->prepare("SELECT id, owner_id, shop_name FROM shops WHERE id = ? AND status = 'active' LIMIT 1");
    $shop_stmt->execute([$shop_id]);
    $shop = $shop_stmt->fetch();

    if($error === '' && !$shop) {
        $error = 'Selected shop is not available.';
    }

    $uploaded_design_file = null;
    if($error === '' && isset($_FILES['design_file']) && $_FILES['design_file']['error'] !== UPLOAD_ERR_NO_FILE) {
        $allowed_extensions = array_merge(ALLOWED_IMAGE_TYPES, ALLOWED_DOC_TYPES);
        $upload = save_uploaded_media(
            $_FILES['design_file'],
            $allowed_extensions,
            MAX_FILE_SIZE,
            'designs',
            'quote',
            (string) $client_id
        );

        if(!$upload['success']) {
            $error = $upload['error'] === 'File size exceeds the limit.'
                ? 'Uploaded file is too large. Maximum size is ' . $max_upload_mb . 'MB.'
                : 'Unsupported file format. Please upload JPG, PNG, GIF, PDF, DOC, or DOCX.';
        } else {
            $uploaded_design_file = $upload['filename'];
        }
    }

    if($error === '' && !$uploaded_design_file && $customize_order_id > 0) {
        $customized_stmt = $pdo->prepare("SELECT id, design_file FROM orders WHERE id = ? AND client_id = ? LIMIT 1");
        $customized_stmt->execute([$customize_order_id, $client_id]);
        $customized_order = $customized_stmt->fetch();
        if($customized_order) {
            $uploaded_design_file = $customized_order['design_file'] ?: null;
        }
    }

    if($error === '') {
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
        if(!empty($shop['owner_id'])) {
            create_notification($pdo, (int) $shop['owner_id'], $order_id, 'order_status', $message);
        }

        notify_shop_staff($pdo, $shop_id, $order_id, 'order_status', $message);
        create_notification($pdo, $client_id, $order_id, 'success', 'Your design proofing and quotation request has been sent to ' . $shop['shop_name'] . '.');
        cleanup_media($pdo);
        $success = 'Request submitted! The selected shop will prepare your design proofing and price quotation shortly.';
    }
}

$selected_shop_id = (int) ($_POST['shop_id'] ?? ($selected_custom_order['shop_id'] ?? 0));
$selected_service_type = trim((string) ($_POST['service_type'] ?? ($selected_custom_order['service_type'] ?? 'Custom Embroidery Design')));
$selected_design_description = trim((string) ($_POST['design_description'] ?? ($selected_custom_order['design_description'] ?? '')));

$service_type_options = [
    'Custom Embroidery Design',
    'Logo Embroidery',
    'Uniform Embroidery',
    'Cap Embroidery',
    'Bag Embroidery',
    'Patch Embroidery',
    'Other Embroidery Service',
];

if($selected_service_type !== '' && !in_array($selected_service_type, $service_type_options, true)) {
    $service_type_options[] = $selected_service_type;
}


if(isset($_POST['approve_proof'])) {
    $order_id = (int) ($_POST['order_id'] ?? 0);
    $approval_stmt = $pdo->prepare("
        SELECT o.id, o.order_number, o.shop_id, o.client_id, s.owner_id, s.shop_name,
               da.id as approval_id,
               COALESCE(da.design_file, o.design_file) as proof_file,
               da.status as approval_status
        FROM orders o
        JOIN shops s ON o.shop_id = s.id
        LEFT JOIN design_approvals da ON da.order_id = o.id
        WHERE o.id = ? AND o.client_id = ?
        ORDER BY da.updated_at DESC, da.id DESC
        LIMIT 1
    ");
    $approval_stmt->execute([$order_id, $client_id]);
    $approval = $approval_stmt->fetch();

    if(!$approval) {
        $error = 'Unable to locate the proof for approval.';
    } elseif(empty($approval['proof_file'])) {
        $error = 'There is no proof file to approve yet.';
    } elseif($approval['approval_status'] === 'approved') {
        $error = 'This proof has already been approved.';
    } else {
         if(!empty($approval['approval_id'])) {
            $update_stmt = $pdo->prepare("
            UPDATE design_approvals
            SET status = 'approved', approved_at = NOW(), updated_at = NOW()
            WHERE id = ?
        ");
        $update_stmt->execute([$approval['approval_id']]);
        }

        $order_update = $pdo->prepare("UPDATE orders SET design_approved = 1, updated_at = NOW() WHERE id = ?");
        $order_update->execute([$order_id]);

        $message = sprintf('Design proof approved for order #%s.', $approval['order_number']);
        create_notification($pdo, $client_id, $order_id, 'success', $message);
        if(!empty($approval['owner_id'])) {
            create_notification($pdo, (int) $approval['owner_id'], $order_id, 'order_status', $message);
        }
        notify_shop_staff($pdo, (int) $approval['shop_id'], $order_id, 'order_status', $message);

        $success = 'Thank you! The proof is approved and production can begin.';
    }
}

if(isset($_POST['request_revision'])) {
    $order_id = (int) ($_POST['order_id'] ?? 0);
    $revision_notes = sanitize($_POST['revision_notes'] ?? '');

    $approval_stmt = $pdo->prepare("
        SELECT o.id, o.order_number, o.shop_id, o.client_id, s.owner_id, s.shop_name,
                da.id as approval_id,
               COALESCE(da.design_file, o.design_file) as proof_file,
               da.status as approval_status
        FROM orders o
        JOIN shops s ON o.shop_id = s.id
        LEFT JOIN design_approvals da ON da.order_id = o.id
        WHERE o.id = ? AND o.client_id = ?
        ORDER BY da.updated_at DESC, da.id DESC
        LIMIT 1
    ");
    $approval_stmt->execute([$order_id, $client_id]);
    $approval = $approval_stmt->fetch();

    if(!$approval) {
        $error = 'Unable to locate the proof for revision.';
    } elseif($revision_notes === '') {
        $error = 'Please add revision notes for the shop.';
     } elseif(empty($approval['proof_file'])) {
        $error = 'There is no proof file to revise yet.';
    } else {
        if(!empty($approval['approval_id'])) {
            $update_stmt = $pdo->prepare("
            UPDATE design_approvals
            SET status = 'revision', revision_count = revision_count + 1, customer_notes = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $update_stmt->execute([$revision_notes, $approval['approval_id']]);
        }

        $order_update = $pdo->prepare("
            UPDATE orders
            SET revision_count = revision_count + 1,
                revision_notes = ?,
                revision_requested_at = NOW(),
                design_approved = 0,
                updated_at = NOW()
            WHERE id = ?
        ");
        $order_update->execute([$revision_notes, $order_id]);

        $message = sprintf('Revision requested for order #%s.', $approval['order_number']);
        create_notification($pdo, $client_id, $order_id, 'warning', $message);
        if(!empty($approval['owner_id'])) {
            create_notification($pdo, (int) $approval['owner_id'], $order_id, 'order_status', $message);
        }
        notify_shop_staff($pdo, (int) $approval['shop_id'], $order_id, 'order_status', $message);

        $success = 'Your revision request has been sent to the shop.';
    }
}

if(isset($_POST['reject_proof'])) {
    $order_id = (int) ($_POST['order_id'] ?? 0);
    $rejection_notes = sanitize($_POST['rejection_notes'] ?? '');

     $approval_stmt = $pdo->prepare("\n        SELECT o.id, o.order_number, o.shop_id, o.client_id, s.owner_id, s.shop_name,
               da.id as approval_id,
               COALESCE(da.design_file, o.design_file) as proof_file,
               da.status as approval_status
        FROM orders o
        JOIN shops s ON o.shop_id = s.id
        LEFT JOIN design_approvals da ON da.order_id = o.id
        WHERE o.id = ? AND o.client_id = ?
        ORDER BY da.updated_at DESC, da.id DESC
        LIMIT 1
    ");
    $approval_stmt->execute([$order_id, $client_id]);
    $approval = $approval_stmt->fetch();

    if(!$approval) {
        $error = 'Unable to locate the proof to reject.';
    } elseif($rejection_notes === '') {
        $error = 'Please provide rejection notes for the shop.';
    } elseif(empty($approval['proof_file'])) {
        $error = 'There is no proof file to reject yet.';
    } elseif($approval['approval_status'] === 'approved') {
        $error = 'This proof is already approved and cannot be rejected.';
    } else {
       if(!empty($approval['approval_id'])) {
            $update_stmt = $pdo->prepare("\n            UPDATE design_approvals\n            SET status = 'rejected', customer_notes = ?, updated_at = NOW()\n            WHERE id = ?\n        ");
            $update_stmt->execute([$rejection_notes, $approval['approval_id']]);
        }

        $order_update = $pdo->prepare("\n            UPDATE orders\n            SET design_approved = 0,\n                revision_notes = ?,\n                revision_requested_at = NOW(),\n                updated_at = NOW()\n            WHERE id = ?\n        ");
        $order_update->execute([$rejection_notes, $order_id]);

        $message = sprintf('Design proof rejected for order #%s.', $approval['order_number']);
        create_notification($pdo, $client_id, $order_id, 'warning', $message);
        if(!empty($approval['owner_id'])) {
            create_notification($pdo, (int) $approval['owner_id'], $order_id, 'order_status', $message);
        }
        notify_shop_staff($pdo, (int) $approval['shop_id'], $order_id, 'order_status', $message);

        $success = 'The proof was rejected and sent back to the shop for updates.';
    }
}


if(isset($_POST['accept_price_quote']) || isset($_POST['reject_price_quote']) || isset($_POST['negotiate_price_quote']) || isset($_POST['reject_shop_quote'])) {
    $order_id = (int) ($_POST['order_id'] ?? 0);
    $comment = sanitize($_POST['quote_comment'] ?? '');

    $quote_stmt = $pdo->prepare("SELECT o.id, o.order_number, o.shop_id, o.client_id, s.owner_id, s.shop_name
        FROM orders o
        JOIN shops s ON s.id = o.shop_id
        WHERE o.id = ? AND o.client_id = ?
        LIMIT 1");
    $quote_stmt->execute([$order_id, $client_id]);
    $order_quote = $quote_stmt->fetch();

    if(!$order_quote) {
        $error = 'Unable to locate this quote request.';
    } elseif(isset($_POST['accept_price_quote'])) {
        update_quote_details($pdo, $order_id, [
            'price_quote_status' => 'accepted',
            'price_quote_comment' => $comment !== '' ? $comment : null,
            'price_quote_updated_at' => date('c'),
        ]);
        $message = sprintf('Client accepted the price quote for order #%s.', $order_quote['order_number']);
        if(!empty($order_quote['owner_id'])) {
            create_notification($pdo, (int) $order_quote['owner_id'], $order_id, 'success', $message);
        }
        notify_shop_staff($pdo, (int) $order_quote['shop_id'], $order_id, 'success', $message);
        $success = 'Price quote accepted. The shop has been notified.';
    } elseif(isset($_POST['reject_price_quote'])) {
        if($comment === '') {
            $error = 'Please share a reason before rejecting the quoted price.';
        } else {
            update_quote_details($pdo, $order_id, [
                'price_quote_status' => 'rejected',
                'price_quote_comment' => $comment,
                'price_quote_updated_at' => date('c'),
            ]);
            $message = sprintf('Client rejected the price quote for order #%s.', $order_quote['order_number']);
            if(!empty($order_quote['owner_id'])) {
                create_notification($pdo, (int) $order_quote['owner_id'], $order_id, 'warning', $message);
            }
            notify_shop_staff($pdo, (int) $order_quote['shop_id'], $order_id, 'warning', $message);
            $success = 'Price quote rejected. You may now negotiate or choose another shop.';
        }
    } elseif(isset($_POST['negotiate_price_quote'])) {
        if($comment === '') {
            $error = 'Please add your negotiation recommendation for the shop.';
        } else {
            update_quote_details($pdo, $order_id, [
                'price_quote_status' => 'negotiation_requested',
                'price_quote_comment' => $comment,
                'price_quote_updated_at' => date('c'),
            ]);
            $message = sprintf('Client requested a price negotiation for order #%s.', $order_quote['order_number']);
            if(!empty($order_quote['owner_id'])) {
                create_notification($pdo, (int) $order_quote['owner_id'], $order_id, 'order_status', $message);
            }
            notify_shop_staff($pdo, (int) $order_quote['shop_id'], $order_id, 'order_status', $message);
            $success = 'Negotiation request sent to the shop.';
        }
    } elseif(isset($_POST['reject_shop_quote'])) {
        update_quote_details($pdo, $order_id, [
            'price_quote_status' => 'shop_rejected',
            'price_quote_comment' => $comment !== '' ? $comment : 'Client opted to select another shop.',
            'price_quote_updated_at' => date('c'),
        ]);
        $success = 'Shop quote rejected. You can submit a new request above and select another shop.';
    }
}

$approvals_stmt = $pdo->prepare("
    SELECT o.id as order_id, o.order_number, o.status as order_status, o.design_approved,
           o.design_version_id,o.design_file as order_design_file,
           o.service_type, o.design_description, o.price, o.quote_details,
           s.shop_name, s.owner_id,
           da.status as approval_status, da.design_file, da.provider_notes, da.revision_count,
           COALESCE(da.updated_at, o.updated_at) as updated_at,
           dv.version_no as design_version_no, dv.preview_file as design_version_preview,
           dv.created_at as design_version_created_at, dp.title as design_project_title
    FROM orders o
    JOIN shops s ON o.shop_id = s.id
    LEFT JOIN design_approvals da ON da.order_id = o.id
    LEFT JOIN design_versions dv ON dv.id = o.design_version_id
    LEFT JOIN design_projects dp ON dp.id = dv.project_id
    WHERE o.client_id = ?
      AND o.status IN ('accepted', 'in_progress')
      AND o.design_approved = 0
      AND (
        (da.id IS NOT NULL AND da.status IN ('pending', 'revision'))
        OR o.design_file IS NOT NULL
      )
    ORDER BY COALESCE(da.updated_at, o.updated_at) DESC
");
$approvals_stmt->execute([$client_id]);
$approvals = $approvals_stmt->fetchAll();

$requests_stmt = $pdo->prepare("
    SELECT o.id, o.order_number, o.service_type, o.design_description, o.status, o.price,
           o.created_at, o.updated_at, o.design_approved, s.shop_name
    FROM orders o
    JOIN shops s ON s.id = o.shop_id
    WHERE o.client_id = ?
      AND o.client_notes = 'Quote request submitted via Services page.'
      AND o.design_approved = 0
      AND o.status IN ('pending', 'accepted', 'in_progress')
    ORDER BY o.updated_at DESC, o.created_at DESC
");
$requests_stmt->execute([$client_id]);
$request_history = $requests_stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Design Proofing &amp; Approval Module - Client</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .proofing-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin: 2rem 0;
        }

        .proof-card {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            background: var(--bg-primary);
            padding: 1.5rem;
        }

        .proof-card img {
            width: 100%;
            height: auto;
            border-radius: var(--radius);
            border: 1px solid var(--gray-200);
            margin-top: 1rem;
        }

        .proof-actions {
            display: grid;
            gap: 0.75rem;
            margin-top: 1rem;
        }
        .quotation-layout {
            display: grid;
            grid-template-columns: 1.15fr .85fr;
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .service-note {
            border: 1px solid #dbeafe;
            background: #eff6ff;
            border-radius: var(--radius);
            padding: 1rem;
        }

        .upload-preview {
            margin-top: 0.75rem;
            border: 1px dashed var(--gray-300);
            border-radius: var(--radius);
            padding: 0.75rem;
            background: var(--bg-secondary);
        }

        .quote-meta {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 0.75rem;
            background: var(--bg-secondary);
            margin-top: 0.75rem;
        }

        .shop-selection-list {
            display: grid;
            gap: 0.75rem;
        }

        .shop-option {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 0.75rem;
            background: var(--bg-secondary);
        }

        .shop-option.active {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 1px rgba(59, 130, 246, 0.2);
        }

        .request-history {
            margin-top: 2rem;
            display: grid;
            gap: 1rem;
        }

        .request-item {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 1rem;
            background: var(--bg-primary);
        }

        @media(max-width: 900px) {
            .quotation-layout {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
   <?php require_once __DIR__ . '/includes/customer_navbar.php'; ?>
    <div class="container">
        <div class="dashboard-header fade-in">
            <div class="d-flex justify-between align-center">
                <div>
                    <h2>Design Proofing &amp; Approval</h2>
                    <p class="text-muted">Review proofs and approve before production begins.</p>
                </div>
                <span class="badge badge-primary"><i class="fas fa-clipboard-check"></i> Module 9</span>
            </div>
        </div>

        <?php if(!empty($error)): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>

        <?php if(!empty($success)): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>

         <div class="quotation-layout">
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-file-signature text-primary"></i> Request Design Proofing &amp; Quote</h3>
                   <p class="text-muted">Use the dropdown fields below to submit design proofing and price quote requests.</p>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="customize_order_id" value="<?php echo (int) ($selected_custom_order['id'] ?? 0); ?>">

                    <div class="form-group">
                         <label>Service Selection</label>
                        <select class="form-control" name="service_type" required>
                            <?php foreach($service_type_options as $service_option): ?>
                                <option value="<?php echo htmlspecialchars($service_option); ?>" <?php echo $selected_service_type === $service_option ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($service_option); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Design Description</label>
                        <textarea class="form-control" name="design_description" rows="5" required placeholder="Describe the design you want for proofing and price quotation."><?php echo htmlspecialchars($selected_design_description); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label>Shop Selection</label>
                        <select class="form-control" name="shop_id" required>
                            <option value="">Select a shop for proofing and quote</option>
                            <?php foreach($shops as $shop): ?>
                                <option value="<?php echo (int) $shop['id']; ?>" <?php echo $selected_shop_id === (int) $shop['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($shop['shop_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Upload Design File (Optional)</label>
                        <input type="file" class="form-control" name="design_file" id="designFileInput" accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx">
                        <small class="text-muted">Max <?php echo $max_upload_mb; ?>MB.</small>
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
                    </div>
                <?php endif; ?>
                <div class="card">
                    <h4><i class="fas fa-store text-primary"></i> Shop Selection</h4>
                    <p class="text-muted">Choose from active shops. Each includes a quick description to help with your selection.</p>
                    <div class="shop-selection-list" id="shopSelectionList">
                        <?php foreach($shops as $shop): ?>
                            <?php $is_active_shop = $selected_shop_id === (int) $shop['id']; ?>
                            <button
                                type="button"
                                class="shop-option <?php echo $is_active_shop ? 'active' : ''; ?>"
                                data-shop-option
                                data-shop-id="<?php echo (int) $shop['id']; ?>"
                            >
                                <div class="d-flex justify-between align-center">
                                    <strong><?php echo htmlspecialchars($shop['shop_name']); ?></strong>
                                    <span class="badge badge-primary"><?php echo number_format((float) ($shop['rating'] ?? 0), 1); ?> ★</span>
                                </div>
                                <p class="text-muted mb-1"><?php echo htmlspecialchars(shop_preview_description($shop['shop_description'] ?? '')); ?></p>
                                <?php if(!empty($shop['address'])): ?>
                                    <small class="text-muted"><i class="fas fa-location-dot"></i> <?php echo htmlspecialchars($shop['address']); ?></small>
                                <?php endif; ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

         <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-clock-rotate-left text-primary"></i> Requested Design Proofing &amp; Price Quotations</h3>
                <p class="text-muted mb-0">These are your submitted requests that are still waiting for approval progress.</p>
            </div>
            <?php if(!empty($request_history)): ?>
                <div class="request-history">
                    <?php foreach($request_history as $request): ?>
                        <div class="request-item">
                            <div class="d-flex justify-between align-center mb-2">
                                <strong>Order #<?php echo htmlspecialchars($request['order_number']); ?></strong>
                                <span class="badge badge-warning"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $request['status']))); ?></span>
                            </div>
                            <p class="mb-1"><i class="fas fa-store"></i> <?php echo htmlspecialchars($request['shop_name']); ?></p>
                            <p class="mb-1"><strong>Service:</strong> <?php echo htmlspecialchars($request['service_type'] ?: 'Custom Embroidery Design'); ?></p>
                            <p class="mb-1"><strong>Design request:</strong> <?php echo htmlspecialchars($request['design_description'] ?: 'No design details provided.'); ?></p>
                            <p class="mb-0"><strong>Quoted price:</strong> <?php echo $request['price'] !== null ? '₱' . number_format((float) $request['price'], 2) : 'Waiting for shop quotation'; ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="text-muted mb-0">No active requests found. Submit a design proofing request above to get started.</p>
            <?php endif; ?>
        </div>


        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-shield-halved text-primary"></i> Pending Designs (Waiting for Approval)</h3>
                <p class="text-muted">Review design details, quoted pricing, and respond with acceptance, rejection, or negotiation.</p>
            </div>
            <?php if(!empty($approvals)): ?>
                <div class="proofing-grid">
                    <?php foreach($approvals as $approval): ?>
                        <div class="proof-card">
                            <h4>Order #<?php echo htmlspecialchars($approval['order_number']); ?></h4>
                            <p class="text-muted mb-2"><i class="fas fa-store"></i> <?php echo htmlspecialchars($approval['shop_name']); ?></p>

                            <?php
                                $design_version_preview = proof_file_url($approval['design_version_preview'] ?? null);
                                $has_design_version = !empty($approval['design_version_id']);
                                $approval_status = $approval['approval_status'] ?? 'pending';
                                $proof_file = !empty($approval['design_file'])
                                    ? $approval['design_file']
                                    : ($approval['order_design_file'] ?? '');
                                    $proof_file_url = proof_file_url($proof_file);
                            ?>
                            <span class="badge badge-warning">Proof <?php echo htmlspecialchars($approval_status); ?></span>
                             <div class="quote-meta">
                                <p class="mb-1"><strong>Design details:</strong> <?php echo htmlspecialchars($approval['design_description'] ?: 'No description provided.'); ?></p>
                                <p class="mb-1"><strong>Service:</strong> <?php echo htmlspecialchars($approval['service_type'] ?: 'Custom Embroidery Design'); ?></p>
                                <p class="mb-0"><strong>Shop price quote:</strong>
                                    <?php echo $approval['price'] !== null ? '₱' . number_format((float) $approval['price'], 2) : 'Awaiting shop quote'; ?>
                                </p>
                            </div>
                            <?php if(!empty($approval['provider_notes'])): ?>
                                <div class="alert alert-info mt-3 mb-2">
                                    <strong><i class="fas fa-user-tie"></i> Staff approval request:</strong>
                                    <p class="mb-0"><?php echo nl2br(htmlspecialchars($approval['provider_notes'])); ?></p>
                                </div>
                            <?php endif; ?>
                            <?php if($has_design_version): ?>
                                <div class="mt-3">
                                    <p class="text-muted mb-1">
                                        <i class="fas fa-layer-group"></i>
                                        Latest saved version
                                        <?php if(!empty($approval['design_version_no'])): ?>
                                            (v<?php echo (int) $approval['design_version_no']; ?>)
                                        <?php endif; ?>
                                    </p>
                                    <?php if(!empty($approval['design_project_title'])): ?>
                                        <p class="mb-1"><strong><?php echo htmlspecialchars($approval['design_project_title']); ?></strong></p>
                                    <?php endif; ?>
                                    <?php if(!empty($approval['design_version_created_at'])): ?>
                                        <small class="text-muted">Saved <?php echo date('M d, Y', strtotime($approval['design_version_created_at'])); ?></small>
                                    <?php endif; ?>
                                    <?php if($design_version_preview): ?>
                                        <?php if(is_design_image($approval['design_version_preview'])): ?>
                                            <img src="<?php echo htmlspecialchars($design_version_preview); ?>" alt="Saved design version" class="mt-2">
                                        <?php endif; ?>
                                        <p class="mt-2 mb-0">
                                            <a href="<?php echo htmlspecialchars($design_version_preview); ?>" target="_blank" rel="noopener noreferrer">
                                                <i class="fas fa-paperclip"></i> View saved design
                                            </a>
                                        </p>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

            <?php if($proof_file_url): ?>
                                <?php if(is_design_image($proof_file)): ?>
                                     <img src="<?php echo htmlspecialchars($proof_file_url); ?>" alt="Design proof">
                                <?php endif; ?>
                                <p class="mt-2 mb-0">
                                   <a href="<?php echo htmlspecialchars($proof_file_url); ?>" target="_blank" rel="noopener noreferrer">
                                        <i class="fas fa-paperclip"></i> View proof file
                                    </a>
                                </p>
                            <?php endif; ?>

                            <div class="proof-actions">
                                <form method="POST">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="order_id" value="<?php echo $approval['order_id']; ?>">
                                    <div class="form-group">
                                        <textarea name="quote_comment" class="form-control" rows="2" placeholder="Comment/recommendation for the quoted price (optional)"></textarea>
                                    </div>
                                    <button type="submit" name="accept_price_quote" class="btn btn-outline-success btn-block">
                                        <i class="fas fa-thumbs-up"></i> Accept Price
                                    </button>
                                </form>
                                <form method="POST">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="order_id" value="<?php echo $approval['order_id']; ?>">
                                    <div class="form-group">
                                        <textarea name="quote_comment" class="form-control" rows="2" placeholder="Reason for rejecting the price" required></textarea>
                                    </div>
                                    <button type="submit" name="reject_price_quote" class="btn btn-outline-danger btn-block">
                                        <i class="fas fa-ban"></i> Reject Price
                                    </button>
                                </form>
                                <form method="POST">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="order_id" value="<?php echo $approval['order_id']; ?>">
                                    <div class="form-group">
                                        <textarea name="quote_comment" class="form-control" rows="2" placeholder="Suggest your preferred budget / negotiation notes" required></textarea>
                                    </div>
                                    <button type="submit" name="negotiate_price_quote" class="btn btn-outline-primary btn-block">
                                        <i class="fas fa-comments-dollar"></i> Negotiate Price
                                    </button>
                                </form>
                                <form method="POST">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="order_id" value="<?php echo $approval['order_id']; ?>">
                                    <div class="form-group">
                                        <textarea name="quote_comment" class="form-control" rows="2" placeholder="Optional note before switching shops"></textarea>
                                    </div>
                                    <button type="submit" name="reject_shop_quote" class="btn btn-outline-secondary btn-block">
                                        <i class="fas fa-store-slash"></i> Reject Shop &amp; Select Another
                                    </button>
                                </form>
                                <form method="POST">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="order_id" value="<?php echo $approval['order_id']; ?>">
                                    <button type="submit" name="approve_proof" class="btn btn-success btn-block">
                                        <i class="fas fa-check-circle"></i> Approve Proof
                                    </button>
                                </form>
                                <form method="POST">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="order_id" value="<?php echo $approval['order_id']; ?>">
                                    <div class="form-group">
                                        <textarea name="revision_notes" class="form-control" rows="2" placeholder="Request a revision (optional details)" required></textarea>
                                    </div>
                                    <button type="submit" name="request_revision" class="btn btn-outline-warning btn-block">
                                        <i class="fas fa-rotate-left"></i> Request Revision
                                    </button>
                                </form>
                                <form method="POST">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="order_id" value="<?php echo $approval['order_id']; ?>">
                                    <div class="form-group">
                                        <textarea name="rejection_notes" class="form-control" rows="2" placeholder="Share rejection notes" required></textarea>
                                    </div>
                                    <button type="submit" name="reject_proof" class="btn btn-outline-danger btn-block">
                                        <i class="fas fa-times-circle"></i> Reject Proof
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="text-muted mb-0">No proofs are waiting for your approval right now.</p>
            <?php endif; ?>
            </div>
    </div>
     <script>
        const designFileInput = document.getElementById('designFileInput');
        const uploadPreview = document.getElementById('uploadPreview');
        const shopOptions = document.querySelectorAll('[data-shop-option]');
        const shopSelect = document.querySelector('select[name="shop_id"]');

        function refreshUploadPreview() {
            if(!designFileInput || !uploadPreview) return;
            const file = designFileInput.files && designFileInput.files[0];
            if(!file) {
                uploadPreview.style.display = 'none';
                uploadPreview.innerHTML = '';
                return;
            }

            uploadPreview.style.display = 'block';
            uploadPreview.innerHTML = `<strong>Selected file:</strong> ${file.name} (${Math.max(1, Math.round(file.size / 1024))} KB)<br><button type="button" class="btn btn-outline-danger btn-sm" id="removeUploadBtn"><i class="fas fa-trash"></i> Remove selected design</button>`;
            const removeBtn = document.getElementById('removeUploadBtn');
            if(removeBtn) {
                removeBtn.addEventListener('click', () => {
                    designFileInput.value = '';
                    refreshUploadPreview();
                });
            }
        }

        if(designFileInput) {
            designFileInput.addEventListener('change', refreshUploadPreview);
        }

        if(shopSelect && shopOptions.length > 0) {
            shopOptions.forEach((option) => {
                option.addEventListener('click', () => {
                    const selectedId = option.getAttribute('data-shop-id');
                    shopSelect.value = selectedId;
                    shopOptions.forEach((item) => item.classList.remove('active'));
                    option.classList.add('active');
                });
            });

            shopSelect.addEventListener('change', () => {
                shopOptions.forEach((item) => {
                    item.classList.toggle('active', item.getAttribute('data-shop-id') === shopSelect.value);
                });
            });
        }
    </script>
</body>
</html>
