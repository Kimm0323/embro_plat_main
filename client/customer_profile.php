<?php
session_start();
require_once '../config/db.php';
require_role('client');

$client_id = $_SESSION['user']['id'];
$unread_notifications = fetch_unread_notification_count($pdo, $client_id);

$profile_stmt = $pdo->prepare("SELECT fullname, email, phone, created_at, last_login FROM users WHERE id = ? LIMIT 1");
$profile_stmt->execute([$client_id]);
$profile = $profile_stmt->fetch() ?: [];

$delivery_stmt = $pdo->prepare(" 
    SELECT o.order_number, s.shop_name, f.fulfillment_type, f.status, f.notes, f.updated_at
    FROM orders o
    LEFT JOIN shops s ON s.id = o.shop_id
    LEFT JOIN order_fulfillments f ON f.order_id = o.id
    WHERE o.client_id = ?
    ORDER BY COALESCE(f.updated_at, o.updated_at) DESC
    LIMIT 5
");
$delivery_stmt->execute([$client_id]);
$delivery_entries = $delivery_stmt->fetchAll();

$payment_stmt = $pdo->prepare(" 
    SELECT p.amount, p.status, p.created_at, o.order_number, s.shop_name
    FROM payments p
    JOIN orders o ON o.id = p.order_id
    LEFT JOIN shops s ON s.id = p.shop_id
    WHERE p.client_id = ?
    ORDER BY p.created_at DESC
    LIMIT 5
");
$payment_stmt->execute([$client_id]);
$payment_entries = $payment_stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Profile</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <?php require_once __DIR__ . '/includes/customer_navbar.php'; ?>

    <div class="container">
        <div class="dashboard-header">
            <h2>Customer Profile</h2>
            <p class="text-muted">Review your personal information, delivery details, and payment methods used in recent orders.</p>
        </div>

        <div class="card mb-3">
            <div class="card-header">
                <h3><i class="fas fa-id-card text-primary"></i> Personal Information</h3>
            </div>
            <div class="form-grid">
                <div>
                    <label>Full name</label>
                    <div class="form-control bg-light"><?php echo htmlspecialchars($profile['fullname'] ?? 'Not available'); ?></div>
                </div>
                <div>
                    <label>Email</label>
                    <div class="form-control bg-light"><?php echo htmlspecialchars($profile['email'] ?? 'Not available'); ?></div>
                </div>
                <div>
                    <label>Phone</label>
                    <div class="form-control bg-light"><?php echo htmlspecialchars($profile['phone'] ?? 'Not yet provided'); ?></div>
                </div>
                <div>
                    <label>Member since</label>
                    <div class="form-control bg-light"><?php echo !empty($profile['created_at']) ? date('M d, Y', strtotime($profile['created_at'])) : 'Not available'; ?></div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">
                <h3><i class="fas fa-truck text-primary"></i> Delivery Activity</h3>
            </div>
            <?php if (!empty($delivery_entries)): ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Order</th>
                                <th>Shop</th>
                                <th>Fulfillment</th>
                                <th>Status</th>
                                <th>Delivery Notes / Address</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($delivery_entries as $entry): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($entry['order_number'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($entry['shop_name'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars(ucfirst($entry['fulfillment_type'] ?? 'N/A')); ?></td>
                                    <td><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $entry['status'] ?? 'N/A'))); ?></td>
                                    <td><?php echo htmlspecialchars($entry['notes'] ?? 'No delivery address notes yet.'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="text-muted mb-0">No delivery records yet. Place an order to add delivery details.</p>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-credit-card text-primary"></i> Payment Methods Used</h3>
            </div>
            <?php if (!empty($payment_entries)): ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Order</th>
                                <th>Shop</th>
                                <th>Amount</th>
                                <th>Payment Status</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payment_entries as $payment): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($payment['order_number']); ?></td>
                                    <td><?php echo htmlspecialchars($payment['shop_name'] ?? '-'); ?></td>
                                    <td>₱<?php echo number_format((float) ($payment['amount'] ?? 0), 2); ?></td>
                                    <td><?php echo htmlspecialchars(ucfirst($payment['status'])); ?></td>
                                    <td><?php echo date('M d, Y h:i A', strtotime($payment['created_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="text-muted mb-0">No payments yet. Your payment records will appear here after checkout.</p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>