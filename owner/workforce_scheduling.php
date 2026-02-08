<?php
session_start();
require_once '../config/db.php';
require_role('owner');

$owner_id = $_SESSION['user']['id'];
$shop_stmt = $pdo->prepare("SELECT shop_name FROM shops WHERE owner_id = ?");
$shop_stmt->execute([$owner_id]);
$shop = $shop_stmt->fetch();

$core_steps = [
    [
        'title' => 'Capture job requirements',
        'detail' => 'Work orders inherit service type, quantity, and due dates from the order pipeline.',
    ],
    [
        'title' => 'Match skills and availability',
        'detail' => 'Assignments are filtered against staff availability windows and skill tags.',
    ],
    [
        'title' => 'Confirm schedule slot',
        'detail' => 'Selected staff receive scheduled dates and time blocks to reserve capacity.',
    ],
    [
        'title' => 'Monitor workload balance',
        'detail' => 'Leads review workloads to keep daily job counts within limits.',
    ],
];

$automation = [
    [
        'title' => 'Skill-based assignment',
        'detail' => 'Skill tags highlight the best-fit staff for embroidery styles, materials, and machines.',
        'icon' => 'fas fa-award',
    ],
    [
        'title' => 'Load balancing',
        'detail' => 'Capacity checks keep daily schedules within max active job limits.',
        'icon' => 'fas fa-scale-balanced',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Workforce Scheduling &amp; Assignment Module - Owner</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .workforce-grid {
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
                    <h2>Workforce Scheduling &amp; Assignment</h2>
                    <p class="text-muted">Assign jobs to the right staff while keeping production load balanced.</p>
                </div>
                <span class="badge badge-primary"><i class="fas fa-users-gear"></i> Module 13</span>
            </div>
        </div>

        <div class="workforce-grid">
            <div class="card overview-card">
                <div class="card-header">
                    <h3><i class="fas fa-bullseye text-primary"></i> Purpose</h3>
                </div>
                <p class="text-muted mb-0">
                    Assigns jobs to staff efficiently, ensuring each order is matched with the right skills and
                    scheduled within daily capacity limits.
                </p>
            </div>

            <div class="card process-card">
                <div class="card-header">
                    <h3><i class="fas fa-route text-primary"></i> Core Process</h3>
                    <p class="text-muted">From job intake to balanced schedules.</p>
                </div>
                <div class="flow-list">
                    <?php foreach ($core_steps as $index => $step): ?>
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
                    <p class="text-muted">Built-in support for smart assignments.</p>
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
