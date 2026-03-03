<?php
session_start();

require_once '../config/db.php';
require_role('owner');

$owner_id = (int) ($_SESSION['user']['id'] ?? 0);
$shop_stmt = $pdo->prepare('SELECT * FROM shops WHERE owner_id = ? LIMIT 1');
$shop_stmt->execute([$owner_id]);
$shop = $shop_stmt->fetch(PDO::FETCH_ASSOC);

if(!$shop) {
    header('Location: create_shop.php');
    exit();
}

$shop_id = (int) $shop['id'];
$status_filter = sanitize($_GET['status'] ?? 'all');
$allowed_status_filters = ['all', 'pending', 'accepted', 'in_progress', 'completed', 'cancelled'];
if(!in_array($status_filter, $allowed_status_filters, true)) {
    $status_filter = 'all';
}

$quote_filter = sanitize($_GET['quote_status'] ?? 'all');
$allowed_quote_filters = ['all', 'pending_acceptance', 'accepted', 'waiting_owner', 'rejected', 'negotiation_requested', 'shop_rejected'];
if(!in_array($quote_filter, $allowed_quote_filters, true)) {
    $quote_filter = 'all';
}


if(isset($_GET['accepted']) && $_GET['accepted'] === '1') {
    $accept_success = 'Quotation request accepted. It now appears in official orders.';
}

$where_clauses = [
    'o.shop_id = :shop_id',
    "(o.client_notes = 'Quote request submitted via Services page.' OR JSON_EXTRACT(o.quote_details, '$.requested_from_services') = true)",
];
$params = ['shop_id' => $shop_id];

if($status_filter !== 'all') {
    $where_clauses[] = 'o.status = :order_status';
    $params['order_status'] = $status_filter;
}

$requests_sql = "
    SELECT o.id, o.order_number, o.service_type, o.design_description, o.design_file, o.status,
           o.price, o.quote_details, o.created_at, o.updated_at, o.design_approved,
           c.fullname AS client_name
    FROM orders o
    JOIN users c ON c.id = o.client_id
    WHERE " . implode(' AND ', $where_clauses) . "
    ORDER BY o.updated_at DESC, o.created_at DESC
";

$requests_stmt = $pdo->prepare($requests_sql);
$requests_stmt->execute($params);
$raw_requests = $requests_stmt->fetchAll(PDO::FETCH_ASSOC);


if(isset($_POST['accept_quote_request'])) {
    $order_id = (int) ($_POST['order_id'] ?? 0);

    $request_stmt = $pdo->prepare("SELECT o.id, o.order_number, o.quote_details, o.client_id
        FROM orders o
        WHERE o.id = ? AND o.shop_id = ?
        LIMIT 1");
    $request_stmt->execute([$order_id, $shop_id]);
    $request = $request_stmt->fetch(PDO::FETCH_ASSOC);

    if(!$request) {
        $accept_error = 'Unable to locate this quotation request.';
    } else {
        $details = [];
        if(!empty($request['quote_details'])) {
            $decoded = json_decode($request['quote_details'], true);
            if(is_array($decoded)) {
                $details = $decoded;
            }
        }

        $owner_request_status = $details['owner_request_status'] ?? 'pending_acceptance';
        if($owner_request_status === 'accepted') {
            $accept_error = 'This quotation request is already accepted.';
        } else {
            $details['owner_request_status'] = 'accepted';
            $details['owner_request_accepted_at'] = date('c');
            $details['owner_request_accepted_by'] = $_SESSION['user']['fullname'] ?? 'Shop owner';

            $update_stmt = $pdo->prepare("UPDATE orders SET quote_details = ?, updated_at = NOW() WHERE id = ? AND shop_id = ?");
            $update_stmt->execute([
                json_encode($details),
                $order_id,
                $shop_id,
            ]);

            create_notification(
                $pdo,
                (int) $request['client_id'],
                $order_id,
                'success',
                sprintf('Your quotation request #%s has been accepted by the shop and is now an official order.', $request['order_number'])
            );

            $accept_success = sprintf('Quotation request #%s is now accepted and considered an official order.', $request['order_number']);
            header('Location: quotation_requests.php?status=' . urlencode($status_filter) . '&quote_status=' . urlencode($quote_filter) . '&accepted=1');
            exit();
        }
    }
}

$requests = [];
$summary = [
    'total' => 0,
    'pending_acceptance' => 0,
    'waiting_owner' => 0,
    'accepted' => 0,
    'rejected' => 0,
    'negotiation_requested' => 0,
];

foreach($raw_requests as $row) {
    $details = [];
    if(!empty($row['quote_details'])) {
        $decoded = json_decode($row['quote_details'], true);
        if(is_array($decoded)) {
            $details = $decoded;
        }
    }

    $client_quote_status = $details['price_quote_status'] ?? 'waiting_owner';
    if($client_quote_status === '' || $client_quote_status === null) {
        $client_quote_status = 'waiting_owner';
    }

    $owner_request_status = $details['owner_request_status'] ?? 'pending_acceptance';

    $summary['total']++;
    $effective_quote_status = $owner_request_status === 'accepted' ? $client_quote_status : 'pending_acceptance';
    if(isset($summary[$effective_quote_status])) {
        $summary[$effective_quote_status]++;
    }

    if($quote_filter !== 'all' && $effective_quote_status !== $quote_filter) {
        continue;
    }

    $row['owner_request_status'] = $owner_request_status;
    $row['client_quote_status'] = $client_quote_status;
    $row['owner_request_status'] = $owner_request_status;
    $row['quote_comment'] = $details['price_quote_comment'] ?? '';
    $row['timeline_days'] = $details['owner_quote_update']['timeline_days'] ?? null;
    $row['owner_message'] = $details['owner_quote_update']['owner_message'] ?? '';
    $requests[] = $row;
}

$status_badges = [
    'pending' => 'badge-warning',
    'accepted' => 'badge-info',
    'in_progress' => 'badge-primary',
    'completed' => 'badge-success',
    'cancelled' => 'badge-danger',
];

$quote_status_labels = [
    'pending_acceptance' => 'Pending owner acceptance',
    'waiting_owner' => 'Waiting for owner quote',
    'accepted' => 'Client accepted quote',
    'rejected' => 'Client rejected quote',
    'negotiation_requested' => 'Negotiation requested',
    'shop_rejected' => 'Client selected another shop',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quotation Requests - <?php echo htmlspecialchars($shop['shop_name']); ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 0.75rem;
            margin-bottom: 1rem;
        }

        .summary-card {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            background: var(--bg-primary);
            padding: 0.9rem;
        }

        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 0.75rem;
            margin-bottom: 1rem;
        }

        .request-table td {
            vertical-align: top;
        }

        .request-meta {
            font-size: 0.86rem;
            color: var(--gray-600);
            margin-top: 0.35rem;
        }

        .quote-status-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            border-radius: 999px;
            border: 1px solid var(--gray-300);
            background: var(--bg-secondary);
            padding: 0.18rem 0.6rem;
            font-size: 0.8rem;
        }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/includes/owner_navbar.php'; ?>

    <div class="container">
        <div class="dashboard-header fade-in">
            <div class="d-flex justify-between align-center">
                <div>
                    <h2><i class="fas fa-file-invoice-dollar"></i> Client Quotation Requests</h2>
                    <p class="text-muted">Requests submitted from design proofing before becoming official production orders.</p>
                </div>
                <a href="shop_orders.php" class="btn btn-outline"><i class="fas fa-list"></i> Open Official Orders</a>
            </div>
        </div>

        <div class="summary-grid">
            <div class="summary-card"><strong><?php echo (int) $summary['total']; ?></strong><br><span class="text-muted">Total quote requests</span></div>
            <div class="summary-card"><strong><?php echo (int) $summary['pending_acceptance']; ?></strong><br><span class="text-muted">Pending your acceptance</span></div>
            <div class="summary-card"><strong><?php echo (int) $summary['waiting_owner']; ?></strong><br><span class="text-muted">Waiting for your quote</span></div>
            <div class="summary-card"><strong><?php echo (int) $summary['accepted']; ?></strong><br><span class="text-muted">Client accepted</span></div>
            <div class="summary-card"><strong><?php echo (int) $summary['negotiation_requested']; ?></strong><br><span class="text-muted">Needs negotiation</span></div>
            <div class="summary-card"><strong><?php echo (int) $summary['rejected']; ?></strong><br><span class="text-muted">Rejected by client</span></div>
        </div>

         <?php if(!empty($accept_error)): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($accept_error); ?></div>
        <?php endif; ?>
        <?php if(!empty($accept_success)): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($accept_success); ?></div>
        <?php endif; ?>

        <form method="GET" class="card" style="margin-bottom: 1rem;">
            <div class="filters-grid">
                <div class="form-group" style="margin: 0;">
                    <label>Order Status</label>
                    <select name="status" class="form-control">
                        <?php foreach($allowed_status_filters as $option): ?>
                            <option value="<?php echo htmlspecialchars($option); ?>" <?php echo $status_filter === $option ? 'selected' : ''; ?>>
                                <?php echo ucfirst(str_replace('_', ' ', $option)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin: 0;">
                    <label>Client Quote Response</label>
                    <select name="quote_status" class="form-control">
                        <?php foreach($allowed_quote_filters as $option): ?>
                            <option value="<?php echo htmlspecialchars($option); ?>" <?php echo $quote_filter === $option ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($quote_status_labels[$option] ?? ucfirst(str_replace('_', ' ', $option))); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin: 0; display: flex; align-items: flex-end;">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Apply Filters</button>
                </div>
            </div>
        </form>

        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-clipboard-list text-primary"></i> Requests Queue</h3>
            </div>

            <?php if(empty($requests)): ?>
                <p class="text-muted">No quotation requests matched the selected filters.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table request-table">
                        <thead>
                            <tr>
                                <th>Order</th>
                                <th>Client</th>
                                <th>Service &amp; Design</th>
                                <th>Order Status</th>
                                <th>Quote Response</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($requests as $request): ?>
                                <tr>
                                    <td>
                                        <strong>#<?php echo htmlspecialchars($request['order_number']); ?></strong>
                                        <div class="request-meta">Created: <?php echo date('M d, Y h:i A', strtotime($request['created_at'])); ?></div>
                                        <div class="request-meta">Updated: <?php echo date('M d, Y h:i A', strtotime($request['updated_at'])); ?></div>
                                    </td>
                                    <td><?php echo htmlspecialchars($request['client_name']); ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($request['service_type']); ?></strong>
                                        <div class="request-meta"><?php echo nl2br(htmlspecialchars($request['design_description'])); ?></div>
                                        <?php if(!empty($request['timeline_days'])): ?>
                                            <div class="request-meta">Proposed timeline: <?php echo (int) $request['timeline_days']; ?> day(s)</div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php $order_status = $request['status']; ?>
                                        <span class="badge <?php echo $status_badges[$order_status] ?? 'badge-secondary'; ?>">
                                            <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $order_status))); ?>
                                        </span>
                                    </td>
                                    <td>
                                         <?php $quote_status = $request['effective_quote_status']; ?>
                                        <span class="quote-status-pill">
                                            <i class="fas fa-comment-dots text-primary"></i>
                                            <?php echo htmlspecialchars($quote_status_labels[$quote_status] ?? ucfirst(str_replace('_', ' ', $quote_status))); ?>
                                        </span>
                                        <?php if($request['quote_comment'] !== ''): ?>
                                            <div class="request-meta"><strong>Comment:</strong> <?php echo htmlspecialchars($request['quote_comment']); ?></div>
                                        <?php endif; ?>
                                        <?php if($request['owner_message'] !== ''): ?>
                                            <div class="request-meta"><strong>Your latest message:</strong> <?php echo htmlspecialchars($request['owner_message']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if(($request['owner_request_status'] ?? 'pending_acceptance') !== 'accepted'): ?>
                                            <form method="POST" style="display:inline;">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="order_id" value="<?php echo (int) $request['id']; ?>">
                                                <button type="submit" name="accept_quote_request" class="btn btn-sm btn-success" onclick="return confirm('Accept this quotation request and move it to official orders?');"><i class="fas fa-check"></i> Accept Request</button>
                                            </form>
                                        <?php else: ?>
                                            <a class="btn btn-sm btn-primary" href="shop_orders.php?filter=pending"><i class="fas fa-pen"></i> Update Quote</a>
                                        <?php endif; ?>
                                        <a class="btn btn-sm btn-outline" href="view_order.php?id=<?php echo (int) $request['id']; ?>"><i class="fas fa-eye"></i> View</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
