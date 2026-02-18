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

$address_exists_stmt = $pdo->query("SHOW COLUMNS FROM suppliers LIKE 'address'");
if (!$address_exists_stmt->fetch()) {
    $pdo->exec("ALTER TABLE suppliers ADD COLUMN address VARCHAR(255) DEFAULT NULL AFTER contact");
}

$suppliers_stmt = $pdo->prepare("
    SELECT name, contact, address, status, created_at
    FROM suppliers
    WHERE shop_id = ?
    ORDER BY created_at DESC
");
$suppliers_stmt->execute([$shop_id]);
$suppliers = $suppliers_stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supplier List - Owner</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="container" style="padding-top: 2rem; padding-bottom: 2rem;">
        <div class="card">
            <div class="card-header d-flex justify-between align-center">
                <div>
                    <h2><i class="fas fa-truck-field text-primary"></i> Supplier List</h2>
                    <p class="text-muted mb-0">Complete list of suppliers for <?php echo htmlspecialchars($shop['shop_name']); ?>.</p>
                </div>
                <a href="storage_warehouse_management.php" class="btn btn-light">Back to Storage &amp; Warehouse Management</a>
            </div>

            <table class="table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Contact Number</th>
                        <th>Address</th>
                        <th>Status</th>
                        <th>Added On</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($suppliers)): ?>
                        <tr>
                            <td colspan="5" class="text-muted">No suppliers added yet.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($suppliers as $supplier): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($supplier['name']); ?></td>
                                <td><?php echo htmlspecialchars($supplier['contact'] ?? '—'); ?></td>
                                <td><?php echo htmlspecialchars($supplier['address'] ?? '—'); ?></td>
                                <td><span class="badge badge-info"><?php echo htmlspecialchars(ucfirst($supplier['status'] ?? 'active')); ?></span></td>
                                <td class="text-muted"><?php echo date('M d, Y', strtotime($supplier['created_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>