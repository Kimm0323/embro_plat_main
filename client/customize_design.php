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

function validate_design_description(string $description): string {
    $trimmed = trim($description);
    $length = mb_strlen($trimmed);
    if ($length < 30) {
        return 'Design description must be at least 30 characters and include placement, size, and color details.';
    }
    if ($length > 1000) {
        return 'Design description cannot exceed 1000 characters.';
    }

    return '';
}

$orders_stmt = $pdo->prepare("
    SELECT o.*, s.shop_name
    FROM orders o
    JOIN shops s ON o.shop_id = s.id
    WHERE o.client_id = ? AND o.status IN ('pending', 'accepted', 'in_progress')
    ORDER BY o.created_at DESC
");
$orders_stmt->execute([$client_id]);
$orders = $orders_stmt->fetchAll();

if(isset($_POST['update_design'])) {
    $order_id = $_POST['order_id'] ?? '';
    $design_description = sanitize($_POST['design_description'] ?? '');
    $client_notes = sanitize($_POST['client_notes'] ?? '');

    $order_stmt = $pdo->prepare("
        SELECT id, design_file, client_notes
        FROM orders
        WHERE id = ? AND client_id = ? AND status IN ('pending', 'accepted', 'in_progress')
    ");
    $order_stmt->execute([$order_id, $client_id]);
    $order = $order_stmt->fetch();

    if(!$order) {
        $error = 'Please select a valid order to update.';
    } else {
        $design_error = validate_design_description($design_description);
        if ($design_error !== '') {
            $error = $design_error;
        }
    }

    if ($error === '') {
        $design_file = $order['design_file'];
        $existing_notes = $order['client_notes'] ?? '';

        if(isset($_FILES['design_file']) && $_FILES['design_file']['error'] !== UPLOAD_ERR_NO_FILE) {
            $file = $_FILES['design_file'];
            $allowed_extensions = array_merge(ALLOWED_IMAGE_TYPES, ALLOWED_DOC_TYPES);
            $upload = save_uploaded_media(
                $file,
                $allowed_extensions,
                MAX_FILE_SIZE,
                'designs',
                'design',
                (string) $order_id,
                $order['design_file']
            );
            if (!$upload['success']) {
                $error = $upload['error'] === 'File size exceeds the limit.'
                    ? 'File size exceeds the ' . $max_upload_mb . 'MB limit.'
                    : 'Only JPG, PNG, GIF, PDF, DOC, and DOCX files are allowed.';
            } else {
                $design_file = $upload['filename'];
            }
        }

        if($error === '') {
            $combined_notes = $existing_notes;
            if($client_notes !== '') {
                $combined_notes = trim($existing_notes . "\n" . $client_notes);
            }

            $update_stmt = $pdo->prepare("
                UPDATE orders
                SET design_description = ?, design_file = ?, client_notes = ?, updated_at = NOW()
                WHERE id = ? AND client_id = ?
            ");
            $update_stmt->execute([$design_description, $design_file, $combined_notes, $order_id, $client_id]);
            $success = 'Design details updated successfully.';
            cleanup_media($pdo);
        }
    }

    $orders_stmt->execute([$client_id]);
    $orders = $orders_stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customize Design</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .design-card {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px;
            background: #fff;
            margin-bottom: 18px;
        }
        .design-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 10px;
            margin-top: 12px;
            color: #64748b;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar--compact">
        <div class="container d-flex justify-between align-center">
            <a href="dashboard.php" class="navbar-brand">
                <i class="fas fa-user"></i> Client Portal
            </a>
            <ul class="navbar-nav">
                <li><a href="dashboard.php" class="nav-link">Dashboard</a></li>
                <li class="dropdown">
                    <a href="#" class="nav-link dropdown-toggle">
                        <i class="fas fa-clipboard-list"></i> Orders
                    </a>
                    <div class="dropdown-menu">
                        <a href="place_order.php" class="dropdown-item"><i class="fas fa-plus-circle"></i> Place Order</a>
                        <a href="track_order.php" class="dropdown-item"><i class="fas fa-route"></i> Track Orders</a>
                    </div>
                </li>
                <li class="dropdown">
                    <a href="#" class="nav-link dropdown-toggle active">
                        <i class="fas fa-layer-group"></i> Services
                    </a>
                    <div class="dropdown-menu">
                        <a href="customize_design.php" class="dropdown-item active"><i class="fas fa-paint-brush"></i> Customize Design</a>
                        <a href="design_editor.php" class="dropdown-item"><i class="fas fa-pencil-ruler"></i> Design Editor</a>
                        <a href="rate_provider.php" class="dropdown-item"><i class="fas fa-star"></i> Rate Provider</a>
                        <a href="search_discovery.php" class="dropdown-item"><i class="fas fa-compass"></i> Search &amp; Discovery</a>
                        <a href="design_proofing.php" class="dropdown-item"><i class="fas fa-clipboard-check"></i> Design Proofing &amp; Approval</a>
                        <a href="pricing_quotation.php" class="dropdown-item"><i class="fas fa-calculator"></i> Pricing &amp; Quotation</a>
                        <a href="order_management.php" class="dropdown-item"><i class="fas fa-clipboard-list"></i> Order Management</a>
                        <a href="payment_handling.php" class="dropdown-item"><i class="fas fa-hand-holding-dollar"></i> Payment Handling &amp; Release</a>
                        <a href="client_posting_community.php" class="dropdown-item"><i class="fas fa-comments"></i> Client Posting &amp; Community</a>
                    </div>
                </li>
                <li><a href="messages.php" class="nav-link">Messages</a></li>
                <li><a href="notifications.php" class="nav-link">Notifications
                    <?php if($unread_notifications > 0): ?>
                        <span class="badge badge-danger"><?php echo $unread_notifications; ?></span>
                    <?php endif; ?>
                </a></li>
                 <li class="dropdown">
                    <a href="#" class="nav-link dropdown-toggle">
                        <i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($_SESSION['user']['fullname']); ?>
                    </a>
                    <div class="dropdown-menu">
                        <a href="../auth/logout.php" class="dropdown-item"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </li>

            </ul>
        </div>
    </nav>

    <div class="container">
        <div class="dashboard-header">
            <h2>Customize Your Design</h2>
            <p class="text-muted">Update design details or upload revised files for active orders.</p>
        </div>

        <?php if($error): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
        <?php endif; ?>
        <?php if($success): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div>
        <?php endif; ?>

        <?php if(!empty($orders)): ?>
            <?php foreach($orders as $order): ?>
                <div class="design-card">
                    <div class="d-flex justify-between align-center">
                        <div>
                            <h4 class="mb-1"><?php echo htmlspecialchars($order['service_type']); ?></h4>
                            <p class="text-muted mb-0"><i class="fas fa-store"></i> <?php echo htmlspecialchars($order['shop_name']); ?></p>
                        </div>
                        <div class="text-right">
                            <div class="text-muted">#<?php echo htmlspecialchars($order['order_number']); ?></div>
                            <div class="mt-1">Status: <?php echo htmlspecialchars(str_replace('_', ' ', $order['status'])); ?></div>
                        </div>
                    </div>

                    <div class="design-meta">
                        <div><i class="fas fa-calendar"></i> Created: <?php echo date('M d, Y', strtotime($order['created_at'])); ?></div>
                        <div><i class="fas fa-box"></i> Quantity: <?php echo htmlspecialchars($order['quantity']); ?></div>
                        <?php if(!empty($order['design_file'])): ?>
                            <div>
                                <i class="fas fa-paperclip"></i>
                                <a href="../assets/uploads/designs/<?php echo htmlspecialchars($order['design_file']); ?>" target="_blank">Current design file</a>
                            </div>
                        <?php endif; ?>
                    </div>

                    <form method="POST" enctype="multipart/form-data" class="mt-3">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">

                        <div class="form-group">
                            <label>Design Description</label>
                            <textarea name="design_description" class="form-control" rows="4" required
                                      placeholder="Placement: (e.g., left chest)&#10;Size: (e.g., 3in x 2in)&#10;Colors/Thread: (e.g., navy + white)&#10;Fabric/Item: (e.g., cotton polo)&#10;Notes: (optional)"><?php echo htmlspecialchars($order['design_description']); ?></textarea>
                            <small class="text-muted">Provide at least 30 characters with placement, size, and color details for consistent quoting.</small>
                        </div>

                        <div class="form-group">
                            <label>Upload Updated Design File (Optional)</label>
                            <input type="file" name="design_file" class="form-control" accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx">
                            <small class="text-muted">Max size: <?php echo $max_upload_mb; ?>MB. Supported formats: JPG, PNG, GIF, PDF, DOC, DOCX.</small>
                        </div>

                        <div class="form-group">
                            <label>Add a Note (Optional)</label>
                            <textarea name="client_notes" class="form-control" rows="2" placeholder="Share extra instructions or updates..."></textarea>
                        </div>

                        <div class="text-right">
                            <button type="submit" name="update_design" class="btn btn-primary">
                                <i class="fas fa-save"></i> Save Updates
                            </button>
                        </div>
                    </form>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="card">
                <div class="text-center p-4">
                    <i class="fas fa-paint-brush fa-3x text-muted mb-3"></i>
                    <h4>No Active Orders</h4>
                    <p class="text-muted">Only pending or in-progress orders can be updated here.</p>
                    <a href="place_order.php" class="btn btn-primary">Place a New Order</a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>