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

// Ensure required schema updates for this module.
$address_exists_stmt = $pdo->query("SHOW COLUMNS FROM suppliers LIKE 'address'");
if (!$address_exists_stmt->fetch()) {
    $pdo->exec("ALTER TABLE suppliers ADD COLUMN address VARCHAR(255) DEFAULT NULL AFTER contact");
}

$pdo->exec("
    CREATE TABLE IF NOT EXISTS warehouse_stock_management (
        id INT(11) NOT NULL AUTO_INCREMENT,
        shop_id INT(11) NOT NULL,
         material_id INT(11) DEFAULT NULL,
        material_input VARCHAR(120) DEFAULT NULL,
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

$material_input_exists_stmt = $pdo->query("SHOW COLUMNS FROM warehouse_stock_management LIKE 'material_input'");
if (!$material_input_exists_stmt->fetch()) {
    $pdo->exec("ALTER TABLE warehouse_stock_management ADD COLUMN material_input VARCHAR(120) DEFAULT NULL AFTER material_id");
}

        $material_id_col_stmt = $pdo->query("SHOW COLUMNS FROM warehouse_stock_management LIKE 'material_id'");
$material_id_col = $material_id_col_stmt->fetch();
if ($material_id_col && strtoupper($material_id_col['Null']) === 'NO') {
    $pdo->exec("ALTER TABLE warehouse_stock_management MODIFY COLUMN material_id INT(11) DEFAULT NULL");
}

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

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
       $material_input = trim($_POST['material_input'] ?? '');
        $opening_stock_qty = $_POST['opening_stock_qty'] ?? '';
        $warehouse_location = trim($_POST['warehouse_location'] ?? '');
        $reorder_quantity = $_POST['reorder_quantity'] ?? '';

        if (
            $material_input === '' ||
            $warehouse_location === '' ||
            $opening_stock_qty === '' || !is_numeric($opening_stock_qty) ||
            $reorder_quantity === '' || !is_numeric($reorder_quantity)
        ) {
            $error = 'Please provide valid stock management details.';
        } else {
             $material_id = null;
            $material_stmt = $pdo->prepare("SELECT id FROM raw_materials WHERE shop_id = ? AND name = ? LIMIT 1");
            $material_stmt->execute([$shop_id, $material_input]);
            $material_match = $material_stmt->fetch();
            if ($material_match) {
                $material_id = (int) $material_match['id'];
            }

            $stmt = $pdo->prepare("
                INSERT INTO warehouse_stock_management (
                    shop_id,
                    material_id,
                    material_input,
                    opening_stock_qty,
                    warehouse_location,
                    reorder_quantity
                 ) VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $shop_id,
                $material_id,
                 $material_input,
                $opening_stock_qty,
                $warehouse_location,
                $reorder_quantity,
            ]);
            $success = 'Stock management entry created successfully.';
        }
    }
}

$suppliers_stmt = $pdo->prepare("SELECT id, name, contact, address, created_at FROM suppliers WHERE shop_id = ? ORDER BY created_at DESC");
$suppliers_stmt->execute([$shop_id]);
$suppliers = $suppliers_stmt->fetchAll();

$stock_management_stmt = $pdo->prepare("
    SELECT wsm.*, rm.name AS material_name, rm.unit
    FROM warehouse_stock_management wsm
    LEFT JOIN raw_materials rm ON rm.id = wsm.material_id
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

         .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            box-sizing: border-box;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            padding: 0.65rem 0.75rem;
            font-size: 0.95rem;
            font-family: inherit;
        }

        .form-group input,
        .form-group select {
            height: 42px;
        }

        .form-group textarea {
            min-height: 102px;
            resize: vertical;
        }


        .supplier-form-card,
        .stock-management-form-card {
            grid-column: span 4;
        }

        .supplier-list-card,
        .stock-management-list-card {
            grid-column: span 8;
        }

       @media (max-width: 992px) {
            .supplier-form-card,
            .supplier-list-card
            .stock-management-form-card,
            .stock-management-list-card {
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
                   <p class="text-muted">Manage stock management settings and supplier records from one place.</p>
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
                         <label>Material Input</label>
                       <input type="text" name="material_input" placeholder="Enter material name" required>
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
                        <label>Reorder quantity</label>
                        <input type="number" step="0.01" min="0" name="reorder_quantity" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Save Stock Management</button>
                </form>
            </div>
            <div class="card stock-management-list-card">
                <div class="card-header">
                   <h3><i class="fas fa-warehouse text-primary"></i> Stock Management List</h3>
                   <p class="text-muted">Material opening stock and reorder quantity settings per location.</p>
                </div>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Material Input</th>
                            <th>Opening Stock Quantity</th>
                            <th>Warehouse / Location</th>
                            <th>Reorder Quantity</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($stock_management_entries)): ?>
                            <tr>
                               <td colspan="4" class="text-muted">No stock management records found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($stock_management_entries as $entry): ?>
                                <tr>
                                     <td><?php echo htmlspecialchars($entry['material_input'] ?? $entry['material_name'] ?? '—'); ?></td>
                                    <td><?php echo number_format((float) $entry['opening_stock_qty'], 2); ?> <?php echo htmlspecialchars($entry['unit']); ?></td>
                                    <td><?php echo htmlspecialchars($entry['warehouse_location']); ?></td>
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
