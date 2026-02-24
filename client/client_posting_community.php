<?php
session_start();
require_once '../config/db.php';
require_once '../includes/media_manager.php';
require_role('client');

$client_id = $_SESSION['user']['id'];
$unread_notifications = fetch_unread_notification_count($pdo, $client_id);
$form_error = '';
$form_success = '';
$comment_error = '';
$comment_success = '';
$client_posts = [];
$community_table_exists = table_exists($pdo, 'client_community_posts');
$community_price_exists = $community_table_exists && column_exists($pdo, 'client_community_posts', 'preferred_price');
$community_comments_table_exists = table_exists($pdo, 'community_post_comments');

if (!$community_table_exists) {
    $form_error = 'Community posts are unavailable because the database schema is missing the client_community_posts table. Please import the latest embroidery_platform.sql.';
 } elseif (!$community_price_exists) {
    $form_error = 'Community posts need the preferred_price column so customers can set a project budget. Please import the latest embroidery_platform.sql.';
}

if (!$community_comments_table_exists && $form_error === '') {
    $comment_error = 'Comment features are unavailable because the community_post_comments table is missing. Please import the latest embroidery_platform.sql.';
}

if ($community_table_exists && $community_price_exists && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_post'])) {
    $title = sanitize($_POST['title'] ?? '');
    $category = sanitize($_POST['category'] ?? '');
    $description = sanitize($_POST['description'] ?? '');
    $preferred_price = trim($_POST['preferred_price'] ?? '');
    $desired_quantity = trim($_POST['desired_quantity'] ?? '');
    $target_date = trim($_POST['target_date'] ?? '');
    $image_path = null;


     if ($title === '' || $category === '' || $description === '' || $preferred_price === '') {
        $form_error = 'Please complete the required fields to publish your post.';
     }

     if ($form_error === '' && (!is_numeric($preferred_price) || (float) $preferred_price <= 0)) {
        $form_error = 'Please enter a valid target budget greater than zero.';
    }

    if ($form_error === '' && isset($_FILES['reference_image']) && $_FILES['reference_image']['error'] !== UPLOAD_ERR_NO_FILE) {
        $upload = save_uploaded_media(
            $_FILES['reference_image'],
            ALLOWED_IMAGE_TYPES,
            MAX_FILE_SIZE,
            'community_posts',
            'community_post',
            (string) $client_id
        );
        if (!$upload['success']) {
            $form_error = $upload['error'];
        } else {
            $image_path = $upload['path'];
        }
    }

    if ($form_error === '') {
        $insert_stmt = $pdo->prepare("
            INSERT INTO client_community_posts
                 (client_id, title, category, description, preferred_price, desired_quantity, target_date, image_path, status, created_at)
            VALUES
               (?, ?, ?, ?, ?, ?, ?, ?, 'open', NOW())
        ");
        $insert_stmt->execute([
            $client_id,
            $title,
            $category,
            $description,
            (float) $preferred_price,
            $desired_quantity !== '' ? $desired_quantity : null,
            $target_date !== '' ? $target_date : null,
            $image_path,
        ]);
        $form_success = 'Your post is now live for shop owners to review.';
    }
}

if ($community_table_exists && $community_comments_table_exists && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_client_comment'])) {
    $post_id = (int) ($_POST['post_id'] ?? 0);
    $comment_text = sanitize($_POST['comment_text'] ?? '');
    $recommended_shop_id = (int) ($_POST['recommended_shop_id'] ?? 0);

    if ($post_id <= 0 || $comment_text === '') {
        $comment_error = 'Please provide a comment before submitting your recommendation.';
    } else {
        $post_exists_stmt = $pdo->prepare('SELECT id FROM client_community_posts WHERE id = ? AND client_id = ? LIMIT 1');
        $post_exists_stmt->execute([$post_id, $client_id]);
        $post_exists = $post_exists_stmt->fetchColumn();

        if (!$post_exists) {
            $comment_error = 'The selected post is unavailable for commenting.';
        } else {
            $insert_comment_stmt = $pdo->prepare('
                INSERT INTO community_post_comments (post_id, commenter_user_id, commenter_role, shop_id, comment_text, created_at)
                VALUES (?, ?, "client", ?, ?, NOW())
            ');
            $insert_comment_stmt->execute([
                $post_id,
                $client_id,
                $recommended_shop_id > 0 ? $recommended_shop_id : null,
                $comment_text,
            ]);

            $comment_success = 'Client recommendation posted successfully.';
        }
    }
}

$shop_options_stmt = $pdo->query('SELECT id, shop_name FROM shops ORDER BY shop_name ASC LIMIT 200');
$shop_options = $shop_options_stmt->fetchAll(PDO::FETCH_ASSOC);

if ($community_table_exists && $community_price_exists) {
    $client_posts_stmt = $pdo->prepare("
        SELECT id, title, category, description, preferred_price, desired_quantity, target_date, image_path, status, created_at
        FROM client_community_posts
        WHERE client_id = ?
        ORDER BY created_at DESC
        LIMIT 6
    ");
    $client_posts_stmt->execute([$client_id]);
    $client_posts = $client_posts_stmt->fetchAll();
}

$post_comments_map = [];
if ($community_comments_table_exists && !empty($client_posts)) {
    $post_ids = array_map(static function (array $post): int {
        return (int) $post['id'];
    }, $client_posts);

    $placeholders = implode(',', array_fill(0, count($post_ids), '?'));
    $comments_stmt = $pdo->prepare("\n        SELECT c.post_id,
               c.commenter_role,
               c.comment_text,
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
    <title>Client Posting &amp; Community Interaction Module - Client</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .community-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 1.5rem;
            margin: 2rem 0;
        }

        .overview-card {
            grid-column: span 12;
        }

         .post-form {
            display: grid;
            gap: 1rem;
        }

        .post-form textarea {
            min-height: 140px;
            resize: vertical;
        }

        .post-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            font-size: 0.9rem;
            color: var(--gray-500);
        }

        .post-card {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 1rem;
            background: var(--bg-primary);
        }

         .post-image {
            width: 100%;
            border-radius: var(--radius);
            object-fit: cover;
            max-height: 220px;
            margin-bottom: 0.75rem;
        }

        .comment-section {
            border-top: 1px solid var(--gray-200);
            margin-top: 1rem;
            padding-top: 1rem;
        }

        .comment-item {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 0.75rem;
            margin-bottom: 0.75rem;
        }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/includes/customer_navbar.php'; ?>


    <div class="container">
        <div class="dashboard-header fade-in">
            <div class="d-flex justify-between align-center">
                <div>
                    <h2>Client Posting &amp; Community Interaction</h2>
                    <p class="text-muted">Publish project requests and manage your recent community posts.</p>
                </div>
                <span class="badge badge-primary"><i class="fas fa-comments"></i> Module 20</span>
            </div>
        </div>

        <div class="community-grid">
             <div class="card overview-card">
                <div class="card-header">
                    <h3><i class="fas fa-pen-nib text-primary"></i> Create a community post</h3>
                    <p class="text-muted mb-0">Let shop owners know what you need and when you need it.</p>
                </div>
                <?php if ($form_error): ?>
                    <div class="alert alert-danger mb-3"><?php echo htmlspecialchars($form_error); ?></div>
                <?php endif; ?>
                <?php if ($form_success): ?>
                    <div class="alert alert-success mb-3"><?php echo htmlspecialchars($form_success); ?></div>
                <?php endif; ?>
                <?php if ($comment_error): ?>
                    <div class="alert alert-danger mb-3"><?php echo htmlspecialchars($comment_error); ?></div>
                <?php endif; ?>
                <?php if ($comment_success): ?>
                    <div class="alert alert-success mb-3"><?php echo htmlspecialchars($comment_success); ?></div>
                <?php endif; ?>
                <form method="POST" class="post-form" enctype="multipart/form-data">
                    <?php echo csrf_field(); ?>
                    <div class="form-grid">
                        <div>
                            <label for="title">Post title</label>
                            <input type="text" id="title" name="title" class="form-control" placeholder="e.g., Hoodie embroidery for fall launch" required>
                        </div>
                        <div>
                            <label for="category">Post category</label>
                            <select id="category" name="category" class="form-control" required>
                                <option value="">Select a category</option>
                                <option value="Request">Request post</option>
                                <option value="Inspiration">Inspiration board</option>
                                <option value="Question">Community question</option>
                                <option value="Collaboration">Collaboration call</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label for="description">Project details</label>
                        <textarea id="description" name="description" class="form-control" placeholder="Share the embroidery placement, materials, budget range, or any references." required></textarea>
                    </div>
                    <div class="form-grid">
                        <div>
                            <label for="preferred_price">Target budget (₱)</label>
                            <input type="number" id="preferred_price" name="preferred_price" class="form-control" min="1" step="0.01" placeholder="e.g., 7500" required>
                        </div>
                        <div>
                            <label for="desired_quantity">Estimated quantity (optional)</label>
                            <input type="number" id="desired_quantity" name="desired_quantity" class="form-control" min="1" placeholder="e.g., 150">
                        </div>
                        <div>
                            <label for="target_date">Target delivery date (optional)</label>
                            <input type="date" id="target_date" name="target_date" class="form-control">
                        </div>
                    </div>
                    <div>
                        <label for="reference_image">Add a reference image (optional)</label>
                        <input type="file" id="reference_image" name="reference_image" class="form-control" accept="image/*">
                        <small class="text-muted">JPG, PNG, or GIF up to 5 MB.</small>
                    </div>
                    <button type="submit" name="submit_post" class="btn btn-primary">
                        <i class="fas fa-paper-plane"></i> Post
                    </button>
                </form>
            </div>

            <div class="card overview-card">
                <div class="card-header">
                    <h3><i class="fas fa-list text-primary"></i> Your recent posts</h3>
                    <p class="text-muted mb-0">Track what you have shared with shop owners.</p>
                </div>
                <?php if (empty($client_posts)): ?>
                    <p class="text-muted mb-0">You have not created any community posts yet.</p>
                <?php else: ?>
                    <div class="flow-list">
                        <?php foreach ($client_posts as $post): ?>
                            <div class="post-card">
                                <div class="d-flex justify-between align-center mb-2">
                                    <strong><?php echo htmlspecialchars($post['title']); ?></strong>
                                    <span class="badge badge-primary"><?php echo htmlspecialchars($post['category']); ?></span>
                                </div>
                                  <?php if (!empty($post['image_path'])): ?>
                                    <img class="post-image" src="../assets/uploads/<?php echo htmlspecialchars($post['image_path']); ?>" alt="Post reference image">
                                <?php endif; ?>
                                <p class="text-muted mb-2"><?php echo nl2br(htmlspecialchars($post['description'])); ?></p>
                                <div class="post-meta">
                                    <span><i class="fas fa-calendar"></i> Posted <?php echo date('M d, Y', strtotime($post['created_at'])); ?></span>
                                     <span><i class="fas fa-peso-sign"></i> Budget ₱<?php echo number_format((float) ($post['preferred_price'] ?? 0), 2); ?></span>
                                    <?php if (!empty($post['desired_quantity'])): ?>
                                        <span><i class="fas fa-box"></i> Qty <?php echo htmlspecialchars($post['desired_quantity']); ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($post['target_date'])): ?>
                                        <span><i class="fas fa-clock"></i> Target <?php echo date('M d, Y', strtotime($post['target_date'])); ?></span>
                                    <?php endif; ?>
                                    <span><i class="fas fa-signal"></i> <?php echo htmlspecialchars(ucfirst($post['status'])); ?></span>
                                </div>

                                 <div class="comment-section">
                                    <h5 class="mb-2"><i class="fas fa-user-friends text-primary"></i> Client comments (shop recommendations)</h5>
                                    <?php
                                        $post_comments = $post_comments_map[(int) $post['id']] ?? [];
                                        $client_comments = array_values(array_filter($post_comments, static function (array $item): bool {
                                            return ($item['commenter_role'] ?? '') === 'client';
                                        }));
                                        $shop_comments = array_values(array_filter($post_comments, static function (array $item): bool {
                                            return ($item['commenter_role'] ?? '') === 'shop';
                                        }));
                                    ?>
                                    <?php if (empty($client_comments)): ?>
                                        <p class="text-muted small">No client recommendations yet.</p>
                                    <?php else: ?>
                                        <?php foreach ($client_comments as $client_comment): ?>
                                            <div class="comment-item">
                                                <strong><?php echo htmlspecialchars($client_comment['commenter_name'] ?? 'Client'); ?></strong>
                                                <?php if (!empty($client_comment['shop_name'])): ?>
                                                    <span class="badge badge-primary">Recommended: <?php echo htmlspecialchars($client_comment['shop_name']); ?></span>
                                                <?php endif; ?>
                                                <p class="mb-1 mt-1"><?php echo nl2br(htmlspecialchars($client_comment['comment_text'])); ?></p>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>

                                    <?php if ($community_comments_table_exists): ?>
                                        <form method="POST" class="post-form mt-2">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="post_id" value="<?php echo (int) $post['id']; ?>">
                                            <div class="form-grid">
                                                <div>
                                                    <label>Recommend a shop (optional)</label>
                                                    <select name="recommended_shop_id" class="form-control">
                                                        <option value="">Select a shop</option>
                                                        <?php foreach ($shop_options as $shop_option): ?>
                                                            <option value="<?php echo (int) $shop_option['id']; ?>"><?php echo htmlspecialchars($shop_option['shop_name']); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                            </div>
                                            <label class="mt-2">Add your recommendation</label>
                                            <textarea name="comment_text" class="form-control" rows="2" required placeholder="Recommend a shop, quality notes, and why you suggest them."></textarea>
                                            <button type="submit" name="submit_client_comment" class="btn btn-outline-primary btn-sm mt-2">
                                                <i class="fas fa-comment"></i> Submit client comment
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>

                                <div class="comment-section">
                                    <h5 class="mb-2"><i class="fas fa-store text-primary"></i> Shop comments (service suggestions)</h5>
                                    <?php if (empty($shop_comments)): ?>
                                        <p class="text-muted small">No shop suggestions yet.</p>
                                    <?php else: ?>
                                        <?php foreach ($shop_comments as $shop_comment): ?>
                                            <div class="comment-item">
                                                <strong><?php echo htmlspecialchars($shop_comment['shop_name'] ?? 'Shop'); ?></strong>
                                                <p class="mb-2 mt-1"><?php echo nl2br(htmlspecialchars($shop_comment['comment_text'])); ?></p>
                                                <?php if (!empty($shop_comment['shop_id'])): ?>
                                                    <a href="place_order.php?shop_id=<?php echo (int) $shop_comment['shop_id']; ?>" class="btn btn-primary btn-sm">
                                                        <i class="fas fa-cart-plus"></i> Make Order
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
(function hydrateDraftFromDesignEditor() {
    const params = new URLSearchParams(window.location.search);
    if (!params.get('from_design_editor')) {
        return;
    }

    const draftRaw = localStorage.getItem('embroider_community_post_draft');
    if (!draftRaw) {
        return;
    }

    try {
        const draft = JSON.parse(draftRaw);
        const titleField = document.getElementById('title');
        const categoryField = document.getElementById('category');
        const descriptionField = document.getElementById('description');
        const priceField = document.getElementById('preferred_price');

        if (titleField && draft.title) {
            titleField.value = draft.title;
        }
        if (categoryField && draft.category) {
            categoryField.value = draft.category;
        }
        if (descriptionField && draft.description) {
            descriptionField.value = draft.description;
        }
        if (priceField && draft.preferred_price) {
            priceField.value = draft.preferred_price;
        }

        localStorage.removeItem('embroider_community_post_draft');
    } catch (error) {
        console.warn('Unable to prefill community draft from design editor.', error);
    }
})();
</script>
</body>
</html>
