<?php
session_start();
require_once '../config/db.php';
require_once '../config/constants.php';
require_role('owner');

$owner_id = $_SESSION['user']['id'];
$shop_stmt = $pdo->prepare("SELECT * FROM shops WHERE owner_id = ?");
$shop_stmt->execute([$owner_id]);
$shop = $shop_stmt->fetch();

if(!$shop) {
    header("Location: create_shop.php");
    exit();
}

$shop_id = $shop['id'];
$success = null;
$error = null;

$fulfillment_types = [
    'delivery' => 'Delivery',
    'pickup' => 'Pickup',
];

$fulfillment_statuses = [
    FULFILLMENT_PENDING => 'Pending',
    FULFILLMENT_READY_FOR_PICKUP => 'Ready for Pickup',
    FULFILLMENT_OUT_FOR_DELIVERY => 'Out for Delivery',
    FULFILLMENT_DELIVERED => 'Delivered',
    FULFILLMENT_CLAIMED => 'Claimed',
    FULFILLMENT_FAILED => 'Failed',
];

if(isset($_POST['save_fulfillment'])) {
    $order_id = (int) ($_POST['order_id'] ?? 0);
    $fulfillment_type = $_POST['fulfillment_type'] ?? '';
    $status = $_POST['status'] ?? '';
    $courier = sanitize($_POST['courier'] ?? '');
    $tracking_number = sanitize($_POST['tracking_number'] ?? '');
    $pickup_location = sanitize($_POST['pickup_location'] ?? '');
    $notes = sanitize($_POST['notes'] ?? '');

    $order_stmt = $pdo->prepare("
        SELECT o.id, o.order_number, o.client_id, u.fullname as client_name
        FROM orders o
        JOIN users u ON o.client_id = u.id
        WHERE o.id = ? AND o.shop_id = ? AND o.status = 'completed'
    ");
    $order_stmt->execute([$order_id, $shop_id]);
    $order = $order_stmt->fetch();

    if(!$order) {
        $error = 'Only completed orders can be moved to delivery or pickup.';
    } elseif(!isset($fulfillment_types[$fulfillment_type])) {
        $error = 'Please select a valid fulfillment type.';
    } elseif(!isset($fulfillment_statuses[$status])) {
        $error = 'Please select a valid fulfillment status.';
    } else {
        $existing_stmt = $pdo->prepare("SELECT * FROM order_fulfillments WHERE order_id = ?");
        $existing_stmt->execute([$order_id]);
        $existing = $existing_stmt->fetch();

        $current_status = $existing['status'] ?? FULFILLMENT_PENDING;
        if($status !== $current_status) {
            [$can_transition, $transition_error] = order_workflow_validate_fulfillment_status(
                $pdo,
                $order_id,
                $current_status,
                $status
            );
            if(!$can_transition) {
                $error = $transition_error ?: 'Status transition is not allowed from the current state.';
            }
        }

        if(!$error) {
            $ready_at = $existing['ready_at'] ?? null;
            $delivered_at = $existing['delivered_at'] ?? null;
            $claimed_at = $existing['claimed_at'] ?? null;
            $now = date('Y-m-d H:i:s');

            if($status === FULFILLMENT_READY_FOR_PICKUP && !$ready_at) {
                $ready_at = $now;
            }
            if($status === FULFILLMENT_DELIVERED && !$delivered_at) {
                $delivered_at = $now;
            }
            if($status === FULFILLMENT_CLAIMED && !$claimed_at) {
                $claimed_at = $now;
            }

            if($existing) {
                $update_stmt = $pdo->prepare("
                    UPDATE order_fulfillments
                    SET fulfillment_type = ?,
                        status = ?,
                        courier = ?,
                        tracking_number = ?,
                        pickup_location = ?,
                        notes = ?,
                        ready_at = ?,
                        delivered_at = ?,
                        claimed_at = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $update_stmt->execute([
                    $fulfillment_type,
                    $status,
                    $courier ?: null,
                    $tracking_number ?: null,
                    $pickup_location ?: null,
                    $notes ?: null,
                    $ready_at,
                    $delivered_at,
                    $claimed_at,
                    $existing['id']
                ]);
                $fulfillment_id = (int) $existing['id'];
            } else {
                $insert_stmt = $pdo->prepare("
                    INSERT INTO order_fulfillments
                        (order_id, fulfillment_type, status, courier, tracking_number, pickup_location, notes, ready_at, delivered_at, claimed_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $insert_stmt->execute([
                    $order_id,
                    $fulfillment_type,
                    $status,
                    $courier ?: null,
                    $tracking_number ?: null,
                    $pickup_location ?: null,
                    $notes ?: null,
                    $ready_at,
                    $delivered_at,
                    $claimed_at
                ]);
                $fulfillment_id = (int) $pdo->lastInsertId();
            }

            if(!$existing || $status !== $current_status) {
                $history_stmt = $pdo->prepare("
                    INSERT INTO order_fulfillment_history (fulfillment_id, status, notes)
                    VALUES (?, ?, ?)
                ");
                $history_stmt->execute([$fulfillment_id, $status, $notes ?: null]);

                $message = sprintf(
                    'Order #%s fulfillment updated to %s (%s).',
                    $order['order_number'],
                    strtolower(str_replace('_', ' ', $status)),
                    $fulfillment_types[$fulfillment_type]
                );
                create_notification($pdo, (int) $order['client_id'], $order_id, 'info', $message);
            }

            $success = 'Fulfillment details updated successfully.';
        }
    }
}

$orders_stmt = $pdo->prepare("
    SELECT o.id,
           o.order_number,
           o.completed_at,
           o.payment_status,
           u.fullname AS client_name,
           f.id AS fulfillment_id,
           f.fulfillment_type,
           f.status,
           f.courier,
           f.tracking_number,
           f.pickup_location,
           f.notes,
           f.ready_at,
           f.delivered_at,
           f.claimed_at,
           f.updated_at
    FROM orders o
    JOIN users u ON o.client_id = u.id
    LEFT JOIN order_fulfillments f ON f.order_id = o.id
    WHERE o.shop_id = ? AND o.status = 'completed'
    ORDER BY o.completed_at DESC, o.created_at DESC
");
$orders_stmt->execute([$shop_id]);
$orders = $orders_stmt->fetchAll();

$history_by_fulfillment = [];
if(!empty($orders)) {
    $fulfillment_ids = array_filter(array_column($orders, 'fulfillment_id'));
    if(!empty($fulfillment_ids)) {
        $placeholders = implode(',', array_fill(0, count($fulfillment_ids), '?'));
        $history_stmt = $pdo->prepare("
            SELECT * FROM order_fulfillment_history
            WHERE fulfillment_id IN ($placeholders)
            ORDER BY created_at DESC
        ");
        $history_stmt->execute($fulfillment_ids);
        $history_rows = $history_stmt->fetchAll();

        foreach($history_rows as $row) {
            $history_by_fulfillment[$row['fulfillment_id']][] = $row;
        }
    }
}

function fulfillment_pill(?string $status): string {
    $map = [
        FULFILLMENT_PENDING => 'status-pending',
        FULFILLMENT_READY_FOR_PICKUP => 'status-ready',
        FULFILLMENT_OUT_FOR_DELIVERY => 'status-out',
        FULFILLMENT_DELIVERED => 'status-delivered',
        FULFILLMENT_CLAIMED => 'status-claimed',
        FULFILLMENT_FAILED => 'status-failed',
    ];
    $label = $status ? ucfirst(str_replace('_', ' ', $status)) : 'Not set';
    $class = $map[$status] ?? 'status-pending';
    return '<span class="status-pill ' . $class . '">' . $label . '</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delivery & Pickup Management - <?php echo htmlspecialchars($shop['shop_name']); ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .status-pill {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
            text-transform: capitalize;
        }
        .status-ready { background: rgba(255, 193, 7, 0.15); color: #b58900; }
        .status-out { background: rgba(23, 162, 184, 0.15); color: #138496; }
        .status-delivered { background: rgba(40, 167, 69, 0.15); color: #218838; }
        .status-claimed { background: rgba(32, 201, 151, 0.15); color: #1e7e62; }
        .status-failed { background: rgba(220, 53, 69, 0.15); color: #bd2130; }
        .status-pending { background: rgba(108, 117, 125, 0.15); color: #495057; }
        .delivery-card {
            background: #fff;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.04);
        }
        .delivery-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 14px;
        }
        .delivery-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
            font-size: 14px;
        }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 12px;
            margin-top: 16px;
        }
        .history-list {
            margin-top: 12px;
            padding-left: 18px;
            color: #64748b;
            font-size: 13px;
        }
        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
        }
        .alert-success { background: rgba(40, 167, 69, 0.12); color: #1e7e34; }
        .alert-error { background: rgba(220, 53, 69, 0.12); color: #a71d2a; }
        .module-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }
        .module-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .module-card {
            background: #fff;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            padding: 18px;
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.04);
        }
        .module-card h3 {
            margin-bottom: 8px;
            font-size: 16px;
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="container d-flex justify-between align-center">
            <a href="dashboard.php" class="navbar-brand">
                <i class="fas fa-store"></i> <?php echo htmlspecialchars($shop['shop_name']); ?>
            </a>
            <ul class="navbar-nav">
                <li><a href="dashboard.php" class="nav-link">Dashboard</a></li>
                <li><a href="shop_orders.php" class="nav-link">Orders</a></li>
                <li><a href="reviews.php" class="nav-link">Reviews</a></li>
                <li><a href="messages.php" class="nav-link">Messages</a></li>
                <li><a href="delivery_management.php" class="nav-link active">Delivery & Pickup</a></li>
                <li><a href="manage_staff.php" class="nav-link">Staff</a></li>
                <li><a href="profile.php" class="nav-link">Profile</a></li>
                <li><a href="../auth/logout.php" class="nav-link">Logout</a></li>
            </ul>
        </div>
    </nav>

    <div class="container" style="margin-top: 30px;">
        <div class="module-header">
            <div>
                <h2>Delivery & Pickup Management</h2>
                <p class="text-muted">Manage handoff details for completed orders, including pickup readiness and delivery confirmations.</p>
            </div>
            <span class="badge badge-primary"><i class="fas fa-truck-fast"></i> Module 16</span>
        </div>

        <div class="module-grid">
            <div class="module-card">
                <h3><i class="fas fa-bullseye text-primary"></i> Purpose</h3>
                <p class="text-muted mb-0">Handles pickup and delivery confirmation so every order reaches the client with a verified handoff.</p>
            </div>
            <div class="module-card">
                <h3><i class="fas fa-robot text-primary"></i> Automation</h3>
                <p class="text-muted mb-0">Payment release trigger once delivery or pickup is confirmed.</p>
            </div>
        </div>

        <?php if($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        <?php if($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if(empty($orders)): ?>
            <div class="delivery-card">
                <p class="text-muted">No completed orders are ready for delivery or pickup yet.</p>
            </div>
        <?php else: ?>
            <?php foreach($orders as $order): ?>
                <?php $payment_hold = payment_hold_status(STATUS_COMPLETED, $order['payment_status'] ?? 'unpaid'); ?>
                <div class="delivery-card">
                    <div class="delivery-header">
                        <div>
                            <h4 class="mb-1">Order #<?php echo htmlspecialchars($order['order_number']); ?></h4>
                            <div class="text-muted">Client: <?php echo htmlspecialchars($order['client_name']); ?></div>
                        </div>
                        <div>
                            <?php echo fulfillment_pill($order['status'] ?? null); ?>
                            <div class="mt-1">
                                <span class="hold-pill <?php echo htmlspecialchars($payment_hold['class']); ?>">
                                    Hold: <?php echo htmlspecialchars($payment_hold['label']); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="delivery-meta">
                        <div><strong>Type:</strong> <?php echo htmlspecialchars($fulfillment_types[$order['fulfillment_type'] ?? 'pickup'] ?? 'Not set'); ?></div>
                        <div><strong>Tracking:</strong> <?php echo htmlspecialchars($order['tracking_number'] ?? 'Not provided'); ?></div>
                        <div><strong>Courier:</strong> <?php echo htmlspecialchars($order['courier'] ?? 'Not assigned'); ?></div>
                        <div><strong>Pickup location:</strong> <?php echo htmlspecialchars($order['pickup_location'] ?? 'Not specified'); ?></div>
                        <div><strong>Ready at:</strong> <?php echo $order['ready_at'] ? date('M d, Y H:i', strtotime($order['ready_at'])) : '—'; ?></div>
                        <div><strong>Delivered at:</strong> <?php echo $order['delivered_at'] ? date('M d, Y H:i', strtotime($order['delivered_at'])) : '—'; ?></div>
                        <div><strong>Claimed at:</strong> <?php echo $order['claimed_at'] ? date('M d, Y H:i', strtotime($order['claimed_at'])) : '—'; ?></div>
                        <div><strong>Last update:</strong> <?php echo $order['updated_at'] ? date('M d, Y H:i', strtotime($order['updated_at'])) : '—'; ?></div>
                    </div>

                    <form method="POST" class="form-grid">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="order_id" value="<?php echo (int) $order['id']; ?>">
                        <div>
                            <label>Fulfillment type</label>
                            <select name="fulfillment_type" class="form-control" required>
                                <?php foreach($fulfillment_types as $value => $label): ?>
                                    <option value="<?php echo $value; ?>" <?php echo ($order['fulfillment_type'] ?? 'pickup') === $value ? 'selected' : ''; ?>>
                                        <?php echo $label; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label>Status</label>
                            <select name="status" class="form-control" required>
                                <?php foreach($fulfillment_statuses as $value => $label): ?>
                                    <option value="<?php echo $value; ?>" <?php echo ($order['status'] ?? FULFILLMENT_PENDING) === $value ? 'selected' : ''; ?>>
                                        <?php echo $label; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label>Courier</label>
                            <input type="text" name="courier" class="form-control" value="<?php echo htmlspecialchars($order['courier'] ?? ''); ?>" placeholder="e.g. LBC Express">
                        </div>
                        <div>
                            <label>Tracking number</label>
                            <input type="text" name="tracking_number" class="form-control" value="<?php echo htmlspecialchars($order['tracking_number'] ?? ''); ?>" placeholder="Tracking ID">
                        </div>
                        <div>
                            <label>Pickup location</label>
                            <input type="text" name="pickup_location" class="form-control" value="<?php echo htmlspecialchars($order['pickup_location'] ?? ''); ?>" placeholder="Front desk, lobby, etc.">
                        </div>
                        <div>
                            <label>Notes</label>
                            <input type="text" name="notes" class="form-control" value="<?php echo htmlspecialchars($order['notes'] ?? ''); ?>" placeholder="Optional note">
                        </div>
                        <div>
                            <button type="submit" name="save_fulfillment" class="btn btn-primary" style="margin-top: 22px;">Save update</button>
                        </div>
                    </form>

                    <?php if(!empty($order['fulfillment_id'])): ?>
                        <?php $history = $history_by_fulfillment[$order['fulfillment_id']] ?? []; ?>
                        <?php if(!empty($history)): ?>
                            <ul class="history-list">
                                <?php foreach(array_slice($history, 0, 4) as $event): ?>
                                    <li>
                                        <?php echo ucfirst(str_replace('_', ' ', $event['status'])); ?> —
                                        <?php echo date('M d, Y H:i', strtotime($event['created_at'])); ?>
                                        <?php if(!empty($event['notes'])): ?>
                                            (<?php echo htmlspecialchars($event['notes']); ?>)
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</body>
</html>
