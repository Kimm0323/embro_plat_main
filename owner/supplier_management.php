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
$editing_supplier = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_supplier') {
        $name = trim($_POST['name'] ?? '');
        $contact = trim($_POST['contact'] ?? '');
        $rating = $_POST['rating'] !== '' ? (float) $_POST['rating'] : null;
        $status = $_POST['status'] ?? 'active';

        if ($name === '') {
            $error = 'Supplier name is required.';
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO suppliers (shop_id, name, contact, rating, status)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $shop_id,
                $name,
                $contact !== '' ? $contact : null,
                $rating,
                $status,
            ]);
            $success = 'Supplier added successfully.';
        }
    }

    if ($action === 'update_supplier') {
        $supplier_id = (int) ($_POST['supplier_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $contact = trim($_POST['contact'] ?? '');
        $rating = $_POST['rating'] !== '' ? (float) $_POST['rating'] : null;
        $status = $_POST['status'] ?? 'active';

        if ($supplier_id <= 0 || $name === '') {
            $error = 'Please provide the required supplier details.';
        } else {
            $stmt = $pdo->prepare("
                UPDATE suppliers
                SET name = ?, contact = ?, rating = ?, status = ?
                WHERE id = ? AND shop_id = ?
            ");
            $stmt->execute([
                $name,
                $contact !== '' ? $contact : null,
                $rating,
                $status,
                $supplier_id,
                $shop_id,
            ]);
            $success = 'Supplier updated successfully.';
        }
    }

    if ($action === 'delete_supplier') {
        $supplier_id = (int) ($_POST['supplier_id'] ?? 0);
        if ($supplier_id > 0) {
            $stmt = $pdo->prepare("DELETE FROM suppliers WHERE id = ? AND shop_id = ?");
            $stmt->execute([$supplier_id, $shop_id]);
            $success = 'Supplier removed successfully.';
        } else {
            $error = 'Unable to delete the selected supplier.';
        }
    }

    if ($action === 'create_request') {
        $supplier_id = (int) ($_POST['supplier_id'] ?? 0);
        $status = $_POST['status'] ?? 'draft';
        $material_id = (int) ($_POST['material_id'] ?? 0);
        $qty = $_POST['qty'] ?? '';
        $unit_cost = $_POST['unit_cost'] ?? '';

        if ($status === '') {
            $error = 'Please select a status for the purchase request.';
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO purchase_requests (shop_id, supplier_id, status, created_by, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $shop_id,
                $supplier_id > 0 ? $supplier_id : null,
                $status,
                $owner_id,
            ]);
            $request_id = (int) $pdo->lastInsertId();

            if ($material_id > 0 && $qty !== '' && is_numeric($qty)) {
                $item_stmt = $pdo->prepare("
                    INSERT INTO purchase_request_items (request_id, material_id, qty, unit_cost)
                    VALUES (?, ?, ?, ?)
                ");
                $item_stmt->execute([
                    $request_id,
                    $material_id,
                    $qty,
                    $unit_cost !== '' ? $unit_cost : null,
                ]);
            }

            $success = 'Purchase request created successfully.';
        }
    }

    if ($action === 'add_request_item') {
        $request_id = (int) ($_POST['request_id'] ?? 0);
        $material_id = (int) ($_POST['material_id'] ?? 0);
        $qty = $_POST['qty'] ?? '';
        $unit_cost = $_POST['unit_cost'] ?? '';

        if ($request_id <= 0 || $material_id <= 0 || $qty === '' || !is_numeric($qty)) {
            $error = 'Please provide a valid material and quantity for the request item.';
        } else {
            $request_stmt = $pdo->prepare("SELECT id FROM purchase_requests WHERE id = ? AND shop_id = ?");
            $request_stmt->execute([$request_id, $shop_id]);
            $request_exists = $request_stmt->fetchColumn();

            if ($request_exists) {
                $item_stmt = $pdo->prepare("
                    INSERT INTO purchase_request_items (request_id, material_id, qty, unit_cost)
                    VALUES (?, ?, ?, ?)
                ");
                $item_stmt->execute([
                    $request_id,
                    $material_id,
                    $qty,
                    $unit_cost !== '' ? $unit_cost : null,
                ]);
                $success = 'Request item added successfully.';
            } else {
                $error = 'Unable to add items to the selected request.';
            }
        }
    }

    if ($action === 'update_request_status') {
        $request_id = (int) ($_POST['request_id'] ?? 0);
        $status = $_POST['status'] ?? '';

        if ($request_id <= 0 || $status === '') {
            $error = 'Please select a valid request and status.';
        } else {
            $request_stmt = $pdo->prepare("SELECT id FROM purchase_requests WHERE id = ? AND shop_id = ?");
            $request_stmt->execute([$request_id, $shop_id]);
            $request_exists = $request_stmt->fetchColumn();

            if ($request_exists) {
                $columns = ['status' => $status];
                if ($status === 'approved') {
                    $columns['approved_by'] = $owner_id;
                    $columns['approved_at'] = date('Y-m-d H:i:s');
                }
                if ($status === 'closed') {
                    $columns['closed_at'] = date('Y-m-d H:i:s');
                }

                $set_clause = [];
                $params = [];
                foreach ($columns as $column => $value) {
                    $set_clause[] = "{$column} = ?";
                    $params[] = $value;
                }
                $params[] = $request_id;
                $params[] = $shop_id;

                $stmt = $pdo->prepare("UPDATE purchase_requests SET " . implode(', ', $set_clause) . " WHERE id = ? AND shop_id = ?");
                $stmt->execute($params);
                $success = 'Purchase request updated successfully.';
            } else {
                $error = 'Unable to update the selected request.';
            }
        }
    }
}

if (isset($_GET['edit_supplier'])) {
    $edit_id = (int) $_GET['edit_supplier'];
    if ($edit_id > 0) {
        $edit_stmt = $pdo->prepare("SELECT * FROM suppliers WHERE id = ? AND shop_id = ?");
        $edit_stmt->execute([$edit_id, $shop_id]);
        $editing_supplier = $edit_stmt->fetch();
    }
}

$materials_stmt = $pdo->prepare("SELECT id, name, unit, unit_cost FROM raw_materials WHERE shop_id = ? ORDER BY name");
$materials_stmt->execute([$shop_id]);
$materials = $materials_stmt->fetchAll();

$suppliers_stmt = $pdo->prepare("SELECT * FROM suppliers WHERE shop_id = ? ORDER BY created_at DESC");
$suppliers_stmt->execute([$shop_id]);
$suppliers = $suppliers_stmt->fetchAll();

$stats_stmt = $pdo->prepare("
    SELECT
        s.id,
        s.name,
        s.contact,
        s.rating,
        s.status,
        COUNT(DISTINCT pr.id) as request_count,
        COALESCE(SUM(pri.qty * COALESCE(pri.unit_cost, 0)), 0) as total_spend,
        AVG(CASE WHEN pr.closed_at IS NOT NULL THEN TIMESTAMPDIFF(DAY, pr.created_at, pr.closed_at) END) as avg_lead_time,
        SUM(CASE WHEN pr.closed_at IS NOT NULL AND TIMESTAMPDIFF(DAY, pr.created_at, pr.closed_at) <= 7 THEN 1 ELSE 0 END) as on_time_count,
        SUM(CASE WHEN pr.closed_at IS NOT NULL THEN 1 ELSE 0 END) as closed_count
    FROM suppliers s
    LEFT JOIN purchase_requests pr ON pr.supplier_id = s.id
    LEFT JOIN purchase_request_items pri ON pri.request_id = pr.id
    WHERE s.shop_id = ?
    GROUP BY s.id
    ORDER BY s.created_at DESC
");
$stats_stmt->execute([$shop_id]);
$supplier_scorecards = $stats_stmt->fetchAll();

$requests_stmt = $pdo->prepare("
    SELECT pr.*, s.name AS supplier_name,
           COALESCE(SUM(pri.qty * COALESCE(pri.unit_cost, 0)), 0) as total_cost,
           COUNT(pri.id) as item_count
    FROM purchase_requests pr
    LEFT JOIN suppliers s ON s.id = pr.supplier_id
    LEFT JOIN purchase_request_items pri ON pri.request_id = pr.id
    WHERE pr.shop_id = ?
    GROUP BY pr.id
    ORDER BY pr.created_at DESC
");
$requests_stmt->execute([$shop_id]);
$purchase_requests = $requests_stmt->fetchAll();

$items_stmt = $pdo->prepare("
    SELECT pri.*, rm.name AS material_name, rm.unit
    FROM purchase_request_items pri
    JOIN raw_materials rm ON rm.id = pri.material_id
    JOIN purchase_requests pr ON pr.id = pri.request_id
    WHERE pr.shop_id = ?
    ORDER BY pri.id DESC
");
$items_stmt->execute([$shop_id]);
$request_items = $items_stmt->fetchAll();
$request_items_by_id = [];
foreach ($request_items as $item) {
    $request_items_by_id[$item['request_id']][] = $item;
}

$kpi_stmt = $pdo->prepare("
    SELECT
        COUNT(*) as supplier_count,
        SUM(CASE WHEN status IN ('active','preferred') THEN 1 ELSE 0 END) as active_count
    FROM suppliers
    WHERE shop_id = ?
");
$kpi_stmt->execute([$shop_id]);
$supplier_counts = $kpi_stmt->fetch();

$lead_stmt = $pdo->prepare("
    SELECT
        AVG(TIMESTAMPDIFF(DAY, created_at, closed_at)) as avg_lead_time,
        SUM(CASE WHEN closed_at IS NOT NULL AND TIMESTAMPDIFF(DAY, created_at, closed_at) <= 7 THEN 1 ELSE 0 END) as on_time_count,
        SUM(CASE WHEN closed_at IS NOT NULL THEN 1 ELSE 0 END) as closed_total
    FROM purchase_requests
    WHERE shop_id = ? AND closed_at IS NOT NULL
");
$lead_stmt->execute([$shop_id]);
$lead_stats = $lead_stmt->fetch();

$spend_stmt = $pdo->prepare("
    SELECT COALESCE(SUM(pri.qty * COALESCE(pri.unit_cost, 0)), 0) as monthly_spend
    FROM purchase_request_items pri
    JOIN purchase_requests pr ON pr.id = pri.request_id
    WHERE pr.shop_id = ? AND DATE_FORMAT(pr.created_at, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
");
$spend_stmt->execute([$shop_id]);
$monthly_spend = (float) $spend_stmt->fetchColumn();

$avg_lead = $lead_stats['avg_lead_time'] !== null ? round((float) $lead_stats['avg_lead_time'], 1) : 0;
$on_time_rate = 0;
if ((int) ($lead_stats['closed_total'] ?? 0) > 0) {
    $on_time_rate = round(((int) $lead_stats['on_time_count'] / (int) $lead_stats['closed_total']) * 100);
}

$supplier_kpis = [
    [
        'label' => 'Active suppliers',
        'value' => (int) ($supplier_counts['active_count'] ?? 0),
        'note' => 'Approved vendors for materials.',
        'icon' => 'fas fa-people-arrows',
        'tone' => 'primary',
    ],
    [
        'label' => 'Average lead time',
        'value' => $avg_lead > 0 ? $avg_lead . ' days' : 'N/A',
        'note' => 'From PR approval to close.',
        'icon' => 'fas fa-hourglass-half',
        'tone' => 'info',
    ],
    [
        'label' => 'On-time delivery rate',
        'value' => $on_time_rate . '%',
        'note' => 'Closed requests within 7 days.',
        'icon' => 'fas fa-truck-fast',
        'tone' => 'success',
    ],
    [
        'label' => 'Monthly spend',
        'value' => '₱' . number_format($monthly_spend, 2),
        'note' => 'Material procurement.',
        'icon' => 'fas fa-receipt',
        'tone' => 'warning',
    ],
];

$automation_rules = [
    [
        'title' => 'Purchase request generation',
        'detail' => 'Draft PRs are created from low-stock alerts and manual submissions.',
        'icon' => 'fas fa-file-circle-plus',
    ],
    [
        'title' => 'Supplier performance tracking',
        'detail' => 'Score vendors by lead time, spend, and closed request volume.',
        'icon' => 'fas fa-chart-line',
    ],
    [
        'title' => 'Auto-routing for approvals',
        'detail' => 'Update request status for approvals and closures in one place.',
        'icon' => 'fas fa-user-check',
    ],
];

$review_checkpoints = [
    [
        'title' => 'Quarterly supplier review',
        'detail' => 'Review scorecards and compliance documents every 90 days.',
        'icon' => 'fas fa-calendar-check',
    ],
    [
        'title' => 'Delivery exception log',
        'detail' => 'Capture late deliveries and short-shipments to support escalation.',
        'icon' => 'fas fa-clipboard-list',
    ],
    [
        'title' => 'Savings opportunities',
        'detail' => 'Flag bundle pricing or alternative vendors based on volume.',
        'icon' => 'fas fa-tags',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supplier Management Module - Owner</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .supplier-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 1.5rem;
            margin: 2rem 0;
        }

        .supplier-kpi {
            grid-column: span 3;
        }

        .purpose-card,
        .review-card {
            grid-column: span 12;
        }

        .scorecard-card {
            grid-column: span 8;
        }

        .form-card {
            grid-column: span 4;
        }

        .purchase-card,
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

        .review-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1rem;
        }

        .review-item {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 1rem;
            background: white;
            display: flex;
            gap: 0.75rem;
            align-items: flex-start;
        }

        .review-item i {
            color: var(--primary-600);
            margin-top: 0.25rem;
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
                    <h2>Supplier Management</h2>
                    <p class="text-muted">Coordinate purchasing, monitor supplier health, and keep replenishment on schedule.</p>
                </div>
                <span class="badge badge-primary"><i class="fas fa-handshake"></i> Module 23</span>
            </div>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="supplier-grid">
            <div class="card purpose-card">
                <div class="card-header">
                    <h3><i class="fas fa-bullseye text-primary"></i> Purpose</h3>
                </div>
                <p class="text-muted mb-0">
                    Handles purchasing and supplier evaluation to ensure embroidery materials arrive on time, at the right quality,
                    and within budget.
                </p>
            </div>

            <?php foreach ($supplier_kpis as $kpi): ?>
                <div class="card supplier-kpi">
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
                    <h3><i class="fas fa-user-plus text-primary"></i> <?php echo $editing_supplier ? 'Update Supplier' : 'Add Supplier'; ?></h3>
                </div>
                <form method="POST">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="<?php echo $editing_supplier ? 'update_supplier' : 'create_supplier'; ?>">
                    <?php if ($editing_supplier): ?>
                        <input type="hidden" name="supplier_id" value="<?php echo (int) $editing_supplier['id']; ?>">
                    <?php endif; ?>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Supplier name</label>
                            <input type="text" name="name" value="<?php echo htmlspecialchars($editing_supplier['name'] ?? ''); ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Contact details</label>
                            <input type="text" name="contact" value="<?php echo htmlspecialchars($editing_supplier['contact'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label>Rating</label>
                            <input type="number" step="0.1" name="rating" value="<?php echo htmlspecialchars($editing_supplier['rating'] ?? ''); ?>" placeholder="4.5">
                        </div>
                        <div class="form-group">
                            <label>Status</label>
                            <select name="status">
                                <?php
                                $status_options = ['active' => 'Active', 'preferred' => 'Preferred', 'watchlist' => 'Watchlist', 'inactive' => 'Inactive'];
                                $current_status = $editing_supplier['status'] ?? 'active';
                                ?>
                                <?php foreach ($status_options as $value => $label): ?>
                                    <option value="<?php echo $value; ?>" <?php echo $current_status === $value ? 'selected' : ''; ?>>
                                        <?php echo $label; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="d-flex justify-between align-center">
                        <button type="submit" class="btn btn-primary">
                            <?php echo $editing_supplier ? 'Update Supplier' : 'Create Supplier'; ?>
                        </button>
                        <?php if ($editing_supplier): ?>
                            <a href="supplier_management.php" class="btn btn-light">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <div class="card scorecard-card">
                <div class="card-header">
                    <h3><i class="fas fa-chart-line text-primary"></i> Supplier Scorecards</h3>
                    <p class="text-muted">Performance tracking across lead time, spend, and service status.</p>
                </div>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Supplier</th>
                            <th>Contact</th>
                            <th>Rating</th>
                            <th>Avg. lead time</th>
                            <th>Total spend</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($supplier_scorecards)): ?>
                            <tr>
                                <td colspan="6" class="text-muted">No suppliers added yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($supplier_scorecards as $supplier): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($supplier['name']); ?></td>
                                <td><?php echo htmlspecialchars($supplier['contact'] ?? '—'); ?></td>
                                <td><?php echo $supplier['rating'] !== null ? number_format((float) $supplier['rating'], 1) : '—'; ?></td>
                                <td><?php echo $supplier['avg_lead_time'] !== null ? number_format((float) $supplier['avg_lead_time'], 1) . ' days' : '—'; ?></td>
                                <td>₱<?php echo number_format((float) $supplier['total_spend'], 2); ?></td>
                                <td>
                                    <?php
                                    $status_tone = $supplier['status'] === 'preferred' ? 'success' : ($supplier['status'] === 'watchlist' ? 'warning' : ($supplier['status'] === 'inactive' ? 'secondary' : 'info'));
                                    ?>
                                    <span class="badge badge-<?php echo $status_tone; ?>">
                                        <?php echo htmlspecialchars(ucfirst($supplier['status'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="supplier_management.php?edit_supplier=<?php echo (int) $supplier['id']; ?>" class="btn btn-sm btn-light">Edit</a>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Remove this supplier?');">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete_supplier">
                                        <input type="hidden" name="supplier_id" value="<?php echo (int) $supplier['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="card purchase-card">
                <div class="card-header">
                    <h3><i class="fas fa-file-signature text-primary"></i> Create Purchase Request</h3>
                    <p class="text-muted">Draft PRs and capture initial line items.</p>
                </div>
                <form method="POST">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="create_request">
                    <div class="form-group">
                        <label>Supplier</label>
                        <select name="supplier_id">
                            <option value="">Unassigned</option>
                            <?php foreach ($suppliers as $supplier): ?>
                                <option value="<?php echo (int) $supplier['id']; ?>"><?php echo htmlspecialchars($supplier['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status">
                            <option value="draft">Draft</option>
                            <option value="pending">Pending approval</option>
                            <option value="approved">Approved</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Material</label>
                        <select name="material_id">
                            <option value="">Select a material</option>
                            <?php foreach ($materials as $material): ?>
                                <option value="<?php echo (int) $material['id']; ?>">
                                    <?php echo htmlspecialchars($material['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Quantity</label>
                            <input type="number" step="0.01" name="qty" placeholder="0">
                        </div>
                        <div class="form-group">
                            <label>Unit cost (₱)</label>
                            <input type="number" step="0.01" name="unit_cost" placeholder="0.00">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">Create Request</button>
                </form>
            </div>

            <div class="card scorecard-card">
                <div class="card-header">
                    <h3><i class="fas fa-clipboard-list text-primary"></i> Purchase Requests Queue</h3>
                    <p class="text-muted">Track approvals, items, and closure actions.</p>
                </div>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Request</th>
                            <th>Supplier</th>
                            <th>Items</th>
                            <th>Total cost</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($purchase_requests)): ?>
                            <tr>
                                <td colspan="6" class="text-muted">No purchase requests yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($purchase_requests as $request): ?>
                                <tr>
                                    <td>#<?php echo (int) $request['id']; ?></td>
                                    <td><?php echo htmlspecialchars($request['supplier_name'] ?? 'Unassigned'); ?></td>
                                    <td><?php echo (int) $request['item_count']; ?></td>
                                    <td>₱<?php echo number_format((float) $request['total_cost'], 2); ?></td>
                                    <td>
                                        <span class="badge badge-light"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $request['status']))); ?></span>
                                    </td>
                                    <td>
                                        <form method="POST" class="d-inline">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="update_request_status">
                                            <input type="hidden" name="request_id" value="<?php echo (int) $request['id']; ?>">
                                            <select name="status" onchange="this.form.submit()">
                                                <?php
                                                $status_options = ['draft' => 'Draft', 'pending' => 'Pending', 'approved' => 'Approved', 'closed' => 'Closed', 'cancelled' => 'Cancelled'];
                                                ?>
                                                <?php foreach ($status_options as $value => $label): ?>
                                                    <option value="<?php echo $value; ?>" <?php echo $request['status'] === $value ? 'selected' : ''; ?>>
                                                        <?php echo $label; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </form>
                                    </td>
                                </tr>
                                <?php if (!empty($request_items_by_id[$request['id']])): ?>
                                    <tr>
                                        <td colspan="6">
                                            <strong>Items:</strong>
                                            <ul class="text-muted">
                                                <?php foreach ($request_items_by_id[$request['id']] as $item): ?>
                                                    <li>
                                                        <?php echo htmlspecialchars($item['material_name']); ?> -
                                                        <?php echo htmlspecialchars($item['qty']); ?> <?php echo htmlspecialchars($item['unit']); ?>
                                                        (₱<?php echo number_format((float) ($item['unit_cost'] ?? 0), 2); ?>)
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                <div class="card-header">
                    <h4><i class="fas fa-plus text-primary"></i> Add Request Item</h4>
                </div>
                <form method="POST">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="add_request_item">
                    <div class="form-group">
                        <label>Request</label>
                        <select name="request_id" required>
                            <option value="">Select request</option>
                            <?php foreach ($purchase_requests as $request): ?>
                                <option value="<?php echo (int) $request['id']; ?>">PR #<?php echo (int) $request['id']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Material</label>
                        <select name="material_id" required>
                            <option value="">Select material</option>
                            <?php foreach ($materials as $material): ?>
                                <option value="<?php echo (int) $material['id']; ?>"><?php echo htmlspecialchars($material['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Quantity</label>
                            <input type="number" step="0.01" name="qty" required>
                        </div>
                        <div class="form-group">
                            <label>Unit cost (₱)</label>
                            <input type="number" step="0.01" name="unit_cost" placeholder="0.00">
                        </div>
                    </div>
                <button type="submit" class="btn btn-primary">Add Item</button>
                </form>
            </div>

            <div class="card automation-card">
                <div class="card-header">
                    <h3><i class="fas fa-gear text-primary"></i> Automation</h3>
                    <p class="text-muted">Purchase workflows that stay aligned with demand.</p>
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

            <div class="card review-card">
                <div class="card-header">
                    <h3><i class="fas fa-clipboard-check text-primary"></i> Review &amp; Compliance</h3>
                    <p class="text-muted">Keep suppliers evaluated and aligned with quality standards.</p>
                </div>
                <div class="review-list">
                    <?php foreach ($review_checkpoints as $review): ?>
                        <div class="review-item">
                            <i class="<?php echo $review['icon']; ?>"></i>
                            <div>
                                <strong><?php echo $review['title']; ?></strong>
                                <p class="text-muted mb-0"><?php echo $review['detail']; ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
