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

    if ($action === 'log_transaction') {
        $material_id = (int) ($_POST['material_id'] ?? 0);
        $type = $_POST['type'] ?? '';
        $qty = $_POST['qty'] ?? '';
        $ref_type = trim($_POST['ref_type'] ?? '');
        $ref_id = $_POST['ref_id'] !== '' ? (int) $_POST['ref_id'] : null;

        if ($material_id <= 0 || $type === '' || $qty === '' || !is_numeric($qty)) {
            $error = 'Please provide a valid material transaction.';
        } else {
            $delta = (float) $qty;
            if (in_array($type, ['issue', 'out'], true)) {
                $delta = -abs($delta);
            } elseif (in_array($type, ['return', 'in'], true)) {
                $delta = abs($delta);
            }

            try {
                $pdo->beginTransaction();
                $update_stmt = $pdo->prepare("UPDATE raw_materials SET current_stock = current_stock + ? WHERE id = ? AND shop_id = ?");
                $update_stmt->execute([$delta, $material_id, $shop_id]);

                $log_stmt = $pdo->prepare("
                    INSERT INTO inventory_transactions (shop_id, material_id, type, qty, ref_type, ref_id)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $log_stmt->execute([$shop_id, $material_id, $type, $delta, $ref_type !== '' ? $ref_type : null, $ref_id]);
                $pdo->commit();
                $success = 'Inventory transaction logged successfully.';
            } catch (PDOException $e) {
                $pdo->rollBack();
                $error = 'Unable to log inventory transaction.';
            }
        }
    }

    if ($action === 'generate_purchase_requests') {
        $low_stock_stmt = $pdo->prepare("
            SELECT id, name, current_stock, min_stock_level, unit_cost
            FROM raw_materials
            WHERE shop_id = ? AND min_stock_level IS NOT NULL AND current_stock <= min_stock_level
        ");
        $low_stock_stmt->execute([$shop_id]);
        $low_stock_materials = $low_stock_stmt->fetchAll();
        $created = 0;

        foreach ($low_stock_materials as $material) {
            $existing_stmt = $pdo->prepare("
                SELECT pri.id
                FROM purchase_request_items pri
                JOIN purchase_requests pr ON pr.id = pri.request_id
                WHERE pr.shop_id = ? AND pri.material_id = ? AND pr.status IN ('draft','pending','approved')
                LIMIT 1
            ");
            $existing_stmt->execute([$shop_id, $material['id']]);
            if ($existing_stmt->fetchColumn()) {
                continue;
            }

            $qty_needed = (float) $material['min_stock_level'] - (float) $material['current_stock'];
            if ($qty_needed <= 0) {
                continue;
            }

            $request_stmt = $pdo->prepare("
                INSERT INTO purchase_requests (shop_id, supplier_id, status, created_by, created_at)
                VALUES (?, NULL, 'draft', ?, NOW())
            ");
            $request_stmt->execute([$shop_id, $owner_id]);
            $request_id = (int) $pdo->lastInsertId();

            $item_stmt = $pdo->prepare("
                INSERT INTO purchase_request_items (request_id, material_id, qty, unit_cost)
                VALUES (?, ?, ?, ?)
            ");
            $item_stmt->execute([
                $request_id,
                $material['id'],
                $qty_needed,
                $material['unit_cost'] !== null ? $material['unit_cost'] : null,
            ]);
            $created++;
        }

        $success = $created > 0 ? 'Draft purchase requests generated.' : 'No new purchase requests were required.';
    }
}

$materials_stmt = $pdo->prepare("SELECT id, name, unit, current_stock, min_stock_level FROM raw_materials WHERE shop_id = ? ORDER BY name");
$materials_stmt->execute([$shop_id]);
$materials = $materials_stmt->fetchAll();

$transactions_stmt = $pdo->prepare("
    SELECT it.*, rm.name as material_name
    FROM inventory_transactions it
    JOIN raw_materials rm ON rm.id = it.material_id
    WHERE it.shop_id = ?
    ORDER BY it.created_at DESC
    LIMIT 10
");
$transactions_stmt->execute([$shop_id]);
$transactions = $transactions_stmt->fetchAll();

$low_stock = array_filter($materials, function ($material) {
    return $material['min_stock_level'] !== null && (float) $material['current_stock'] <= (float) $material['min_stock_level'];
});

$production_stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE shop_id = ? AND status = 'in_progress'");
$production_stmt->execute([$shop_id]);
$active_production = (int) $production_stmt->fetchColumn();

$deducted_stmt = $pdo->prepare("
    SELECT COUNT(*) FROM inventory_transactions
    WHERE shop_id = ? AND type = 'issue' AND DATE(created_at) = CURDATE()
");
$deducted_stmt->execute([$shop_id]);
$deducted_today = (int) $deducted_stmt->fetchColumn();

$finished_stmt = $pdo->prepare("
    SELECT COUNT(*) FROM finished_goods
    WHERE shop_id = ? AND YEARWEEK(stored_at, 1) = YEARWEEK(CURDATE(), 1)
");
$finished_stmt->execute([$shop_id]);
$finished_this_week = (int) $finished_stmt->fetchColumn();

$sync_kpis = [
    [
        'label' => 'Active production runs',
        'value' => $active_production,
        'note' => 'Jobs consuming materials today.',
        'icon' => 'fas fa-industry',
        'tone' => 'primary',
    ],
    [
        'label' => 'Materials deducted',
        'value' => $deducted_today,
        'note' => 'Issue transactions logged today.',
        'icon' => 'fas fa-scissors',
        'tone' => 'success',
    ],
    [
        'label' => 'Purchase triggers',
        'value' => count($low_stock),
        'note' => 'Low-stock materials detected.',
        'icon' => 'fas fa-cart-plus',
        'tone' => 'warning',
    ],
    [
        'label' => 'Finished goods logged',
        'value' => $finished_this_week,
        'note' => 'Units completed this week.',
        'icon' => 'fas fa-box-open',
        'tone' => 'info',
    ],
];

$sync_events = [
    [
        'stage' => 'Order release',
        'inventory' => 'Reserve thread + backing',
        'production' => 'Allocate machine time',
        'purchasing' => 'Check supplier ETAs',
        'status' => $active_production > 0 ? 'On track' : 'Scheduled',
    ],
    [
        'stage' => 'Production start',
        'inventory' => 'Log material issue transactions',
        'production' => 'Track batch consumption',
        'purchasing' => 'Trigger low-stock PRs',
        'status' => count($low_stock) > 0 ? 'Action required' : 'On track',
    ],
    [
        'stage' => 'QC approval',
        'inventory' => 'Reconcile scrap + waste',
        'production' => 'Close work order',
        'purchasing' => 'Update vendor score',
        'status' => 'On track',
    ],
    [
        'stage' => 'Finished goods',
        'inventory' => 'Receive FG into storage',
        'production' => 'Hand off to fulfillment',
        'purchasing' => 'Confirm inbound coverage',
        'status' => $finished_this_week > 0 ? 'On track' : 'Scheduled',
    ],
];

$supply_chain_signals = [
    [
        'title' => 'Material deduction queue',
        'detail' => $deducted_today > 0 ? 'Issue transactions logged today.' : 'No material issues logged yet today.',
        'icon' => 'fas fa-layer-group',
    ],
    [
        'title' => 'Purchase trigger thresholds',
        'detail' => count($low_stock) > 0 ? count($low_stock) . ' materials below safety stock.' : 'All materials above reorder point.',
        'icon' => 'fas fa-circle-exclamation',
    ],
    [
        'title' => 'Finished goods logging',
        'detail' => $finished_this_week > 0 ? $finished_this_week . ' orders stored this week.' : 'No finished goods logged this week.',
        'icon' => 'fas fa-clipboard-check',
    ],
];

$automation_rules = [
    [
        'title' => 'Material deduction',
        'detail' => 'Log issue transactions as production starts to keep real-time stock accuracy.',
        'icon' => 'fas fa-robot',
    ],
    [
        'title' => 'Purchase triggers',
        'detail' => 'Auto-create purchase request drafts when materials drop below reorder points.',
        'icon' => 'fas fa-cart-shopping',
    ],
    [
        'title' => 'Finished goods logging',
        'detail' => 'Log completed items to finished goods storage with batch traceability.',
        'icon' => 'fas fa-boxes-packing',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory–Production–Supply Chain Automation Engine - Owner</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .automation-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 1.5rem;
            margin: 2rem 0;
        }

        .sync-kpi {
            grid-column: span 3;
        }

        .purpose-card,
        .signals-card {
            grid-column: span 12;
        }

        .sync-map-card {
            grid-column: span 8;
        }

        .transaction-card {
            grid-column: span 8;
        }

        .form-card {
            grid-column: span 4;
        }

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

        .signal-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1rem;
        }

        .signal-item {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 1rem;
            background: white;
            display: flex;
            gap: 0.75rem;
            align-items: flex-start;
        }

        .signal-item i {
            color: var(--primary-600);
            margin-top: 0.25rem;
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
                    <h2>Inventory–Production–Supply Chain Automation Engine</h2>
                    <p class="text-muted">Synchronize inventory, production, and purchasing workflows with a unified control layer.</p>
                </div>
                <span class="badge badge-primary"><i class="fas fa-sitemap"></i> Module 26</span>
            </div>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="automation-grid">
            <div class="card purpose-card">
                <div class="card-header">
                    <h3><i class="fas fa-bullseye text-primary"></i> Purpose</h3>
                </div>
                <p class="text-muted mb-0">
                    Synchronizes inventory balances, production activity, and purchasing decisions so material usage, supply
                    triggers, and finished goods updates stay aligned in real time.
                </p>
            </div>

            <?php foreach ($sync_kpis as $kpi): ?>
                <div class="card sync-kpi">
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
                    <h3><i class="fas fa-clipboard-check text-primary"></i> Log Inventory Transaction</h3>
                    <p class="text-muted">Deduct or return materials when production starts or completes.</p>
                </div>
                <form method="POST">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="log_transaction">
                    <div class="form-group">
                        <label>Material</label>
                        <select name="material_id" required>
                            <option value="">Select material</option>
                            <?php foreach ($materials as $material): ?>
                                <option value="<?php echo (int) $material['id']; ?>"><?php echo htmlspecialchars($material['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Type</label>
                        <select name="type" required>
                            <option value="issue">Issue (production start)</option>
                            <option value="return">Return (production complete)</option>
                            <option value="adjust">Adjust</option>
                            <option value="in">Receive</option>
                            <option value="out">Consume</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Quantity</label>
                        <input type="number" step="0.01" name="qty" required>
                    </div>
                    <div class="form-group">
                        <label>Reference type</label>
                        <input type="text" name="ref_type" placeholder="order">
                    </div>
                    <div class="form-group">
                        <label>Reference ID</label>
                        <input type="number" name="ref_id" placeholder="123">
                    </div>
                    <button type="submit" class="btn btn-primary">Log Transaction</button>
                </form>
                <form method="POST" class="mt-3">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="generate_purchase_requests">
                    <button type="submit" class="btn btn-light">Generate Draft Purchase Requests</button>
                </form>
            </div>

            <div class="card sync-map-card">
                <div class="card-header">
                    <h3><i class="fas fa-diagram-project text-primary"></i> Synchronization Map</h3>
                    <p class="text-muted">Key handoffs across inventory, production, and purchasing.</p>
                </div>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Stage</th>
                            <th>Inventory</th>
                            <th>Production</th>
                            <th>Purchasing</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sync_events as $event): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($event['stage']); ?></td>
                                <td><?php echo htmlspecialchars($event['inventory']); ?></td>
                                <td><?php echo htmlspecialchars($event['production']); ?></td>
                                <td><?php echo htmlspecialchars($event['purchasing']); ?></td>
                                <td>
                                    <span class="badge badge-<?php echo $event['status'] === 'Action required' ? 'warning' : 'success'; ?>">
                                        <?php echo htmlspecialchars($event['status']); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="card transaction-card">
                <div class="card-header">
                    <h3><i class="fas fa-layer-group text-primary"></i> Inventory Transaction Ledger</h3>
                    <p class="text-muted">Latest material deductions and returns.</p>
                </div>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Material</th>
                            <th>Type</th>
                            <th>Qty</th>
                            <th>Reference</th>
                            <th>Logged</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($transactions)): ?>
                            <tr>
                                <td colspan="5" class="text-muted">No transactions recorded yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($transactions as $transaction): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($transaction['material_name']); ?></td>
                                    <td><?php echo htmlspecialchars(ucfirst($transaction['type'])); ?></td>
                                    <td><?php echo number_format((float) $transaction['qty'], 2); ?></td>
                                    <td><?php echo htmlspecialchars($transaction['ref_type'] ?? '—'); ?> <?php echo $transaction['ref_id'] ? '#' . (int) $transaction['ref_id'] : ''; ?></td>
                                    <td><?php echo date('M d, Y H:i', strtotime($transaction['created_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                <div class="card-header">
                    <h4><i class="fas fa-circle-exclamation text-primary"></i> Low-stock Materials</h4>
                </div>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Material</th>
                            <th>On hand</th>
                            <th>Reorder point</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($low_stock)): ?>
                            <tr>
                                <td colspan="3" class="text-muted">All materials above reorder point.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($low_stock as $material): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($material['name']); ?></td>
                                    <td><?php echo number_format((float) $material['current_stock'], 2); ?></td>
                                    <td><?php echo number_format((float) $material['min_stock_level'], 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="card automation-card">
                <div class="card-header">
                    <h3><i class="fas fa-gear text-primary"></i> Automation</h3>
                    <p class="text-muted">Rules that keep the supply chain engine in sync.</p>
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

            <div class="card signals-card">
                <div class="card-header">
                    <h3><i class="fas fa-wave-square text-primary"></i> Supply Chain Signals</h3>
                    <p class="text-muted">Operational indicators feeding the automation engine.</p>
                </div>
                <div class="signal-list">
                    <?php foreach ($supply_chain_signals as $signal): ?>
                        <div class="signal-item">
                            <i class="<?php echo $signal['icon']; ?>"></i>
                            <div>
                                <strong><?php echo $signal['title']; ?></strong>
                                <p class="text-muted mb-0"><?php echo $signal['detail']; ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
