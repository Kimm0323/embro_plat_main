<?php
session_start();
require_once '../config/db.php';
require_role('owner');

$owner_id = $_SESSION['user']['id'];
$order_id = (int) ($_GET['order_id'] ?? 0);
$payment_id = (int) ($_GET['payment_id'] ?? 0);
if ($order_id <= 0) {
    header('Location: shop_orders.php');
    exit();
}

$shop_stmt = $pdo->prepare("SELECT id, shop_name FROM shops WHERE owner_id = ?");
$shop_stmt->execute([$owner_id]);
$shop = $shop_stmt->fetch();

if (!$shop) {
    header('Location: create_shop.php');
    exit();
}

$order_stmt = $pdo->prepare("
    SELECT o.order_number, o.price, u.fullname AS client_name
    FROM orders o
    JOIN users u ON o.client_id = u.id
    WHERE o.id = ? AND o.shop_id = ?
    LIMIT 1
");
$order_stmt->execute([$order_id, $shop['id']]);
$order = $order_stmt->fetch();

if (!$order) {
    header('Location: shop_orders.php');
    exit();
}

$receipt_query = "
    SELECT p.id AS payment_id,
           p.amount,
           p.created_at,
           p.status AS payment_verification_status,
           o.price AS order_total,
           pr.receipt_number,
           pr.issued_at
    FROM payments p
    JOIN orders o ON p.order_id = o.id
    JOIN payment_receipts pr ON pr.payment_id = p.id
    WHERE p.order_id = ? AND p.status = 'verified'
    ";

$receipt_params = [$order_id];
if($payment_id > 0) {
    $receipt_query .= " AND p.id = ?";
    $receipt_params[] = $payment_id;
}

$receipt_query .= " ORDER BY p.created_at DESC LIMIT 1";

$receipt_stmt = $pdo->prepare($receipt_query);
$receipt_stmt->execute($receipt_params);
$receipt = $receipt_stmt->fetch();

function receipt_settlement_type(array $receipt): string {
    $amount = (float) ($receipt['amount'] ?? 0);
    $order_total = (float) ($receipt['order_total'] ?? 0);
    if($order_total <= 0 || $amount <= 0) {
        return 'Unclassified';
    }
    $downpayment_due = round($order_total * 0.20, 2);
    if(abs($amount - $order_total) <= 0.01) {
        return 'Full payment';
    }
    if(abs($amount - $downpayment_due) <= 0.01) {
        return 'Downpayment';
    }
    return 'Balance';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt - <?php echo htmlspecialchars($order['order_number']); ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .document-card {
            max-width: 720px;
            margin: 0 auto;
        }
        .document-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }
        .document-meta {
            display: grid;
            gap: 10px;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        }
    </style>
</head>
<body>
    <?php include __DIR__ . "/includes/owner_navbar.php"; ?>

    <div class="container">
        <div class="card document-card">
            <div class="document-header">
                <div>
                    <h2>Payment Receipt</h2>
                    <p class="text-muted">Order #<?php echo htmlspecialchars($order['order_number']); ?></p>
                </div>
                <a href="view_order.php?id=<?php echo $order_id; ?>" class="btn btn-outline-primary btn-sm">Back</a>
            </div>

            <?php if($receipt): ?>
                <div class="document-meta">
                    <div>
                        <strong>Receipt #</strong>
                        <div><?php echo htmlspecialchars($receipt['receipt_number']); ?></div>
                    </div>
                    <div>
                        <strong>Issued</strong>
                        <div><?php echo date('M d, Y', strtotime($receipt['issued_at'])); ?></div>
                    </div>
                    <div>
                        <strong>Payment ID</strong>
                        <div>#<?php echo (int) $receipt['payment_id']; ?></div>
                    </div>
                    <div>
                        <strong>Client</strong>
                        <div><?php echo htmlspecialchars($order['client_name']); ?></div>
                    </div>
                     <div>
                        <strong>Payment type</strong>
                        <div><?php echo htmlspecialchars(receipt_settlement_type($receipt)); ?></div>
                    </div>
                    <div>
                        <strong>Verification status</strong>
                        <div><?php echo htmlspecialchars(ucfirst($receipt['payment_verification_status'] ?? 'verified')); ?></div>
                    </div>
                    <div>
                        <strong>Paid at</strong>
                        <div><?php echo date('M d, Y h:i A', strtotime($receipt['created_at'])); ?></div>
                    </div>
                </div>
                <hr>
                <div>
                    <strong>Amount Paid</strong>
                    <h3>₱<?php echo number_format((float) ($receipt['amount'] ?? $order['price'] ?? 0), 2); ?></h3>
                </div>
                <div class="mt-2 text-muted">
                    Order total: ₱<?php echo number_format((float) ($receipt['order_total'] ?? $order['price'] ?? 0), 2); ?>
                </div>
            <?php else: ?>
                <p class="text-muted">Receipt details are available after payment verification.</p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
