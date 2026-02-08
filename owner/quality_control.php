<?php
session_start();
require_once '../config/db.php';
require_role('owner');

$owner_id = $_SESSION['user']['id'];
$shop_stmt = $pdo->prepare("SELECT shop_name FROM shops WHERE owner_id = ?");
$shop_stmt->execute([$owner_id]);
$shop = $shop_stmt->fetch();

$inspection_steps = [
    [
        'title' => 'Pre-stitch verification',
        'detail' => 'Confirm thread colors, design placement, and fabric specs match the approved proof.',
        'icon' => 'fas fa-file-circle-check',
    ],
    [
        'title' => 'In-line sampling',
        'detail' => 'Inspect first-off samples for tension, density, and registration before full runs.',
        'icon' => 'fas fa-magnifying-glass',
    ],
    [
        'title' => 'Finish & trim audit',
        'detail' => 'Check trims, backing, and edge finishes to ensure clean presentation.',
        'icon' => 'fas fa-scissors',
    ],
    [
        'title' => 'Final packaging review',
        'detail' => 'Verify count accuracy, labeling, and protective packaging before dispatch.',
        'icon' => 'fas fa-box-open',
    ],
];

$quality_metrics = [
    [
        'label' => 'Defect rate',
        'value' => '1.4%',
        'note' => 'Rolling 30-day production average.',
        'icon' => 'fas fa-chart-line',
        'tone' => 'success',
    ],
    [
        'label' => 'Rework queue',
        'value' => '3 jobs',
        'note' => 'Awaiting correction before shipment.',
        'icon' => 'fas fa-rotate-right',
        'tone' => 'warning',
    ],
    [
        'label' => 'QC pass rate',
        'value' => '98.6%',
        'note' => 'First-pass approvals today.',
        'icon' => 'fas fa-circle-check',
        'tone' => 'info',
    ],
];

$automation = [
    [
        'title' => 'Delivery lock until QC pass',
        'detail' => 'Jobs cannot be marked ready for pickup or delivery until QC approval is logged.',
        'icon' => 'fas fa-lock',
    ],
    [
        'title' => 'Auto-generated QC reports',
        'detail' => 'Inspection outcomes are summarized per batch for client visibility.',
        'icon' => 'fas fa-file-lines',
    ],
    [
        'title' => 'Exception alerts',
        'detail' => 'Supervisors are notified when defects exceed set thresholds.',
        'icon' => 'fas fa-bell',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quality Control Module - Owner</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .qc-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 1.5rem;
            margin: 2rem 0;
        }

        .overview-card {
            grid-column: span 12;
        }

        .inspection-card {
            grid-column: span 7;
        }

        .metrics-card {
            grid-column: span 5;
        }

        .automation-card {
            grid-column: span 12;
        }

        .inspection-item {
            display: flex;
            gap: 1rem;
            align-items: flex-start;
            padding: 1rem;
            border-radius: var(--radius);
            border: 1px solid var(--gray-200);
            background: var(--bg-primary);
        }

        .inspection-icon {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: var(--radius-full);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--primary-100);
            color: var(--primary-700);
            font-size: 1rem;
        }

        .inspection-list {
            display: grid;
            gap: 1rem;
        }

        .metric-item {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 1rem;
            background: white;
        }

        .metric-item i {
            color: var(--primary-600);
        }

        .metric-value {
            font-size: 1.5rem;
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
                    <h2>Quality Control</h2>
                    <p class="text-muted">Standardize inspections so every delivery meets client expectations.</p>
                </div>
                <span class="badge badge-primary"><i class="fas fa-shield-check"></i> Module 15</span>
            </div>
        </div>

        <div class="qc-grid">
            <div class="card overview-card">
                <div class="card-header">
                    <h3><i class="fas fa-bullseye text-primary"></i> Purpose</h3>
                </div>
                <p class="text-muted mb-0">
                    Ensures output meets quality standards, locking delivery actions until inspection criteria are
                    satisfied and recorded.
                </p>
            </div>

            <div class="card inspection-card">
                <div class="card-header">
                    <h3><i class="fas fa-clipboard-check text-primary"></i> Inspection Workflow</h3>
                    <p class="text-muted">Standardized checkpoints for every embroidery batch.</p>
                </div>
                <div class="inspection-list">
                    <?php foreach ($inspection_steps as $step): ?>
                        <div class="inspection-item">
                            <span class="inspection-icon"><i class="<?php echo $step['icon']; ?>"></i></span>
                            <div>
                                <strong><?php echo $step['title']; ?></strong>
                                <p class="text-muted mb-0"><?php echo $step['detail']; ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card metrics-card">
                <div class="card-header">
                    <h3><i class="fas fa-chart-pie text-primary"></i> Quality Metrics</h3>
                    <p class="text-muted">Live view of inspection performance.</p>
                </div>
                <div class="d-flex flex-column gap-3">
                    <?php foreach ($quality_metrics as $metric): ?>
                        <div class="metric-item">
                            <div class="d-flex align-center gap-2">
                                <i class="<?php echo $metric['icon']; ?>"></i>
                                <strong><?php echo $metric['label']; ?></strong>
                            </div>
                            <div class="metric-value text-<?php echo $metric['tone']; ?>">
                                <?php echo $metric['value']; ?>
                            </div>
                            <p class="text-muted mb-0"><?php echo $metric['note']; ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card automation-card">
                <div class="card-header">
                    <h3><i class="fas fa-robot text-primary"></i> Automation</h3>
                    <p class="text-muted">Guardrails that keep quality approvals consistent.</p>
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
        </div>
    </div>
</body>
</html>
