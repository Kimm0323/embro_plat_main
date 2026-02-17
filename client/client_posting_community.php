<?php
session_start();
require_once '../config/db.php';
require_once '../includes/media_manager.php';
require_role('client');

$client_id = $_SESSION['user']['id'];
$unread_notifications = fetch_unread_notification_count($pdo, $client_id);
$form_error = '';
$form_success = '';
$client_posts = [];
$community_table_exists = table_exists($pdo, 'client_community_posts');
$community_price_exists = $community_table_exists && column_exists($pdo, 'client_community_posts', 'preferred_price');

if (!$community_table_exists) {
    $form_error = 'Community posts are unavailable because the database schema is missing the client_community_posts table. Please import the latest embroidery_platform.sql.';
    } elseif (!$community_price_exists) {
    $form_error = 'Community posts need the preferred_price column so customers can set a project budget. Please import the latest embroidery_platform.sql.';
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

if ($community_table_exists && $community_price_exists) {
    $client_posts_stmt = $pdo->prepare("
        SELECT title, category, description, preferred_price, desired_quantity, target_date, image_path, status, created_at
        FROM client_community_posts
        WHERE client_id = ?
        ORDER BY created_at DESC
        LIMIT 6
    ");
    $client_posts_stmt->execute([$client_id]);
    $client_posts = $client_posts_stmt->fetchAll();
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
                        <i class="fas fa-paper-plane"></i> Publish post
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
