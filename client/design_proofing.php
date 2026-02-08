<?php
session_start();
require_once '../config/db.php';
require_once '../config/constants.php';
require_role('client');

$client_id = $_SESSION['user']['id'];
$unread_notifications = fetch_unread_notification_count($pdo, $client_id);

function is_design_image(?string $filename): bool {
    if(!$filename) {
        return false;
    }
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($extension, ALLOWED_IMAGE_TYPES, true);
}

function notify_shop_staff(PDO $pdo, int $shop_id, int $order_id, string $type, string $message): void {
    $staff_stmt = $pdo->prepare("SELECT user_id FROM shop_staffs WHERE shop_id = ? AND status = 'active'");
    $staff_stmt->execute([$shop_id]);
    $staff_ids = $staff_stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach($staff_ids as $staff_id) {
        create_notification($pdo, (int) $staff_id, $order_id, $type, $message);
    }
}

if(isset($_POST['approve_proof'])) {
    $order_id = (int) ($_POST['order_id'] ?? 0);
    $approval_stmt = $pdo->prepare("
        SELECT o.id, o.order_number, o.shop_id, o.client_id, s.owner_id, s.shop_name,
               da.id as approval_id, da.design_file, da.status as approval_status
        FROM orders o
        JOIN shops s ON o.shop_id = s.id
        JOIN design_approvals da ON da.order_id = o.id
        WHERE o.id = ? AND o.client_id = ?
        LIMIT 1
    ");
    $approval_stmt->execute([$order_id, $client_id]);
    $approval = $approval_stmt->fetch();

    if(!$approval) {
        $error = 'Unable to locate the proof for approval.';
    } elseif(empty($approval['design_file'])) {
        $error = 'There is no proof file to approve yet.';
    } elseif($approval['approval_status'] === 'approved') {
        $error = 'This proof has already been approved.';
    } else {
        $update_stmt = $pdo->prepare("
            UPDATE design_approvals
            SET status = 'approved', approved_at = NOW(), updated_at = NOW()
            WHERE id = ?
        ");
        $update_stmt->execute([$approval['approval_id']]);

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
               da.id as approval_id, da.design_file, da.status as approval_status
        FROM orders o
        JOIN shops s ON o.shop_id = s.id
        JOIN design_approvals da ON da.order_id = o.id
        WHERE o.id = ? AND o.client_id = ?
        LIMIT 1
    ");
    $approval_stmt->execute([$order_id, $client_id]);
    $approval = $approval_stmt->fetch();

    if(!$approval) {
        $error = 'Unable to locate the proof for revision.';
    } elseif($revision_notes === '') {
        $error = 'Please add revision notes for the shop.';
    } elseif(empty($approval['design_file'])) {
        $error = 'There is no proof file to revise yet.';
    } else {
        $update_stmt = $pdo->prepare("
            UPDATE design_approvals
            SET status = 'revision', revision_count = revision_count + 1, customer_notes = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $update_stmt->execute([$revision_notes, $approval['approval_id']]);

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

$approvals_stmt = $pdo->prepare("
    SELECT o.id as order_id, o.order_number, o.status as order_status, o.design_approved,
           o.design_version_id,
           s.shop_name, s.owner_id,
           da.status as approval_status, da.design_file, da.revision_count, da.updated_at,
           dv.version_no as design_version_no, dv.preview_file as design_version_preview,
           dv.created_at as design_version_created_at, dp.title as design_project_title
    FROM orders o
    JOIN shops s ON o.shop_id = s.id
    JOIN design_approvals da ON da.order_id = o.id
    LEFT JOIN design_versions dv ON dv.id = o.design_version_id
    LEFT JOIN design_projects dp ON dp.id = dv.project_id
    WHERE o.client_id = ? AND da.status IN ('pending', 'revision')
    ORDER BY da.updated_at DESC
");
$approvals_stmt->execute([$client_id]);
$approvals = $approvals_stmt->fetchAll();
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
                        <a href="customize_design.php" class="dropdown-item"><i class="fas fa-paint-brush"></i> Customize Design</a>
                        <a href="rate_provider.php" class="dropdown-item"><i class="fas fa-star"></i> Rate Provider</a>
                        <a href="search_discovery.php" class="dropdown-item"><i class="fas fa-compass"></i> Search &amp; Discovery</a>
                        <a href="design_proofing.php" class="dropdown-item active"><i class="fas fa-clipboard-check"></i> Design Proofing &amp; Approval</a>
                        <a href="pricing_quotation.php" class="dropdown-item"><i class="fas fa-calculator"></i> Pricing &amp; Quotation</a>
                        <a href="order_management.php" class="dropdown-item"><i class="fas fa-clipboard-list"></i> Order Management</a>
                        <a href="payment_handling.php" class="dropdown-item"><i class="fas fa-hand-holding-dollar"></i> Payment Handling &amp; Release</a>
                        <a href="client_posting_community.php" class="dropdown-item"><i class="fas fa-comments"></i> Client Posting &amp; Community</a>
                    </div>
                </li>
                <li><a href="messages.php" class="nav-link">Messages</a></li>
                <li><a href="notifications.php" class="nav-link">Notifications
                    <?php if ($unread_notifications > 0): ?>
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
        <div class="dashboard-header fade-in">
            <div class="d-flex justify-between align-center">
                <div>
                    <h2>Design Proofing &amp; Approval</h2>
                    <p class="text-muted">Review proofs and approve before production begins.</p>
                </div>
                <span class="badge badge-primary"><i class="fas fa-clipboard-check"></i> Module 9</span>
            </div>
        </div>

        <?php if(isset($error)): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>

            <?php if(isset($success)): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-shield-halved text-primary"></i> Proofs Awaiting Your Approval</h3>
                <p class="text-muted">Approve the proof to unlock production or request a revision.</p>
            </div>
            <?php if(!empty($approvals)): ?>
                <div class="proofing-grid">
                    <?php foreach($approvals as $approval): ?>
                        <div class="proof-card">
                            <h4>Order #<?php echo htmlspecialchars($approval['order_number']); ?></h4>
                            <p class="text-muted mb-2"><i class="fas fa-store"></i> <?php echo htmlspecialchars($approval['shop_name']); ?></p>
                            <span class="badge badge-warning">Proof <?php echo htmlspecialchars($approval['approval_status']); ?></span>

                            <?php
                                $design_version_preview = !empty($approval['design_version_preview'])
                                    ? '../assets/uploads/designs/' . $approval['design_version_preview']
                                    : null;
                                $has_design_version = !empty($approval['design_version_id']);
                            ?>
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

            <?php if(!empty($approval['design_file'])): ?>
                                <?php if(is_design_image($approval['design_file'])): ?>
                                    <img src="../<?php echo htmlspecialchars($approval['design_file']); ?>" alt="Design proof">
                                <?php endif; ?>
                                <p class="mt-2 mb-0">
                                    <a href="../<?php echo htmlspecialchars($approval['design_file']); ?>" target="_blank" rel="noopener noreferrer">
                                        <i class="fas fa-paperclip"></i> View proof file
                                    </a>
                                </p>
                            <?php endif; ?>

                            <div class="proof-actions">
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
                                        <textarea name="revision_notes" class="form-control" rows="2" placeholder="Share revision notes" required></textarea>
                                    </div>
                                    <button type="submit" name="request_revision" class="btn btn-outline-warning btn-block">
                                        <i class="fas fa-pen"></i> Request Revision
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="text-muted mb-0">No proofs are waiting for your approval right now.</p>
            <?php endif; ?>
