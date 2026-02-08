<?php
session_start();
require_once '../config/db.php';
require_role('client');

$client_id = $_SESSION['user']['id'];
$unread_notifications = fetch_unread_notification_count($pdo, $client_id);

$coreInputs = [
    [
        'title' => 'Design complexity profile',
        'detail' => 'Threads, stitch count, color changes, and placement drive the base rate.',
        'icon' => 'fas fa-layer-group',
    ],
    [
        'title' => 'Workload & quantity factors',
        'detail' => 'Rush flags, batch size, and material type adjust the pricing model.',
        'icon' => 'fas fa-boxes-stacked',
    ],
    [
        'title' => 'Service-level selections',
        'detail' => 'Add-ons like digitizing, patch backing, and finishing are quoted instantly.',
        'icon' => 'fas fa-sliders',
    ],
];

$automation = [
    [
        'title' => 'Complexity-based pricing',
        'detail' => 'Stitch density, color count, and size automatically scale the quote range.',
        'icon' => 'fas fa-calculator',
    ],
    [
        'title' => 'Time estimation',
        'detail' => 'Projected production hours update lead times and delivery expectations.',
        'icon' => 'fas fa-clock',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pricing &amp; Quotation Automation Module - Client</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .pricing-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 1.5rem;
            margin: 2rem 0;
        }

        .overview-card {
            grid-column: span 12;
        }

        .inputs-card {
            grid-column: span 7;
        }

        .automation-card {
            grid-column: span 5;
        }

        .input-list {
            display: grid;
            gap: 1rem;
        }

        .input-item {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 1rem;
            background: var(--bg-primary);
        }

        .input-item i {
            color: var(--primary-600);
        }

        .automation-item {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 1rem;
            background: white;
        }

        .automation-item i {
            color: var(--primary-600);
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
                        <a href="rate_provider.php" class="dropdown-item"><i class="fas fa-star"></i> Rate Provider</a>
                        <a href="search_discovery.php" class="dropdown-item"><i class="fas fa-compass"></i> Search &amp; Discovery</a>
                        <a href="design_proofing.php" class="dropdown-item"><i class="fas fa-clipboard-check"></i> Design Proofing &amp; Approval</a>
                        <a href="pricing_quotation.php" class="dropdown-item active"><i class="fas fa-calculator"></i> Pricing &amp; Quotation</a>
                        <a href="order_management.php" class="dropdown-item"><i class="fas fa-clipboard-list"></i> Order Management</a>
                        <a href="payment_handling.php" class="dropdown-item"><i class="fas fa-hand-holding-dollar"></i> Payment Handling &amp; Release</a>
                        <a href="client_posting_community.php" class="dropdown-item"><i class="fas fa-comments"></i> Client Posting &amp; Community</a>
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
                    <h2>Pricing &amp; Quotation Automation</h2>
                    <p class="text-muted">Generate accurate price ranges and timelines before production begins.</p>
                </div>
                <span class="badge badge-primary"><i class="fas fa-calculator"></i> Module 11</span>
            </div>
        </div>

        <div class="pricing-grid">
            <div class="card overview-card">
                <div class="card-header">
                    <h3><i class="fas fa-bullseye text-primary"></i> Purpose</h3>
                </div>
                <p class="text-muted mb-0">
                    Generates price estimates based on design complexity and workload inputs, ensuring clients receive
                    consistent quotes alongside clear turnaround expectations.
                </p>
            </div>

            <div class="card inputs-card">
                <div class="card-header">
                    <h3><i class="fas fa-list-check text-primary"></i> Quote Inputs</h3>
                    <p class="text-muted">Data points that shape the estimated price range.</p>
                </div>
                <div class="input-list">
                    <?php foreach ($coreInputs as $input): ?>
                        <div class="input-item">
                            <h4 class="d-flex align-center gap-2">
                                <i class="<?php echo $input['icon']; ?>"></i>
                                <?php echo $input['title']; ?>
                            </h4>
                            <p class="text-muted mb-0"><?php echo $input['detail']; ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card automation-card">
                <div class="card-header">
                    <h3><i class="fas fa-robot text-primary"></i> Automation</h3>
                    <p class="text-muted">Logic that keeps pricing and timing aligned.</p>
                </div>
                <div class="d-flex flex-column gap-3">
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
        </div>
    </div>
</body>
</html>
