<?php
session_start();
require_once 'config/db.php';

$hiring_stmt = $pdo->query(" 
    SELECT hp.title,
           hp.description,
           hp.expires_at,
           hp.created_at,
           s.shop_name,
           s.address,
           s.phone,
           s.email,
           s.rating
    FROM hiring_posts hp
    INNER JOIN shops s ON s.id = hp.shop_id
    WHERE hp.status = 'live'
      AND s.status = 'active'
      AND (hp.expires_at IS NULL OR hp.expires_at >= NOW())
    ORDER BY hp.created_at DESC
");
$hiring_posts = $hiring_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hiring Shops - Embroidery Platform</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <nav class="navbar">
        <div class="container d-flex justify-between align-center">
            <a href="index.php" class="navbar-brand">
                <i class="fas fa-threads"></i> Embroidery Platform
            </a>
            <ul class="navbar-nav">
                <li><a href="index.php" class="nav-link">Home</a></li>
                <li><a href="hiring_shops.php" class="nav-link active">Hiring Shops</a></li>
                <?php if(isset($_SESSION['user'])): ?>
                    <li><a href="auth/logout.php" class="nav-link">Logout</a></li>
                <?php else: ?>
                    <li><a href="auth/login.php" class="nav-link">Login</a></li>
                <?php endif; ?>
            </ul>
        </div>
    </nav>

    <section class="section">
        <div class="container">
            <h1 class="section-title">Shops Hiring Right Now</h1>
            <p class="text-muted text-center mb-3">Browse open embroidery job opportunities and apply directly with the shop.</p>

            <?php if (empty($hiring_posts)): ?>
                <div class="card text-center">
                    <h3>No active hiring posts yet</h3>
                    <p class="text-muted mb-0">Please check back soon for new openings.</p>
                </div>
            <?php else: ?>
                <div class="hiring-shops-grid">
                    <?php foreach ($hiring_posts as $post): ?>
                        <article class="hiring-shop-card">
                            <div class="d-flex justify-between align-center">
                                <h3><?php echo htmlspecialchars($post['title']); ?></h3>
                                <span class="hiring-status-pill"><i class="fas fa-briefcase"></i> Open</span>
                            </div>
                            <p class="text-muted mb-2"><?php echo nl2br(htmlspecialchars($post['description'])); ?></p>
                            <div class="hiring-shop-meta">
                                <span><i class="fas fa-store"></i> <?php echo htmlspecialchars($post['shop_name']); ?></span>
                                <span><i class="fas fa-location-dot"></i> <?php echo htmlspecialchars($post['address'] ?: 'Address not provided'); ?></span>
                                <span><i class="fas fa-phone"></i> <?php echo htmlspecialchars($post['phone'] ?: 'Phone not provided'); ?></span>
                                <span><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($post['email'] ?: 'Email not provided'); ?></span>
                                <span><i class="fas fa-star"></i> Rating: <?php echo number_format((float) ($post['rating'] ?? 0), 1); ?>/5</span>
                                <span><i class="fas fa-calendar"></i> Expires: <?php echo !empty($post['expires_at']) ? date('M d, Y', strtotime($post['expires_at'])) : 'No expiry'; ?></span>
                            </div>
                            <div class="mt-3">
                                <a href="auth/register.php?type=client" class="btn btn-primary">
                                    <i class="fas fa-paper-plane"></i> Apply Now
                                </a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>
</body>
</html>
