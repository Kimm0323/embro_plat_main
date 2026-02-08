<?php
session_start();
require_once '../config/db.php';
require_role('client');

$client_id = $_SESSION['user']['id'];
$unread_notifications = fetch_unread_notification_count($pdo, $client_id);

$stats_stmt = $pdo->prepare("
    SELECT
        COUNT(*) as total_orders,
        SUM(CASE WHEN status IN ('pending', 'accepted', 'in_progress') THEN 1 ELSE 0 END) as active_orders,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_orders,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_orders
    FROM orders
    WHERE client_id = ?
");
$stats_stmt->execute([$client_id]);
$stats = $stats_stmt->fetch();

$pending_ratings_count_stmt = $pdo->prepare("
    SELECT COUNT(*) as pending_count
    FROM orders o
    WHERE o.client_id = ?
      AND o.status = 'completed'
      AND (o.rating IS NULL OR o.rating = 0)
      AND EXISTS (
          SELECT 1
          FROM order_fulfillments f
          WHERE f.order_id = o.id
            AND f.status = 'claimed'
      )
");
$pending_ratings_count_stmt->execute([$client_id]);
$pending_ratings_count = (int) ($pending_ratings_count_stmt->fetch()['pending_count'] ?? 0);

$pending_ratings_stmt = $pdo->prepare("
    SELECT o.id, o.order_number, o.service_type, o.completed_at, s.shop_name
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
    LIMIT 3
");
$pending_ratings_stmt->execute([$client_id]);
$pending_ratings = $pending_ratings_stmt->fetchAll();

$orders_stmt = $pdo->prepare("
    SELECT o.*, s.shop_name, s.logo
    FROM orders o
    JOIN shops s ON o.shop_id = s.id
    WHERE o.client_id = ?
    ORDER BY o.created_at DESC
    LIMIT 5
");
$orders_stmt->execute([$client_id]);
$recent_orders = $orders_stmt->fetchAll();

function client_status_badge($status) {
    $map = [
        'pending' => 'badge-warning',
        'accepted' => 'badge-info',
        'in_progress' => 'badge-primary',
        'completed' => 'badge-success',
        'cancelled' => 'badge-danger'
    ];
    $class = $map[$status] ?? 'badge-secondary';
    return '<span class="badge ' . $class . '">' . ucfirst(str_replace('_', ' ', $status)) . '</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Dashboard</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 15px;
            margin: 25px 0;
        }
        .action-card {
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 18px;
            background: #fff;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .action-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.08);
        }
        .order-list {
            display: grid;
            gap: 15px;
        }
        .order-card {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 18px;
            background: #fff;
        }
        .order-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 10px;
            margin-top: 12px;
            color: #64748b;
            font-size: 0.9rem;
        }
        .rating-prompt {
            border: 1px solid #fde68a;
            background: #fffbeb;
            border-radius: 14px;
            padding: 18px;
            margin: 20px 0 10px;
        }
        .rating-prompt-list {
            margin: 12px 0 0;
            padding-left: 18px;
            color: #92400e;
        }
        .rating-prompt-list li {
            margin-bottom: 6px;
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
                <li><a href="dashboard.php" class="nav-link active">Dashboard</a></li>
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
                    <a href="#" class="nav-link dropdown-toggle">
                        <i class="fas fa-layer-group"></i> Services
                    </a>
                    <div class="dropdown-menu">
                        <a href="customize_design.php" class="dropdown-item"><i class="fas fa-paint-brush"></i> Customize Design</a>
                        <a href="rate_provider.php" class="dropdown-item"><i class="fas fa-star"></i> Rate Provider</a>
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
            <h2>Welcome back, <?php echo htmlspecialchars($_SESSION['user']['fullname']); ?>!</h2>
            <p class="text-muted">Manage your embroidery orders, track progress, and review completed work.</p>
        </div>
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-receipt"></i></div>
                <div class="stat-number"><?php echo $stats['total_orders'] ?? 0; ?></div>
                <div class="stat-label">Total Orders</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-spinner"></i></div>
                <div class="stat-number"><?php echo $stats['active_orders'] ?? 0; ?></div>
                <div class="stat-label">Active Orders</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                <div class="stat-number"><?php echo $stats['completed_orders'] ?? 0; ?></div>
                <div class="stat-label">Completed</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-times-circle"></i></div>
                <div class="stat-number"><?php echo $stats['cancelled_orders'] ?? 0; ?></div>
                <div class="stat-label">Cancelled</div>
            </div>
        </div>
        <?php if($pending_ratings_count > 0): ?>
            <div class="rating-prompt">
                <div class="d-flex justify-between align-center">
                    <div>
                        <h3 class="mb-1"><i class="fas fa-star text-warning"></i> Share Your Feedback</h3>
                        <p class="text-muted mb-0">
                            You have <?php echo $pending_ratings_count; ?> completed
                            <?php echo $pending_ratings_count === 1 ? 'order' : 'orders'; ?> ready for rating.
                        </p>
                    </div>
                    <a href="rate_provider.php" class="btn btn-primary">Rate Now</a>
                </div>
                <?php if(!empty($pending_ratings)): ?>
                    <ul class="rating-prompt-list">
                        <?php foreach($pending_ratings as $pending): ?>
                            <li>
                                <?php echo htmlspecialchars($pending['service_type']); ?> with
                                <?php echo htmlspecialchars($pending['shop_name']); ?>
                                (<?php echo htmlspecialchars($pending['order_number']); ?>)
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <div class="quick-actions">
            <a href="place_order.php" class="action-card">
                <h4><i class="fas fa-plus-circle text-primary"></i> Place a New Order</h4>
                <p class="text-muted">Start a new embroidery request and upload your design.</p>
            </a>
            <a href="track_order.php" class="action-card">
                <h4><i class="fas fa-route text-primary"></i> Track Orders</h4>
                <p class="text-muted">Monitor progress, due dates, and delivery updates.</p>
            </a>
            <a href="customize_design.php" class="action-card">
                <h4><i class="fas fa-paint-brush text-primary"></i> Customize Design</h4>
                <p class="text-muted">Share revised notes or upload new artwork files.</p>
            </a>
            <a href="rate_provider.php" class="action-card">
                <h4><i class="fas fa-star text-primary"></i> Rate Providers</h4>
                <p class="text-muted">Leave feedback for completed embroidery orders.</p>
            </a>
            <a href="search_discovery.php" class="action-card">
                <h4><i class="fas fa-compass text-primary"></i> Search &amp; Discovery</h4>
                <p class="text-muted">Browse shops, check availability, and explore hiring posts.</p>
            </a>
            <a href="design_proofing.php" class="action-card">
                <h4><i class="fas fa-clipboard-check text-primary"></i> Design Proofing &amp; Approval</h4>
                <p class="text-muted">Review proofs, approve revisions, and unlock production.</p>
            </a>
            <a href="pricing_quotation.php" class="action-card">
                <h4><i class="fas fa-calculator text-primary"></i> Pricing &amp; Quotation</h4>
                <p class="text-muted">Preview automated quotes based on complexity and workload.</p>
            </a>
            <a href="client_posting_community.php" class="action-card">
                <h4><i class="fas fa-comments text-primary"></i> Client Posting &amp; Community</h4>
                <p class="text-muted">Share requests, gather inspiration, and connect with shops.</p>
            </a>
        </div>
        <div class="card">
            <h3>Recent Orders</h3>
            <?php if(!empty($recent_orders)): ?>
                <div class="order-list">
                    <?php foreach($recent_orders as $order): ?>
                        <div class="order-card">
                            <div class="d-flex justify-between align-center">
                                <div>
                                    <h4 class="mb-1"><?php echo htmlspecialchars($order['service_type']); ?></h4>
                                    <p class="text-muted mb-0">
                                        <i class="fas fa-store"></i> <?php echo htmlspecialchars($order['shop_name']); ?>
                                    </p>
                                </div>
                                <div class="text-right">
                                    <?php echo client_status_badge($order['status']); ?>
                                    <div class="text-muted mt-2">#<?php echo htmlspecialchars($order['order_number']); ?></div>
                                </div>
                            </div>
                            <div class="order-meta">
                                <div><i class="fas fa-calendar"></i> <?php echo date('M d, Y', strtotime($order['created_at'])); ?></div>
                                <div><i class="fas fa-box"></i> Qty: <?php echo htmlspecialchars($order['quantity']); ?></div>
                                <div><i class="fas fa-peso-sign"></i> ₱<?php echo number_format($order['price'] ?? 0, 2); ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="text-right mt-3">
                    <a href="track_order.php" class="btn btn-primary">View All Orders</a>
                </div>
            <?php else: ?>
                <div class="text-center p-4">
                    <i class="fas fa-receipt fa-3x text-muted mb-3"></i>
                    <h4>No Orders Yet</h4>
                    <p class="text-muted">Start by placing your first embroidery order.</p>
                    <a href="place_order.php" class="btn btn-primary">Place an Order</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>



