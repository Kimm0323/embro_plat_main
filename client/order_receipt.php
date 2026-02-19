<?php
session_start();
require_once '../config/db.php';
require_once '../config/constants.php';
require_role('client');

$client_id = (int) ($_SESSION['user']['id'] ?? 0);
$order_id = (int) ($_GET['order_id'] ?? 0);
$downpayment_rate = 0.20;

if ($order_id <= 0) {
    header('Location: track_order.php');
    exit;
}

$order_stmt = $pdo->prepare("
    SELECT o.*, s.shop_name
    FROM orders o
    INNER JOIN shops s ON s.id = o.shop_id
    WHERE o.id = ? AND o.client_id = ?
    LIMIT 1
");
$order_stmt->execute([$order_id, $client_id]);
$order = $order_stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    header('Location: track_order.php');
    exit;
}

$payment_stmt = $pdo->prepare("
    SELECT *
    FROM payments
    WHERE order_id = ?
    ORDER BY created_at DESC
    LIMIT 1
");
$payment_stmt->execute([$order_id]);
$payment = $payment_stmt->fetch(PDO::FETCH_ASSOC) ?: null;

$fulfillment_stmt = $pdo->prepare("
    SELECT *
    FROM order_fulfillment
    WHERE order_id = ?
    ORDER BY created_at DESC
    LIMIT 1
");
$fulfillment_stmt->execute([$order_id]);
$fulfillment = $fulfillment_stmt->fetch(PDO::FETCH_ASSOC) ?: null;

$amount_total = $order['price'] !== null ? (float) $order['price'] : null;
$required_downpayment = $amount_total !== null ? round($amount_total * $downpayment_rate, 2) : null;
$amount_paid = (($order['payment_status'] ?? 'unpaid') === 'paid' || (($payment['status'] ?? null) === 'verified'))
    ? ($required_downpayment ?? 0.0)
    : 0.0;
$balance_due = $amount_total !== null ? max(0, $amount_total - (float) $amount_paid) : null;
$payment_method = $payment ? 'Uploaded proof of payment' : 'Not provided';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Receipt - <?php echo htmlspecialchars($order['order_number']); ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .receipt-shell {
            max-width: 900px;
            margin: 0 auto;
        }
        .receipt-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 18px;
            gap: 10px;
            flex-wrap: wrap;
        }
        .receipt-section {
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 14px;
            background: #fff;
        }
        .receipt-section h5 {
            margin-bottom: 10px;
        }
        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 8px 14px;
        }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/includes/customer_navbar.php'; ?>

    <div class="container">
        <div class="card receipt-shell">
            <div class="receipt-head">
                <div>
                    <h2><i class="fas fa-receipt text-primary"></i> Order Receipt</h2>
                    <p class="text-muted mb-0">Created for order #<?php echo htmlspecialchars($order['order_number']); ?></p>
                </div>
                <a href="track_order.php" class="btn btn-outline-primary btn-sm">Back to Track Orders</a>
            </div>

            <div class="receipt-section">
                <h5>Order Overview</h5>
                <div class="detail-grid">
                    <div><strong>Order Number:</strong> #<?php echo htmlspecialchars($order['order_number']); ?></div>
                    <div><strong>Shop:</strong> <?php echo htmlspecialchars($order['shop_name']); ?></div>
                    <div><strong>Order Date:</strong> <?php echo date('M d, Y', strtotime($order['created_at'])); ?></div>
                    <div><strong>Order Status:</strong> <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $order['status'] ?? 'pending'))); ?></div>
                </div>
            </div>

            <div class="receipt-section">
                <h5>Shipping Information</h5>
                <div class="detail-grid">
                    <div><strong>Recipient Name:</strong> <?php echo htmlspecialchars($_SESSION['user']['fullname'] ?? 'Not provided'); ?></div>
                    <div><strong>Phone Number:</strong> <?php echo htmlspecialchars($_SESSION['user']['phone'] ?? 'Not provided'); ?></div>
                    <div><strong>Shipping Address:</strong> <?php echo htmlspecialchars($fulfillment['pickup_location'] ?? 'Not provided'); ?></div>
                    <div><strong>Shipping Method:</strong> <?php echo !empty($fulfillment['fulfillment_type']) ? htmlspecialchars($fulfillment['fulfillment_type'] === 'pickup' ? 'Pick up' : 'Courier') : 'Not scheduled'; ?></div>
                    <div><strong>Tracking Number:</strong> <?php echo htmlspecialchars($fulfillment['tracking_number'] ?? 'Not provided'); ?></div>
                </div>
            </div>

            <div class="receipt-section">
                <h5>Order Items</h5>
                <div class="detail-grid">
                    <div><strong>Product Name:</strong> <?php echo htmlspecialchars($order['service_type']); ?></div>
                    <div><strong>Quantity:</strong> <?php echo (int) $order['quantity']; ?></div>
                    <div><strong>Amount:</strong> <?php echo $amount_total !== null ? '₱' . number_format($amount_total, 2) : 'Awaiting quote'; ?></div>
                </div>
            </div>

            <div class="receipt-section">
                <h5>Payment Information</h5>
                <div class="detail-grid">
                    <div><strong>Payment Method:</strong> <?php echo htmlspecialchars($payment_method); ?></div>
                    <div><strong>Payment Status:</strong> <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $order['payment_status'] ?? 'unpaid'))); ?></div>
                    <div><strong>Total Amount:</strong> <?php echo $amount_total !== null ? '₱' . number_format($amount_total, 2) : 'Awaiting quote'; ?></div>
                    <div><strong>Required Downpayment (20%):</strong> <?php echo $required_downpayment !== null ? '₱' . number_format($required_downpayment, 2) : 'Awaiting quote'; ?></div>
                    <div><strong>Amount Paid:</strong> <?php echo $amount_total !== null ? '₱' . number_format((float) $amount_paid, 2) : '₱0.00'; ?></div>
                    <div><strong>Balance Due:</strong> <?php echo $balance_due !== null ? '₱' . number_format($balance_due, 2) : 'Awaiting quote'; ?></div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
