<?php
session_start();
require_once '../config/db.php';
require_role('hr');

$hr_id = $_SESSION['user']['id'];
$hr_name = htmlspecialchars($_SESSION['user']['fullname'] ?? 'HR Lead');

$hr_stmt = $pdo->prepare("
    SELECT se.shop_id, s.shop_name
    FROM shop_staffs se
    JOIN shops s ON se.shop_id = s.id
    WHERE se.user_id = ? AND se.staff_role = 'hr' AND se.status = 'active'
");
$hr_stmt->execute([$hr_id]);
$hr_shop = $hr_stmt->fetch();

if (!$hr_shop) {
    die("You are not assigned to any shop as HR. Please contact your shop owner.");
}

$shop_id = (int) $hr_shop['shop_id'];
$shop_name = $hr_shop['shop_name'];

$expire_stmt = $pdo->prepare("
    UPDATE hiring_posts
    SET status = 'expired'
    WHERE shop_id = ?
      AND expires_at IS NOT NULL
      AND expires_at < NOW()
      AND status IN ('draft', 'live')
");
$expire_stmt->execute([$shop_id]);

$errors = [];
$success = null;

if (isset($_POST['create_post'])) {
    $title = sanitize($_POST['title'] ?? '');
    $description = sanitize($_POST['description'] ?? '');
    $status = $_POST['status'] ?? 'live';
    $expires_at = $_POST['expires_at'] ?? null;

    $allowed_statuses = ['draft', 'live', 'closed', 'expired'];
    if (!in_array($status, $allowed_statuses, true)) {
        $status = 'live';
    }

    if ($title === '') {
        $errors[] = 'Please provide a job title.';
    }

    if (empty($errors)) {
        $stmt = $pdo->prepare("
            INSERT INTO hiring_posts (shop_id, created_by, title, description, status, expires_at)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $shop_id,
            $hr_id,
            $title,
            $description ?: null,
            $status,
            $expires_at ?: null,
        ]);
        $success = 'Hiring post created successfully.';
    }
}

if (isset($_POST['update_post'])) {
    $post_id = (int) ($_POST['post_id'] ?? 0);
    $title = sanitize($_POST['title'] ?? '');
    $description = sanitize($_POST['description'] ?? '');
    $status = $_POST['status'] ?? 'live';
    $expires_at = $_POST['expires_at'] ?? null;

    $allowed_statuses = ['draft', 'live', 'closed', 'expired'];
    if (!in_array($status, $allowed_statuses, true)) {
        $status = 'live';
    }

    if ($title === '') {
        $errors[] = 'Please provide a job title.';
    }

    if (empty($errors)) {
        $check_stmt = $pdo->prepare("SELECT id FROM hiring_posts WHERE id = ? AND shop_id = ?");
        $check_stmt->execute([$post_id, $shop_id]);
        if ($check_stmt->fetch()) {
            $stmt = $pdo->prepare("
                UPDATE hiring_posts
                SET title = ?, description = ?, status = ?, expires_at = ?
                WHERE id = ? AND shop_id = ?
            ");
            $stmt->execute([
                $title,
                $description ?: null,
                $status,
                $expires_at ?: null,
                $post_id,
                $shop_id,
            ]);
            $success = 'Hiring post updated successfully.';
        } else {
            $errors[] = 'Unable to update that hiring post.';
        }
    }
}

if (isset($_POST['delete_post'])) {
    $post_id = (int) ($_POST['post_id'] ?? 0);
    $check_stmt = $pdo->prepare("SELECT id FROM hiring_posts WHERE id = ? AND shop_id = ?");
    $check_stmt->execute([$post_id, $shop_id]);
    if ($check_stmt->fetch()) {
        $delete_stmt = $pdo->prepare("DELETE FROM hiring_posts WHERE id = ? AND shop_id = ?");
        $delete_stmt->execute([$post_id, $shop_id]);
        $success = 'Hiring post deleted successfully.';
    } else {
        $errors[] = 'Unable to delete that hiring post.';
    }
}

$posts_stmt = $pdo->prepare("
    SELECT hp.*, u.fullname AS creator_name
    FROM hiring_posts hp
    LEFT JOIN users u ON hp.created_by = u.id
    WHERE hp.shop_id = ?
    ORDER BY hp.created_at DESC
");
$posts_stmt->execute([$shop_id]);
$hiring_posts = $posts_stmt->fetchAll();

$open_stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM hiring_posts
    WHERE shop_id = ?
      AND status = 'live'
      AND (expires_at IS NULL OR expires_at >= NOW())
");
$open_stmt->execute([$shop_id]);
$open_roles = (int) $open_stmt->fetchColumn();

$draft_stmt = $pdo->prepare("SELECT COUNT(*) FROM hiring_posts WHERE shop_id = ? AND status = 'draft'");
$draft_stmt->execute([$shop_id]);
$draft_roles = (int) $draft_stmt->fetchColumn();

$expired_stmt = $pdo->prepare("SELECT COUNT(*) FROM hiring_posts WHERE shop_id = ? AND status = 'expired'");
$expired_stmt->execute([$shop_id]);
$expired_roles = (int) $expired_stmt->fetchColumn();

$closed_stmt = $pdo->prepare("SELECT COUNT(*) FROM hiring_posts WHERE shop_id = ? AND status = 'closed'");
$closed_stmt->execute([$shop_id]);
$closed_roles = (int) $closed_stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HR Hiring Management Module</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .hiring-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 1.5rem;
            margin: 2rem 0;
        }

        .hiring-kpi {
            grid-column: span 3;
        }

        .hiring-kpi .metric {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }

        .hiring-kpi .metric i {
            font-size: 1.5rem;
        }

        .purpose-card,
        .workflow-card {
            grid-column: span 12;
        }

        .postings-card {
            grid-column: span 8;
        }

        .channels-card,
        .alerts-card {
            grid-column: span 4;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 1rem;
        }

        .form-grid .span-6 {
            grid-column: span 6;
        }

        .form-grid .span-12 {
            grid-column: span 12;
        }

        .table-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.2rem 0.6rem;
            border-radius: 999px;
            font-size: 0.8rem;
            background: var(--gray-100);
        }
    </style>
</head>
<body>
    <nav class="navbar navbar--compact">
        <div class="container d-flex justify-between align-center">
            <a href="dashboard.php" class="navbar-brand">
                <i class="fas fa-people-group"></i> <?php echo $hr_name; ?>
            </a>
            <ul class="navbar-nav">
                <li><a href="dashboard.php" class="nav-link">Dashboard</a></li>
                <li><a href="hiring_management.php" class="nav-link active">Hiring</a></li>
                <li><a href="staff_productivity_performance.php" class="nav-link">Productivity</a></li>
                <li><a href="payroll_compensation.php" class="nav-link">Payroll</a></li>
                <li><a href="analytics_reporting.php" class="nav-link">Analytics</a></li>
                <li><a href="../auth/logout.php" class="nav-link">Logout</a></li>
            </ul>
        </div>
    </nav>

    <main class="container">
        <section class="page-header">
            <div>
                <h1>Hiring Management</h1>
                <p class="text-muted">Track hiring posts for <?php echo htmlspecialchars($shop_name); ?> and keep listings up to date.</p>
            </div>
            <span class="badge">Logged in as <?php echo $hr_name; ?></span>
        </section>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php elseif ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <section class="hiring-grid">
            <div class="card hiring-kpi">
                <div class="metric">
                    <div>
                        <h3>Open roles</h3>
                        <p class="value"><?php echo $open_roles; ?></p>
                        <p class="text-muted">Live listings</p>
                    </div>
                    <i class="fas fa-briefcase text-primary"></i>
                </div>
            </div>
            <div class="card hiring-kpi">
                <div class="metric">
                    <div>
                        <h3>Draft roles</h3>
                        <p class="value"><?php echo $draft_roles; ?></p>
                        <p class="text-muted">Not yet published</p>
                    </div>
                    <i class="fas fa-file-circle-plus text-info"></i>
                </div>
            </div>
            <div class="card hiring-kpi">
                <div class="metric">
                    <div>
                        <h3>Closed roles</h3>
                        <p class="value"><?php echo $closed_roles; ?></p>
                        <p class="text-muted">Filled positions</p>
                    </div>
                    <i class="fas fa-circle-check text-success"></i>
                </div>
            </div>
            <div class="card hiring-kpi">
                <div class="metric">
                    <div>
                        <h3>Expired roles</h3>
                        <p class="value"><?php echo $expired_roles; ?></p>
                        <p class="text-muted">Auto-closed</p>
                    </div>
                    <i class="fas fa-hourglass-end text-warning"></i>
                </div>
            </div>

            <div class="card postings-card">
                <h2>Create hiring post</h2>
                <form method="POST" class="form-grid">
                    <?php echo csrf_field(); ?>
                    <div class="form-group span-6">
                        <label>Job title</label>
                        <input type="text" name="title" required>
                    </div>
                    <div class="form-group span-6">
                        <label>Status</label>
                        <select name="status">
                            <option value="live">Live</option>
                            <option value="draft">Draft</option>
                            <option value="closed">Closed</option>
                            <option value="expired">Expired</option>
                        </select>
                    </div>
                    <div class="form-group span-12">
                        <label>Description</label>
                        <textarea name="description" rows="3"></textarea>
                    </div>
                    <div class="form-group span-6">
                        <label>Expires at</label>
                        <input type="datetime-local" name="expires_at">
                    </div>
                    <div class="form-group span-6" style="display:flex;align-items:end;">
                        <button type="submit" name="create_post" class="btn btn-primary">Create post</button>
                    </div>
                </form>
            </div>

            <div class="card channels-card">
                <h2>Automation rules</h2>
                <ul class="list">
                    <li>Posts automatically expire once the end date has passed.</li>
                    <li>Drafts stay hidden until you publish them.</li>
                    <li>Closed roles remain visible for reporting.</li>
                </ul>
            </div>

            <div class="card alerts-card">
                <h2>Hiring pipeline notes</h2>
                <ul class="list">
                    <li>Keep job descriptions concise and updated.</li>
                    <li>Set expiration dates for all time-bound roles.</li>
                    <li>Review statuses weekly to avoid stale postings.</li>
                </ul>
            </div>

            <div class="card workflow-card">
                <h2>Active hiring posts</h2>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Status</th>
                                <th>Expires</th>
                                <th>Created</th>
                                <th>Owner</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($hiring_posts)): ?>
                                <tr>
                                    <td colspan="6">No hiring posts yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($hiring_posts as $post): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($post['title']); ?></strong>
                                            <div class="text-muted"><?php echo htmlspecialchars($post['description'] ?? ''); ?></div>
                                        </td>
                                        <td>
                                            <span class="status-pill">
                                                <?php echo htmlspecialchars(ucfirst($post['status'])); ?>
                                            </span>
                                        </td>
                                        <td><?php echo $post['expires_at'] ? htmlspecialchars(date('M d, Y H:i', strtotime($post['expires_at']))) : '—'; ?></td>
                                        <td><?php echo htmlspecialchars(date('M d, Y', strtotime($post['created_at']))); ?></td>
                                        <td><?php echo htmlspecialchars($post['creator_name'] ?? 'System'); ?></td>
                                        <td>
                                            <div class="table-actions">
                                                <button class="btn btn-secondary" type="button" onclick="document.getElementById('edit-<?php echo $post['id']; ?>').classList.toggle('hidden')">Edit</button>
                                                <form method="POST" onsubmit="return confirm('Delete this hiring post?');">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="post_id" value="<?php echo $post['id']; ?>">
                                                    <button type="submit" name="delete_post" class="btn btn-danger">Delete</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                    <tr id="edit-<?php echo $post['id']; ?>" class="hidden">
                                        <td colspan="6">
                                            <form method="POST" class="form-grid">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="post_id" value="<?php echo $post['id']; ?>">
                                                <div class="form-group span-6">
                                                    <label>Job title</label>
                                                    <input type="text" name="title" value="<?php echo htmlspecialchars($post['title']); ?>" required>
                                                </div>
                                                <div class="form-group span-6">
                                                    <label>Status</label>
                                                    <select name="status">
                                                        <?php foreach (['draft', 'live', 'closed', 'expired'] as $status): ?>
                                                            <option value="<?php echo $status; ?>" <?php echo $post['status'] === $status ? 'selected' : ''; ?>><?php echo ucfirst($status); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="form-group span-12">
                                                    <label>Description</label>
                                                    <textarea name="description" rows="3"><?php echo htmlspecialchars($post['description'] ?? ''); ?></textarea>
                                                </div>
                                                <div class="form-group span-6">
                                                    <label>Expires at</label>
                                                    <input type="datetime-local" name="expires_at" value="<?php echo $post['expires_at'] ? htmlspecialchars(date('Y-m-d\TH:i', strtotime($post['expires_at']))) : ''; ?>">
                                                </div>
                                                <div class="form-group span-6" style="display:flex;align-items:end;gap:0.5rem;">
                                                    <button type="submit" name="update_post" class="btn btn-primary">Save changes</button>
                                                    <button type="button" class="btn btn-secondary" onclick="document.getElementById('edit-<?php echo $post['id']; ?>').classList.add('hidden')">Cancel</button>
                                                </div>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </main>
</body>
</html>
