<?php
session_start();
require_once '../config/db.php';
require_role('owner');

$owner_id = $_SESSION['user']['id'];
$shop_stmt = $pdo->prepare("SELECT id, shop_name FROM shops WHERE owner_id = ?");
$shop_stmt->execute([$owner_id]);
$shop = $shop_stmt->fetch();

if (!$shop) {
    die('No shop assigned to this owner. Please contact support.');
}

$shop_id = (int) $shop['id'];
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'log_finished_good') {
        $order_id = (int) ($_POST['order_id'] ?? 0);
        $location_id = (int) ($_POST['storage_location_id'] ?? 0);
        $status = $_POST['status'] ?? 'stored';

        if ($order_id <= 0 || $location_id <= 0) {
            $error = 'Please select a completed order and storage location.';
        } else {
            $order_stmt = $pdo->prepare("
                SELECT id, status
                FROM orders
                WHERE id = ? AND shop_id = ?
                LIMIT 1
            ");
            $order_stmt->execute([$order_id, $shop_id]);
            $order = $order_stmt->fetch();

            if (!$order || $order['status'] !== 'completed') {
                $error = 'Only completed orders can be logged in finished goods.';
            } else {
                $existing_stmt = $pdo->prepare("SELECT id FROM finished_goods WHERE order_id = ?");
                $existing_stmt->execute([$order_id]);
                $existing = $existing_stmt->fetchColumn();

                if ($existing) {
                    $error = 'This order is already logged in finished goods.';
                } else {
                    $insert_stmt = $pdo->prepare("
                        INSERT INTO finished_goods (order_id, shop_id, storage_location_id, status, stored_at)
                        VALUES (?, ?, ?, ?, NOW())
                    ");
                    $insert_stmt->execute([$order_id, $shop_id, $location_id, $status]);
                    $success = 'Finished goods record created successfully.';
                }
            }
        }
    }

    if ($action === 'update_finished_status') {
        $finished_id = (int) ($_POST['finished_id'] ?? 0);
        $status = $_POST['status'] ?? '';

        if ($finished_id <= 0 || $status === '') {
            $error = 'Please select a valid finished goods record.';
        } else {
            $update_stmt = $pdo->prepare("
                UPDATE finished_goods
                SET status = ?, released_at = CASE WHEN ? = 'released' THEN NOW() ELSE released_at END
                WHERE id = ? AND shop_id = ?
            ");
            $update_stmt->execute([$status, $status, $finished_id, $shop_id]);
            $success = 'Finished goods status updated.';
        }
    }
}

$auto_release_stmt = $pdo->prepare("
    UPDATE finished_goods fg
    JOIN orders o ON o.id = fg.order_id
    JOIN order_fulfillments ofl ON ofl.order_id = o.id
    SET fg.status = 'released', fg.released_at = NOW()
    WHERE fg.shop_id = ? AND fg.status <> 'released'
      AND ofl.status IN ('delivered','claimed')
");
$auto_release_stmt->execute([$shop_id]);

$locations_stmt = $pdo->prepare("SELECT id, code FROM storage_locations WHERE shop_id = ? ORDER BY code");
$locations_stmt->execute([$shop_id]);
$storage_locations = $locations_stmt->fetchAll();

$available_orders_stmt = $pdo->prepare("
    SELECT o.id, o.order_number
    FROM orders o
    LEFT JOIN finished_goods fg ON fg.order_id = o.id
    WHERE o.shop_id = ? AND o.status = 'completed' AND fg.id IS NULL
    ORDER BY o.completed_at DESC
");
$available_orders_stmt->execute([$shop_id]);
$available_orders = $available_orders_stmt->fetchAll();

$finished_stmt = $pdo->prepare("
    SELECT fg.*, o.order_number, o.quantity, o.payment_status, u.fullname as client_name,
           sl.code as location_code, ofl.fulfillment_type, ofl.status as fulfillment_status
    FROM finished_goods fg
    JOIN orders o ON o.id = fg.order_id
    JOIN users u ON u.id = o.client_id
    LEFT JOIN storage_locations sl ON sl.id = fg.storage_location_id
    LEFT JOIN order_fulfillments ofl ON ofl.order_id = o.id
    WHERE fg.shop_id = ?
    ORDER BY fg.stored_at DESC
");
$finished_stmt->execute([$shop_id]);
$finished_orders = $finished_stmt->fetchAll();

$zones_stmt = $pdo->prepare("
    SELECT sl.code,
           COUNT(fg.id) as stored_count
    FROM storage_locations sl
    LEFT JOIN finished_goods fg ON fg.storage_location_id = sl.id AND fg.status <> 'released'
    WHERE sl.shop_id = ?
    GROUP BY sl.id
    ORDER BY sl.code
");
$zones_stmt->execute([$shop_id]);
$storage_zones = $zones_stmt->fetchAll();

$release_stmt = $pdo->prepare("
    SELECT fg.id, o.order_number, ofl.fulfillment_type, ofl.status, fg.stored_at
    FROM finished_goods fg
    JOIN orders o ON o.id = fg.order_id
    LEFT JOIN order_fulfillments ofl ON ofl.order_id = o.id
    WHERE fg.shop_id = ? AND fg.status IN ('stored','ready')
    ORDER BY fg.stored_at ASC
    LIMIT 5
");
$release_stmt->execute([$shop_id]);
$release_schedule = $release_stmt->fetchAll();

$staging_count = 0;
$ready_count = 0;
$pickup_count = 0;
$aging_count = 0;
foreach ($finished_orders as $order) {
    if (in_array($order['status'], ['stored', 'ready'], true)) {
        $staging_count++;
    }
    if ($order['status'] === 'ready') {
        $ready_count++;
    }
    if (($order['fulfillment_type'] ?? '') === 'pickup' && $order['status'] !== 'released') {
        $pickup_count++;
    }
    if ($order['status'] !== 'released' && $order['stored_at'] && strtotime($order['stored_at']) <= strtotime('-48 hours')) {
        $aging_count++;
    }
}

$storage_kpis = [
    [
        'label' => 'Orders in staging',
        'value' => $staging_count,
        'note' => 'Awaiting pickup or delivery.',
        'icon' => 'fas fa-box-open',
        'tone' => 'primary',
    ],
    [
        'label' => 'Ready for dispatch',
        'value' => $ready_count,
        'note' => 'Packed and labeled.',
        'icon' => 'fas fa-truck-fast',
        'tone' => 'success',
    ],
    [
        'label' => 'Pending client pickup',
        'value' => $pickup_count,
        'note' => 'Scheduled for pickup.',
        'icon' => 'fas fa-hand-holding',
        'tone' => 'info',
    ],
    [
        'label' => 'Aging over 48 hrs',
        'value' => $aging_count,
        'note' => 'Needs follow-up.',
        'icon' => 'fas fa-clock',
        'tone' => 'warning',
    ],
];

$automation_rules = [
    [
        'title' => 'QC pass registration',
        'detail' => 'Register completed orders into Finished Goods storage after QC approval.',
        'icon' => 'fas fa-clipboard-check',
    ],
    [
        'title' => 'Pickup notification',
        'detail' => 'Send pickup-ready alerts to clients once orders are shelved.',
        'icon' => 'fas fa-bell',
    ],
    [
        'title' => 'Dispatch manifest',
        'detail' => 'Build delivery manifests with location labels and route grouping.',
        'icon' => 'fas fa-route',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Finished Goods &amp; Order Storage Module - Owner</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .storage-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 1.5rem;
            margin: 2rem 0;
        }

        .storage-kpi {
            grid-column: span 3;
        }

        .purpose-card,
        .schedule-card {
            grid-column: span 12;
        }

        .orders-card {
            grid-column: span 8;
        }

        .form-card {
            grid-column: span 4;
        }

        .zones-card,
        .automation-card {
            grid-column: span 4;
        }

        .kpi-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }

        .kpi-item i {
            font-size: 1.5rem;
        }

        .schedule-item {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 1rem;
            background: white;
        }

        .schedule-item + .schedule-item {
            margin-top: 1rem;
        }

        .automation-item {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 1rem;
            background: white;
        }

        .automation-item + .automation-item {
            margin-top: 1rem;
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
                    <h2>Finished Goods &amp; Order Storage</h2>
                    <p class="text-muted">Track completed orders, assign storage locations, and coordinate pickup or delivery handoffs.</p>
                </div>
                <span class="badge badge-primary"><i class="fas fa-warehouse"></i> Module 25</span>
            </div>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="storage-grid">
            <div class="card purpose-card">
                <div class="card-header">
                    <h3><i class="fas fa-bullseye text-primary"></i> Purpose</h3>
                </div>
                <p class="text-muted mb-0">
                    Stores completed orders awaiting delivery or pickup, keeping finished goods organized, labeled, and
                    dispatch-ready.
                </p>
            </div>

            <?php foreach ($storage_kpis as $kpi): ?>
                <div class="card storage-kpi">
                    <div class="kpi-item">
                        <div>
                            <p class="text-muted mb-1"><?php echo $kpi['label']; ?></p>
                            <h3 class="mb-1"><?php echo $kpi['value']; ?></h3>
                            <small class="text-muted"><?php echo $kpi['note']; ?></small>
                        </div>
                        <span class="badge badge-<?php echo $kpi['tone']; ?>">
                            <i class="<?php echo $kpi['icon']; ?>"></i>
                        </span>
                    </div>
                </div>
            <?php endforeach; ?>

            <div class="card form-card">
                <div class="card-header">
                    <h3><i class="fas fa-clipboard-check text-primary"></i> Log Finished Goods</h3>
                    <p class="text-muted">Register QC-approved orders into storage.</p>
                </div>
                <form method="POST">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="log_finished_good">
                    <div class="form-group">
                        <label>Completed order</label>
                        <select name="order_id" required>
                            <option value="">Select order</option>
                            <?php foreach ($available_orders as $order): ?>
                                <option value="<?php echo (int) $order['id']; ?>">#<?php echo htmlspecialchars($order['order_number']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Storage location</label>
                        <select name="storage_location_id" required>
                            <option value="">Select location</option>
                            <?php foreach ($storage_locations as $location): ?>
                                <option value="<?php echo (int) $location['id']; ?>"><?php echo htmlspecialchars($location['code']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status">
                            <option value="stored">Stored</option>
                            <option value="ready">Ready</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">Log Finished Goods</button>
                </form>
            </div>

            <div class="card orders-card">
                <div class="card-header">
                    <h3><i class="fas fa-boxes-packing text-primary"></i> Finished Goods Queue</h3>
                    <p class="text-muted">Orders in storage with location and release channel.</p>
                </div>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Client</th>
                            <th>Channel</th>
                            <th>Items</th>
                            <th>Location</th>
                            <th>Status</th>
                            <th>Hold</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($finished_orders)): ?>
                            <tr>
                                <td colspan="8" class="text-muted">No finished goods logged yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($finished_orders as $order): ?>
                                <?php $payment_hold = payment_hold_status(STATUS_COMPLETED, $order['payment_status'] ?? 'unpaid'); ?>
                                <tr>
                                    <td>#<?php echo htmlspecialchars($order['order_number']); ?></td>
                                    <td><?php echo htmlspecialchars($order['client_name']); ?></td>
                                    <td><?php echo htmlspecialchars(ucfirst($order['fulfillment_type'] ?? 'pickup')); ?></td>
                                    <td><?php echo htmlspecialchars($order['quantity']); ?></td>
                                    <td><?php echo htmlspecialchars($order['location_code'] ?? 'Unassigned'); ?></td>
                                    <td><?php echo htmlspecialchars(ucfirst($order['status'])); ?></td>
                                    <td>
                                        <span class="hold-pill <?php echo htmlspecialchars($payment_hold['class']); ?>">
                                            <?php echo htmlspecialchars($payment_hold['label']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <form method="POST">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="update_finished_status">
                                            <input type="hidden" name="finished_id" value="<?php echo (int) $order['id']; ?>">
                                            <select name="status" onchange="this.form.submit()">
                                                <?php
                                                $status_options = ['stored' => 'Stored', 'ready' => 'Ready', 'released' => 'Released'];
                                                ?>
                                                <?php foreach ($status_options as $value => $label): ?>
                                                    <option value="<?php echo $value; ?>" <?php echo $order['status'] === $value ? 'selected' : ''; ?>>
                                                        <?php echo $label; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="card zones-card">
                <div class="card-header">
                    <h3><i class="fas fa-map-location-dot text-primary"></i> Storage Zones</h3>
                    <p class="text-muted">Finished goods bins with utilization status.</p>
                </div>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Zone</th>
                            <th>Orders stored</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($storage_zones)): ?>
                            <tr>
                                <td colspan="2" class="text-muted">No storage locations configured.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($storage_zones as $zone): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($zone['code']); ?></td>
                                    <td><?php echo (int) $zone['stored_count']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="card automation-card">
                <div class="card-header">
                    <h3><i class="fas fa-gear text-primary"></i> Automation</h3>
                    <p class="text-muted">Rules that keep handoffs and notifications synchronized.</p>
                </div>
                <?php foreach ($automation_rules as $rule): ?>
                    <div class="automation-item">
                        <div class="d-flex align-center gap-2 mb-2">
                            <i class="<?php echo $rule['icon']; ?> text-primary"></i>
                            <strong><?php echo $rule['title']; ?></strong>
                        </div>
                        <p class="text-muted mb-0"><?php echo $rule['detail']; ?></p>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="card schedule-card">
                <div class="card-header">
                    <h3><i class="fas fa-calendar-check text-primary"></i> Release Schedule</h3>
                    <p class="text-muted">Upcoming pickup and delivery windows.</p>
                </div>
                <?php if (empty($release_schedule)): ?>
                    <p class="text-muted">No orders queued for release.</p>
                <?php else: ?>
                    <?php foreach ($release_schedule as $slot): ?>
                        <div class="schedule-item">
                            <div class="d-flex justify-between align-center mb-2">
                                <strong>Order #<?php echo htmlspecialchars($slot['order_number']); ?></strong>
                                <span class="badge badge-secondary"><?php echo htmlspecialchars(ucfirst($slot['fulfillment_type'] ?? 'pickup')); ?></span>
                            </div>
                            <p class="text-muted mb-1">Status: <?php echo htmlspecialchars(ucfirst($slot['status'] ?? 'pending')); ?></p>
                            <small class="text-muted">Stored: <?php echo date('M d, Y', strtotime($slot['stored_at'])); ?></small>
                        </div>
                        <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
