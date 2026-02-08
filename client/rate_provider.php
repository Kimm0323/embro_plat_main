<?php
session_start();
require_once '../config/db.php';
require_role('client');

$client_id = $_SESSION['user']['id'];
$unread_notifications = fetch_unread_notification_count($pdo, $client_id);
$error = '';
$success = '';
$blocked_terms = ['spam', 'scam', 'fraud', 'abuse', 'offensive'];

function contains_blocked_terms(string $text, array $blocked_terms): bool {
    foreach($blocked_terms as $term) {
        if($term !== '' && stripos($text, $term) !== false) {
            return true;
        }
    }
    return false;
}

if(isset($_POST['submit_rating'])) {
    $order_id = $_POST['order_id'] ?? '';
    $rating = (int)($_POST['rating'] ?? 0);
    $rating_title = sanitize($_POST['rating_title'] ?? '');
    $rating_comment = sanitize($_POST['rating_comment'] ?? '');

    if($rating < 1 || $rating > 5) {
        $error = 'Please select a rating between 1 and 5.';
        } elseif($rating_title !== '' && mb_strlen($rating_title) < 3) {
        $error = 'Review titles should be at least 3 characters long.';
    } elseif($rating_comment !== '' && mb_strlen($rating_comment) < 10) {
        $error = 'Review comments should be at least 10 characters long.';
    } elseif(contains_blocked_terms($rating_title, $blocked_terms) || contains_blocked_terms($rating_comment, $blocked_terms)) {
        $error = 'Please remove inappropriate language from your review.';
    } else {
        $order_stmt = $pdo->prepare("
        SELECT id, shop_id, rating, rating_status
        FROM orders
        WHERE id = ? AND client_id = ? AND status = 'completed'
          AND EXISTS (
              SELECT 1
              FROM order_fulfillments f
              WHERE f.order_id = orders.id
                AND f.status = 'claimed'
          )
    ");
        $order_stmt->execute([$order_id, $client_id]);
        $order = $order_stmt->fetch();

        if(!$order) {
            $error = 'Ratings are available after an order is completed and claimed.';
            } elseif(!empty($order['rating'])) {
            $error = 'This order already has a rating. Each completed order can only be reviewed once.';
        } else {
            $update_stmt = $pdo->prepare("
                UPDATE orders
                SET rating = ?,
                    rating_title = ?,
                    rating_comment = ?,
                    rating_status = 'pending',
                    rating_submitted_at = NOW(),
                    updated_at = NOW()
                WHERE id = ? AND client_id = ?
            ");
            $update_stmt->execute([$rating, $rating_title, $rating_comment, $order_id, $client_id]);

            $success = 'Thank you! Your rating has been submitted and is awaiting moderation.';
        }
    }
}

$pending_stmt = $pdo->prepare("
    SELECT o.*, s.shop_name
    FROM orders o
    JOIN shops s ON o.shop_id = s.id
    WHERE o.client_id = ?
      AND o.status = 'completed'
      AND (o.rating IS NULL OR o.rating = 0)
      AND EXISTS (
          SELECT 1
          FROM order_fulfillments f
          WHERE f.order_id = o.id
            AND f.status = 'claimed'
      )
    ORDER BY o.completed_at DESC, o.created_at DESC
");
$pending_stmt->execute([$client_id]);
$pending_orders = $pending_stmt->fetchAll();

$rated_stmt = $pdo->prepare("
    SELECT o.*, s.shop_name
    FROM orders o
    JOIN shops s ON o.shop_id = s.id
    WHERE o.client_id = ?
      AND o.status = 'completed'
      AND o.rating IS NOT NULL
      AND o.rating > 0
    ORDER BY o.completed_at DESC, o.created_at DESC
    LIMIT 5
");
$rated_stmt->execute([$client_id]);
$rated_orders = $rated_stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rate Provider</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .rating-card {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px;
            background: #fff;
            margin-bottom: 16px;
        }
        .rating-stars {
            display: flex;
            gap: 8px;
            margin: 10px 0 15px;
            flex-direction: row-reverse;
            justify-content: flex-end;
        }
        .rating-stars input {
            display: none;
        }
        .rating-stars label {
            cursor: pointer;
            font-size: 1.5rem;
            color: #cbd5f5;
            transition: color 0.2s ease;
        }
        .rating-stars input:checked ~ label {
            color: #f59e0b;
        }
        .rating-stars label:hover,
        .rating-stars label:hover ~ label {
            color: #fbbf24;
        }
        .rated-list {
            display: grid;
            gap: 12px;
        }
        .rated-card {
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 15px;
            background: #fff;
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
                        <a href="rate_provider.php" class="dropdown-item active"><i class="fas fa-star"></i> Rate Provider</a>
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
            <h2>Rate Your Providers</h2>
            <p class="text-muted">Share feedback for completed orders to help shops improve their service. Ratings are available after an order has been claimed.</p>
        </div>

        <?php if($error): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
        <?php endif; ?>
        <?php if($success): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div>
        <?php endif; ?>

        <div class="card">
            <h3>Pending Ratings</h3>
            <?php if(!empty($pending_orders)): ?>
                <?php foreach($pending_orders as $order): ?>
                    <div class="rating-card">
                        <div class="d-flex justify-between align-center">
                            <div>
                                <h4 class="mb-1"><?php echo htmlspecialchars($order['service_type']); ?></h4>
                                <p class="text-muted mb-0"><i class="fas fa-store"></i> <?php echo htmlspecialchars($order['shop_name']); ?></p>
                            </div>
                            <div class="text-right">
                                <div class="text-muted">#<?php echo htmlspecialchars($order['order_number']); ?></div>
                                <?php if(!empty($order['completed_at'])): ?>
                                    <div class="text-muted">Completed: <?php echo date('M d, Y', strtotime($order['completed_at'])); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <form method="POST" class="mt-3">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                            <div class="rating-stars">
                                <?php for($i = 5; $i >= 1; $i--): ?>
                                    <input type="radio" id="rating-<?php echo $order['id']; ?>-<?php echo $i; ?>" name="rating" value="<?php echo $i; ?>" required>
                                    <label for="rating-<?php echo $order['id']; ?>-<?php echo $i; ?>">
                                        <i class="fas fa-star"></i>
                                    </label>
                                <?php endfor; ?>
                            </div>
                            <div class="form-group">
                                <label>Review Title (Optional)</label>
                                <input type="text" name="rating_title" class="form-control" maxlength="150" placeholder="Summarize your experience">
                            </div>
                            <div class="form-group">
                                <label>Review Comment (Optional)</label>
                                <textarea name="rating_comment" class="form-control" rows="3" placeholder="Share details about quality, communication, or delivery"></textarea>
                            </div>
                                <button type="submit" name="submit_rating" class="btn btn-primary">
                                <i class="fas fa-paper-plane"></i> Submit Rating
                            </button>
                        </form>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="text-center p-4">
                    <i class="fas fa-star fa-3x text-muted mb-3"></i>
                    <h4>No Pending Ratings</h4>
                    <p class="text-muted">You have rated all completed orders. Thank you!</p>
                </div>
            <?php endif; ?>
        </div>

        <div class="card mt-4">
            <h3>Recently Rated</h3>
            <?php if(!empty($rated_orders)): ?>
                <div class="rated-list">
                    <?php foreach($rated_orders as $order): ?>
                        <div class="rated-card">
                            <div class="d-flex justify-between align-center">
                                <div>
                                    <strong><?php echo htmlspecialchars($order['service_type']); ?></strong>
                                    <div class="text-muted small"><i class="fas fa-store"></i> <?php echo htmlspecialchars($order['shop_name']); ?></div>
                                </div>
                                <div>
                                    <?php for($i = 1; $i <= 5; $i++): ?>
                                        <i class="fas fa-star<?php echo $i <= $order['rating'] ? '' : '-o'; ?> text-warning"></i>
                                    <?php endfor; ?>
                                </div>
                                <?php if(!empty($order['rating_status']) && $order['rating_status'] !== 'approved'): ?>
                                        <?php if($order['rating_status'] === 'rejected'): ?>
                                            <span class="badge badge-danger">Rejected</span>
                                        <?php else: ?>
                                            <span class="badge badge-warning">Pending moderation</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                            </div>
                            <?php if(!empty($order['rating_title']) || !empty($order['rating_comment'])): ?>
                                <div class="mt-2">
                                    <?php if(!empty($order['rating_title'])): ?>
                                        <strong><?php echo htmlspecialchars($order['rating_title']); ?></strong>
                                    <?php endif; ?>
                                    <?php if(!empty($order['rating_comment'])): ?>
                                        <p class="text-muted mb-0"><?php echo htmlspecialchars($order['rating_comment']); ?></p>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <div class="text-muted small mt-2">#<?php echo htmlspecialchars($order['order_number']); ?></div>
                            <?php if(!empty($order['rating_response'])): ?>
                                <div class="mt-2 alert alert-info">
                                    <strong>Shop response:</strong>
                                    <div><?php echo htmlspecialchars($order['rating_response']); ?></div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="text-center p-4">
                    <i class="fas fa-clipboard-check fa-3x text-muted mb-3"></i>
                    <h4>No Ratings Yet</h4>
                    <p class="text-muted">Complete an order to leave your first rating.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>