<?php
session_start();
require_once '../config/db.php';
require_role('client');

$client_id = $_SESSION['user']['id'];
$unread_notifications = fetch_unread_notification_count($pdo, $client_id);

$shop_id = isset($_GET['shop_id']) ? (int) $_GET['shop_id'] : 0;
$shop = null;
$portfolio_items = [];
$formatted_opening_time = '';
$formatted_closing_time = '';

if ($shop_id > 0) {
    $shop_stmt = $pdo->prepare("
         SELECT id, shop_name, shop_description, address, phone, email, rating, rating_count, opening_time, closing_time
        FROM shops
        WHERE id = ? AND status = 'active'
    ");
    $shop_stmt->execute([$shop_id]);
    $shop = $shop_stmt->fetch(PDO::FETCH_ASSOC);

    if ($shop) {
         $opening_dt = DateTime::createFromFormat('H:i:s', $shop['opening_time'])
            ?: DateTime::createFromFormat('H:i', $shop['opening_time']);
        $closing_dt = DateTime::createFromFormat('H:i:s', $shop['closing_time'])
            ?: DateTime::createFromFormat('H:i', $shop['closing_time']);
        if ($opening_dt && $closing_dt) {
            $formatted_opening_time = $opening_dt->format('g:i A');
            $formatted_closing_time = $closing_dt->format('g:i A');
        }
        $portfolio_stmt = $pdo->prepare("
            SELECT id, title, description, price, image_path, created_at
            FROM shop_portfolio
            WHERE shop_id = ?
            ORDER BY created_at DESC
        ");
        $portfolio_stmt->execute([$shop_id]);
        $portfolio_items = $portfolio_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $shop ? htmlspecialchars($shop['shop_name']) : 'Shop Details'; ?> - Client</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .portfolio-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 1.5rem;
            margin-top: 1.5rem;
        }

        .portfolio-card {
            display: flex;
            flex-direction: column;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            background: #fff;
            overflow: hidden;
            box-shadow: 0 6px 16px rgba(15, 23, 42, 0.05);
        }

        .portfolio-card img {
            width: 100%;
            height: 220px;
            object-fit: cover;
             border-radius: 0;
            border: 0;
            background: var(--gray-100);
        }

        .portfolio-card-body {
            padding: 1rem;
        }

        .portfolio-card h4 {
            margin: 0 0 0.35rem;
        }

        .portfolio-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 0.85rem;
        }

        .portfolio-actions .btn {
            flex: 1;
            justify-content: center;
        }

         .info-list {
            display: grid;
            gap: 0.75rem;
        }

        .info-item {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            align-items: center;
            font-size: 0.95rem;
        }

        .info-item i {
            color: var(--primary-500);
        }

        .info-item span {
            color: var(--gray-600);
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
                <li><a href="dashboard.php#shop-discovery" class="nav-link">Shop Discovery</a></li>
                <li><a href="messages.php" class="nav-link">Messages</a></li>
                <li><a href="notifications.php" class="nav-link">Notifications
                    <?php if ($unread_notifications > 0): ?>
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
        <?php if (!$shop): ?>
            <div class="card mt-4">
                <h3>Shop not found</h3>
                <p class="text-muted mb-0">We couldn't find that shop. Please return to the shop discovery list and try again.</p>
                <div class="mt-3">
                    <a href="dashboard.php#shop-discovery" class="btn btn-primary"><i class="fas fa-arrow-left"></i> Back to Shop Discovery</a>
                </div>
            </div>
        <?php else: ?>
            <div class="dashboard-header fade-in">
                <div class="d-flex justify-between align-center">
                    <div>
                        <h2><?php echo htmlspecialchars($shop['shop_name']); ?></h2>
                        <p class="text-muted mb-0">Shop description and posted works.</p>
                    </div>
                      <div class="d-flex align-center" style="gap: 0.75rem;">
                        <a href="place_order.php?shop_id=<?php echo (int) $shop['id']; ?>" class="btn btn-primary">
                            <i class="fas fa-plus-circle"></i> Place Order
                        </a>
                        <a href="dashboard.php#shop-discovery" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Back to dashboard</a>
                    </div>
                </div>
            </div>

            <div class="card">
                  <div class="card-header">
                    <h3><i class="fas fa-store text-primary"></i> Store Information</h3>
                </div>
                <div class="info-list">
                    <div class="info-item">
                        <i class="fas fa-location-dot"></i>
                        <span><?php echo htmlspecialchars($shop['address'] ?? 'Address not available.'); ?></span>
                    </div>
                    <div class="info-item">
                        <i class="fas fa-phone"></i>
                        <span><?php echo htmlspecialchars($shop['phone'] ?? 'Phone not available.'); ?></span>
                    </div>
                    <div class="info-item">
                        <i class="fas fa-envelope"></i>
                        <span><?php echo htmlspecialchars($shop['email'] ?? 'Email not available.'); ?></span>
                    </div>
                    <div class="info-item">
                        <i class="fas fa-star"></i>
                        <span>
                            <?php if (!empty($shop['rating_count'])): ?>
                                <?php echo number_format((float) $shop['rating'], 1); ?>/5 (<?php echo (int) $shop['rating_count']; ?> reviews)
                            <?php else: ?>
                                No ratings yet.
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="info-item">
                        <i class="fas fa-clock"></i>
                        <span>
                            <?php if ($formatted_opening_time !== '' && $formatted_closing_time !== ''): ?>
                                <?php echo htmlspecialchars($formatted_opening_time); ?> - <?php echo htmlspecialchars($formatted_closing_time); ?> (PHT)
                            <?php else: ?>
                                Hours not available.
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
            </div>

             <div class="card mt-4">
                <div class="card-header">
                    <h3><i class="fas fa-info-circle text-primary"></i> Shop Description</h3>
                </div>
                <p class="text-muted mb-0"><?php echo nl2br(htmlspecialchars($shop['shop_description'] ?? 'No shop description provided.')); ?></p>
            </div>

            <div class="card mt-4">
                <div class="card-header">
                    <h3><i class="fas fa-images text-primary"></i> Posted Works</h3>
                </div>
                <?php if (empty($portfolio_items)): ?>
                    <p class="text-muted mb-0">This shop has not posted any works yet.</p>
                <?php else: ?>
                    <div class="portfolio-grid">
                        <?php foreach ($portfolio_items as $item): ?>
                            <div class="portfolio-card">
                                <img src="../assets/uploads/<?php echo htmlspecialchars($item['image_path']); ?>" alt="<?php echo htmlspecialchars($item['title']); ?>">
                                 <div class="portfolio-card-body">
                                    <h4><?php echo htmlspecialchars($item['title']); ?></h4>
                                     <?php if (isset($item['price'])): ?>
                                        <p class="mb-1"><strong>Starting at ₱<?php echo number_format((float) $item['price'], 2); ?></strong></p>
                                    <?php endif; ?>
                                    <p class="text-muted mb-0"><?php echo nl2br(htmlspecialchars($item['description'] ?? '')); ?></p>
                                     <div class="portfolio-actions">
                                        <a href="../assets/uploads/<?php echo htmlspecialchars($item['image_path']); ?>" target="_blank" rel="noopener" class="btn btn-outline btn-sm">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                        <a href="place_order.php?shop_id=<?php echo (int) $shop['id']; ?>&portfolio_id=<?php echo (int) $item['id']; ?>" class="btn btn-primary btn-sm">
                                            <i class="fas fa-cart-plus"></i> Order
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>