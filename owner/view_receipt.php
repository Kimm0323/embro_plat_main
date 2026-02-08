<?php
session_start();
require_once '../config/db.php';
require_role('owner');

$owner_id = $_SESSION['user']['id'];
$order_id = (int) ($_GET['order_id'] ?? 0);
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

$receipt_stmt = $pdo->prepare("
    SELECT p.amount, p.created_at, pr.receipt_number, pr.issued_at
    FROM payments p
    JOIN payment_receipts pr ON pr.payment_id = p.id
    WHERE p.order_id = ? AND p.status = 'verified'
    ORDER BY p.created_at DESC
    LIMIT 1
");
$receipt_stmt->execute([$order_id]);
$receipt = $receipt_stmt->fetch();
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
    <nav class="navbar">
        <div class="container d-flex justify-between align-center">
            <a href="dashboard.php" class="navbar-brand">
                <i class="fas fa-store"></i> <?php echo htmlspecialchars($shop['shop_name']); ?>
            </a>
            <ul class="navbar-nav">
                <li><a href="dashboard.php" class="nav-link">Dashboard</a></li>
                <li><a href="shop_orders.php" class="nav-link">Orders</a></li>
                <li><a href="messages.php" class="nav-link">Messages</a></li>
                <li><a href="payment_verifications.php" class="nav-link">Payments</a></li>
                <li><a href="../auth/logout.php" class="nav-link">Logout</a></li>
            </ul>
        </div>
    </nav>

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
                        <strong>Client</strong>
                        <div><?php echo htmlspecialchars($order['client_name']); ?></div>
                    </div>
                </div>
                <hr>
                <div>
                    <strong>Amount Paid</strong>
                    <h3>₱<?php echo number_format((float) ($receipt['amount'] ?? $order['price'] ?? 0), 2); ?></h3>
                </div>
            <?php else: ?>
                <p class="text-muted">Receipt details are available after payment verification.</p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
