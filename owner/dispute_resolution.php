<?php
session_start();
require_once '../config/db.php';
require_role('owner');

$owner_id = $_SESSION['user']['id'];
$shop_stmt = $pdo->prepare("SELECT shop_name FROM shops WHERE owner_id = ?");
$shop_stmt->execute([$owner_id]);
$shop = $shop_stmt->fetch();

$dispute_flow = [
    [
        'title' => 'Complaint intake',
        'detail' => 'Capture issue type, order details, supporting photos, and timestamps in one case record.',
    ],
    [
        'title' => 'Eligibility review',
        'detail' => 'Confirm service level, delivery date, and policy coverage before proposing outcomes.',
    ],
    [
        'title' => 'Resolution proposal',
        'detail' => 'Offer repair, remake, partial credit, or refund based on severity and evidence.',
    ],
    [
        'title' => 'Settlement & closure',
        'detail' => 'Log acceptance, trigger fulfillment or refund, and close the case with notes.',
    ],
];

$refund_matrix = [
    [
        'label' => 'Minor defect',
        'value' => '10-20% credit',
        'note' => 'Small stitching issues without usability impact.',
        'icon' => 'fas fa-stitch',
        'tone' => 'info',
    ],
    [
        'label' => 'Moderate defect',
        'value' => '30-50% refund',
        'note' => 'Visible issues requiring client rework.',
        'icon' => 'fas fa-circle-exclamation',
        'tone' => 'warning',
    ],
    [
        'label' => 'Critical defect',
        'value' => '100% refund',
        'note' => 'Order unusable or incorrect fulfillment.',
        'icon' => 'fas fa-triangle-exclamation',
        'tone' => 'danger',
    ],
];

$automation = [
    [
        'title' => 'Deadline reminders',
        'detail' => 'Automated nudges keep owners and clients aligned on response and resolution windows.',
        'icon' => 'fas fa-bell',
    ],
    [
        'title' => 'Refund calculations',
        'detail' => 'Policy-based refund suggestions compute credits by defect severity and order value.',
        'icon' => 'fas fa-calculator',
    ],
    [
        'title' => 'Audit trail logs',
        'detail' => 'Every status change and message is saved for compliance and review.',
        'icon' => 'fas fa-file-shield',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dispute &amp; Resolution Module - Owner</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .dispute-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 1.5rem;
            margin: 2rem 0;
        }

        .overview-card {
            grid-column: span 12;
        }

        .process-card {
            grid-column: span 7;
        }

        .refund-card {
            grid-column: span 5;
        }

        .automation-card {
            grid-column: span 12;
        }

        .process-step {
            display: flex;
            gap: 1rem;
            align-items: flex-start;
            padding: 1rem;
            border-radius: var(--radius);
            border: 1px solid var(--gray-200);
            background: var(--bg-primary);
        }

        .process-step .badge {
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

        .process-list {
            display: grid;
            gap: 1rem;
        }

        .refund-item {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 1rem;
            background: white;
        }

        .refund-item i {
            color: var(--primary-600);
        }

        .refund-value {
            font-size: 1.35rem;
            font-weight: 700;
            margin: 0.25rem 0;
        }

        .automation-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1rem;
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
                    <h2>Dispute &amp; Resolution</h2>
                    <p class="text-muted">Manage complaints and refunds with clear service-level expectations.</p>
                </div>
                <span class="badge badge-primary"><i class="fas fa-scale-balanced"></i> Module 18</span>
            </div>
        </div>

        <div class="dispute-grid">
            <div class="card overview-card">
                <div class="card-header">
                    <h3><i class="fas fa-bullseye text-primary"></i> Purpose</h3>
                </div>
                <p class="text-muted mb-0">
                    Centralizes complaint handling, ensures consistent refund policies, and documents every step
                    until closure.
                </p>
            </div>

            <div class="card process-card">
                <div class="card-header">
                    <h3><i class="fas fa-route text-primary"></i> Resolution Flow</h3>
                    <p class="text-muted">Structured steps from intake to closure.</p>
                </div>
                <div class="process-list">
                    <?php foreach ($dispute_flow as $index => $step): ?>
                        <div class="process-step">
                            <span class="badge"><?php echo $index + 1; ?></span>
                            <div>
                                <strong><?php echo $step['title']; ?></strong>
                                <p class="text-muted mb-0"><?php echo $step['detail']; ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card refund-card">
                <div class="card-header">
                    <h3><i class="fas fa-hand-holding-dollar text-primary"></i> Refund Matrix</h3>
                    <p class="text-muted">Policy-guided refund ranges by defect severity.</p>
                </div>
                <div class="process-list">
                    <?php foreach ($refund_matrix as $item): ?>
                        <div class="refund-item">
                            <div class="d-flex justify-between align-center">
                                <div>
                                    <strong><?php echo $item['label']; ?></strong>
                                    <div class="refund-value"><?php echo $item['value']; ?></div>
                                </div>
                                <span class="badge badge-<?php echo $item['tone']; ?>">
                                    <i class="<?php echo $item['icon']; ?>"></i>
                                </span>
                            </div>
                            <p class="text-muted mb-0"><?php echo $item['note']; ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card automation-card">
                <div class="card-header">
                    <h3><i class="fas fa-robot text-primary"></i> Automation</h3>
                    <p class="text-muted">Reminders, calculations, and compliance tracking.</p>
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
