<?php
session_start();
require_once '../config/db.php';
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
$error = '';
$success = '';
$allowed_statuses = ['pending', 'approved', 'rejected'];

update_shop_rating_summary($pdo, $shop_id);
$shop_stmt->execute([$owner_id]);
$shop = $shop_stmt->fetch();

if(isset($_POST['moderate_rating'])) {
    $order_id = (int) ($_POST['order_id'] ?? 0);
    $rating_status = $_POST['rating_status'] ?? '';

    if(!in_array($rating_status, $allowed_statuses, true)) {
        $error = 'Please select a valid moderation status.';
    } else {
        $order_stmt = $pdo->prepare("SELECT id FROM orders WHERE id = ? AND shop_id = ? AND rating IS NOT NULL AND rating > 0");
        $order_stmt->execute([$order_id, $shop_id]);
        $order = $order_stmt->fetch();

        if(!$order) {
            $error = 'Unable to locate the rating for moderation.';
        } else {
            $update_stmt = $pdo->prepare("UPDATE orders SET rating_status = ?, rating_moderated_at = NOW(), updated_at = NOW() WHERE id = ? AND shop_id = ?");
            $update_stmt->execute([$rating_status, $order_id, $shop_id]);
            update_shop_rating_summary($pdo, $shop_id);
            $shop_stmt->execute([$owner_id]);
            $shop = $shop_stmt->fetch();
            $success = 'Rating moderation status updated.';
        }
    }
}

if(isset($_POST['submit_response'])) {
    $order_id = (int) ($_POST['order_id'] ?? 0);
    $rating_response = trim($_POST['rating_response'] ?? '');

    if($rating_response === '') {
        $error = 'Please enter a response before submitting.';
    } elseif(mb_strlen($rating_response) < 5) {
        $error = 'Responses should be at least 5 characters long.';
    } elseif(mb_strlen($rating_response) > 500) {
        $error = 'Responses should be 500 characters or less.';
    } else {
        $order_stmt = $pdo->prepare("SELECT id, rating_status FROM orders WHERE id = ? AND shop_id = ? AND rating IS NOT NULL AND rating > 0");
        $order_stmt->execute([$order_id, $shop_id]);
        $order = $order_stmt->fetch();

        if(!$order) {
            $error = 'Unable to locate the rating for response.';
        } elseif(($order['rating_status'] ?? 'pending') !== 'approved') {
            $error = 'Approve the rating before adding a response.';
        } else {
            $update_stmt = $pdo->prepare("UPDATE orders SET rating_response = ?, rating_response_at = NOW(), updated_at = NOW() WHERE id = ? AND shop_id = ?");
            $update_stmt->execute([sanitize($rating_response), $order_id, $shop_id]);
            $success = 'Response saved successfully.';
        }
    }
}

$ratings_stmt = $pdo->prepare("
    SELECT o.id,
           o.order_number,
           o.service_type,
           o.rating,
           o.rating_title,
           o.rating_comment,
           o.rating_submitted_at,
           o.rating_status,
           o.rating_response,
           o.rating_response_at,
           u.fullname as client_name
    FROM orders o
    JOIN users u ON o.client_id = u.id
    WHERE o.shop_id = ?
      AND o.rating IS NOT NULL
      AND o.rating > 0
    ORDER BY o.rating_submitted_at DESC
");
$ratings_stmt->execute([$shop_id]);
$ratings = $ratings_stmt->fetchAll();

function rating_status_badge_class(string $status): string {
    switch($status) {
        case 'approved':
            return 'badge-success';
        case 'rejected':
            return 'badge-danger';
        case 'pending':
        default:
            return 'badge-warning';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reviews - <?php echo htmlspecialchars($shop['shop_name']); ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .review-card {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px;
            background: #fff;
            margin-bottom: 16px;
        }
        .review-meta {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }
        .review-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }
        .review-response {
            background: #f8fafc;
            border-radius: 10px;
            padding: 12px;
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
                <li><a href="shop_profile.php" class="nav-link">Shop Profile</a></li>
                <li><a href="manage_staff.php" class="nav-link">Staff</a></li>
                <li><a href="shop_orders.php" class="nav-link">Orders</a></li>
                <li><a href="reviews.php" class="nav-link active">Reviews</a></li>
                <li><a href="messages.php" class="nav-link">Messages</a></li>
                <li><a href="delivery_management.php" class="nav-link">Delivery & Pickup</a></li>
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

    <div class="container">
        <div class="dashboard-header">
            <h2>Ratings & Reviews</h2>
            <p class="text-muted">Moderate feedback and respond to client reviews. Approved ratings contribute to your shop score.</p>
        </div>

        <div class="card mb-3">
            <div class="d-flex justify-between align-center">
                <div>
                    <strong>Average rating:</strong> <?php echo number_format((float) $shop['rating'], 1); ?>/5
                </div>
                <div>
                    <strong>Total reviews:</strong> <?php echo (int) ($shop['rating_count'] ?? 0); ?>
                </div>
            </div>
        </div>

        <?php if($error): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
        <?php endif; ?>
        <?php if($success): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div>
        <?php endif; ?>

        <?php if(!empty($ratings)): ?>
            <?php foreach($ratings as $rating): ?>
                <div class="review-card">
                    <div class="review-meta">
                        <div>
                            <strong><?php echo htmlspecialchars($rating['service_type']); ?></strong>
                            <div class="text-muted small">Client: <?php echo htmlspecialchars($rating['client_name']); ?></div>
                        </div>
                        <div class="text-right">
                            <div>
                                <?php for($i = 1; $i <= 5; $i++): ?>
                                    <i class="fas fa-star<?php echo $i <= (int) $rating['rating'] ? '' : '-o'; ?> text-warning"></i>
                                <?php endfor; ?>
                            </div>
                            <div class="small text-muted">#<?php echo htmlspecialchars($rating['order_number']); ?></div>
                            <?php if(!empty($rating['rating_submitted_at'])): ?>
                                <div class="small text-muted">Submitted: <?php echo date('M d, Y', strtotime($rating['rating_submitted_at'])); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="mt-3">
                        <span class="badge <?php echo rating_status_badge_class($rating['rating_status'] ?? 'pending'); ?>">
                            <?php echo ucfirst($rating['rating_status'] ?? 'pending'); ?>
                        </span>
                    </div>

                    <?php if(!empty($rating['rating_title'])): ?>
                        <h4 class="mt-2 mb-1"><?php echo htmlspecialchars($rating['rating_title']); ?></h4>
                    <?php endif; ?>
                    <?php if(!empty($rating['rating_comment'])): ?>
                        <p class="text-muted mb-2"><?php echo htmlspecialchars($rating['rating_comment']); ?></p>
                    <?php endif; ?>

                    <div class="review-actions mt-3">
                        <form method="POST" class="d-flex align-center" style="gap: 8px;">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="order_id" value="<?php echo $rating['id']; ?>">
                            <select name="rating_status" class="form-control" style="max-width: 200px;" required>
                                <?php foreach($allowed_statuses as $status): ?>
                                    <option value="<?php echo $status; ?>" <?php echo ($rating['rating_status'] ?? 'pending') === $status ? 'selected' : ''; ?>>
                                        <?php echo ucfirst($status); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" name="moderate_rating" class="btn btn-outline-primary btn-sm">
                                Update Status
                            </button>
                        </form>
                    </div>

                    <div class="review-response mt-3">
                        <?php if(!empty($rating['rating_response'])): ?>
                            <strong>Your response</strong>
                            <div class="text-muted"><?php echo htmlspecialchars($rating['rating_response']); ?></div>
                            <?php if(!empty($rating['rating_response_at'])): ?>
                                <div class="small text-muted">Responded: <?php echo date('M d, Y', strtotime($rating['rating_response_at'])); ?></div>
                            <?php endif; ?>
                        <?php else: ?>
                            <form method="POST">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="order_id" value="<?php echo $rating['id']; ?>">
                                <div class="form-group">
                                    <label>Add a response</label>
                                    <textarea name="rating_response" class="form-control" rows="3" maxlength="500" placeholder="Thank the client, address concerns, or share next steps..."></textarea>
                                </div>
                                <button type="submit" name="submit_response" class="btn btn-primary btn-sm">
                                    <i class="fas fa-reply"></i> Submit Response
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="text-center p-4">
                <i class="fas fa-star-half-alt fa-3x text-muted mb-3"></i>
                <h4>No reviews yet</h4>
                <p class="text-muted">Client ratings will appear here once submitted.</p>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
