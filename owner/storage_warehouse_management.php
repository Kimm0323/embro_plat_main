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

// Ensure required schema updates for this module.
$address_exists_stmt = $pdo->query("SHOW COLUMNS FROM suppliers LIKE 'address'");
if (!$address_exists_stmt->fetch()) {
    $pdo->exec("ALTER TABLE suppliers ADD COLUMN address VARCHAR(255) DEFAULT NULL AFTER contact");
}

$pdo->exec("
    CREATE TABLE IF NOT EXISTS warehouse_stock_management (
        id INT(11) NOT NULL AUTO_INCREMENT,
        shop_id INT(11) NOT NULL,
        material_id INT(11) NOT NULL,
        opening_stock_qty DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        warehouse_location VARCHAR(120) NOT NULL,
        reorder_level DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        reorder_quantity DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_wsm_shop_id (shop_id),
        KEY idx_wsm_material_id (material_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

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

    if ($action === 'create_supplier') {
        $name = trim($_POST['name'] ?? '');
        $contact = trim($_POST['contact'] ?? '');
        $address = trim($_POST['address'] ?? '');

        if ($name === '') {
            $error = 'Supplier name is required.';
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO suppliers (shop_id, name, contact, address)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([
                $shop_id,
                $name,
                $contact !== '' ? $contact : null,
                $address !== '' ? $address : null,
            ]);
            $success = 'Supplier added successfully.';
        }
    }

    if ($action === 'create_stock_management') {
        $material_id = (int) ($_POST['material_id'] ?? 0);
        $opening_stock_qty = $_POST['opening_stock_qty'] ?? '';
        $warehouse_location = trim($_POST['warehouse_location'] ?? '');
        $reorder_level = $_POST['reorder_level'] ?? '';
        $reorder_quantity = $_POST['reorder_quantity'] ?? '';

        if (
            $material_id <= 0 ||
            $warehouse_location === '' ||
            $opening_stock_qty === '' || !is_numeric($opening_stock_qty) ||
            $reorder_level === '' || !is_numeric($reorder_level) ||
            $reorder_quantity === '' || !is_numeric($reorder_quantity)
        ) {
            $error = 'Please provide valid stock management details.';
        } else {
             $stmt = $pdo->prepare("
                INSERT INTO warehouse_stock_management (
                    shop_id,
                    material_id,
                    opening_stock_qty,
                    warehouse_location,
                    reorder_level,
                    reorder_quantity
                ) VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $shop_id,
                $material_id,
                $opening_stock_qty,
                $warehouse_location,
                $reorder_level,
                $reorder_quantity,
            ]);
            $success = 'Stock management entry created successfully.';
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
     SELECT sl.*
    FROM storage_locations sl
    WHERE sl.shop_id = ?
    ORDER BY sl.created_at DESC
");
$locations_stmt->execute([$shop_id]);
$storage_locations = $locations_stmt->fetchAll();

$suppliers_stmt = $pdo->prepare("SELECT id, name, contact, address, created_at FROM suppliers WHERE shop_id = ? ORDER BY created_at DESC");
$suppliers_stmt->execute([$shop_id]);
$suppliers = $suppliers_stmt->fetchAll();

$stock_management_stmt = $pdo->prepare("
    SELECT wsm.*, rm.name AS material_name, rm.unit
    FROM warehouse_stock_management wsm
    JOIN raw_materials rm ON rm.id = wsm.material_id
    WHERE wsm.shop_id = ?
    ORDER BY wsm.created_at DESC
");
$stock_management_stmt->execute([$shop_id]);
$stock_management_entries = $stock_management_stmt->fetchAll();
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

        .location-card,
        .stock-management-list-card,
        .supplier-list-card {
            grid-column: span 12;
        }

        .form-card,
        .supplier-form-card,
        .stock-management-form-card {
            grid-column: span 4;
        }

        .module-card {
            grid-column: span 8;
        }

       @media (max-width: 992px) {
            .location-card,
            .stock-management-list-card,
            .supplier-list-card,
            .form-card,
            .supplier-form-card,
            .stock-management-form-card,
            .module-card {
                grid-column: span 12;
            }
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
                   <p class="text-muted">Manage locations, maintain stock management settings, and add supplier records from one place.</p>
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
            <div class="card form-card">
                <div class="card-header">
                   <h3><i class="fas fa-location-dot text-primary"></i> <?php echo $editing_location ? 'Edit Storage Location' : 'Add Storage Location'; ?></h3>
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

            <div class="card module-card">
                <div class="card-header">
                    <h3><i class="fas fa-map-location-dot text-primary"></i> Storage Locations</h3>
                    <p class="text-muted">Manage all warehouse and storage locations.</p>
                </div>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Description</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($storage_locations)): ?>
                            <tr>
                               <td colspan="3" class="text-muted">No storage locations created yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($storage_locations as $location): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($location['code']); ?></td>
                                    <td><?php echo htmlspecialchars($location['description'] ?? '—'); ?></td>
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

            <div class="card supplier-form-card">
                <div class="card-header">
                    <h3><i class="fas fa-truck-field text-primary"></i> Add Supplier</h3>
                </div>
                <form method="POST">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="create_supplier">
                    <div class="form-group">
                        <label>Supplier name</label>
                        <input type="text" name="name" required>
                    </div>
                    <div class="form-group">
                        <label>Contact number</label>
                        <input type="text" name="contact">
                    </div>
                    <div class="form-group">
                        <label>Address</label>
                        <textarea name="address" rows="3"></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">Add Supplier</button>
                </form>
            </div>

            <div class="card supplier-list-card">
                <div class="card-header d-flex justify-between align-center">
                    <div>
                        <h3><i class="fas fa-list text-primary"></i> Supplier List</h3>
                        <p class="text-muted">Quick view of all suppliers added for this shop.</p>
                    </div>
                 <a href="supplier_list.php" class="btn btn-light">Open Full Supplier List Page</a>
                </div>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Contact Number</th>
                            <th>Address</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($suppliers)): ?>
                            <tr>
                                <td colspan="3" class="text-muted">No suppliers added yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($suppliers as $supplier): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($supplier['name']); ?></td>
                                    <td><?php echo htmlspecialchars($supplier['contact'] ?? '—'); ?></td>
                                    <td><?php echo htmlspecialchars($supplier['address'] ?? '—'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>

            <div class="card stock-management-form-card">
                <div class="card-header">
                    <h3><i class="fas fa-clipboard-list text-primary"></i> Stock Management</h3>
                </div>
                <form method="POST">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="create_stock_management">
                    <div class="form-group">
                        <label>Inventory Section (Material)</label>
                        <select name="material_id" required>
                            <option value="">Select material</option>
                            <?php foreach ($materials as $material): ?>
                                <option value="<?php echo (int) $material['id']; ?>"><?php echo htmlspecialchars($material['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Opening stock quantity</label>
                        <input type="number" step="0.01" min="0" name="opening_stock_qty" required>
                    </div>
                    <div class="form-group">
                        <label>Warehouse / Location</label>
                        <input type="text" name="warehouse_location" required>
                    </div>
                    <div class="form-group">
                        <label>Reorder level</label>
                        <input type="number" step="0.01" min="0" name="reorder_level" required>
                    </div>
                    <div class="form-group">
                        <label>Reorder quantity</label>
                        <input type="number" step="0.01" min="0" name="reorder_quantity" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Save Stock Management</button>
                </form>
            </div>

            <div class="card stock-management-list-card">
                <div class="card-header">
                   <h3><i class="fas fa-warehouse text-primary"></i> Stock Management List</h3>
                    <p class="text-muted">Inventory section opening stock and reorder settings per location.</p>
                </div>
                <table class="table">
                    <thead>
                        <tr>
                           <th>Inventory Section</th>
                            <th>Opening Stock Quantity</th>
                            <th>Warehouse / Location</th>
                            <th>Reorder Level</th>
                            <th>Reorder Quantity</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($stock_management_entries)): ?>
                            <tr>
                                <td colspan="5" class="text-muted">No stock management records found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($stock_management_entries as $entry): ?>
                                <tr>
                                     <td><?php echo htmlspecialchars($entry['material_name']); ?></td>
                                    <td><?php echo number_format((float) $entry['opening_stock_qty'], 2); ?> <?php echo htmlspecialchars($entry['unit']); ?></td>
                                    <td><?php echo htmlspecialchars($entry['warehouse_location']); ?></td>
                                    <td><?php echo number_format((float) $entry['reorder_level'], 2); ?> <?php echo htmlspecialchars($entry['unit']); ?></td>
                                    <td><?php echo number_format((float) $entry['reorder_quantity'], 2); ?> <?php echo htmlspecialchars($entry['unit']); ?></td>
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
