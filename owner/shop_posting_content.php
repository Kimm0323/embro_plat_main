<?php
session_start();
require_once '../config/db.php';
require_role('owner');

$owner_id = $_SESSION['user']['id'];
$shop_stmt = $pdo->prepare("SELECT shop_name FROM shops WHERE owner_id = ?");
$shop_stmt->execute([$owner_id]);
$shop = $shop_stmt->fetch();

$post_types = [
    [
        'title' => 'Portfolio',
        'detail' => 'Showcase signature projects with photos, materials, and stitching techniques.',
        'icon' => 'fas fa-images',
    ],
    [
        'title' => 'Promotions',
        'detail' => 'Highlight seasonal offers, volume discounts, or bundle packages.',
        'icon' => 'fas fa-tags',
    ],
    [
        'title' => 'Announcements',
        'detail' => 'Share shop updates, holiday schedules, or new equipment launches.',
        'icon' => 'fas fa-bullhorn',
    ],
    [
        'title' => 'Hiring posts',
        'detail' => 'Post open roles for digitizers, machine operators, and quality specialists.',
        'icon' => 'fas fa-user-plus',
    ],
];

$workflow = [
    [
        'title' => 'Draft & upload',
        'detail' => 'Write the story, attach visuals, and select categories for quick discovery.',
        'icon' => 'fas fa-pen-to-square',
    ],
    [
        'title' => 'Audience & tags',
        'detail' => 'Target clients by industry, order size, or delivery urgency.',
        'icon' => 'fas fa-filter',
    ],
    [
        'title' => 'Schedule or publish',
        'detail' => 'Queue posts for campaign windows or publish immediately.',
        'icon' => 'fas fa-calendar-check',
    ],
    [
        'title' => 'Engagement insights',
        'detail' => 'Track inquiries, saves, and clicks to refine future posts.',
        'icon' => 'fas fa-chart-column',
    ],
];

$scheduling = [
    [
        'label' => 'Morning promo burst',
        'time' => '08:00 - 10:00',
        'detail' => 'Feature limited-time offers while clients plan their day.',
    ],
    [
        'label' => 'Afternoon portfolio spotlight',
        'time' => '13:00 - 15:00',
        'detail' => 'Rotate recent projects when engagement peaks midweek.',
    ],
    [
        'label' => 'Weekend hiring push',
        'time' => 'Saturday',
        'detail' => 'Share open roles when applicants are more available.',
    ],
];

$automation = [
    [
        'title' => 'Post scheduling',
        'detail' => 'Auto-publish queued posts based on time zone and campaign windows.',
        'icon' => 'fas fa-clock',
    ],
    [
        'title' => 'Portfolio suggestions',
        'detail' => 'Recommend high-performing projects to spotlight for new clients.',
        'icon' => 'fas fa-lightbulb',
    ],
    [
        'title' => 'Content reuse',
        'detail' => 'Transform completed orders into case studies with client approval prompts.',
        'icon' => 'fas fa-repeat',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shop Posting &amp; Content Management Module - Owner</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .content-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 1.5rem;
            margin: 2rem 0;
        }

        .overview-card {
            grid-column: span 12;
        }

        .types-card {
            grid-column: span 7;
        }

        .workflow-card {
            grid-column: span 5;
        }

        .schedule-card {
            grid-column: span 6;
        }

        .automation-card {
            grid-column: span 6;
        }

        .type-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .type-item,
        .workflow-step,
        .schedule-item,
        .automation-item {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 1rem;
            background: var(--bg-primary);
        }

        .type-item i,
        .workflow-step i,
        .automation-item i {
            color: var(--primary-600);
        }

        .workflow-list,
        .schedule-list,
        .automation-list {
            display: grid;
            gap: 1rem;
        }

        .schedule-item {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            align-items: center;
        }

        .schedule-time {
            font-weight: 700;
            color: var(--primary-700);
            white-space: nowrap;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar--compact">
        <div class="container d-flex justify-between align-center">
            <a href="dashboard.php" class="navbar-brand">
                <i class="fas fa-store"></i> <?php echo htmlspecialchars($shop['shop_name'] ?? 'Shop Owner'); ?>
            </a>
            <ul class="navbar-nav">
                <li><a href="dashboard.php" class="nav-link">Dashboard</a></li>
                <li><a href="shop_profile.php" class="nav-link">Shop Profile</a></li>
                <li><a href="manage_staff.php" class="nav-link">Staff</a></li>
                <li><a href="shop_orders.php" class="nav-link">Orders</a></li>
                <li><a href="messages.php" class="nav-link">Messages</a></li>
                <li><a href="payment_verifications.php" class="nav-link">Payments</a></li>
                <li><a href="earnings.php" class="nav-link">Earnings</a></li>
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
                    <h2>Shop Posting &amp; Content Management</h2>
                    <p class="text-muted">Plan, publish, and optimize shop content in one workspace.</p>
                </div>
                <span class="badge badge-primary"><i class="fas fa-pen-fancy"></i> Module 19</span>
            </div>
        </div>

        <div class="content-grid">
            <div class="card overview-card">
                <div class="card-header">
                    <h3><i class="fas fa-bullseye text-primary"></i> Purpose</h3>
                </div>
                <p class="text-muted mb-0">
                    Allows shops to publish branded content, attract new clients, and keep followers informed of
                    availability, promotions, and hiring needs.
                </p>
            </div>

            <div class="card types-card">
                <div class="card-header">
                    <h3><i class="fas fa-layer-group text-primary"></i> Post Types</h3>
                    <p class="text-muted">Choose the right format for every message.</p>
                </div>
                <div class="type-grid">
                    <?php foreach ($post_types as $type): ?>
                        <div class="type-item">
                            <div class="d-flex align-center mb-2">
                                <i class="<?php echo $type['icon']; ?> mr-2"></i>
                                <strong><?php echo $type['title']; ?></strong>
                            </div>
                            <p class="text-muted mb-0"><?php echo $type['detail']; ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card workflow-card">
                <div class="card-header">
                    <h3><i class="fas fa-route text-primary"></i> Content Workflow</h3>
                    <p class="text-muted">From concept to conversion.</p>
                </div>
                <div class="workflow-list">
                    <?php foreach ($workflow as $step): ?>
                        <div class="workflow-step">
                            <div class="d-flex align-center mb-2">
                                <i class="<?php echo $step['icon']; ?> mr-2"></i>
                                <strong><?php echo $step['title']; ?></strong>
                            </div>
                            <p class="text-muted mb-0"><?php echo $step['detail']; ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card schedule-card">
                <div class="card-header">
                    <h3><i class="fas fa-calendar-alt text-primary"></i> Scheduling Playbook</h3>
                    <p class="text-muted">Keep campaigns consistent with a publishing rhythm.</p>
                </div>
                <div class="schedule-list">
                    <?php foreach ($scheduling as $slot): ?>
                        <div class="schedule-item">
                            <div>
                                <strong><?php echo $slot['label']; ?></strong>
                                <p class="text-muted mb-0"><?php echo $slot['detail']; ?></p>
                            </div>
                            <span class="schedule-time"><?php echo $slot['time']; ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card automation-card">
                <div class="card-header">
                    <h3><i class="fas fa-robot text-primary"></i> Automation</h3>
                    <p class="text-muted">Reduce manual effort while keeping content fresh.</p>
                </div>
                <div class="automation-list">
                    <?php foreach ($automation as $item): ?>
                        <div class="automation-item">
                            <div class="d-flex align-center mb-2">
                                <i class="<?php echo $item['icon']; ?> mr-2"></i>
                                <strong><?php echo $item['title']; ?></strong>
                            </div>
                            <p class="text-muted mb-0"><?php echo $item['detail']; ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
