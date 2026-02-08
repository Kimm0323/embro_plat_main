<?php
session_start();
require_once '../config/db.php';
require_role('client');

$client_id = $_SESSION['user']['id'];
$unread_notifications = fetch_unread_notification_count($pdo, $client_id);

$post_channels = [
    [
        'title' => 'Request posts',
        'detail' => 'Share upcoming embroidery needs, quantities, and target delivery windows.',
        'icon' => 'fas fa-bullhorn',
    ],
    [
        'title' => 'Inspiration boards',
        'detail' => 'Collect artwork references, palettes, and stitch styles in one thread.',
        'icon' => 'fas fa-palette',
    ],
    [
        'title' => 'Community questions',
        'detail' => 'Ask for advice on fabrics, sizing, or digitizing best practices.',
        'icon' => 'fas fa-circle-question',
    ],
    [
        'title' => 'Collaboration calls',
        'detail' => 'Invite shops to co-create sample runs or limited collections.',
        'icon' => 'fas fa-handshake',
    ],
];

$community_flow = [
    [
        'title' => 'Create a post',
        'detail' => 'Describe the project goals, budget range, and preferred turnaround.',
    ],
    [
        'title' => 'Gather feedback',
        'detail' => 'Receive suggestions, availability notes, and alternative materials.',
    ],
    [
        'title' => 'Shortlist shops',
        'detail' => 'Pin replies, compare offers, and start private conversations.',
    ],
    [
        'title' => 'Convert to order',
        'detail' => 'Launch a draft order once the plan and timeline are confirmed.',
    ],
];

$automation = [
    [
        'title' => 'Order draft generation',
        'detail' => 'Request posts prefill order drafts with sizing, quantity, and timeline fields.',
        'icon' => 'fas fa-file-pen',
    ],
    [
        'title' => 'Demand pattern analysis',
        'detail' => 'Aggregate tags and volumes to reveal trending styles and peak request windows.',
        'icon' => 'fas fa-chart-line',
    ],
];

$insight_cards = [
    [
        'label' => 'Trending request tags',
        'value' => 'Hoodie embroidery, varsity patches, eco threads',
    ],
    [
        'label' => 'Average response time',
        'value' => '2-4 hours from verified shops',
    ],
    [
        'label' => 'Top inspiration sources',
        'value' => 'Brand kits, product mockups, fabric swatches',
    ],
];
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

        .channels-card {
            grid-column: span 7;
        }

        .flow-card {
            grid-column: span 5;
        }

        .automation-card {
            grid-column: span 6;
        }

        .insights-card {
            grid-column: span 6;
        }

        .channel-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .channel-item,
        .flow-step,
        .automation-item,
        .insight-item {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 1rem;
            background: var(--bg-primary);
        }

        .channel-item i,
        .automation-item i {
            color: var(--primary-600);
        }

        .flow-list,
        .automation-list,
        .insight-list {
            display: grid;
            gap: 1rem;
        }

        .flow-step {
            display: flex;
            gap: 1rem;
            align-items: flex-start;
        }

        .flow-step .badge {
            width: 2rem;
            height: 2rem;
            border-radius: var(--radius-full);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            background: var(--primary-100);
            color: var(--primary-700);
        }

        .insight-label {
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--gray-500);
        }

        .insight-value {
            font-weight: 600;
            margin-top: 0.35rem;
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
                <li><a href="dashboard.php" class="nav-link">Dashboard</a></li>
                <li class="dropdown">
                    <a href="#" class="nav-link dropdown-toggle">
                        <i class="fas fa-clipboard-list"></i> Orders
                    </a>
                    <div class="dropdown-menu">
                        <a href="place_order.php" class="dropdown-item"><i class="fas fa-plus-circle"></i> Place Order</a>
                        <a href="track_order.php" class="dropdown-item"><i class="fas fa-route"></i> Track Orders</a>
                    </div>
                </li>
                <li class="dropdown">
                    <a href="#" class="nav-link dropdown-toggle active">
                        <i class="fas fa-layer-group"></i> Services
                    </a>
                    <div class="dropdown-menu">
                        <a href="customize_design.php" class="dropdown-item"><i class="fas fa-paint-brush"></i> Customize Design</a>
                        <a href="design_editor.php" class="dropdown-item"><i class="fas fa-pencil-ruler"></i> Design Editor</a>
                        <a href="rate_provider.php" class="dropdown-item"><i class="fas fa-star"></i> Rate Provider</a>
                        <a href="search_discovery.php" class="dropdown-item"><i class="fas fa-compass"></i> Search &amp; Discovery</a>
                        <a href="design_proofing.php" class="dropdown-item"><i class="fas fa-clipboard-check"></i> Design Proofing &amp; Approval</a>
                        <a href="pricing_quotation.php" class="dropdown-item"><i class="fas fa-calculator"></i> Pricing &amp; Quotation</a>
                        <a href="order_management.php" class="dropdown-item"><i class="fas fa-clipboard-list"></i> Order Management</a>
                        <a href="payment_handling.php" class="dropdown-item"><i class="fas fa-hand-holding-dollar"></i> Payment Handling &amp; Release</a>
                        <a href="client_posting_community.php" class="dropdown-item active"><i class="fas fa-comments"></i> Client Posting &amp; Community</a>
                    </div>
                </li>
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
        <div class="dashboard-header fade-in">
            <div class="d-flex justify-between align-center">
                <div>
                    <h2>Client Posting &amp; Community Interaction</h2>
                    <p class="text-muted">Share inspiration, gather feedback, and turn conversations into orders.</p>
                </div>
                <span class="badge badge-primary"><i class="fas fa-comments"></i> Module 20</span>
            </div>
        </div>

        <div class="community-grid">
            <div class="card overview-card">
                <div class="card-header">
                    <h3><i class="fas fa-bullseye text-primary"></i> Purpose</h3>
                </div>
                <p class="text-muted mb-0">
                    Allows clients to share requests and inspirations with the community, capture expert feedback,
                    and build momentum before formalizing embroidery orders.
                </p>
            </div>

            <div class="card channels-card">
                <div class="card-header">
                    <h3><i class="fas fa-layer-group text-primary"></i> Posting Channels</h3>
                    <p class="text-muted">Keep requests and inspiration visible to the right audiences.</p>
                </div>
                <div class="channel-grid">
                    <?php foreach ($post_channels as $channel): ?>
                        <div class="channel-item">
                            <div class="d-flex align-center mb-2">
                                <i class="<?php echo $channel['icon']; ?> mr-2"></i>
                                <strong><?php echo $channel['title']; ?></strong>
                            </div>
                            <p class="text-muted mb-0"><?php echo $channel['detail']; ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card flow-card">
                <div class="card-header">
                    <h3><i class="fas fa-route text-primary"></i> Community Flow</h3>
                    <p class="text-muted">From post to confirmed order.</p>
                </div>
                <div class="flow-list">
                    <?php foreach ($community_flow as $index => $step): ?>
                        <div class="flow-step">
                            <span class="badge"><?php echo $index + 1; ?></span>
                            <div>
                                <strong><?php echo $step['title']; ?></strong>
                                <p class="text-muted mb-0"><?php echo $step['detail']; ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card automation-card">
                <div class="card-header">
                    <h3><i class="fas fa-robot text-primary"></i> Automation</h3>
                    <p class="text-muted">Reduce manual work and reveal new demand signals.</p>
                </div>
                <div class="automation-list">
                    <?php foreach ($automation as $rule): ?>
                        <div class="automation-item">
                            <h4 class="d-flex align-center gap-2">
                                <i class="<?php echo $rule['icon']; ?>"></i>
                                <?php echo $rule['title']; ?>
                            </h4>
                            <p class="text-muted mb-0"><?php echo $rule['detail']; ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card insights-card">
                <div class="card-header">
                    <h3><i class="fas fa-chart-pie text-primary"></i> Demand Snapshot</h3>
                    <p class="text-muted">Community trends surfaced from recent posts.</p>
                </div>
                <div class="insight-list">
                    <?php foreach ($insight_cards as $insight): ?>
                        <div class="insight-item">
                            <div class="insight-label"><?php echo $insight['label']; ?></div>
                            <div class="insight-value"><?php echo $insight['value']; ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
