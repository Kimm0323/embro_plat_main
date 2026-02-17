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

// Get shop statistics
$stats_stmt = $pdo->prepare("
    SELECT 
        (SELECT COUNT(*) FROM orders WHERE shop_id = ?) as total_orders,
        (SELECT COUNT(*) FROM orders WHERE shop_id = ? AND status = 'pending') as pending_orders,
        (SELECT COUNT(*) FROM orders WHERE shop_id = ? AND status = 'in_progress') as active_orders,
        (SELECT COUNT(*) FROM orders WHERE shop_id = ? AND status = 'completed') as completed_orders,
        (SELECT SUM(price) FROM orders WHERE shop_id = ? AND status = 'completed') as total_earnings,
        (SELECT COUNT(*) FROM shop_staffs WHERE shop_id = ? AND status = 'active') as total_staff,
        (SELECT COUNT(*) FROM orders WHERE shop_id = ? AND status = 'accepted') as accepted_orders,
        (SELECT COUNT(*) FROM orders WHERE shop_id = ? AND status = 'cancelled') as cancelled_orders
");
$stats_stmt->execute([$shop_id, $shop_id, $shop_id, $shop_id, $shop_id, $shop_id, $shop_id, $shop_id]);
$stats = $stats_stmt->fetch();

// Recent orders
$orders_stmt = $pdo->prepare("
    SELECT o.*, u.fullname as client_name 
    FROM orders o 
    JOIN users u ON o.client_id = u.id 
    WHERE o.shop_id = ? 
    ORDER BY o.created_at DESC 
    LIMIT 5
");
$orders_stmt->execute([$shop_id]);
$recent_orders = $orders_stmt->fetchAll();

$completion_rate = $stats['total_orders'] > 0
    ? ($stats['completed_orders'] / $stats['total_orders'] * 100)
    : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Owner Dashboard - <?php echo htmlspecialchars($shop['shop_name']); ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .shop-header {
            background: linear-gradient(135deg, #6a11cb 0%, #2575fc 100%);
            color: white;
            padding: 30px;
            border-radius: 10px;
            margin-bottom: 25px;
        }
        .shop-rating {
            background: rgba(255,255,255,0.2);
            padding: 8px 20px;
            border-radius: 25px;
            display: inline-block;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin: 25px 0;
        }
        .content-stack {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        .table-responsive {
            width: 100%;
            overflow-x: auto;
        }
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            text-align: center;
            transition: transform 0.3s;
        }
        .stat-card:hover {
            transform: translateY(-5px);
        }
        .order-status {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 5px;
        }
        .pending { background: #ffc107; }
        .accepted { background: #17a2b8; }
        .in_progress { background: #007bff; }
        .completed { background: #28a745; }
        @media (max-width: 768px) {
            .shop-header .d-flex {
                flex-direction: column;
                align-items: flex-start !important;
                gap: 16px;
            }
            .shop-header .text-right {
                text-align: left;
            }
            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
                gap: 12px;
            }
            .stat-card {
                padding: 14px;
            }
            .stat-number {
                font-size: 1.2rem;
                word-break: break-word;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar--compact">
        <div class="container d-flex justify-between align-center">
            <a href="dashboard.php" class="navbar-brand">
                <i class="fas fa-store"></i> <?php echo htmlspecialchars($shop['shop_name']); ?>
            </a>
            <ul class="navbar-nav">
                <li><a href="dashboard.php" class="nav-link active">Dashboard</a></li>
                <li><a href="shop_profile.php" class="nav-link">Shop Profile</a></li>
                <li><a href="pricing_management.php" class="nav-link">Pricing</a></li>
                <li><a href="manage_staff.php" class="nav-link">Staff</a></li>
                <li><a href="shop_orders.php" class="nav-link">Orders</a></li>
                <li><a href="client_community_posts.php" class="nav-link">Community Posts</a></li>
                <li><a href="reviews.php" class="nav-link">Reviews</a></li>
                <li><a href="messages.php" class="nav-link">Messages</a></li>
                <li><a href="delivery_management.php" class="nav-link">Delivery & Pickup</a></li>
                <li class="dropdown">
                    <a href="#" class="nav-link dropdown-toggle">
                        <i class="fas fa-coins"></i> Finance
                    </a>
                    <div class="dropdown-menu">
                        <a href="payment_verifications.php" class="dropdown-item"><i class="fas fa-receipt"></i> Payments</a>
                        <a href="earnings.php" class="dropdown-item"><i class="fas fa-wallet"></i> Earnings</a>
                    </div>
                </li>
                <li><a href="analytics_reporting.php" class="nav-link">Analytics</a></li>
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
        <!-- Shop Header -->
        <div class="shop-header">
            <div class="d-flex justify-between align-center">
                <div>
                    <h2><?php echo htmlspecialchars($shop['shop_name']); ?></h2>
                    <p class="mb-0"><?php echo htmlspecialchars($shop['shop_description']); ?></p>
                    <div class="mt-2">
                        <i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($shop['address']); ?>
                    </div>
                </div>
                <div class="text-right">
                    <div class="shop-rating mb-3">
                        <i class="fas fa-star"></i> 
                        <strong><?php echo number_format((float) $shop['rating'], 1); ?></strong>
                        <small>(<?php echo (int) ($shop['rating_count'] ?? 0); ?> reviews)</small>
                    </div>
                    <div>
                        <a href="shop_profile.php" class="btn btn-light btn-sm">
                            <i class="fas fa-edit"></i> Edit Profile
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon text-primary">
                    <i class="fas fa-shopping-cart"></i>
                </div>
                <div class="stat-number"><?php echo $stats['total_orders']; ?></div>
                <div class="stat-label">Total Orders</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon text-warning">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-number"><?php echo $stats['pending_orders']; ?></div>
                <div class="stat-label">Pending Orders</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon text-info">
                    <i class="fas fa-spinner"></i>
                </div>
                <div class="stat-number"><?php echo $stats['active_orders']; ?></div>
                <div class="stat-label">Active Orders</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon text-success">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
                <div class="stat-number">₱<?php echo number_format($stats['total_earnings'], 2); ?></div>
                <div class="stat-label">Total Earnings</div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="card mb-4">
            <h3>Quick Actions</h3>
            <div class="d-flex flex-wrap" style="gap: 10px;">
                <a href="shop_orders.php?filter=pending" class="btn btn-primary">
                    <i class="fas fa-clipboard-check"></i> Review Orders (<?php echo $stats['pending_orders']; ?>)
                </a>
                <a href="manage_staff.php" class="btn btn-outline-primary">
                    <i class="fas fa-users"></i> Manage Staff (<?php echo $stats['total_staff']; ?>)
                </a>
                <a href="shop_profile.php" class="btn btn-outline-success">
                    <i class="fas fa-edit"></i> Edit Shop Profile
                </a>
                <a href="earnings.php" class="btn btn-outline-warning">
                    <i class="fas fa-chart-line"></i> View Earnings
                </a>
                <a href="create_hr.php" class="btn btn-outline-info">
                    <i class="fas fa-user-plus"></i> Create HR
                </a>
                 <a href="storage_warehouse_management.php" class="btn btn-outline-secondary">
                    <i class="fas fa-warehouse"></i> Warehouse Management
                </a>
                <a href="supplier_management.php" class="btn btn-outline-dark">
                    <i class="fas fa-truck-loading"></i> Supplier Management
                </a>
            </div>
        </div>

         <div class="content-stack">
            <div class="card">
                <h3>Recent Orders in Your Shop</h3>
                <?php if(!empty($recent_orders)): ?>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Order #</th>
                                    <th>Client</th>
                                    <th>Service</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Hold</th>
                                    <th>Date</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($recent_orders as $order): ?>
                                <tr>
                                    <td>#<?php echo htmlspecialchars($order['order_number']); ?></td>
                                    <td><?php echo htmlspecialchars($order['client_name']); ?></td>
                                    <td><?php echo htmlspecialchars($order['service_type']); ?></td>
                                    <td>₱<?php echo number_format($order['price'], 2); ?></td>
                                    <td>
                                        <span class="order-status <?php echo htmlspecialchars($order['status']); ?>"></span>
                                        <?php echo ucfirst(str_replace('_', ' ', $order['status'])); ?>
                                    </td>
                                    <td>
                                        <?php $payment_hold = payment_hold_status($order['status'] ?? STATUS_PENDING, $order['payment_status'] ?? 'unpaid'); ?>
                                        <span class="hold-pill <?php echo htmlspecialchars($payment_hold['class']); ?>">
                                            <?php echo htmlspecialchars($payment_hold['label']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M d', strtotime($order['created_at'])); ?></td>
                                    <td>
                                        <?php if($order['status'] == 'pending'): ?>
                                            <div class="d-flex" style="gap: 5px;">
                                                 <a href="view_order.php?id=<?php echo $order['id']; ?>" class="btn btn-sm btn-outline-primary">View</a>
                                                <a href="accept_order.php?id=<?php echo $order['id']; ?>" class="btn btn-sm btn-success" onclick="return confirm('Accept this order and use the estimated price as the official price?');">Accept</a>
                                                <a href="reject_order.php?id=<?php echo $order['id']; ?>" class="btn btn-sm btn-danger">Reject</a>
                                            </div>
                                        <?php else: ?>
                                           <a href="view_order.php?id=<?php echo $order['id']; ?>" class="btn btn-sm btn-outline-primary">View</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <</div>
                <?php else: ?>
                    <div class="text-center p-4">
                        <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                        <h4>No Orders Yet</h4>
                        <p class="text-muted">Orders will appear here once customers place them.</p>
                    </div>
                <?php endif; ?>
            </div>

            <div class="card">
                <h3>Shop Performance & Ratings</h3>
                <div class="stats-grid" style="margin-top: 0;">
                    <div class="stat-card">
                        <div class="stat-label">Completion Rate</div>
                        <div class="stat-number"><?php echo round($completion_rate, 1); ?>%</div>
                        <div class="progress" style="height: 8px; margin-top: 8px;">
                            <div class="progress-bar bg-success" style="width: <?php echo $completion_rate; ?>%"></div>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-label">Average Rating</div>
                        <div class="stat-number"><?php echo number_format((float) $shop['rating'], 1); ?>/5</div>
                        <div class="text-warning mt-2">
                            <?php for($i = 1; $i <= 5; $i++): ?>
                                <i class="fas fa-star<?php echo $i <= (float) $shop['rating'] ? '' : '-o'; ?>"></i>
                            <?php endfor; ?>
                        </div>
                         <small class="text-muted"><?php echo (int) ($shop['rating_count'] ?? 0); ?> reviews</small>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-label">Accepted Orders</div>
                        <div class="stat-number"><?php echo (int) $stats['accepted_orders']; ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Cancelled Orders</div>
                        <div class="stat-number"><?php echo (int) $stats['cancelled_orders']; ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <p>&copy; 2024 <?php echo htmlspecialchars($shop['shop_name']); ?> - Owner Dashboard</p>
            <small class="text-muted">Shop ID: <?php echo $shop['id']; ?> | Status: <?php echo ucfirst($shop['status']); ?></small>
        </div>
    </footer>
</body>
</html>