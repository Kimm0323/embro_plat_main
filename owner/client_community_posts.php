<?php
session_start();
require_once '../config/db.php';
require_role('owner');

$owner_id = $_SESSION['user']['id'];
$unread_notifications = fetch_unread_notification_count($pdo, $owner_id);
$form_error = '';
$form_success = '';

$shop_stmt = $pdo->prepare("SELECT id, shop_name FROM shops WHERE owner_id = ?");
$shop_stmt->execute([$owner_id]);
$shop = $shop_stmt->fetch();

if (!$shop) {
    header('Location: create_shop.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accept_request'])) {
    $post_id = (int) ($_POST['post_id'] ?? 0);
    $price_input = trim($_POST['quoted_price'] ?? '');
    $quoted_price = filter_var($price_input, FILTER_VALIDATE_FLOAT);

    if ($post_id <= 0) {
        $form_error = 'Unable to process the selected request.';
    } elseif ($quoted_price === false || $quoted_price <= 0) {
        $form_error = 'Please enter a valid price greater than zero before accepting.';
    } else {
        $post_stmt = $pdo->prepare("SELECT id, client_id, title, status FROM client_community_posts WHERE id = ? LIMIT 1");
        $post_stmt->execute([$post_id]);
        $selected_post = $post_stmt->fetch();

        if (!$selected_post) {
            $form_error = 'The selected community request no longer exists.';
        } elseif (($selected_post['status'] ?? '') !== 'open') {
            $form_error = 'This request has already been handled by another shop.';
        } else {
            $update_stmt = $pdo->prepare("UPDATE client_community_posts SET status = 'accepted', updated_at = NOW() WHERE id = ? AND status = 'open'");
            $update_stmt->execute([$post_id]);

            if ($update_stmt->rowCount() > 0) {
                create_notification(
                    $pdo,
                    (int) $selected_post['client_id'],
                    null,
                    'community_post_accepted',
                    sprintf(
                        '%s accepted your request "%s" and sent a quote of ₱%s. Please message the shop to continue.',
                        $shop['shop_name'],
                        $selected_post['title'],
                        number_format((float) $quoted_price, 2)
                    )
                );

                $form_success = 'Request accepted and quote has been sent to the client.';
            } else {
                $form_error = 'Unable to accept this request right now. Please refresh and try again.';
            }
        }
    }
}

$posts_stmt = $pdo->query("
     SELECT ccp.id,
           ccp.title,
           ccp.category,
           ccp.description,
           ccp.desired_quantity,
           ccp.target_date,
           ccp.image_path,
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

         .community-post img {
            width: 100%;
            border-radius: var(--radius);
            object-fit: cover;
            max-height: 220px;
            margin-bottom: 0.75rem;
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
                 <li class="dropdown">
                    <a href="#" class="nav-link dropdown-toggle">
                        <i class="fas fa-coins"></i> Finance
                    </a>
                    <div class="dropdown-menu">
                        <a href="payment_verifications.php" class="dropdown-item"><i class="fas fa-receipt"></i> Payments</a>
                        <a href="earnings.php" class="dropdown-item"><i class="fas fa-wallet"></i> Earnings</a>
                    </div>
                </li>
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

         <?php if ($form_error !== ''): ?>
            <div class="alert alert-danger mb-3"><?php echo htmlspecialchars($form_error); ?></div>
        <?php endif; ?>

        <?php if ($form_success !== ''): ?>
            <div class="alert alert-success mb-3"><?php echo htmlspecialchars($form_success); ?></div>
        <?php endif; ?>

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
                        <?php if (!empty($post['image_path'])): ?>
                            <img src="../assets/uploads/<?php echo htmlspecialchars($post['image_path']); ?>" alt="Post reference image">
                        <?php endif; ?>
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
                         <form method="POST" class="mt-3 d-flex" style="gap: 0.75rem; flex-wrap: wrap; align-items: center;">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="post_id" value="<?php echo (int) $post['id']; ?>">
                            <input
                                type="number"
                                class="form-control"
                                name="quoted_price"
                                min="0.01"
                                step="0.01"
                                placeholder="Set quote price"
                                required
                                style="max-width: 180px;"
                            >
                            <button type="submit" name="accept_request" class="btn btn-primary btn-sm">
                                <i class="fas fa-check"></i> Accept Request
                            </button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>