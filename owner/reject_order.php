<?php
session_start();
require_once '../config/db.php';
require_role('owner');

$owner_id = $_SESSION['user']['id'];
$owner_role = $_SESSION['user']['role'] ?? null;
$order_id = isset($_POST['order_id']) ? (int) $_POST['order_id'] : (int) ($_GET['id'] ?? 0);

if($order_id <= 0) {
    header("Location: shop_orders.php");
    exit();
}

$shop_stmt = $pdo->prepare("SELECT id, shop_name FROM shops WHERE owner_id = ?");
$shop_stmt->execute([$owner_id]);
$shop = $shop_stmt->fetch();

if(!$shop) {
    header("Location: create_shop.php");
    exit();
}

$order_stmt = $pdo->prepare("
    SELECT o.status, o.order_number, o.client_id, o.cancellation_reason, o.payment_status, o.price, s.shop_name
    FROM orders o
    JOIN shops s ON o.shop_id = s.id
    WHERE o.id = ? AND o.shop_id = ?
");
$order_stmt->execute([$order_id, $shop['id']]);
$order = $order_stmt->fetch();

if(!$order) {
    header("Location: shop_orders.php");
    exit();
}

if($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rejection_reason = sanitize($_POST['cancellation_reason'] ?? '');

if($order['status'] !== 'pending') {
        $error = 'Only pending orders can be rejected.';
    } elseif(empty($rejection_reason)) {
        $error = 'Please provide a reason for rejecting this order.';
    } else {
        $update_stmt = $pdo->prepare("
            UPDATE orders
            SET status = 'cancelled',
                cancellation_reason = ?,
                cancelled_at = NOW(),
                updated_at = NOW()
            WHERE id = ? AND shop_id = ?
        ");
        $update_stmt->execute([$rejection_reason, $order_id, $shop['id']]);
        record_order_status_history($pdo, $order_id, STATUS_CANCELLED, 0, $rejection_reason);

        $message = sprintf(
            'Your order #%s has been cancelled by %s. Reason: %s',
            $order['order_number'],
            $order['shop_name'],
            $rejection_reason
        );
        create_notification($pdo, (int) $order['client_id'], $order_id, 'order_status', $message);

create_notification(
            $pdo,
            (int) $order['client_id'],
            $order_id,
            'warning',
            'Your order #' . $order['order_number'] . ' was not accepted by the shop. Reason: ' . $rejection_reason
        );

        log_audit(
            $pdo,
            $owner_id,
            $owner_role,
            'reject_order',
            'orders',
            $order_id,
            ['status' => $order['status'] ?? null, 'cancellation_reason' => $order['cancellation_reason'] ?? null],
            ['status' => 'cancelled', 'cancellation_reason' => $rejection_reason]
        );

        log_audit(
            $pdo,
            $owner_id,
            $owner_role,
            'dispute_resolution',
            'orders',
            $order_id,
            ['status' => $order['status'] ?? null],
            ['status' => 'cancelled', 'resolution' => 'order_rejected', 'reason' => $rejection_reason]
        );

        if(($order['payment_status'] ?? 'unpaid') === 'paid') {
            $payment_stmt = $pdo->prepare("
                SELECT id, amount FROM payments
                WHERE order_id = ? AND status = 'verified'
                ORDER BY created_at DESC
                LIMIT 1
            ");
            $payment_stmt->execute([$order_id]);
            $payment = $payment_stmt->fetch();

            $refund_insert = $pdo->prepare("
                INSERT INTO payment_refunds (order_id, payment_id, amount, reason, requested_by, status, requested_at)
                VALUES (?, ?, ?, ?, ?, 'pending', NOW())
            ");
            $refund_insert->execute([
                $order_id,
                $payment['id'] ?? null,
                (float) ($payment['amount'] ?? $order['price'] ?? 0),
                $rejection_reason,
                $owner_id
            ]);

            $refund_order_stmt = $pdo->prepare("
                UPDATE orders SET payment_status = 'refund_pending', updated_at = NOW()
                WHERE id = ? AND shop_id = ?
            ");
            $refund_order_stmt->execute([$order_id, $shop['id']]);

            if($order['price'] !== null) {
                $invoice_status = determine_invoice_status(STATUS_CANCELLED, 'refund_pending');
                ensure_order_invoice($pdo, $order_id, $order['order_number'], (float) $order['price'], $invoice_status);
            }

            create_notification(
                $pdo,
                (int) $order['client_id'],
                $order_id,
                'payment',
                'Refund pending for order #' . $order['order_number'] . ' after cancellation.'
            );
        }

        header("Location: shop_orders.php?filter=cancelled&action=rejected");
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reject Order #<?php echo htmlspecialchars($order['order_number']); ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <nav class="navbar">
        <div class="container d-flex justify-between align-center">
            <a href="dashboard.php" class="navbar-brand">
                <i class="fas fa-store"></i> <?php echo htmlspecialchars($shop['shop_name']); ?>
            </a>
            <ul class="navbar-nav">
                <li><a href="dashboard.php" class="nav-link">Dashboard</a></li>
                <li><a href="shop_profile.php" class="nav-link">Shop Profile</a></li>
                <li><a href="manage_staff.php" class="nav-link">Staff</a></li>
                <li><a href="shop_orders.php" class="nav-link active">Orders</a></li>
                <li><a href="messages.php" class="nav-link">Messages</a></li>
                <li><a href="payment_verifications.php" class="nav-link">Payments</a></li>
                <li><a href="earnings.php" class="nav-link">Earnings</a></li>
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


<?php if(isset($error)): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>

<div class="card">
            <form method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="order_id" value="<?php echo $order_id; ?>">
                <div class="form-group">
                    <label for="cancellation_reason">Rejection reason</label>
                    <textarea id="cancellation_reason" name="cancellation_reason" class="form-control" rows="4" required placeholder="Explain why this order is being rejected..."></textarea>
                </div>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-times-circle"></i> Reject Order
                    </button>
                    <a href="shop_orders.php?filter=pending" class="btn btn-outline-primary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
