<?php
session_start();
require_once '../config/db.php';
require_role('client');

$client_id = $_SESSION['user']['id'];
$unread_notifications = fetch_unread_notification_count($pdo, $client_id);

$coreFlow = [
    [
        'title' => 'Client order placed',
        'detail' => 'Order details, artwork, and requirements are submitted to the shop.',
    ],
    [
        'title' => 'Review & confirmation',
        'detail' => 'Shop reviews specs, confirms pricing, and validates production readiness.',
    ],
    [
        'title' => 'Production in progress',
        'detail' => 'Jobs are scheduled, stitched, and quality-checked before packaging.',
    ],
    [
        'title' => 'Completion & delivery',
        'detail' => 'Finished orders are marked complete and handed off for pickup or delivery.',
    ],
];

$automation = [
    [
        'title' => 'Status progression updates',
        'detail' => 'Automatic notifications keep clients aware of every stage shift.',
        'icon' => 'fas fa-signal',
    ],
    [
        'title' => 'Stall alerts',
        'detail' => 'Escalations trigger when orders linger too long in a step.',
        'icon' => 'fas fa-triangle-exclamation',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Management Module - Client</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .order-grid {
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

        .automation-card {
            grid-column: span 5;
        }

        .flow-step {
            display: flex;
            gap: 1rem;
            align-items: flex-start;
            padding: 1rem;
            border-radius: var(--radius);
            border: 1px solid var(--gray-200);
            background: var(--bg-primary);
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

        .flow-list {
            display: grid;
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
    <?php require_once __DIR__ . '/includes/customer_navbar.php'; ?>

    <div class="container">
        <div class="dashboard-header fade-in">
            <div class="d-flex justify-between align-center">
                <div>
                    <h2>Order Management</h2>
                    <p class="text-muted">Track every order from placement to delivery-ready completion.</p>
                </div>
                <span class="badge badge-primary"><i class="fas fa-clipboard-list"></i> Module 10</span>
            </div>
        </div>

        <div class="order-grid">
            <div class="card overview-card">
                <div class="card-header">
                    <h3><i class="fas fa-bullseye text-primary"></i> Purpose</h3>
                </div>
                <p class="text-muted mb-0">
                    Tracks embroidery orders from placement through review, production, and completion
                    with clear visibility into every milestone.
                </p>
            </div>

            <div class="card process-card">
                <div class="card-header">
                    <h3><i class="fas fa-route text-primary"></i> Core Flow</h3>
                    <p class="text-muted">Client order through completion.</p>
                </div>
                <div class="flow-list">
                    <?php foreach ($coreFlow as $index => $step): ?>
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
                    <p class="text-muted">Notifications and safeguards for every stage.</p>
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
