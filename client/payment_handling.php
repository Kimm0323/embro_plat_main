<?php
session_start();
require_once '../config/db.php';
require_role('client');

$client_id = $_SESSION['user']['id'];
$unread_notifications = fetch_unread_notification_count($pdo, $client_id);
$downpayment_rate = 0.20;
$payment_method_labels = payment_method_labels_map();

$payments_stmt = $pdo->prepare("
    SELECT
        p.id,
        p.order_id,
        p.amount,
        p.status,
        p.payment_method,
        p.created_at,
        o.order_number,
        o.payment_status,
        s.shop_name
    FROM payments p
    INNER JOIN orders o ON o.id = p.order_id
    LEFT JOIN shops s ON s.id = o.shop_id
    WHERE p.client_id = ?
    ORDER BY p.created_at DESC
");
$payments_stmt->execute([$client_id]);
$payments = $payments_stmt->fetchAll();

$summary_stmt = $pdo->prepare("
    SELECT
        SUM(CASE WHEN o.payment_status = 'paid' THEN 1 ELSE 0 END) AS paid_orders,
        SUM(CASE WHEN o.payment_status = 'pending' THEN 1 ELSE 0 END) AS pending_orders,
        SUM(CASE WHEN o.payment_status IN ('unpaid', 'rejected') THEN 1 ELSE 0 END) AS action_needed,
        SUM(CASE WHEN o.payment_status = 'paid' THEN o.price ELSE 0 END) AS paid_total
    FROM orders o
    WHERE o.client_id = ?
");
$summary_stmt->execute([$client_id]);
$summary = $summary_stmt->fetch() ?: [];

$unpaid_orders_stmt = $pdo->prepare("
    SELECT
        o.id,
        o.order_number,
        o.status,
        o.price,
        o.payment_status,
        o.created_at,
        s.shop_name
    FROM orders o
    LEFT JOIN shops s ON s.id = o.shop_id
    WHERE o.client_id = ?
      AND o.payment_status IN ('unpaid', 'rejected')
    ORDER BY o.created_at DESC
");
$unpaid_orders_stmt->execute([$client_id]);
$unpaid_orders = $unpaid_orders_stmt->fetchAll();

function payment_badge_class($status)
{
    return match ($status) {
        'paid', 'verified' => 'badge-success',
        'pending' => 'badge-warning',
        'rejected' => 'badge-danger',
        default => 'badge-secondary',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
     <title>Payment Methods - Client</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .payment-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 1.25rem;
            margin: 1.5rem 0;
        }

         .payment-grid .card {
            grid-column: span 12;
        }

        .stat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
        }

       .stat-item {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 1rem;
            background: white;
        }

        .stat-item h4 {
            font-size: 1.4rem;
            margin: 0.25rem 0 0;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 0.75rem;
            border-bottom: 1px solid var(--gray-200);
            text-align: left;
            vertical-align: top;
        }

        .action-row {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

         .methods-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1rem;
        }

        .method-card {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 1rem;
            background: #fff;
        }
    </style>
</head>
<body>
     <?php require_once __DIR__ . '/includes/customer_navbar.php'; ?>

<div class="container">
    <div class="dashboard-header fade-in">
        <div class="d-flex justify-between align-center">
            <div>
                <h2>Customer Payments</h2>
                <p class="text-muted">View your payment records and complete payments for orders that still need action.</p>
            </div>
            <a href="track_order.php" class="btn btn-primary"><i class="fas fa-box"></i> Go to My Orders</a>
        </div>
        </div>

          <div class="payment-grid">
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-chart-line text-primary"></i> Payment Overview</h3>
            </div>

            <div class="stat-grid">
                <div class="stat-item">
                    <small class="text-muted">Paid Orders</small>
                    <h4><?php echo (int) ($summary['paid_orders'] ?? 0); ?></h4>
                </div>
                <div class="stat-item">
                    <small class="text-muted">Pending Verification</small>
                    <h4><?php echo (int) ($summary['pending_orders'] ?? 0); ?></h4>
                </div>
                <div class="stat-item">
                    <small class="text-muted">Needs Payment Action</small>
                    <h4><?php echo (int) ($summary['action_needed'] ?? 0); ?></h4>
                </div>
                <div class="stat-item">
                    <small class="text-muted">Total Paid</small>
                    <h4>₱<?php echo number_format((float) ($summary['paid_total'] ?? 0), 2); ?></h4>
                </div>
            </div>
            </div>

           <div class="card">
             <div class="card-header">
                <h3><i class="fas fa-wallet text-primary"></i> Available Payment Methods</h3>
            </div>
            <div class="methods-grid">
                <div class="method-card">
                   <h4 class="mb-1"><i class="fas fa-store"></i> Pick Up Pay</h4>
                    <p class="text-muted mb-0">Pay directly at the shop upon pickup confirmation.</p>
                </div>
                <div class="method-card">
                    <h4 class="mb-1"><i class="fas fa-truck"></i> Cash on Delivery (COD)</h4>
                    <p class="text-muted mb-0">Settle your order in cash when it is delivered to your address.</p>
                </div>
                <div class="method-card">
                    <h4 class="mb-1"><i class="fas fa-credit-card"></i> PayMongo</h4>
                    <p class="text-muted mb-0">Use PayMongo-supported digital channels then submit your proof of payment.</p>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-credit-card text-primary"></i> Recent Payment Submissions</h3>
            </div>
            <?php if (!empty($payments)): ?>
                <div class="table-wrapper">
                    <table>
                        <thead>
                        <tr>
                            <th>Order #</th>
                            <th>Shop</th>
                            <th>Amount</th>
                            <th>Payment Method</th>
                            <th>Payment Status</th>
                            <th>Submitted</th>
                            <th>Action</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($payments as $payment): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($payment['order_number']); ?></td>
                                <td><?php echo htmlspecialchars($payment['shop_name'] ?? '-'); ?></td>
                                <td>₱<?php echo number_format((float) ($payment['amount'] ?? 0), 2); ?></td>
                                <td><?php echo htmlspecialchars($payment_method_labels[$payment['payment_method'] ?? ''] ?? 'Not specified'); ?></td>
                                <td>
                                    <span class="badge <?php echo payment_badge_class($payment['status'] ?? 'pending'); ?>">
                                        <?php echo htmlspecialchars(ucfirst($payment['status'] ?? 'pending')); ?>
                                    </span>
                                </td>
                                <td><?php echo date('M d, Y h:i A', strtotime($payment['created_at'])); ?></td>
                                <td>
                                    <div class="action-row">
                                        <a class="btn btn-sm btn-outline" href="track_order.php?order_id=<?php echo (int) $payment['order_id']; ?>">View Order</a>
                                        <?php if (($payment['status'] ?? '') === 'verified'): ?>
                                            <a class="btn btn-sm btn-primary" href="view_receipt.php?order_id=<?php echo (int) $payment['order_id']; ?>">Receipt</a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="text-muted mb-0">No payment submissions yet. Select an order to upload your payment proof.</p>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-exclamation-circle text-primary"></i> Orders Needing Payment</h3>
            </div>
            <?php if (!empty($unpaid_orders)): ?>
                <div class="table-wrapper">
                    <table>
                        <thead>
                        <tr>
                            <th>Order #</th>
                            <th>Shop</th>
                            <th>Required Downpayment (20%)</th>
                            <th>Order Total</th>
                            <th>Order Status</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($unpaid_orders as $order): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($order['order_number']); ?></td>
                                <td><?php echo htmlspecialchars($order['shop_name'] ?? '-'); ?></td>
                                 <td>₱<?php echo number_format((float) ($order['price'] ?? 0) * $downpayment_rate, 2); ?></td>
                                <td>₱<?php echo number_format((float) ($order['price'] ?? 0), 2); ?></td>
                                 <td><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $order['status'] ?? 'pending'))); ?></td>
                                <td>
                                    <span class="badge <?php echo payment_badge_class($order['payment_status']); ?>">
                                        <?php echo htmlspecialchars(ucfirst($order['payment_status'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="track_order.php?order_id=<?php echo (int) $order['id']; ?>" class="btn btn-sm btn-primary">
                                        Submit Payment
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="text-muted mb-0">All current orders are paid or waiting for verification.</p>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
