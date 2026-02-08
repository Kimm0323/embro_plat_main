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
$editing_material = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_material') {
        $name = trim($_POST['name'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $unit = trim($_POST['unit'] ?? '');
        $current_stock = $_POST['current_stock'] ?? '';
        $min_stock_level = $_POST['min_stock_level'] ?? null;
        $max_stock_level = $_POST['max_stock_level'] ?? null;
        $unit_cost = $_POST['unit_cost'] ?? null;
        $supplier = trim($_POST['supplier'] ?? '');
        $status = $_POST['status'] ?? 'active';

        if ($name === '' || $unit === '' || $current_stock === '') {
            $error = 'Please provide a material name, unit, and current stock.';
        } elseif (!is_numeric($current_stock)) {
            $error = 'Current stock must be a valid number.';
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO raw_materials (shop_id, name, category, unit, current_stock, min_stock_level, max_stock_level, unit_cost, supplier, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $shop_id,
                $name,
                $category !== '' ? $category : null,
                $unit,
                $current_stock,
                $min_stock_level !== '' ? $min_stock_level : null,
                $max_stock_level !== '' ? $max_stock_level : null,
                $unit_cost !== '' ? $unit_cost : null,
                $supplier !== '' ? $supplier : null,
                $status,
            ]);
            $success = 'Raw material added successfully.';
        }
    }

    if ($action === 'update_material') {
        $material_id = (int) ($_POST['material_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $unit = trim($_POST['unit'] ?? '');
        $current_stock = $_POST['current_stock'] ?? '';
        $min_stock_level = $_POST['min_stock_level'] ?? null;
        $max_stock_level = $_POST['max_stock_level'] ?? null;
        $unit_cost = $_POST['unit_cost'] ?? null;
        $supplier = trim($_POST['supplier'] ?? '');
        $status = $_POST['status'] ?? 'active';

        if ($material_id <= 0 || $name === '' || $unit === '' || $current_stock === '') {
            $error = 'Please provide all required fields to update the material.';
        } elseif (!is_numeric($current_stock)) {
            $error = 'Current stock must be a valid number.';
        } else {
            $stmt = $pdo->prepare(
                "UPDATE raw_materials
                 SET name = ?, category = ?, unit = ?, current_stock = ?, min_stock_level = ?, max_stock_level = ?, unit_cost = ?, supplier = ?, status = ?
                 WHERE id = ? AND shop_id = ?"
            );
            $stmt->execute([
                $name,
                $category !== '' ? $category : null,
                $unit,
                $current_stock,
                $min_stock_level !== '' ? $min_stock_level : null,
                $max_stock_level !== '' ? $max_stock_level : null,
                $unit_cost !== '' ? $unit_cost : null,
                $supplier !== '' ? $supplier : null,
                $status,
                $material_id,
                $shop_id,
            ]);
            $success = 'Raw material updated successfully.';
        }
    }

    if ($action === 'delete_material') {
        $material_id = (int) ($_POST['material_id'] ?? 0);
        if ($material_id > 0) {
            $stmt = $pdo->prepare("DELETE FROM raw_materials WHERE id = ? AND shop_id = ?");
            $stmt->execute([$material_id, $shop_id]);
            $success = 'Raw material removed successfully.';
        } else {
            $error = 'Unable to delete the selected material.';
        }
    }
}

if (isset($_GET['edit_id'])) {
    $edit_id = (int) $_GET['edit_id'];
    if ($edit_id > 0) {
        $edit_stmt = $pdo->prepare("SELECT * FROM raw_materials WHERE id = ? AND shop_id = ?");
        $edit_stmt->execute([$edit_id, $shop_id]);
        $editing_material = $edit_stmt->fetch();
    }
}

$materials_stmt = $pdo->prepare("SELECT * FROM raw_materials WHERE shop_id = ? ORDER BY created_at DESC");
$materials_stmt->execute([$shop_id]);
$materials = $materials_stmt->fetchAll();

$inventory_value = 0.0;
$reorder_value = 0.0;
$low_stock_materials = [];

foreach ($materials as $material) {
    $current_stock = (float) $material['current_stock'];
    $unit_cost = $material['unit_cost'] !== null ? (float) $material['unit_cost'] : 0.0;
    $inventory_value += $current_stock * $unit_cost;

    if ($material['min_stock_level'] !== null && $current_stock <= (float) $material['min_stock_level']) {
        $low_stock_materials[] = $material;
        $deficit = (float) $material['min_stock_level'] - $current_stock;
        if ($deficit > 0) {
            $reorder_value += $deficit * $unit_cost;
        }
    }
}

$inventory_kpis = [
    [
        'label' => 'Materials tracked',
        'value' => count($materials),
        'note' => 'Active SKUs available to production.',
        'icon' => 'fas fa-boxes-stacked',
        'tone' => 'primary',
    ],
    [
        'label' => 'Low-stock items',
        'value' => count($low_stock_materials),
        'note' => 'Below or equal to reorder point.',
        'icon' => 'fas fa-triangle-exclamation',
        'tone' => 'warning',
    ],
    [
        'label' => 'On-hand value',
        'value' => '₱' . number_format($inventory_value, 2),
        'note' => 'Estimated value of materials on hand.',
        'icon' => 'fas fa-wallet',
        'tone' => 'info',
    ],
    [
        'label' => 'Days of coverage',
        'value' => '14',
        'note' => 'Average run rate coverage.',
        'icon' => 'fas fa-calendar-day',
        'tone' => 'success',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Raw Material Inventory Management Module - Owner</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .inventory-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 1.5rem;
            margin: 2rem 0;
        }

        .inventory-kpi {
            grid-column: span 3;
        }

        .purpose-card {
            grid-column: span 12;
        }

        .form-card {
            grid-column: span 12;
        }

        .stock-card {
            grid-column: span 8;
        }

        .automation-card {
            grid-column: span 4;
        }

        .alerts-card {
            grid-column: span 12;
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

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1rem;
        }

        .form-grid .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-grid label {
            font-weight: 600;
            margin-bottom: 0.35rem;
        }

        .form-actions {
            display: flex;
            gap: 0.75rem;
            align-items: center;
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

        .alert-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1rem;
        }

        .alert-item {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 1rem;
            background: white;
            display: flex;
            gap: 0.75rem;
            align-items: flex-start;
        }

        .alert-item i {
            color: var(--primary-600);
            margin-top: 0.25rem;
        }
        
        .stock-table td {
            vertical-align: top;
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
                    <h2>Raw Material Inventory Management</h2>
                    <p class="text-muted">Track embroidery materials with live stock visibility and automated replenishment support.</p>
                </div>
                <span class="badge badge-primary"><i class="fas fa-warehouse"></i> Module 22</span>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <div class="inventory-grid">
            <div class="card purpose-card">
                <div class="card-header">
                    <h3><i class="fas fa-bullseye text-primary"></i> Purpose</h3>
                </div>
                <p class="text-muted mb-0">
                    Tracks embroidery materials such as thread, stabilizers, and backing fabric while keeping stock levels aligned
                    with live production demand and supplier lead times.
                </p>
            </div>

            <?php foreach ($inventory_kpis as $kpi): ?>
                <div class="card inventory-kpi">
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
                    <h3><i class="fas fa-pen text-primary"></i> <?php echo $editing_material ? 'Update Raw Material' : 'Add Raw Material'; ?></h3>
                    <p class="text-muted">Capture material details, current stock, and reorder thresholds.</p>
                </div>
                <form method="post">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="<?php echo $editing_material ? 'update_material' : 'create_material'; ?>">
                    <?php if ($editing_material): ?>
                        <input type="hidden" name="material_id" value="<?php echo (int) $editing_material['id']; ?>">
                    <?php endif; ?>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="name">Material name *</label>
                            <input type="text" id="name" name="name" required value="<?php echo htmlspecialchars($editing_material['name'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="category">Category</label>
                            <input type="text" id="category" name="category" value="<?php echo htmlspecialchars($editing_material['category'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="unit">Unit *</label>
                            <input type="text" id="unit" name="unit" required value="<?php echo htmlspecialchars($editing_material['unit'] ?? ''); ?>" placeholder="cones, rolls, yards">
                        </div>
                        <div class="form-group">
                            <label for="current_stock">Current stock *</label>
                            <input type="number" step="0.01" id="current_stock" name="current_stock" required value="<?php echo htmlspecialchars($editing_material['current_stock'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="min_stock_level">Reorder point</label>
                            <input type="number" step="0.01" id="min_stock_level" name="min_stock_level" value="<?php echo htmlspecialchars($editing_material['min_stock_level'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="max_stock_level">Max stock level</label>
                            <input type="number" step="0.01" id="max_stock_level" name="max_stock_level" value="<?php echo htmlspecialchars($editing_material['max_stock_level'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="unit_cost">Unit cost</label>
                            <input type="number" step="0.01" id="unit_cost" name="unit_cost" value="<?php echo htmlspecialchars($editing_material['unit_cost'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="supplier">Supplier</label>
                            <input type="text" id="supplier" name="supplier" value="<?php echo htmlspecialchars($editing_material['supplier'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="status">Status</label>
                            <select id="status" name="status">
                                <option value="active" <?php echo ($editing_material['status'] ?? 'active') === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo ($editing_material['status'] ?? '') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <?php echo $editing_material ? 'Update Material' : 'Add Material'; ?>
                        </button>
                        <?php if ($editing_material): ?>
                            <a href="raw_material_inventory.php" class="btn btn-outline-secondary">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <div class="card stock-card">
                <div class="card-header">
                    <h3><i class="fas fa-layer-group text-primary"></i> Material Stock Levels</h3>
                    <p class="text-muted">Current on-hand quantities with reorder thresholds.</p>
                </div>
                <table class="table stock-table">
                    <thead>
                        <tr>
                            <th>Material</th>
                            <th>Category</th>
                            <th>On-hand</th>
                            <th>Reorder point</th>
                            <th>Unit cost</th>
                            <th>Supplier</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$materials): ?>
                            <tr>
                                <td colspan="8" class="text-muted">No raw materials recorded yet.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($materials as $material): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($material['name']); ?></strong><br>
                                    <small class="text-muted">Unit: <?php echo htmlspecialchars($material['unit']); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($material['category'] ?? '—'); ?></td>
                                <td><?php echo htmlspecialchars($material['current_stock']); ?></td>
                                <td><?php echo htmlspecialchars($material['min_stock_level'] ?? '—'); ?></td>
                                <td><?php echo $material['unit_cost'] !== null ? '₱' . number_format((float) $material['unit_cost'], 2) : '—'; ?></td>
                                <td><?php echo htmlspecialchars($material['supplier'] ?? '—'); ?></td>
                                <td>
                                    <span class="badge badge-<?php echo $material['status'] === 'active' ? 'success' : 'secondary'; ?>">
                                        <?php echo htmlspecialchars(ucfirst($material['status'])); ?>
                                    </span>
                                    <?php if ($material['min_stock_level'] !== null && (float) $material['current_stock'] <= (float) $material['min_stock_level']): ?>
                                        <span class="badge badge-warning">Low</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a class="btn btn-sm btn-outline-primary" href="raw_material_inventory.php?edit_id=<?php echo (int) $material['id']; ?>">Edit</a>
                                    <form method="post" style="display:inline-block" onsubmit="return confirm('Remove this material from inventory?');">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete_material">
                                        <input type="hidden" name="material_id" value="<?php echo (int) $material['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="card automation-card">
                <div class="card-header">
                    <h3><i class="fas fa-gear text-primary"></i> Low-stock Alerts</h3>
                    <p class="text-muted">Materials that need replenishment soon.</p>
                </div>
                <?php if (!$low_stock_materials): ?>
                    <div class="automation-item">
                        <p class="text-muted mb-0">All tracked materials are above reorder point.</p>
                    </div>
                <?php endif; ?>
                <?php foreach ($low_stock_materials as $material): ?>
                    <div class="automation-item">
                        <div class="d-flex align-center gap-2 mb-2">
                            <i class="fas fa-triangle-exclamation text-warning"></i>
                            <strong><?php echo htmlspecialchars($material['name']); ?></strong>
                        </div>
                        <p class="text-muted mb-0">
                            On-hand: <?php echo htmlspecialchars($material['current_stock']); ?> <?php echo htmlspecialchars($material['unit']); ?> ·
                            Reorder point: <?php echo htmlspecialchars($material['min_stock_level']); ?> <?php echo htmlspecialchars($material['unit']); ?>
                        </p>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="card alerts-card">
                <div class="card-header">
                    <h3><i class="fas fa-bell text-primary"></i> Actionable Notes</h3>
                    <p class="text-muted">Use this module as the source of truth for production inventory.</p>
                </div>
                <div class="alert-list">
                    <div class="alert-item">
                        <i class="fas fa-bell"></i>
                        <div>
                            <strong>Low-stock alerts</strong>
                            <p class="text-muted mb-0">Materials below reorder point appear immediately for owner follow-up.</p>
                        </div>
                    </div>
                    <div class="alert-item">
                        <i class="fas fa-clipboard-check"></i>
                        <div>
                            <strong>Inventory hygiene</strong>
                            <p class="text-muted mb-0">Update stock levels after every delivery or production batch.</p>
                        </div>
                    </div>
                    <div class="alert-item">
                        <i class="fas fa-box-open"></i>
                        <div>
                            <strong>Supplier coordination</strong>
                            <p class="text-muted mb-0">Track suppliers and unit costs to support smarter reorder decisions.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
