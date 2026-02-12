<?php
session_start();
require_once '../config/db.php';
require_role('client');

$client_id = $_SESSION['user']['id'];
$order_id = (int) ($_GET['order_id'] ?? 0);
if ($order_id <= 0) {
    header('Location: track_order.php');
    exit();
}

$order_stmt = $pdo->prepare("
    SELECT o.order_number, o.price, o.status, o.payment_status, o.created_at, s.shop_name
    FROM orders o
    JOIN shops s ON o.shop_id = s.id
    WHERE o.id = ? AND o.client_id = ?
    LIMIT 1
");
$order_stmt->execute([$order_id, $client_id]);
$order = $order_stmt->fetch();

if (!$order) {
    header('Location: track_order.php');
    exit();
}

$invoice = null;
if ($order['price'] !== null) {
    $invoice_status = determine_invoice_status($order['status'], $order['payment_status'] ?? 'unpaid');
    $invoice = ensure_order_invoice(
        $pdo,
        $order_id,
        $order['order_number'],
        (float) $order['price'],
        $invoice_status
    );
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice - <?php echo htmlspecialchars($order['order_number']); ?></title>
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
    <nav class="navbar navbar--compact">
        <div class="container d-flex justify-between align-center">
            <a href="dashboard.php" class="navbar-brand">
               <i class="fas fa-user"></i> Client Portal
            </a>
            <ul class="navbar-nav">
                <li><a href="dashboard.php" class="nav-link">Dashboard</a></li>
                <li><a href="track_order.php" class="nav-link">Track Orders</a></li>
                <li><a href="messages.php" class="nav-link">Messages</a></li>
                <li><a href="../auth/logout.php" class="nav-link">Logout</a></li>
            </ul>
        </div>
    </nav>

    <div class="container">
        <div class="card document-card">
            <div class="document-header">
                <div>
                    <h2>Invoice</h2>
                    <p class="text-muted">Order #<?php echo htmlspecialchars($order['order_number']); ?></p>
                </div>
                <a href="track_order.php" class="btn btn-outline-primary btn-sm">Back</a>
            </div>

            <?php if($invoice): ?>
                <div class="document-meta">
                    <div>
                        <strong>Invoice #</strong>
                        <div><?php echo htmlspecialchars($invoice['invoice_number']); ?></div>
                    </div>
                    <div>
                        <strong>Status</strong>
                        <div><?php echo htmlspecialchars(ucfirst($invoice['status'])); ?></div>
                    </div>
                    <div>
                        <strong>Issued</strong>
                        <div><?php echo date('M d, Y', strtotime($invoice['issued_at'])); ?></div>
                    </div>
                    <div>
                        <strong>Shop</strong>
                        <div><?php echo htmlspecialchars($order['shop_name']); ?></div>
                    </div>
                </div>
                <hr>
                <div>
                    <strong>Amount Due</strong>
                    <h3>₱<?php echo number_format((float) $invoice['amount'], 2); ?></h3>
                </div>
            <?php else: ?>
                <p class="text-muted">An invoice will be issued once the price is finalized by the shop.</p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
