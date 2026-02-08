<?php
session_start();
require_once '../config/db.php';
require_once '../config/constants.php';
require_role('owner');

$owner_id = $_SESSION['user']['id'];
$shop_stmt = $pdo->prepare("SELECT * FROM shops WHERE owner_id = ?");
$shop_stmt->execute([$owner_id]);
$shop = $shop_stmt->fetch();

if(!$shop) {
    header("Location: create_shop.php");
    exit();
}

$order_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if($order_id <= 0) {
    header("Location: shop_orders.php");
    exit();
}

$order_stmt = $pdo->prepare("
    SELECT o.*, 
           u.fullname AS client_name,
           u.email AS client_email,
           u.phone AS client_phone,
           s.shop_name,
           au.fullname AS assigned_name,
           dv.version_no AS design_version_no,
           dv.preview_file AS design_version_preview,
           dv.created_at AS design_version_created_at,
           dp.title AS design_project_title
    FROM orders o
    JOIN users u ON o.client_id = u.id
    JOIN shops s ON o.shop_id = s.id
    LEFT JOIN design_versions dv ON dv.id = o.design_version_id
    LEFT JOIN design_projects dp ON dp.id = dv.project_id
    LEFT JOIN users au ON o.assigned_to = au.id
    WHERE o.id = ? AND o.shop_id = ?
    LIMIT 1
");
$order_stmt->execute([$order_id, $shop['id']]);
$order = $order_stmt->fetch();

if(!$order) {
    header("Location: shop_orders.php");
    exit();
}

$proof_stmt = $pdo->prepare("
    SELECT design_file, status, updated_at
    FROM design_approvals
    WHERE order_id = ?
    ORDER BY updated_at DESC
    LIMIT 1
");
$proof_stmt->execute([$order_id]);
$latest_proof = $proof_stmt->fetch();

$proof_history_stmt = $pdo->prepare("
    SELECT design_file, status, updated_at
    FROM design_approvals
    WHERE order_id = ?
    ORDER BY updated_at DESC
");
$proof_history_stmt->execute([$order_id]);
$proof_history = $proof_history_stmt->fetchAll();

$invoice_stmt = $pdo->prepare("SELECT * FROM order_invoices WHERE order_id = ?");
$invoice_stmt->execute([$order_id]);
$invoice = $invoice_stmt->fetch();

$payment_stmt = $pdo->prepare("
    SELECT * FROM payments
    WHERE order_id = ?
    ORDER BY created_at DESC
    LIMIT 1
");
$payment_stmt->execute([$order_id]);
$payment = $payment_stmt->fetch();

$receipt = null;
if($payment) {
    $receipt_stmt = $pdo->prepare("SELECT * FROM payment_receipts WHERE payment_id = ?");
    $receipt_stmt->execute([$payment['id']]);
    $receipt = $receipt_stmt->fetch();
}

$refund_stmt = $pdo->prepare("
    SELECT * FROM payment_refunds
    WHERE order_id = ?
    ORDER BY requested_at DESC
    LIMIT 1
");
$refund_stmt->execute([$order_id]);
$refund = $refund_stmt->fetch();

$active_staff_stmt = $pdo->prepare("
    SELECT 
        se.user_id,
        u.fullname,
        se.availability_days,
        se.availability_start,
        se.availability_end,
        se.max_active_orders
    FROM shop_staffs se
    JOIN users u ON se.user_id = u.id
    WHERE se.shop_id = ? AND se.status = 'active'
    ORDER BY u.fullname ASC
");
$active_staff_stmt->execute([$shop['id']]);
$active_staff = $active_staff_stmt->fetchAll();
$active_staff_map = [];
foreach($active_staff as $staff_member) {
    $active_staff_map[(int) $staff_member['user_id']] = $staff_member;
}

if(isset($_POST['schedule_job'])) {
    $schedule_order_id = (int) ($_POST['order_id'] ?? 0);
    $staff_id = (int) ($_POST['staff_id'] ?? 0);
    $scheduled_date = $_POST['scheduled_date'] ?? '';
    $scheduled_time = $_POST['scheduled_time'] ?? '';
    $task_description = sanitize($_POST['task_description'] ?? '');

    $schedule_order_stmt = $pdo->prepare("SELECT id, status, order_number, assigned_to FROM orders WHERE id = ? AND shop_id = ?");
    $schedule_order_stmt->execute([$schedule_order_id, $shop['id']]);
    $schedule_order = $schedule_order_stmt->fetch();

    $date_object = DateTime::createFromFormat('Y-m-d', $scheduled_date);
    $scheduled_time_value = $scheduled_time !== '' ? $scheduled_time : null;
    if($scheduled_time_value !== null) {
        $time_object = DateTime::createFromFormat('H:i', $scheduled_time_value);
    }

    if($schedule_order_id !== $order_id) {
        $error = "Unable to schedule a different order from this page.";
    } elseif(!$schedule_order) {
        $error = "Order not found for this shop.";
    } elseif(in_array($schedule_order['status'], ['completed', 'cancelled'], true)) {
        $error = "Completed or cancelled orders cannot be scheduled.";
    } elseif($staff_id <= 0 || !isset($active_staff_map[$staff_id])) {
        $error = "Please select an active staff member to schedule.";
    } elseif($scheduled_date === '' || !$date_object) {
        $error = "Please provide a valid scheduled date.";
    } elseif($scheduled_time_value !== null && !$time_object) {
        $error = "Please provide a valid scheduled time.";
    } else {
        $staff = $active_staff_map[$staff_id];
        $availability_days = [];
        if(!empty($staff['availability_days'])) {
            $decoded_days = json_decode($staff['availability_days'], true);
            if(is_array($decoded_days)) {
                $availability_days = array_map('intval', $decoded_days);
            }
        }

        $schedule_day = (int) $date_object->format('N');
        if(!empty($availability_days) && !in_array($schedule_day, $availability_days, true)) {
            $error = "This staff member is not available on the selected date.";
        } elseif($scheduled_time_value !== null && $staff['availability_start'] && $staff['availability_end']
            && ($scheduled_time_value < $staff['availability_start'] || $scheduled_time_value > $staff['availability_end'])) {
            $error = "The scheduled time is outside this staff member's availability hours.";
        } else {
            $capacity_stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM (
                    SELECT js.order_id
                    FROM job_schedule js
                    JOIN orders o ON js.order_id = o.id
                    WHERE js.staff_id = ?
                      AND js.scheduled_date = ?
                      AND o.status NOT IN ('completed', 'cancelled')
                      AND js.order_id != ?
                    UNION
                    SELECT o.id
                    FROM orders o
                    WHERE o.assigned_to = ?
                      AND o.scheduled_date = ?
                      AND o.status NOT IN ('completed', 'cancelled')
                      AND o.id != ?
                      AND NOT EXISTS (
                        SELECT 1 FROM job_schedule js2
                        WHERE js2.order_id = o.id AND js2.staff_id = ?
                      )
                ) as scheduled_jobs
            ");
            $capacity_stmt->execute([
                $staff_id,
                $scheduled_date,
                $schedule_order_id,
                $staff_id,
                $scheduled_date,
                $schedule_order_id,
                $staff_id,
            ]);
            $scheduled_count = (int) $capacity_stmt->fetchColumn();

            $max_active_orders = (int) ($staff['max_active_orders'] ?? 0);
            if($max_active_orders > 0 && $scheduled_count >= $max_active_orders) {
                $error = "Scheduling this job would exceed the staff member's daily capacity.";
            } else {
                if($scheduled_time_value !== null) {
                    $conflict_stmt = $pdo->prepare("
                        SELECT COUNT(*)
                        FROM job_schedule
                        WHERE staff_id = ?
                          AND scheduled_date = ?
                          AND scheduled_time = ?
                          AND order_id != ?
                    ");
                    $conflict_stmt->execute([$staff_id, $scheduled_date, $scheduled_time_value, $schedule_order_id]);
                    $conflict_count = (int) $conflict_stmt->fetchColumn();
                    if($conflict_count > 0) {
                        $error = "This staff member already has a job scheduled at the same time.";
                    }
                }

                if(empty($error)) {
                    $schedule_stmt = $pdo->prepare("SELECT id FROM job_schedule WHERE order_id = ? LIMIT 1");
                    $schedule_stmt->execute([$schedule_order_id]);
                    $existing_schedule = $schedule_stmt->fetch();

                    if($existing_schedule) {
                        $update_schedule_stmt = $pdo->prepare("
                            UPDATE job_schedule
                            SET staff_id = ?, scheduled_date = ?, scheduled_time = ?, task_description = ?
                            WHERE id = ?
                        ");
                        $update_schedule_stmt->execute([
                            $staff_id,
                            $scheduled_date,
                            $scheduled_time_value,
                            $task_description ?: null,
                            $existing_schedule['id']
                        ]);
                    } else {
                        $insert_schedule_stmt = $pdo->prepare("
                            INSERT INTO job_schedule (order_id, staff_id, scheduled_date, scheduled_time, task_description)
                            VALUES (?, ?, ?, ?, ?)
                        ");
                        $insert_schedule_stmt->execute([
                            $schedule_order_id,
                            $staff_id,
                            $scheduled_date,
                            $scheduled_time_value,
                            $task_description ?: null
                        ]);
                    }

                    $update_order_stmt = $pdo->prepare("
                        UPDATE orders
                        SET assigned_to = ?, scheduled_date = ?, updated_at = NOW()
                        WHERE id = ? AND shop_id = ?
                    ");
                    $update_order_stmt->execute([$staff_id, $scheduled_date, $schedule_order_id, $shop['id']]);

                    if($max_active_orders > 0 && $scheduled_count + 1 === $max_active_orders) {
                        $warning = "Scheduling this job reaches the staff member's daily capacity.";
                    }

                    create_notification(
                        $pdo,
                        $staff_id,
                        $schedule_order_id,
                        'info',
                        'You have been scheduled for order #' . $schedule_order['order_number'] . ' on ' . date('M d, Y', strtotime($scheduled_date)) . '.'
                    );

                    $success = "Job scheduled successfully.";
                    $order['assigned_to'] = $staff_id;
                    $order['assigned_name'] = $staff['fullname'];
                    $order['scheduled_date'] = $scheduled_date;
                }
            }
        }
    }
}

$schedule_stmt = $pdo->prepare("
    SELECT js.*, u.fullname as staff_name
    FROM job_schedule js
    JOIN users u ON js.staff_id = u.id
    WHERE js.order_id = ?
    LIMIT 1
");
$schedule_stmt->execute([$order_id]);
$schedule_entry = $schedule_stmt->fetch();
$schedule_capacity = null;
if($schedule_entry && isset($active_staff_map[(int) $schedule_entry['staff_id']])) {
    $staff = $active_staff_map[(int) $schedule_entry['staff_id']];
    $capacity_stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM (
            SELECT js.order_id
            FROM job_schedule js
            JOIN orders o ON js.order_id = o.id
            WHERE js.staff_id = ?
              AND js.scheduled_date = ?
              AND o.status NOT IN ('completed', 'cancelled')
            UNION
            SELECT o.id
            FROM orders o
            WHERE o.assigned_to = ?
              AND o.scheduled_date = ?
              AND o.status NOT IN ('completed', 'cancelled')
              AND NOT EXISTS (
                SELECT 1 FROM job_schedule js2
                WHERE js2.order_id = o.id AND js2.staff_id = ?
              )
        ) as scheduled_jobs
    ");
    $capacity_stmt->execute([
        $schedule_entry['staff_id'],
        $schedule_entry['scheduled_date'],
        $schedule_entry['staff_id'],
        $schedule_entry['scheduled_date'],
        $schedule_entry['staff_id'],
    ]);
    $schedule_capacity = [
        'count' => (int) $capacity_stmt->fetchColumn(),
        'limit' => (int) ($staff['max_active_orders'] ?? 0),
    ];
}

$quote_details = !empty($order['quote_details']) ? json_decode($order['quote_details'], true) : null;
$payment_status = $order['payment_status'] ?? 'unpaid';
$payment_class = 'payment-' . $payment_status;
$design_file_name = $order['design_file'] ?? null;
$design_file = $design_file_name
    ? '../assets/uploads/designs/' . $design_file_name
    : null;
    $design_file_extension = $design_file_name ? strtolower(pathinfo($design_file_name, PATHINFO_EXTENSION)) : '';
$is_design_image = $design_file_name && in_array($design_file_extension, ALLOWED_IMAGE_TYPES, true);
$design_version_name = $order['design_version_preview'] ?? null;
$design_version_file = $design_version_name ? '../assets/uploads/designs/' . $design_version_name : null;
$design_version_extension = $design_version_name ? strtolower(pathinfo($design_version_name, PATHINFO_EXTENSION)) : '';
$is_design_version_image = $design_version_name && in_array($design_version_extension, ALLOWED_IMAGE_TYPES, true);
$latest_proof_file = $latest_proof['design_file'] ?? null;
$latest_proof_url = $latest_proof_file ? '../' . $latest_proof_file : null;
$latest_proof_extension = $latest_proof_file ? strtolower(pathinfo($latest_proof_file, PATHINFO_EXTENSION)) : '';
$is_latest_proof_image = $latest_proof_file && in_array($latest_proof_extension, ALLOWED_IMAGE_TYPES, true);
$payment_hold = payment_hold_status($order['status'] ?? STATUS_PENDING, $payment_status);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order #<?php echo htmlspecialchars($order['order_number']); ?> - <?php echo htmlspecialchars($shop['shop_name']); ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .order-card {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }
        .detail-group {
            background: #f8fafc;
            border-radius: 12px;
            padding: 16px;
        }
        .detail-group h4 {
            margin-bottom: 12px;
        }
        .detail-group p {
            margin-bottom: 8px;
        }
        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
            text-transform: capitalize;
        }
        .status-pending { background: #fef9c3; color: #92400e; }
        .status-accepted { background: #ede9fe; color: #5b21b6; }
        .status-in_progress { background: #e0f2fe; color: #0369a1; }
        .status-completed { background: #dcfce7; color: #166534; }
        .status-cancelled { background: #fee2e2; color: #991b1b; }
        .payment-unpaid { background: #fef3c7; color: #92400e; }
        .payment-pending { background: #e0f2fe; color: #0369a1; }
        .payment-paid { background: #dcfce7; color: #166534; }
        .payment-rejected { background: #fee2e2; color: #991b1b; }
        .payment-refund_pending { background: #fef9c3; color: #92400e; }
        .payment-refunded { background: #e2e8f0; color: #475569; }
        .action-row {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .file-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .design-preview {
            margin-top: 16px;
        }
        .design-preview img {
            max-width: 100%;
            height: auto;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            background: #fff;
        }
        .schedule-form {
            display: grid;
            gap: 12px;
        }
        .schedule-grid {
            display: grid;
            gap: 12px;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        }
        .schedule-summary {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            font-size: 0.95rem;
            color: #475569;
        }
        .notice {
            padding: 12px 16px;
            border-radius: 10px;
            margin-bottom: 16px;
        }
        .notice-success { background: #dcfce7; color: #166534; }
        .notice-error { background: #fee2e2; color: #991b1b; }
        .notice-warning { background: #fef9c3; color: #92400e; }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="container d-flex justify-between align-center">
            <a href="dashboard.php" class="navbar-brand">
                <i class="fas fa-store"></i> <?php echo htmlspecialchars($shop['shop_name']); ?>
            </a>
            <ul class="navbar-nav">
                <li><a href="dashboard.php" class="nav-link">Dashboard</a></li>
                <li><a href="shop_profile.php" class="nav-link">Shop Profile</a></li>
                <li><a href="manage_staff.php" class="nav-link">Staff</a></li>
                <li><a href="shop_orders.php" class="nav-link active">Orders</a></li>
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
        <div class="dashboard-header">
            <h2>Order #<?php echo htmlspecialchars($order['order_number']); ?></h2>
            <p class="text-muted">Review order details and client information.</p>
        </div>

        <?php if(!empty($success)): ?>
            <div class="notice notice-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        <?php if(!empty($warning)): ?>
            <div class="notice notice-warning"><?php echo htmlspecialchars($warning); ?></div>
        <?php endif; ?>
        <?php if(!empty($error)): ?>
            <div class="notice notice-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="action-row mb-3">
            <a href="dashboard.php" class="btn btn-outline-primary">
                <i class="fas fa-arrow-left"></i> Back to Orders
            </a>
            <?php if($order['status'] === 'pending'): ?>
            <?php endif; ?>
        </div>

        <div class="order-card">
            <div class="detail-group">
                <h4>Order Overview</h4>
                <p><strong>Service:</strong> <?php echo htmlspecialchars($order['service_type']); ?></p>
                <p><strong>Quantity:</strong> <?php echo htmlspecialchars($order['quantity']); ?></p>
                <p><strong>Created:</strong> <?php echo date('M d, Y', strtotime($order['created_at'])); ?></p>
                <p><strong>Price:</strong> ₱<?php echo number_format($order['price'], 2); ?></p>
            </div>
            <div class="detail-group">
                <h4>Status</h4>
                <p>
                    <span class="status-pill status-<?php echo htmlspecialchars($order['status']); ?>">
                        <?php echo str_replace('_', ' ', ucfirst($order['status'])); ?>
                    </span>
                </p>
                <?php if($order['status'] === 'cancelled' && !empty($order['cancellation_reason'])): ?>
                    <p><strong>Cancellation reason:</strong> <?php echo nl2br(htmlspecialchars($order['cancellation_reason'])); ?></p>
                <?php endif; ?>
                <p>
                    <span class="status-pill <?php echo htmlspecialchars($payment_class); ?>">
                        <?php echo ucfirst(str_replace('_', ' ', $payment_status)); ?> payment
                    </span>
                </p>
                <p>
                    <span class="hold-pill <?php echo htmlspecialchars($payment_hold['class']); ?>">
                        Hold: <?php echo htmlspecialchars($payment_hold['label']); ?>
                    </span>
                </p>
                <?php if($invoice): ?>
                    <p>
                        <strong>Invoice:</strong> #<?php echo htmlspecialchars($invoice['invoice_number']); ?> (<?php echo htmlspecialchars($invoice['status']); ?>)
                        <a href="view_invoice.php?order_id=<?php echo $order_id; ?>" class="text-primary">View</a>
                    </p>
                <?php else: ?>
                    <p class="text-muted">Invoice will be issued once the price is set.</p>
                <?php endif; ?>
                <?php if($receipt): ?>
                    <p>
                        <strong>Receipt:</strong> #<?php echo htmlspecialchars($receipt['receipt_number']); ?>
                        <a href="view_receipt.php?order_id=<?php echo $order_id; ?>" class="text-primary">View</a>
                    </p>
                <?php endif; ?>
                <?php if($refund): ?>
                    <p><strong>Refund:</strong> <?php echo htmlspecialchars($refund['status']); ?></p>
                <?php endif; ?>
                <p><strong>Assigned To:</strong>
                    <?php if($order['assigned_name']): ?>
                        <?php echo htmlspecialchars($order['assigned_name']); ?>
                    <?php else: ?>
                        <span class="text-muted">Unassigned</span>
                    <?php endif; ?>
                </p>
            </div>
            <div class="detail-group">
                <h4>Client Details</h4>
                <p><strong>Name:</strong> <?php echo htmlspecialchars($order['client_name']); ?></p>
                <p><strong>Email:</strong> <?php echo htmlspecialchars($order['client_email']); ?></p>
                <?php if(!empty($order['client_phone'])): ?>
                    <p><strong>Phone:</strong> <?php echo htmlspecialchars($order['client_phone']); ?></p>
                <?php endif; ?>
            </div>
            <div class="detail-group">
                <h4>Quote Request</h4>
                <?php if($quote_details): ?>
                    <p><strong>Complexity:</strong> <?php echo htmlspecialchars($quote_details['complexity'] ?? 'Standard'); ?></p>
                    <p><strong>Add-ons:</strong> <?php echo htmlspecialchars(!empty($quote_details['add_ons']) ? implode(', ', $quote_details['add_ons']) : 'None'); ?></p>
                    <p><strong>Rush:</strong> <?php echo !empty($quote_details['rush']) ? 'Yes' : 'No'; ?></p>
                    <?php if(isset($quote_details['estimated_total'])): ?>
                        <p><strong>Estimated total:</strong> ₱<?php echo number_format((float) $quote_details['estimated_total'], 2); ?></p>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="text-muted">No quote preferences submitted.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <h3>Design & Notes</h3>
            <p><strong>Description:</strong></p>
            <p><?php echo nl2br(htmlspecialchars($order['design_description'] ?? 'No description provided.')); ?></p>
            <p><strong>Client Notes:</strong></p>
            <p><?php echo nl2br(htmlspecialchars($order['client_notes'] ?? 'No notes provided.')); ?></p>
            <?php if(!empty($order['design_version_id'])): ?>
                <div class="mt-3">
                    <p class="mb-1"><strong>Latest saved design version</strong></p>
                    <p class="text-muted mb-1">
                        <?php if(!empty($order['design_project_title'])): ?>
                            <?php echo htmlspecialchars($order['design_project_title']); ?>
                        <?php endif; ?>
                        <?php if(!empty($order['design_version_no'])): ?>
                            (v<?php echo (int) $order['design_version_no']; ?>)
                        <?php endif; ?>
                    </p>
                    <?php if(!empty($order['design_version_created_at'])): ?>
                        <small class="text-muted">Saved <?php echo date('M d, Y', strtotime($order['design_version_created_at'])); ?></small>
                    <?php endif; ?>
                    <?php if($design_version_file): ?>
                        <p class="mt-2">
                            <a class="file-link" href="<?php echo htmlspecialchars($design_version_file); ?>" target="_blank" rel="noopener noreferrer">
                                <i class="fas fa-paperclip"></i> View saved design version
                            </a>
                        </p>
                        <?php if($is_design_version_image): ?>
                            <div class="design-preview">
                                <img src="<?php echo htmlspecialchars($design_version_file); ?>" alt="Saved design version preview">
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php if($latest_proof_url): ?>
                <div class="mt-3">
                    <p class="mb-1"><strong>Latest proof</strong></p>
                    <?php if(!empty($latest_proof['status'])): ?>
                        <span class="badge badge-secondary">Status: <?php echo htmlspecialchars($latest_proof['status']); ?></span>
                    <?php endif; ?>
                    <?php if(!empty($latest_proof['updated_at'])): ?>
                        <small class="text-muted">Updated <?php echo date('M d, Y', strtotime($latest_proof['updated_at'])); ?></small>
                    <?php endif; ?>
                    <p class="mt-2">
                        <a class="file-link" href="<?php echo htmlspecialchars($latest_proof_url); ?>" target="_blank" rel="noopener noreferrer">
                            <i class="fas fa-file-download"></i> View proof file
                        </a>
                    </p>
                    <?php if($is_latest_proof_image): ?>
                        <div class="design-preview">
                            <img src="<?php echo htmlspecialchars($latest_proof_url); ?>" alt="Latest proof file">
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php if(!empty($proof_history)): ?>
                <div class="mt-3">
                    <p class="mb-1"><strong>Proof history</strong></p>
                    <div class="mt-2">
                        <?php foreach($proof_history as $proof_item): ?>
                            <?php
                                $proof_file = $proof_item['design_file'] ?? '';
                                $proof_url = $proof_file ? '../' . $proof_file : null;
                                $proof_extension = $proof_file ? strtolower(pathinfo($proof_file, PATHINFO_EXTENSION)) : '';
                                $proof_is_image = $proof_file && in_array($proof_extension, ALLOWED_IMAGE_TYPES, true);
                            ?>
                            <div class="mb-3">
                                <div class="d-flex align-center gap-2">
                                    <span class="badge badge-secondary">
                                        <?php echo htmlspecialchars($proof_item['status'] ?? 'pending'); ?>
                                    </span>
                                    <?php if(!empty($proof_item['updated_at'])): ?>
                                        <small class="text-muted">Updated <?php echo date('M d, Y', strtotime($proof_item['updated_at'])); ?></small>
                                    <?php endif; ?>
                                </div>
                                <?php if($proof_url): ?>
                                    <div class="mt-1">
                                        <a class="file-link" href="<?php echo htmlspecialchars($proof_url); ?>" target="_blank" rel="noopener noreferrer">
                                            <i class="fas fa-file-download"></i> View proof file
                                        </a>
                                    </div>
                                <?php endif; ?>
                                <?php if($proof_is_image): ?>
                                    <div class="design-preview">
                                        <img src="<?php echo htmlspecialchars($proof_url); ?>" alt="Proof history file">
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
            <?php if($design_file): ?>
                <p class="mt-3">
                    <a class="file-link" href="<?php echo htmlspecialchars($design_file); ?>" target="_blank" rel="noopener noreferrer">
                        <i class="fas fa-file-download"></i> Download design file
                        </a>
                </p>
                </a>
                </p>
                <?php if($is_design_image): ?>
                    <div class="design-preview">
                        <img src="<?php echo htmlspecialchars($design_file); ?>" alt="Client design upload">
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        
        <div class="card">
            <h3>Schedule & Capacity</h3>
            <?php if($schedule_entry): ?>
                <div class="schedule-summary mb-3">
                    <span><strong>Assigned staff:</strong> <?php echo htmlspecialchars($schedule_entry['staff_name']); ?></span>
                    <span><strong>Date:</strong> <?php echo date('M d, Y', strtotime($schedule_entry['scheduled_date'])); ?></span>
                    <span>
                        <strong>Time:</strong>
                        <?php echo $schedule_entry['scheduled_time'] ? date('h:i A', strtotime($schedule_entry['scheduled_time'])) : 'TBD'; ?>
                    </span>
                    <?php if($schedule_capacity && $schedule_capacity['limit'] > 0): ?>
                        <span><strong>Capacity:</strong> <?php echo $schedule_capacity['count']; ?> / <?php echo $schedule_capacity['limit']; ?> jobs</span>
                    <?php endif; ?>
                </div>
                <?php if(!empty($schedule_entry['task_description'])): ?>
                    <p class="text-muted mb-3"><?php echo nl2br(htmlspecialchars($schedule_entry['task_description'])); ?></p>
                <?php endif; ?>
            <?php else: ?>
                <p class="text-muted">No schedule has been set for this order yet.</p>
            <?php endif; ?>

            <form method="POST" class="schedule-form">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="order_id" value="<?php echo (int) $order['id']; ?>">
                <div class="schedule-grid">
                    <div class="form-group">
                        <label for="staff_id">Assign staff</label>
                        <select name="staff_id" id="staff_id" class="form-control" required>
                            <option value="">Select staff</option>
                            <?php foreach($active_staff as $staff_member): ?>
                                <option value="<?php echo (int) $staff_member['user_id']; ?>"
                                    <?php
                                        $selected_staff = $schedule_entry['staff_id'] ?? $order['assigned_to'] ?? null;
                                        echo ((int) $staff_member['user_id'] === (int) $selected_staff) ? 'selected' : '';
                                    ?>
                                >
                                    <?php echo htmlspecialchars($staff_member['fullname']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="scheduled_date">Scheduled date</label>
                        <input type="date" class="form-control" id="scheduled_date" name="scheduled_date" required
                            value="<?php echo htmlspecialchars($schedule_entry['scheduled_date'] ?? $order['scheduled_date'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="scheduled_time">Scheduled time</label>
                        <input type="time" class="form-control" id="scheduled_time" name="scheduled_time"
                            value="<?php echo htmlspecialchars($schedule_entry['scheduled_time'] ?? ''); ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label for="task_description">Task description</label>
                    <textarea class="form-control" id="task_description" name="task_description" rows="3"><?php echo htmlspecialchars($schedule_entry['task_description'] ?? ''); ?></textarea>
                </div>
                <button type="submit" name="schedule_job" class="btn btn-primary">
                    <i class="fas fa-calendar-check"></i> Save Schedule
                </button>
            </form>
        </div>
    </div>
</body>
</html>
