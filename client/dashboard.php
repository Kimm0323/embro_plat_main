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


$shops_stmt = $pdo->query("
    SELECT id, shop_name, shop_description, rating, address, logo
    FROM shops
    WHERE status = 'active'
    ORDER BY rating DESC, total_orders DESC
    LIMIT 6
");
$featured_shops = $shops_stmt->fetchAll();

$posts_stmt = $pdo->query("
    SELECT sp.title, sp.description, sp.price, sp.image_path, sp.created_at, s.id as shop_id, s.shop_name
    FROM shop_portfolio sp
    JOIN shops s ON sp.shop_id = s.id
    WHERE s.status = 'active'
    ORDER BY sp.created_at DESC
    LIMIT 6
");
$latest_posts = $posts_stmt->fetchAll();
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
        .shop-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 16px;
            margin-top: 16px;
        }
        .shop-card {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 18px;
            background: #fff;
            display: flex;
            flex-direction: column;
            gap: 8px;
             cursor: pointer;
            transition: box-shadow 0.2s ease, transform 0.2s ease;
        }
        .shop-card:hover {
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.08);
            transform: translateY(-2px);
        }
        .shop-card h4 {
            margin: 0;
        }
        .shop-meta {
            color: #64748b;
            font-size: 0.9rem;
            display: grid;
            gap: 6px;
        }
         .shop-rating {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-weight: 600;
            color: #1f2937;
        }
        .shop-actions {
            margin-top: auto;
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
        .post-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 16px;
            margin-top: 16px;
        }
        .post-card {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 16px;
            background: #fff;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .post-card img {
            width: 100%;
            height: 160px;
            object-fit: cover;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            background: #f8fafc;
        }
        .post-card h4 {
            margin: 0;
        }
        .post-card small {
            color: #64748b;
        }
        .post-actions {
            margin-top: auto;
        }
        .stats-grid {
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
        }
        @media (max-width: 640px) {
            .stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            .stat-number {
                font-size: 2rem;
            }
        }
        @media (max-width: 420px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }

    </style>
</head>
<body>

<?php require_once __DIR__ . '/includes/customer_navbar.php'; ?>

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
         <div class="card" id="shop-discovery">
            <div class="d-flex justify-between align-center">
                <div>
                    <h3>Shop List</h3>
                    <p class="text-muted mb-0">Browse active embroidery shops ready for new orders.</p>
                </div>
                <a href="search_discovery.php" class="btn btn-outline-primary">View All Shops</a>
            </div>
            <?php if(!empty($featured_shops)): ?>
                <div class="shop-list">
                    <?php foreach($featured_shops as $shop): ?>
                          <div class="shop-card" data-href="shop_details.php?shop_id=<?php echo (int) $shop['id']; ?>">
                            <h4><?php echo htmlspecialchars($shop['shop_name']); ?></h4>
                            <div class="shop-rating">
                                <i class="fas fa-star text-warning"></i>
                                <?php echo number_format((float) ($shop['rating'] ?? 0), 1); ?>/5
                            </div>
                            <?php if(!empty($shop['shop_description'])): ?>
                                <p class="text-muted mb-0"><?php echo htmlspecialchars($shop['shop_description']); ?></p>
                            <?php endif; ?>
                            <div class="shop-meta">
                                <?php if(!empty($shop['address'])): ?>
                                    <span><i class="fas fa-location-dot"></i> <?php echo htmlspecialchars($shop['address']); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="shop-actions">
                                <a href="shop_details.php?shop_id=<?php echo (int) $shop['id']; ?>" class="btn btn-outline btn-sm">
                                    <i class="fas fa-store"></i> View
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="text-center p-4">
                    <i class="fas fa-store fa-3x text-muted mb-3"></i>
                    <h4>No Active Shops Yet</h4>
                    <p class="text-muted">Please check back soon for available embroidery providers.</p>
                </div>
            <?php endif; ?>
        </div>
         <div class="card">
            <div class="d-flex justify-between align-center">
                <div>
                    <h3>Latest Shop Posts</h3>
                    <p class="text-muted mb-0">Recent updates and posted works from shop owners.</p>
                </div>
                 <div class="d-flex align-center" style="gap: 10px;">
                    <a href="client_posting_community.php" class="btn btn-outline">
                        <i class="fas fa-user-edit"></i> View My Posts
                    </a>
                    <a href="search_discovery.php" class="btn btn-outline-primary">Explore Shops</a>
                </div>
            </div>
            <?php if(!empty($latest_posts)): ?>
                <div class="post-list">
                    <?php foreach($latest_posts as $post): ?>
                        <div class="post-card">
                            <?php if(!empty($post['image_path'])): ?>
                                <img src="../assets/uploads/<?php echo htmlspecialchars($post['image_path']); ?>" alt="<?php echo htmlspecialchars($post['title']); ?>">
                            <?php endif; ?>
                            <h4><?php echo htmlspecialchars($post['title']); ?></h4>
                            <small>
                                <i class="fas fa-store"></i> <?php echo htmlspecialchars($post['shop_name']); ?>
                                · <?php echo date('M d, Y', strtotime($post['created_at'])); ?>
                            </small>
                             <?php if(isset($post['price'])): ?>
                                <p class="mb-1"><strong>Starting at ₱<?php echo number_format((float) $post['price'], 2); ?></strong></p>
                            <?php endif; ?>
                            <?php if(!empty($post['description'])): ?>
                                <p class="text-muted mb-0"><?php echo nl2br(htmlspecialchars($post['description'])); ?></p>
                            <?php endif; ?>
                            <div class="post-actions">
                                <a href="shop_details.php?shop_id=<?php echo (int) $post['shop_id']; ?>" class="btn btn-outline btn-sm">
                                    View shop
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="text-center p-4">
                    <i class="fas fa-images fa-3x text-muted mb-3"></i>
                    <h4>No Posts Yet</h4>
                    <p class="text-muted">Shop owners have not posted any updates yet.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
     <script>
        document.querySelectorAll('.shop-card').forEach(card => {
            card.addEventListener('click', event => {
                if (event.target.closest('a')) {
                    return;
                }
                const href = card.dataset.href;
                if (href) {
                    window.location.href = href;
                }
            });
        });
    </script>
</body>
</html>



