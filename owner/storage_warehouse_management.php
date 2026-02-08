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
$editing_location = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_location') {
        $code = trim($_POST['code'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if ($code === '') {
            $error = 'Location code is required.';
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO storage_locations (shop_id, code, description)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([
                $shop_id,
                $code,
                $description !== '' ? $description : null,
            ]);
            $success = 'Storage location added successfully.';
        }
    }

    if ($action === 'update_location') {
        $location_id = (int) ($_POST['location_id'] ?? 0);
        $code = trim($_POST['code'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if ($location_id <= 0 || $code === '') {
            $error = 'Please provide valid location details.';
        } else {
            $stmt = $pdo->prepare("
                UPDATE storage_locations
                SET code = ?, description = ?
                WHERE id = ? AND shop_id = ?
            ");
            $stmt->execute([
                $code,
                $description !== '' ? $description : null,
                $location_id,
                $shop_id,
            ]);
            $success = 'Storage location updated successfully.';
        }
    }

    if ($action === 'delete_location') {
        $location_id = (int) ($_POST['location_id'] ?? 0);
        if ($location_id > 0) {
            $stmt = $pdo->prepare("DELETE FROM storage_locations WHERE id = ? AND shop_id = ?");
            $stmt->execute([$location_id, $shop_id]);
            $success = 'Storage location removed successfully.';
        } else {
            $error = 'Unable to delete the selected location.';
        }
    }

    if ($action === 'move_stock') {
        $material_id = (int) ($_POST['material_id'] ?? 0);
        $from_location = (int) ($_POST['from_location_id'] ?? 0);
        $to_location = (int) ($_POST['to_location_id'] ?? 0);
        $qty = $_POST['qty'] ?? '';

        if ($material_id <= 0 || $to_location <= 0 || $qty === '' || !is_numeric($qty) || (float) $qty <= 0) {
            $error = 'Please provide valid stock movement details.';
        } elseif ($from_location === $to_location) {
            $error = 'The source and destination locations must be different.';
        } else {
            try {
                $pdo->beginTransaction();

                if ($from_location > 0) {
                    $from_stmt = $pdo->prepare("
                        SELECT id, qty FROM stock_placements
                        WHERE location_id = ? AND material_id = ?
                        FOR UPDATE
                    ");
                    $from_stmt->execute([$from_location, $material_id]);
                    $from_row = $from_stmt->fetch();

                    if (!$from_row || (float) $from_row['qty'] < (float) $qty) {
                        $pdo->rollBack();
                        $error = 'Insufficient stock in the source location.';
                    } else {
                        $remaining = (float) $from_row['qty'] - (float) $qty;
                        if ($remaining > 0) {
                            $update_from = $pdo->prepare("UPDATE stock_placements SET qty = ? WHERE id = ?");
                            $update_from->execute([$remaining, $from_row['id']]);
                        } else {
                            $delete_from = $pdo->prepare("DELETE FROM stock_placements WHERE id = ?");
                            $delete_from->execute([$from_row['id']]);
                        }
                    }
                }

                if (!$error) {
                    $to_stmt = $pdo->prepare("
                        SELECT id, qty FROM stock_placements
                        WHERE location_id = ? AND material_id = ?
                        FOR UPDATE
                    ");
                    $to_stmt->execute([$to_location, $material_id]);
                    $to_row = $to_stmt->fetch();

                    if ($to_row) {
                        $update_to = $pdo->prepare("UPDATE stock_placements SET qty = ? WHERE id = ?");
                        $update_to->execute([(float) $to_row['qty'] + (float) $qty, $to_row['id']]);
                    } else {
                        $insert_to = $pdo->prepare("
                            INSERT INTO stock_placements (location_id, material_id, qty)
                            VALUES (?, ?, ?)
                        ");
                        $insert_to->execute([$to_location, $material_id, $qty]);
                    }

                    $log_stmt = $pdo->prepare("
                        INSERT INTO inventory_transactions (shop_id, material_id, type, qty, ref_type, ref_id)
                        VALUES (?, ?, 'move', ?, 'stock_placement', ?)
                    ");
                    $log_stmt->execute([$shop_id, $material_id, $qty, $to_location]);

                    $pdo->commit();
                    $success = 'Stock movement recorded successfully.';
                }
            } catch (PDOException $e) {
                $pdo->rollBack();
                $error = 'Unable to move stock. Please try again.';
            }
        }
    }
}

if (isset($_GET['edit_location'])) {
    $edit_id = (int) $_GET['edit_location'];
    if ($edit_id > 0) {
        $edit_stmt = $pdo->prepare("SELECT * FROM storage_locations WHERE id = ? AND shop_id = ?");
        $edit_stmt->execute([$edit_id, $shop_id]);
        $editing_location = $edit_stmt->fetch();
    }
}

$materials_stmt = $pdo->prepare("SELECT id, name, unit FROM raw_materials WHERE shop_id = ? ORDER BY name");
$materials_stmt->execute([$shop_id]);
$materials = $materials_stmt->fetchAll();

$locations_stmt = $pdo->prepare("
    SELECT sl.*,
           COALESCE(SUM(sp.qty), 0) as total_qty,
           COUNT(DISTINCT sp.material_id) as material_count
    FROM storage_locations sl
    LEFT JOIN stock_placements sp ON sp.location_id = sl.id
    WHERE sl.shop_id = ?
    GROUP BY sl.id
    ORDER BY sl.created_at DESC
");
$locations_stmt->execute([$shop_id]);
$storage_locations = $locations_stmt->fetchAll();

$placements_stmt = $pdo->prepare("
    SELECT sp.qty, sl.code as location_code, rm.name as material_name, rm.unit
    FROM stock_placements sp
    JOIN storage_locations sl ON sl.id = sp.location_id
    JOIN raw_materials rm ON rm.id = sp.material_id
    WHERE sl.shop_id = ?
    ORDER BY sl.code, rm.name
");
$placements_stmt->execute([$shop_id]);
$stock_placements = $placements_stmt->fetchAll();

$movement_stmt = $pdo->prepare("
    SELECT it.*, rm.name as material_name
    FROM inventory_transactions it
    JOIN raw_materials rm ON rm.id = it.material_id
    WHERE it.shop_id = ? AND it.type = 'move'
    ORDER BY it.created_at DESC
    LIMIT 8
");
$movement_stmt->execute([$shop_id]);
$movement_log = $movement_stmt->fetchAll();

$count_locations = count($storage_locations);
$locations_with_stock = 0;
foreach ($storage_locations as $location) {
    if ((float) $location['total_qty'] > 0) {
        $locations_with_stock++;
    }
}
$utilization = $count_locations > 0 ? round(($locations_with_stock / $count_locations) * 100) : 0;

$moves_today_stmt = $pdo->prepare("
    SELECT COUNT(*) FROM inventory_transactions
    WHERE shop_id = ? AND type = 'move' AND DATE(created_at) = CURDATE()
");
$moves_today_stmt->execute([$shop_id]);
$moves_today = (int) $moves_today_stmt->fetchColumn();

$warehouse_kpis = [
    [
        'label' => 'Active locations',
        'value' => $count_locations,
        'note' => 'Racks, bins, and staging bays.',
        'icon' => 'fas fa-location-dot',
        'tone' => 'primary',
    ],
    [
        'label' => 'Space utilization',
        'value' => $utilization . '%',
        'note' => 'Locations holding inventory.',
        'icon' => 'fas fa-warehouse',
        'tone' => 'info',
    ],
    [
        'label' => 'Materials stored',
        'value' => count($stock_placements),
        'note' => 'Items tracked across locations.',
        'icon' => 'fas fa-clipboard-list',
        'tone' => 'warning',
    ],
    [
        'label' => 'Movements today',
        'value' => $moves_today,
        'note' => 'Transfers & replenishments.',
        'icon' => 'fas fa-right-left',
        'tone' => 'success',
    ],
];

$automation_rules = [
    [
        'title' => 'Pick-list generation',
        'detail' => 'Auto-build pick-lists when orders move to production, grouped by zone and batch.',
        'icon' => 'fas fa-list-check',
    ],
    [
        'title' => 'Stock movement tracking',
        'detail' => 'Log transfers, replenishments, and staging handoffs in real time.',
        'icon' => 'fas fa-arrows-rotate',
    ],
    [
        'title' => 'Cycle count prompts',
        'detail' => 'Prompt cycle counts for high-velocity bins weekly to maintain location accuracy.',
        'icon' => 'fas fa-clipboard-check',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Storage &amp; Warehouse Management Module - Owner</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .warehouse-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 1.5rem;
            margin: 2rem 0;
        }

        .warehouse-kpi {
            grid-column: span 3;
        }

        .purpose-card,
        .movement-card {
            grid-column: span 12;
        }

        .location-card {
            grid-column: span 8;
        }

        .form-card {
            grid-column: span 4;
        }

        .picklist-card,
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

        .queue-item {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 1rem;
            background: white;
        }

        .queue-item + .queue-item {
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
                    <h2>Storage &amp; Warehouse Management</h2>
                    <p class="text-muted">Track storage locations, stock placements, and material movements across the shop.</p>
                </div>
                <span class="badge badge-primary"><i class="fas fa-boxes-stacked"></i> Module 24</span>
            </div>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="warehouse-grid">
            <div class="card purpose-card">
                <div class="card-header">
                    <h3><i class="fas fa-bullseye text-primary"></i> Purpose</h3>
                </div>
                <p class="text-muted mb-0">
                    Manages physical storage locations to keep embroidery materials and finished goods organized, traceable,
                    and ready for picking.
                </p>
            </div>

            <?php foreach ($warehouse_kpis as $kpi): ?>
                <div class="card warehouse-kpi">
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
                    <h3><i class="fas fa-location-plus text-primary"></i> <?php echo $editing_location ? 'Update Location' : 'Add Location'; ?></h3>
                </div>
                <form method="POST">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="<?php echo $editing_location ? 'update_location' : 'create_location'; ?>">
                    <?php if ($editing_location): ?>
                        <input type="hidden" name="location_id" value="<?php echo (int) $editing_location['id']; ?>">
                    <?php endif; ?>
                    <div class="form-group">
                        <label>Location code</label>
                        <input type="text" name="code" value="<?php echo htmlspecialchars($editing_location['code'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description" rows="3"><?php echo htmlspecialchars($editing_location['description'] ?? ''); ?></textarea>
                    </div>
                    <div class="d-flex justify-between align-center">
                        <button type="submit" class="btn btn-primary"><?php echo $editing_location ? 'Update Location' : 'Create Location'; ?></button>
                        <?php if ($editing_location): ?>
                            <a href="storage_warehouse_management.php" class="btn btn-light">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <div class="card location-card">
                <div class="card-header">
                    <h3><i class="fas fa-map-location-dot text-primary"></i> Storage Locations</h3>
                    <p class="text-muted">Active zones with material counts and stock totals.</p>
                </div>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Description</th>
                            <th>Materials</th>
                            <th>Total qty</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($storage_locations)): ?>
                            <tr>
                                <td colspan="5" class="text-muted">No storage locations created yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($storage_locations as $location): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($location['code']); ?></td>
                                    <td><?php echo htmlspecialchars($location['description'] ?? '—'); ?></td>
                                    <td><?php echo (int) $location['material_count']; ?></td>
                                    <td><?php echo number_format((float) $location['total_qty'], 2); ?></td>
                                    <td>
                                        <a href="storage_warehouse_management.php?edit_location=<?php echo (int) $location['id']; ?>" class="btn btn-sm btn-light">Edit</a>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Remove this location?');">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="delete_location">
                                            <input type="hidden" name="location_id" value="<?php echo (int) $location['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="card picklist-card">
                <div class="card-header">
                    <h3><i class="fas fa-right-left text-primary"></i> Move Stock</h3>
                    <p class="text-muted">Transfer materials between storage locations.</p>
                </div>
                <form method="POST">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="move_stock">
                    <div class="form-group">
                        <label>Material</label>
                        <select name="material_id" required>
                            <option value="">Select material</option>
                            <?php foreach ($materials as $material): ?>
                                <option value="<?php echo (int) $material['id']; ?>">
                                    <?php echo htmlspecialchars($material['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <div class="form-group">
                        <label>From location</label>
                        <select name="from_location_id">
                            <option value="0">Receiving/Unassigned</option>
                            <?php foreach ($storage_locations as $location): ?>
                                <option value="<?php echo (int) $location['id']; ?>"><?php echo htmlspecialchars($location['code']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>To location</label>
                        <select name="to_location_id" required>
                            <option value="">Select location</option>
                            <?php foreach ($storage_locations as $location): ?>
                                <option value="<?php echo (int) $location['id']; ?>"><?php echo htmlspecialchars($location['code']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Quantity</label>
                        <input type="number" step="0.01" name="qty" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Record Movement</button>
                </form>
            </div>

            <div class="card automation-card">
                <div class="card-header">
                    <h3><i class="fas fa-gear text-primary"></i> Automation</h3>
                    <p class="text-muted">Pick and movement tasks that stay synchronized.</p>
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

            <div class="card movement-card">
                <div class="card-header">
                    <h3><i class="fas fa-right-left text-primary"></i> Stock Movement Tracking</h3>
                    <p class="text-muted">Every transfer logged for traceability and audit readiness.</p>
                </div>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Material</th>
                            <th>Quantity</th>
                            <th>Logged</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($movement_log)): ?>
                            <tr>
                                <td colspan="4" class="text-muted">No movements logged yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($movement_log as $movement): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($movement['material_name']); ?></td>
                                    <td><?php echo number_format((float) $movement['qty'], 2); ?></td>
                                    <td class="text-muted"><?php echo date('M d, Y H:i', strtotime($movement['created_at'])); ?></td>
                                    <td>
                                        <span class="badge badge-success">Completed</span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                <div class="card-header">
                    <h4><i class="fas fa-boxes-stacked text-primary"></i> Materials in Storage</h4>
                </div>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Location</th>
                            <th>Material</th>
                            <th>Quantity</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($stock_placements)): ?>
                            <tr>
                                <td colspan="3" class="text-muted">No materials placed yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($stock_placements as $placement): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($placement['location_code']); ?></td>
                                    <td><?php echo htmlspecialchars($placement['material_name']); ?></td>
                                    <td><?php echo number_format((float) $placement['qty'], 2); ?> <?php echo htmlspecialchars($placement['unit']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>
