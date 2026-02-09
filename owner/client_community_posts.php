<?php
session_start();
require_once '../config/db.php';
require_role('owner');

$owner_id = $_SESSION['user']['id'];
$unread_notifications = fetch_unread_notification_count($pdo, $owner_id);

$shop_stmt = $pdo->prepare("SELECT id, shop_name FROM shops WHERE owner_id = ?");
$shop_stmt->execute([$owner_id]);
$shop = $shop_stmt->fetch();

if (!$shop) {
    header('Location: create_shop.php');
    exit();
}

$posts_stmt = $pdo->query("
    SELECT ccp.title,
           ccp.category,
           ccp.description,
           ccp.desired_quantity,
           ccp.target_date,
           ccp.status,
           ccp.created_at,
           u.fullname AS client_name
    FROM client_community_posts ccp
    JOIN users u ON ccp.client_id = u.id
    WHERE ccp.status = 'open'
    ORDER BY ccp.created_at DESC
    LIMIT 12
");
$community_posts = $posts_stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Community Posts - Owner</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .post-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-top: 1.5rem;
        }

        .community-post {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 1.25rem;
            background: var(--bg-primary);
        }

        .community-post h4 {
            margin-bottom: 0.5rem;
        }

        .community-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            font-size: 0.85rem;
            color: var(--gray-500);
            margin-top: 0.75rem;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar--compact">
        <div class="container d-flex justify-between align-center">
            <a href="dashboard.php" class="navbar-brand">
                <i class="fas fa-store"></i> <?php echo htmlspecialchars($shop['shop_name']); ?>
            </a>
            <ul class="navbar-nav">
                <li><a href="dashboard.php" class="nav-link">Dashboard</a></li>
                <li><a href="client_community_posts.php" class="nav-link active">Community Posts</a></li>
                <li><a href="messages.php" class="nav-link">Messages</a></li>
                <li><a href="payment_verifications.php" class="nav-link">Payments</a></li>
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
        <div class="dashboard-header fade-in">
            <div class="d-flex justify-between align-center">
                <div>
                    <h2>Client Community Posts</h2>
                    <p class="text-muted">Review live client requests, inspiration boards, and collaboration calls.</p>
                </div>
                <span class="badge badge-primary"><i class="fas fa-comments"></i> Community Feed</span>
            </div>
        </div>

        <?php if (empty($community_posts)): ?>
            <div class="card">
                <p class="text-muted mb-0">No client community posts are available right now. Check back soon.</p>
            </div>
        <?php else: ?>
            <div class="post-grid">
                <?php foreach ($community_posts as $post): ?>
                    <div class="community-post">
                        <span class="badge badge-primary"><?php echo htmlspecialchars($post['category']); ?></span>
                        <h4><?php echo htmlspecialchars($post['title']); ?></h4>
                        <p class="text-muted mb-2"><?php echo nl2br(htmlspecialchars($post['description'])); ?></p>
                        <div class="community-meta">
                            <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($post['client_name']); ?></span>
                            <span><i class="fas fa-calendar"></i> <?php echo date('M d, Y', strtotime($post['created_at'])); ?></span>
                            <?php if (!empty($post['desired_quantity'])): ?>
                                <span><i class="fas fa-box"></i> Qty <?php echo htmlspecialchars($post['desired_quantity']); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($post['target_date'])): ?>
                                <span><i class="fas fa-clock"></i> Target <?php echo date('M d, Y', strtotime($post['target_date'])); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>