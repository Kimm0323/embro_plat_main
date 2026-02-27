<?php
session_start();
require_once '../config/db.php';
require_role('owner');

$owner_id = $_SESSION['user']['id'];
$unread_notifications = fetch_unread_notification_count($pdo, $owner_id);
$form_error = '';
$form_success = '';
$selected_view_post = null;
$community_comments_table_exists = table_exists($pdo, 'community_post_comments');
$comment_offer_price_exists = $community_comments_table_exists && column_exists($pdo, 'community_post_comments', 'negotiated_price');
$comment_offer_quantity_exists = $community_comments_table_exists && column_exists($pdo, 'community_post_comments', 'negotiated_quantity');

$shop_stmt = $pdo->prepare("SELECT id, shop_name FROM shops WHERE owner_id = ?");
$shop_stmt->execute([$owner_id]);
$shop = $shop_stmt->fetch();

if (!$shop) {
    header('Location: create_shop.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accept_request'])) {
    $post_id = (int) ($_POST['post_id'] ?? 0);
     $offer_price = trim((string) ($_POST['offer_price'] ?? ''));
    $offer_quantity = trim((string) ($_POST['offer_quantity'] ?? ''));

    if ($post_id <= 0) {
        $form_error = 'Unable to process the selected request.';
    } else {
        $post_stmt = $pdo->prepare("SELECT id, client_id, title, category, description, preferred_price, desired_quantity, status FROM client_community_posts WHERE id = ? LIMIT 1");
        $post_stmt->execute([$post_id]);
        $selected_post = $post_stmt->fetch();

        if (!$selected_post) {
            $form_error = 'The selected community request no longer exists.';
        } elseif (($selected_post['status'] ?? '') !== 'open') {
            $form_error = 'This request has already been handled by another shop.';
        } else {
           $final_price = $offer_price !== '' ? (float) $offer_price : (float) ($selected_post['preferred_price'] ?? 0);
            $final_quantity = $offer_quantity !== '' ? (int) $offer_quantity : (int) ($selected_post['desired_quantity'] ?? 1);

            if ($final_price <= 0) {
                $form_error = 'Please enter a valid negotiated price before accepting this request.';
            } elseif ($final_quantity <= 0) {
                $form_error = 'Please enter a valid negotiated quantity before accepting this request.';
            }

            if ($form_error === '') {
                try {
                $pdo->beginTransaction();

                $update_stmt = $pdo->prepare("UPDATE client_community_posts SET status = 'accepted', updated_at = NOW() WHERE id = ? AND status = 'open'");
                $update_stmt->execute([$post_id]);

                if ($update_stmt->rowCount() <= 0) {
                    throw new RuntimeException('Unable to accept this request right now. Please refresh and try again.');
                }

                $order_number = 'ORD-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
                $order_stmt = $pdo->prepare("
                    INSERT INTO orders (
                        order_number,
                        client_id,
                        shop_id,
                        service_type,
                        design_description,
                        quantity,
                        price,
                        client_notes,
                        status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')
                ");
                $order_stmt->execute([
                    $order_number,
                    (int) $selected_post['client_id'],
                    (int) $shop['id'],
                    !empty($selected_post['category']) ? $selected_post['category'] : 'Community Request',
                    $selected_post['description'] ?? null,
                    $final_quantity,
                    $final_price,
                    'Converted from community post: ' . ($selected_post['title'] ?? 'Untitled request')
                ]);

                $order_id = (int) $pdo->lastInsertId();

                $shop_update_stmt = $pdo->prepare("UPDATE shops SET total_orders = total_orders + 1 WHERE id = ?");
                $shop_update_stmt->execute([(int) $shop['id']]);

                $pdo->commit();

                create_notification(
                    $pdo,
                    (int) $selected_post['client_id'],
                     $order_id,
                    'community_post_accepted',
                    sprintf(
                        '%s accepted your request "%s" with a negotiated offer of ₱%s for qty %d. Order #%s is now in your orders list for review.',
                        $shop['shop_name'],
                        $selected_post['title'],
                          number_format($final_price, 2),
                        $final_quantity,
                        $order_number
                    )
                );

                $form_success = 'Request accepted and converted to a new order using the negotiated price and quantity.';
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $form_error = $e instanceof RuntimeException
                        ? $e->getMessage()
                        : 'Unable to convert this community request into an order right now.';
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_shop_comment'])) {
    $post_id = (int) ($_POST['post_id'] ?? 0);
    $comment_text = sanitize($_POST['comment_text'] ?? '');
     $offered_price = trim((string) ($_POST['offered_price'] ?? ''));
    $offered_quantity = trim((string) ($_POST['offered_quantity'] ?? ''));

    if (!$community_comments_table_exists) {
        $form_error = 'Shop comments are unavailable because the community_post_comments table is missing.';
    } elseif ($post_id <= 0 || $comment_text === '' || $offered_price === '' || $offered_quantity === '') {
        $form_error = 'Please include your comment, negotiated price, and quantity before posting your offer.';
    } elseif (!is_numeric($offered_price) || (float) $offered_price <= 0) {
        $form_error = 'Please enter a valid negotiated price.';
    } elseif (!ctype_digit($offered_quantity) || (int) $offered_quantity <= 0) {
        $form_error = 'Please enter a valid negotiated quantity.';
    } else {
        $post_exists_stmt = $pdo->prepare('SELECT id FROM client_community_posts WHERE id = ? LIMIT 1');
        $post_exists_stmt->execute([$post_id]);

        if (!$post_exists_stmt->fetchColumn()) {
            $form_error = 'The selected community post is no longer available.';
        } else {
           if ($comment_offer_price_exists && $comment_offer_quantity_exists) {
                $insert_comment_stmt = $pdo->prepare('
                    INSERT INTO community_post_comments
                        (post_id, commenter_user_id, commenter_role, shop_id, comment_text, negotiated_price, negotiated_quantity, created_at)
                    VALUES (?, ?, "shop", ?, ?, ?, ?, NOW())
                ');
                $insert_comment_stmt->execute([
                    $post_id,
                    $owner_id,
                    (int) $shop['id'],
                    $comment_text,
                    (float) $offered_price,
                    (int) $offered_quantity,
                ]);
            } else {
                $insert_comment_stmt = $pdo->prepare('
                    INSERT INTO community_post_comments (post_id, commenter_user_id, commenter_role, shop_id, comment_text, created_at)
                    VALUES (?, ?, "shop", ?, ?, NOW())
                ');
                $insert_comment_stmt->execute([
                    $post_id,
                    $owner_id,
                    (int) $shop['id'],
                    sprintf(
                        "%s\n\nNegotiated offer: ₱%s, Qty %d",
                        $comment_text,
                        number_format((float) $offered_price, 2, '.', ''),
                        (int) $offered_quantity
                    ),
                ]);
            }
            $form_success = 'Your shop suggestion has been posted successfully.';
        }
    }
}

$view_post_id = (int) ($_GET['view_post'] ?? 0);
if ($view_post_id > 0) {
    $view_post_stmt = $pdo->prepare("
        SELECT ccp.id,
               ccp.title,
               ccp.category,
               ccp.description,
               ccp.preferred_price,
               ccp.desired_quantity,
               ccp.target_date,
               ccp.image_path,
               ccp.status,
               ccp.created_at,
               u.fullname AS client_name
        FROM client_community_posts ccp
        JOIN users u ON ccp.client_id = u.id
        WHERE ccp.id = ?
        LIMIT 1
    ");
    $view_post_stmt->execute([$view_post_id]);
    $selected_view_post = $view_post_stmt->fetch();
}


$posts_stmt = $pdo->query("
     SELECT ccp.id,
           ccp.title,
           ccp.category,
           ccp.description,
           ccp.preferred_price,
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

$post_comments_map = [];
if ($community_comments_table_exists && !empty($community_posts)) {
    $post_ids = array_map(static function (array $post): int {
        return (int) $post['id'];
    }, $community_posts);
    $placeholders = implode(',', array_fill(0, count($post_ids), '?'));

    $comment_select = $comment_offer_price_exists && $comment_offer_quantity_exists
        ? 'c.negotiated_price, c.negotiated_quantity'
        : 'NULL AS negotiated_price, NULL AS negotiated_quantity';

    $comments_stmt = $pdo->prepare("\n        SELECT c.post_id,
               c.commenter_role,
               c.comment_text,
               {$comment_select},
               c.created_at,
               u.fullname AS commenter_name,
               s.id AS shop_id,
               s.shop_name
        FROM community_post_comments c
        LEFT JOIN users u ON c.commenter_user_id = u.id
        LEFT JOIN shops s ON c.shop_id = s.id
        WHERE c.post_id IN ($placeholders)
        ORDER BY c.created_at DESC
    ");
    $comments_stmt->execute($post_ids);
    foreach ($comments_stmt->fetchAll(PDO::FETCH_ASSOC) as $comment_row) {
        $post_comments_map[(int) $comment_row['post_id']][] = $comment_row;
    }
}
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
         .post-actions {
            margin-top: 1rem;
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            align-items: center;
        }

        .post-actions form {
            margin: 0;
        }

         .comment-section {
            border-top: 1px solid var(--gray-200);
            margin-top: 1rem;
            padding-top: 0.75rem;
        }

        .comment-item {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 0.65rem;
            margin-bottom: 0.5rem;
        }
    </style>
</head>
<body>
    <?php include __DIR__ . "/includes/owner_navbar.php"; ?>

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

         <?php if ($selected_view_post): ?>
            <div class="card mb-3" id="post-details">
                <div class="card-header d-flex justify-between align-center">
                    <h3 class="mb-0"><i class="fas fa-eye text-primary"></i> Viewing post details</h3>
                    <a href="client_community_posts.php" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-times"></i> Close
                    </a>
                </div>
                <div class="card-content">
                    <span class="badge badge-primary"><?php echo htmlspecialchars($selected_view_post['category']); ?></span>
                    <h4 class="mt-2"><?php echo htmlspecialchars($selected_view_post['title']); ?></h4>
                    <?php if (!empty($selected_view_post['image_path'])): ?>
                        <img src="../assets/uploads/<?php echo htmlspecialchars($selected_view_post['image_path']); ?>" alt="Post reference image">
                    <?php endif; ?>
                    <p class="text-muted mb-2"><?php echo nl2br(htmlspecialchars($selected_view_post['description'])); ?></p>
                    <div class="community-meta">
                        <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($selected_view_post['client_name']); ?></span>
                        <span><i class="fas fa-calendar"></i> <?php echo date('M d, Y', strtotime($selected_view_post['created_at'])); ?></span>
                        <?php if (!empty($selected_view_post['preferred_price'])): ?>
                            <span><i class="fas fa-peso-sign"></i> Price ₱<?php echo number_format((float) $selected_view_post['preferred_price'], 2); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($selected_view_post['desired_quantity'])): ?>
                            <span><i class="fas fa-box"></i> Qty <?php echo htmlspecialchars($selected_view_post['desired_quantity']); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($selected_view_post['target_date'])): ?>
                            <span><i class="fas fa-clock"></i> Target <?php echo date('M d, Y', strtotime($selected_view_post['target_date'])); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
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
                             <?php if (!empty($post['preferred_price'])): ?>
                                <span><i class="fas fa-peso-sign"></i> Price ₱<?php echo number_format((float) $post['preferred_price'], 2); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($post['desired_quantity'])): ?>
                                <span><i class="fas fa-box"></i> Qty <?php echo htmlspecialchars($post['desired_quantity']); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($post['target_date'])): ?>
                                <span><i class="fas fa-clock"></i> Target <?php echo date('M d, Y', strtotime($post['target_date'])); ?></span>
                            <?php endif; ?>
                            </div>
                        <?php
                            $post_comments = $post_comments_map[(int) $post['id']] ?? [];
                            $shop_comments = array_values(array_filter($post_comments, static function (array $item): bool {
                                return ($item['commenter_role'] ?? '') === 'shop';
                            }));
                        ?>
                        <div class="comment-section">
                            <h5 class="mb-2"><i class="fas fa-store text-primary"></i> Shop service suggestions</h5>
                            <?php if (empty($shop_comments)): ?>
                                <p class="text-muted small">No shop service suggestions yet.</p>
                            <?php else: ?>
                                <?php foreach ($shop_comments as $shop_comment): ?>
                                    <div class="comment-item">
                                        <strong><?php echo htmlspecialchars($shop_comment['shop_name'] ?? ($shop_comment['commenter_name'] ?? 'Shop')); ?></strong>
                                        <p class="mb-2 mt-1"><?php echo nl2br(htmlspecialchars($shop_comment['comment_text'])); ?></p>
                                        <?php if (!empty($shop_comment['negotiated_price']) || !empty($shop_comment['negotiated_quantity'])): ?>
                                            <p class="small text-muted mb-2">
                                                <i class="fas fa-handshake"></i>
                                                Offer: ₱<?php echo number_format((float) ($shop_comment['negotiated_price'] ?? 0), 2); ?>
                                                · Qty <?php echo (int) ($shop_comment['negotiated_quantity'] ?? 0); ?>
                                            </p>
                                        <?php endif; ?>
                                        <?php if (!empty($shop_comment['shop_id'])): ?>
                                            <a href="../client/place_order.php?shop_id=<?php echo (int) $shop_comment['shop_id']; ?>" class="btn btn-primary btn-sm">
                                                <i class="fas fa-cart-plus"></i> Make Order
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <?php if ($community_comments_table_exists): ?>
                                <form method="POST" class="mt-2">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="post_id" value="<?php echo (int) $post['id']; ?>">
                                    <label class="mb-1">Suggest your shop service</label>
                                    <textarea name="comment_text" class="form-control" rows="2" required placeholder="Describe why your shop is a good fit and your service offer."></textarea>
                                    <div class="d-flex" style="gap: 0.5rem; flex-wrap: wrap; margin-top: 0.5rem;">
                                        <input type="number" name="offered_price" class="form-control" min="1" step="0.01" required placeholder="Offer price (₱)">
                                        <input type="number" name="offered_quantity" class="form-control" min="1" step="1" required placeholder="Offer quantity">
                                    </div>
                                    <button type="submit" name="submit_shop_comment" class="btn btn-outline-primary btn-sm mt-2">
                                        <i class="fas fa-comment"></i> Post shop comment
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                          <div class="post-actions">
                            <a href="client_community_posts.php?view_post=<?php echo (int) $post['id']; ?>#post-details" class="btn btn-outline-primary btn-sm">
                                <i class="fas fa-eye"></i> View post
                            </a>
                            <form method="POST" class="d-flex" style="gap: 0.75rem; flex-wrap: wrap; align-items: center;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="post_id" value="<?php echo (int) $post['id']; ?>">
                                <input type="number" name="offer_price" class="form-control form-control-sm" min="1" step="0.01" placeholder="Final price (₱)">
                                <input type="number" name="offer_quantity" class="form-control form-control-sm" min="1" step="1" placeholder="Final qty">
                                <button type="submit" name="accept_request" class="btn btn-primary btn-sm">
                                    <i class="fas fa-check"></i> Accept Request as new order
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>