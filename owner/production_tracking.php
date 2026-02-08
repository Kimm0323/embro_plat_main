<?php
session_start();
require_once '../config/db.php';
require_once '../config/constants.php';
require_role('owner');

$owner_id = $_SESSION['user']['id'];
$owner_role = $_SESSION['user']['role'] ?? null;
$shop_stmt = $pdo->prepare("SELECT shop_name FROM shops WHERE owner_id = ?");
$shop_stmt->execute([$owner_id]);
$shop = $shop_stmt->fetch();

$service_provider_stmt = $pdo->prepare("SELECT id FROM service_providers WHERE user_id = ? LIMIT 1");
$service_provider_stmt->execute([$owner_id]);
$service_provider_id = $service_provider_stmt->fetchColumn();

if(isset($_POST['upload_proof'])) {
    $order_id = (int) ($_POST['order_id'] ?? 0);
    $design_file = sanitize($_POST['design_file'] ?? '');
    $provider_notes = sanitize($_POST['provider_notes'] ?? '');

    if($order_id <= 0) {
        $error = "Invalid order selected for proof upload.";
    } elseif($design_file === '') {
        $error = "Please upload a proof file before submitting.";
    } elseif(!$service_provider_id) {
        $error = "Please complete your service provider profile before uploading proofs.";
    } else {
        $order_stmt = $pdo->prepare("
            SELECT o.order_number, o.client_id, o.status
            FROM orders o
            WHERE o.id = ? AND o.shop_id = ?
            LIMIT 1
        ");
        $order_stmt->execute([$order_id, $shop['id']]);
        $order = $order_stmt->fetch();

        if(!$order) {
            $error = "Order not found for proof upload.";
        } else {
            $existing_stmt = $pdo->prepare("SELECT id FROM design_approvals WHERE order_id = ? LIMIT 1");
            $existing_stmt->execute([$order_id]);
            $approval_id = $existing_stmt->fetchColumn();

            if($approval_id) {
                $update_stmt = $pdo->prepare("
                    UPDATE design_approvals
                    SET design_file = ?, provider_notes = ?, status = 'pending', approved_at = NULL, updated_at = NOW()
                    WHERE id = ?
                ");
                $update_stmt->execute([$design_file, $provider_notes ?: null, $approval_id]);
            } else {
                $insert_stmt = $pdo->prepare("
                    INSERT INTO design_approvals (order_id, service_provider_id, design_file, provider_notes, status)
                    VALUES (?, ?, ?, ?, 'pending')
                ");
                $insert_stmt->execute([$order_id, $service_provider_id, $design_file, $provider_notes ?: null]);
            }

            $reset_stmt = $pdo->prepare("
                UPDATE orders
                SET design_approved = 0, updated_at = NOW()
                WHERE id = ? AND shop_id = ?
            ");
            $reset_stmt->execute([$order_id, $shop['id']]);

            $message = sprintf(
                'A new design proof is ready for order #%s.',
                $order['order_number']
            );
            create_notification($pdo, (int) $order['client_id'], $order_id, 'order_status', $message);

            $success = 'Proof uploaded and sent to the client for approval.';
        }
    }
}

if(isset($_POST['start_production'])) {
    $order_id = (int) ($_POST['order_id'] ?? 0);

    $order_stmt = $pdo->prepare("
        SELECT o.order_number, o.client_id, o.status
        FROM orders o
        WHERE o.id = ? AND o.shop_id = ?
        LIMIT 1
    ");
    $order_stmt->execute([$order_id, $shop['id']]);
    $order = $order_stmt->fetch();

    if(!$order) {
        $error = "Unable to locate the order to start production.";
    } elseif($order['status'] !== STATUS_ACCEPTED) {
        $error = "Only accepted orders can be moved to production.";
    } else {
        $order_state = [
            'id' => $order_id,
            'status' => $order['status'],
        ];
        [$can_transition, $transition_error] = order_workflow_validate_order_status($pdo, $order_state, STATUS_IN_PROGRESS);
        if(!$can_transition) {
            $error = $transition_error ?: "Status transition not allowed from the current state.";
        }
    }

    if(!isset($error)) {
        $update_stmt = $pdo->prepare("
            UPDATE orders
            SET status = 'in_progress', updated_at = NOW()
            WHERE id = ? AND shop_id = ?
        ");
        $update_stmt->execute([$order_id, $shop['id']]);

        record_order_status_history($pdo, $order_id, STATUS_IN_PROGRESS, 0, 'Production started by shop owner.');
        create_notification(
            $pdo,
            (int) $order['client_id'],
            $order_id,
            'order_status',
            'Order #' . $order['order_number'] . ' is now in production.'
        );

        log_audit(
            $pdo,
            $owner_id,
            $owner_role,
            'start_production',
            'orders',
            $order_id,
            ['status' => $order['status'] ?? null],
            ['status' => STATUS_IN_PROGRESS]
        );

        $success = 'Production has started for order #' . $order['order_number'] . '.';
    }
}

$orders_stmt = $pdo->prepare("
    SELECT o.id, o.order_number, o.status, o.design_approved, o.client_id, o.payment_status,
           u.fullname as client_name,
           da.status as approval_status,
           da.design_file as approval_file,
           da.revision_count
    FROM orders o
    JOIN users u ON o.client_id = u.id
    LEFT JOIN design_approvals da ON da.order_id = o.id
    WHERE o.shop_id = ? AND o.status IN ('accepted', 'in_progress')
    ORDER BY o.created_at DESC
");
$orders_stmt->execute([$shop['id'] ?? 0]);
$orders = $orders_stmt->fetchAll();

$tracking_stages = [
    [
        'title' => 'Intake confirmed',
        'detail' => 'Order details, stitch count, and due dates are verified before production begins.',
        'icon' => 'fas fa-clipboard-check',
    ],
    [
        'title' => 'Digitizing & proof ready',
        'detail' => 'Artwork files are digitized and queued with machine-ready settings.',
        'icon' => 'fas fa-pen-nib',
    ],
    [
        'title' => 'Stitching in progress',
        'detail' => 'Machine runtime and operator check-ins track progress against the schedule.',
        'icon' => 'fas fa-needle',
    ],
    [
        'title' => 'Quality check',
        'detail' => 'Finished pieces are inspected for thread consistency and alignment.',
        'icon' => 'fas fa-magnifying-glass',
    ],
    [
        'title' => 'Ready for pickup',
        'detail' => 'Completed batches are packaged, labeled, and prepped for pickup/delivery.',
        'icon' => 'fas fa-box',
    ],
];

$insights = [
    [
        'label' => 'Orders on track',
        'value' => '18',
        'note' => 'Running within the expected schedule window.',
        'icon' => 'fas fa-clock',
        'tone' => 'success',
    ],
    [
        'label' => 'At-risk jobs',
        'value' => '4',
        'note' => 'Require follow-up before the due date.',
        'icon' => 'fas fa-triangle-exclamation',
        'tone' => 'warning',
    ],
    [
        'label' => 'Machine utilization',
        'value' => '82%',
        'note' => 'Average embroidery machine usage today.',
        'icon' => 'fas fa-gears',
        'tone' => 'info',
    ],
];

$automation = [
    [
        'title' => 'Overdue alerts',
        'detail' => 'Automated alerts flag jobs that are about to miss their promised ship or pickup dates.',
        'icon' => 'fas fa-bell',
    ],
    [
        'title' => 'Activity logs',
        'detail' => 'Every stage update is captured with timestamps, operator notes, and machine usage.',
        'icon' => 'fas fa-clipboard-list',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Production Tracking Module - Owner</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .proof-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin: 2rem 0;
        }

        .proof-card {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            background: var(--bg-primary);
            padding: 1.5rem;
        }

        .proof-card .badge {
            text-transform: capitalize;
        }

        .proof-card img {
            width: 100%;
            height: auto;
            border-radius: var(--radius);
            border: 1px solid var(--gray-200);
            margin-top: 0.75rem;
        }

        .tracking-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 1.5rem;
            margin: 2rem 0;
        }

        .overview-card {
            grid-column: span 12;
        }

        .stages-card {
            grid-column: span 8;
        }

        .insights-card {
            grid-column: span 4;
        }

        .automation-card {
            grid-column: span 12;
        }

        .stage-item {
            display: flex;
            gap: 1rem;
            align-items: flex-start;
            padding: 1rem;
            border-radius: var(--radius);
            border: 1px solid var(--gray-200);
            background: var(--bg-primary);
        }

        .stage-icon {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: var(--radius-full);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--primary-100);
            color: var(--primary-700);
            font-size: 1rem;
        }

        .stage-list {
            display: grid;
            gap: 1rem;
        }

        .insight-item {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 1rem;
            background: white;
        }

        .insight-item i {
            color: var(--primary-600);
        }

        .insight-value {
            font-size: 1.5rem;
            font-weight: 700;
            margin: 0.25rem 0;
        }

        .automation-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1rem;
        }

        .automation-item {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 1rem;
            background: white;
        }

        .automation-item i {
            color: var(--primary-600);
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
                    <h2>Production Tracking</h2>
                    <p class="text-muted">Monitor embroidery progress and keep every job on schedule.</p>
                </div>
                <span class="badge badge-primary"><i class="fas fa-chart-line"></i> Module 14</span>
            </div>
        </div>

        <?php if(isset($error)): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>

        <?php if(isset($success)): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-clipboard-check text-primary"></i> Proof Approval Gate</h3>
                <p class="text-muted">Upload proofs and unlock production only after client approval.</p>
            </div>
            <?php if(!empty($orders)): ?>
                <div class="proof-grid">
                    <?php foreach($orders as $order): ?>
                        <?php
                            $approval_status = $order['approval_status'] ?? 'pending';
                            $approval_file = $order['approval_file'] ?? '';
                            $approved = (int) $order['design_approved'] === 1 || $approval_status === 'approved';
                            $payment_hold = payment_hold_status($order['status'] ?? STATUS_PENDING, $order['payment_status'] ?? 'unpaid');
                        ?>
                        <div class="proof-card">
                            <h4>Order #<?php echo htmlspecialchars($order['order_number']); ?></h4>
                            <p class="text-muted mb-2">
                                <i class="fas fa-user"></i> <?php echo htmlspecialchars($order['client_name']); ?>
                            </p>
                            <div class="d-flex gap-2 align-center">
                                <span class="badge badge-secondary">Status: <?php echo htmlspecialchars(str_replace('_', ' ', $order['status'])); ?></span>
                                <span class="badge <?php echo $approved ? 'badge-success' : 'badge-warning'; ?>">
                                    Proof: <?php echo htmlspecialchars($approval_status ?: 'pending'); ?>
                                </span>
                                <span class="hold-pill <?php echo htmlspecialchars($payment_hold['class']); ?>">
                                    Hold: <?php echo htmlspecialchars($payment_hold['label']); ?>
                                </span>
                            </div>

                            <?php if($approval_file): ?>
                                <img src="../<?php echo htmlspecialchars($approval_file); ?>" alt="Design proof">
                            <?php endif; ?>

                            <form method="POST" class="proof-upload-form mt-3" enctype="multipart/form-data">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                <input type="hidden" name="design_file" value="">
                                <div class="form-group">
                                    <label>Upload New Proof</label>
                                    <input type="file" name="proof_file" class="form-control" accept="image/*" required>
                                </div>
                                <div class="form-group">
                                    <label>Notes (Optional)</label>
                                    <textarea name="provider_notes" class="form-control" rows="2" placeholder="Add notes for the client review."></textarea>
                                </div>
                                <button type="submit" name="upload_proof" class="btn btn-outline-primary btn-sm">
                                    <i class="fas fa-upload"></i> Send Proof
                                </button>
                            </form>

                            <form method="POST" class="mt-3">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                <button type="submit" name="start_production" class="btn btn-primary btn-sm" <?php echo $approved ? '' : 'disabled'; ?>>
                                    <i class="fas fa-play"></i> Start Production
                                </button>
                                <?php if(!$approved): ?>
                                    <p class="text-muted mt-2 mb-0"><i class="fas fa-lock"></i> Awaiting client approval.</p>
                                <?php endif; ?>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="text-muted mb-0">No accepted or in-progress orders are ready for proofing yet.</p>
            <?php endif; ?>
        </div>

        <div class="tracking-grid">
            <div class="card overview-card">
                <div class="card-header">
                    <h3><i class="fas fa-bullseye text-primary"></i> Purpose</h3>
                </div>
                <p class="text-muted mb-0">
                    Tracks every embroidery job from intake through pickup, giving owners real-time visibility into
                    production status, bottlenecks, and completion readiness.
                </p>
            </div>

            <div class="card stages-card">
                <div class="card-header">
                    <h3><i class="fas fa-route text-primary"></i> Progress Stages</h3>
                    <p class="text-muted">Standard checkpoints used to monitor embroidery progress.</p>
                </div>
                <div class="stage-list">
                    <?php foreach ($tracking_stages as $stage): ?>
                        <div class="stage-item">
                            <span class="stage-icon"><i class="<?php echo $stage['icon']; ?>"></i></span>
                            <div>
                                <strong><?php echo $stage['title']; ?></strong>
                                <p class="text-muted mb-0"><?php echo $stage['detail']; ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card insights-card">
                <div class="card-header">
                    <h3><i class="fas fa-chart-pie text-primary"></i> Live Insights</h3>
                    <p class="text-muted">Snapshot of shop-floor activity.</p>
                </div>
                <div class="d-flex flex-column gap-3">
                    <?php foreach ($insights as $insight): ?>
                        <div class="insight-item">
                            <div class="d-flex align-center gap-2">
                                <i class="<?php echo $insight['icon']; ?>"></i>
                                <strong><?php echo $insight['label']; ?></strong>
                            </div>
                            <div class="insight-value text-<?php echo $insight['tone']; ?>">
                                <?php echo $insight['value']; ?>
                            </div>
                            <p class="text-muted mb-0"><?php echo $insight['note']; ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card automation-card">
                <div class="card-header">
                    <h3><i class="fas fa-robot text-primary"></i> Automation</h3>
                    <p class="text-muted">Proactive notifications that keep production moving.</p>
                </div>
                <div class="automation-list">
                    <?php foreach ($automation as $rule): ?>
                        <div class="automation-item">
                            <h4 class="d-flex align-center gap-2">
                                <i class="<?php echo $rule['icon']; ?>"></i>
                                <?php echo $rule['title']; ?>
                            </h4>
                            <p class="text-muted mb-0"><?php echo $rule['detail']; ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('.proof-upload-form').forEach(form => {
                form.addEventListener('submit', async event => {
                    event.preventDefault();
                    const fileInput = form.querySelector('input[type="file"]');
                    if(!fileInput || !fileInput.files.length) {
                        alert('Please select a proof file to upload.');
                        return;
                    }
                    const formData = new FormData(form);
                    formData.set('file', fileInput.files[0]);
                    try {
                        const response = await fetch('../api/upload_api.php', {
                            method: 'POST',
                            body: formData
                        });
                        const result = await response.json();
                        if(!response.ok || result.error) {
                            alert(result.error || 'Upload failed. Please try again.');
                            return;
                        }
                        const designInput = form.querySelector('input[name="design_file"]');
                        designInput.value = result.file.path;
                        form.submit();
                    } catch (error) {
                        alert('Unable to upload the proof. Please try again.');
                    }
                });
            });
        });
    </script>
</body>
</html>
