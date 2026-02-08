<?php
session_start();
require_once '../config/db.php';
require_once '../config/constants.php';
require_role(['staff','employee']);

$staff_id = $_SESSION['user']['id'];
$staff_role = $_SESSION['user']['role'] ?? null;

$emp_stmt = $pdo->prepare("
    SELECT se.*, s.shop_name, s.logo 
    FROM shop_staffs se 
    JOIN shops s ON se.shop_id = s.id 
    WHERE se.user_id = ? AND se.status = 'active'
");
$emp_stmt->execute([$staff_id]);
$staff = $emp_stmt->fetch();

if(!$staff) {
    die("You are not assigned to any shop. Please contact your shop owner.");
}

$staff_permissions = fetch_staff_permissions($pdo, $staff_id);
require_staff_permission($pdo, $staff_id, 'update_status');

// Get assigned jobs
$jobs_stmt = $pdo->prepare("
    SELECT 
        o.*,
        u.fullname as client_name,
        s.shop_name,
        COALESCE(js.scheduled_date, o.scheduled_date) as schedule_date,
        js.scheduled_time as schedule_time
    FROM orders o 
    JOIN users u ON o.client_id = u.id 
    JOIN shops s ON o.shop_id = s.id 
    LEFT JOIN job_schedule js ON js.order_id = o.id AND js.staff_id = ?
    WHERE (o.assigned_to = ? OR js.staff_id = ?)
      AND o.status IN ('accepted', 'in_progress')
    ORDER BY schedule_date ASC, js.scheduled_time ASC
");
$jobs_stmt->execute([$staff_id, $staff_id, $staff_id]);
$jobs = $jobs_stmt->fetchAll();
$photo_counts = [];
function is_design_image(?string $filename): bool {
    if(!$filename) {
        return false;
    }
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($extension, ALLOWED_IMAGE_TYPES, true);
}

function fetch_order_info(PDO $pdo, int $staff_id, int $order_id): ?array {
    $order_info_stmt = $pdo->prepare("
        SELECT o.status, o.progress, o.order_number, o.client_id, o.design_approved, s.shop_name, s.owner_id
        FROM orders o
        JOIN shops s ON o.shop_id = s.id
        LEFT JOIN job_schedule js ON js.order_id = o.id AND js.staff_id = ?
        WHERE o.id = ? AND (o.assigned_to = ? OR js.staff_id = ?)
        LIMIT 1
    ");
    $order_info_stmt->execute([$staff_id, $order_id, $staff_id, $staff_id]);
    $order_info = $order_info_stmt->fetch();

    return $order_info ?: null;
}

if(!empty($jobs)) {
    $job_ids = array_column($jobs, 'id');
    $placeholders = implode(',', array_fill(0, count($job_ids), '?'));
    $photo_stmt = $pdo->prepare("
        SELECT order_id, COUNT(*) as photo_count
        FROM order_photos
        WHERE staff_id = ?
          AND order_id IN ($placeholders)
        GROUP BY order_id
    ");
    $photo_stmt->execute(array_merge([$staff_id], $job_ids));
    $photo_counts = $photo_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

// Update status
if(isset($_POST['upload_proof'])) {
    $order_id = (int) ($_POST['order_id'] ?? 0);
    $design_file = sanitize($_POST['design_file'] ?? '');
    $provider_notes = sanitize($_POST['provider_notes'] ?? '');

    $order_info = fetch_order_info($pdo, $staff_id, $order_id);
    if(!$order_info) {
        $error = "Unable to upload proof for this order.";
    } elseif($design_file === '') {
        $error = "Please upload a proof file before submitting.";
    } else {
        $provider_stmt = $pdo->prepare("
            SELECT sp.id
            FROM shops s
            JOIN service_providers sp ON sp.user_id = s.owner_id
            WHERE s.id = ?
            LIMIT 1
        ");
        $provider_stmt->execute([$staff['shop_id']]);
        $service_provider_id = $provider_stmt->fetchColumn();

        if(!$service_provider_id) {
            $error = "Unable to locate the shop's service provider profile.";
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
                WHERE id = ?
            ");
            $reset_stmt->execute([$order_id]);

            $message = sprintf(
                'A new design proof is ready for order #%s.',
                $order_info['order_number']
            );
            create_notification($pdo, (int) $order_info['client_id'], $order_id, 'order_status', $message);
            if(!empty($order_info['owner_id'])) {
                create_notification($pdo, (int) $order_info['owner_id'], $order_id, 'info', $message);
            }

            $success = 'Proof uploaded and sent to the client for approval.';
        }
    }
}

if(isset($_POST['escalate_issue'])) {
    $order_id = (int) ($_POST['order_id'] ?? 0);
    $escalation_type = $_POST['escalate_issue'] ?? '';
    $escalation_note = sanitize($_POST['escalation_note'] ?? '');
    $valid_escalations = [
        'needs_clarification' => 'Needs clarification',
        'blocked' => 'Blocked',
    ];
    $order_info = fetch_order_info($pdo, $staff_id, $order_id);

    if(!$order_info) {
        $error = "Unable to escalate this order.";
    } elseif(!array_key_exists($escalation_type, $valid_escalations)) {
        $error = "Invalid escalation option.";
    } elseif($escalation_note === '') {
        $error = "Please add details before escalating.";
    } else {
        $label = $valid_escalations[$escalation_type];
        $note = sprintf('[%s] %s', $label, $escalation_note);
        $update_stmt = $pdo->prepare("
            UPDATE orders 
            SET shop_notes = CONCAT(COALESCE(shop_notes, ''), '\n', ?), updated_at = NOW()
            WHERE id = ? AND (assigned_to = ? OR EXISTS (
                SELECT 1 FROM job_schedule js WHERE js.order_id = orders.id AND js.staff_id = ?
            ))
        ");
        $update_stmt->execute([$note, $order_id, $staff_id, $staff_id]);

        $message = sprintf(
            'Order #%s needs attention: %s.',
            $order_info['order_number'],
            strtolower($label)
        );
        if(!empty($order_info['owner_id'])) {
            create_notification($pdo, (int) $order_info['owner_id'], (int) $order_id, 'warning', $message);
        }
        if($escalation_type === 'needs_clarification') {
            create_notification($pdo, (int) $order_info['client_id'], (int) $order_id, 'warning', $message);
        }

        $success = $label . " request sent successfully.";
    }
}

if(isset($_POST['update_status'])) {
    $order_id = (int) ($_POST['order_id'] ?? 0);
    $progress = (int) ($_POST['progress'] ?? 0);
    $status = $_POST['status'] ?? '';
    $staff_notes = sanitize($_POST['staff_notes'] ?? '');

    $photo_check_stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM order_photos
        WHERE order_id = ? AND staff_id = ?
    ");
    $photo_check_stmt->execute([$order_id, $staff_id]);
    $photo_count = (int) $photo_check_stmt->fetchColumn();

    $order_info = fetch_order_info($pdo, $staff_id, $order_id);

    $allowed_statuses = [STATUS_IN_PROGRESS, STATUS_COMPLETED];

    if(!$order_info) {
        $error = "Unable to update this order.";
    } elseif($photo_count === 0) {
        $error = "Please upload a progress photo before updating the status.";
    } elseif(!in_array($status, $allowed_statuses, true)) {
        $error = "Invalid status selection.";
    } else {
        $order_state = $order_info;
        $order_state['id'] = $order_id;
        [$can_transition, $transition_error] = order_workflow_validate_order_status($pdo, $order_state, $status);
        if(!$can_transition) {
            $error = $transition_error ?: "Status transition not allowed from the current state.";
        }
    }

    if(!isset($error)) {
        try {
            $update_stmt = $pdo->prepare("
                UPDATE orders 
                SET progress = ?, status = ?, shop_notes = CONCAT(COALESCE(shop_notes, ''), '\n', ?), 
                    updated_at = NOW() 
                WHERE id = ? AND (assigned_to = ? OR EXISTS (
                    SELECT 1 FROM job_schedule js WHERE js.order_id = orders.id AND js.staff_id = ?
                ))
            ");
            $update_stmt->execute([$progress, $status, $staff_notes, $order_id, $staff_id, $staff_id]);

            if($status === 'completed') {
                $complete_stmt = $pdo->prepare("UPDATE orders SET completed_at = NOW() WHERE id = ?");
                $complete_stmt->execute([$order_id]);
            }

            if($order_info && $order_info['status'] !== $status) {
                record_order_status_history(
                    $pdo,
                    $order_id,
                    $status,
                    (int) $progress,
                    $staff_notes !== '' ? $staff_notes : null,
                    $staff_id
                );
                $message = sprintf(
                    'Order #%s status updated to %s by %s.',
                    $order_info['order_number'],
                    str_replace('_', ' ', $status),
                    $order_info['shop_name']
                );
                create_notification($pdo, (int) $order_info['client_id'], (int) $order_id, 'order_status', $message);
                if(!empty($order_info['owner_id'])) {
                    create_notification($pdo, (int) $order_info['owner_id'], (int) $order_id, 'order_status', $message);
                }

                $status_label = ucfirst(str_replace('_', ' ', $status));
                $notification_type = $status === 'completed' ? 'success' : 'info';
                create_notification(
                    $pdo,
                    (int) $order_info['client_id'],
                    $order_id,
                    $notification_type,
                    'Order #' . $order_info['order_number'] . ' has been updated to ' . strtolower($status_label) . '.'
                );
            }
            

           $success = "Status updated successfully!";
        } catch(PDOException $e) {
            $error = "Failed to update status: " . $e->getMessage();

                log_audit(
                $pdo,
                $staff_id,
                $staff_role,
                'update_order_status',
                'orders',
                (int) $order_id,
                [
                    'status' => $order_info['status'] ?? null,
                    'progress' => $order_info['progress'] ?? null,
                ],
                [
                    'status' => $status,
                    'progress' => (int) $progress,
                ]
            );
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Update Job Status - staff Dashboard</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .job-details {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .progress-slider {
            width: 100%;
            margin: 20px 0;
        }
        .status-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 10px;
            margin: 20px 0;
        }
        .status-option {
            padding: 15px;
            border: 2px solid #dee2e6;
            border-radius: 8px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
        }
        .status-option:hover {
            border-color: #4361ee;
        }
        .status-option.selected {
            border-color: #4361ee;
            background: #4361ee;
            color: white;
        }
        .section-header {
            font-weight: 600;
            margin-bottom: 10px;
            color: #343a40;
        }
        .job-steps {
            display: grid;
            gap: 10px;
            margin-bottom: 20px;
        }
        .step-item {
            display: flex;
            align-items: center;
            padding: 10px 12px;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            background: #fff;
            color: #495057;
        }
        .step-item.completed {
            border-color: #16a34a;
            background: #ecfdf3;
            color: #14532d;
        }
        .step-index {
            width: 26px;
            height: 26px;
            border-radius: 50%;
            background: #e9ecef;
            color: #495057;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            margin-right: 10px;
        }
        .step-item.completed .step-index {
            background: #16a34a;
            color: white;
        }
        .escalation-panel {
            border: 1px dashed #f59e0b;
            border-radius: 10px;
            padding: 16px;
            background: #fffbeb;
        }
        .escalation-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .photo-upload {
            border: 2px dashed #dee2e6;
            border-radius: 8px;
            padding: 30px;
            text-align: center;
            margin: 20px 0;
        }
        .design-preview {
            margin-top: 12px;
        }
        .design-preview img {
            width: 100%;
            max-width: 320px;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
        }
        .design-file {
            margin-top: 10px;
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="container d-flex justify-between align-center">
            <a href="dashboard.php" class="navbar-brand">
                <i class="fas fa-user-tie"></i> Update Job Status
            </a>
            <ul class="navbar-nav">
                <li><a href="dashboard.php" class="nav-link">Dashboard</a></li>
                <?php if(!empty($staff_permissions['view_jobs'])): ?>
                    <li><a href="assigned_jobs.php" class="nav-link">My Jobs</a></li>
                    <li><a href="schedule.php" class="nav-link">Schedule</a></li>
                <?php endif; ?>
                <?php if(!empty($staff_permissions['update_status'])): ?>
                    <li><a href="update_status.php" class="nav-link active">Update Status</a></li>
                <?php endif; ?>
                <?php if(!empty($staff_permissions['upload_photos'])): ?>
                    <li><a href="upload_photos.php" class="nav-link">Upload Photos</a></li>
                <?php endif; ?>
                <li><a href="../auth/logout.php" class="nav-link">Logout</a></li>
            </ul>
        </div>
    </nav>

    <div class="container">
        <div class="dashboard-header">
            <h2>Update Job Status</h2>
            <p class="text-muted">Update progress and status of your assigned jobs</p>
        </div>

        <?php if(isset($error)): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <?php if(isset($success)): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>

        <?php if(!empty($jobs)): ?>
            <?php foreach($jobs as $job): ?>
                <?php
                    $has_photo = !empty($photo_counts[$job['id']]);
                    $payment_hold = payment_hold_status($job['status'] ?? STATUS_PENDING, $job['payment_status'] ?? 'unpaid');
                ?>
            <div class="card mb-4">
                <div class="job-details">
                    <div class="d-flex justify-between align-center">
                        <div>
                            <h4><?php echo htmlspecialchars($job['service_type']); ?></h4>
                            <p class="mb-1">
                                <i class="fas fa-user"></i> <?php echo htmlspecialchars($job['client_name']); ?> |
                                <i class="fas fa-store"></i> <?php echo htmlspecialchars($job['shop_name']); ?> |
                                Order #<?php echo $job['order_number']; ?>
                            </p>
                            <p class="mb-0 text-muted">
                                <?php echo htmlspecialchars($job['design_description']); ?>
                            </p>
                            <?php if(!empty($job['design_file'])): ?>
                                <div class="design-file">
                                    <a href="../assets/uploads/designs/<?php echo htmlspecialchars($job['design_file']); ?>" target="_blank" rel="noopener noreferrer">
                                        <i class="fas fa-paperclip"></i> View design file
                                    </a>
                                </div>
                                <?php if(is_design_image($job['design_file'])): ?>
                                    <div class="design-preview">
                                        <img src="../assets/uploads/designs/<?php echo htmlspecialchars($job['design_file']); ?>" alt="Client design upload">
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if(!empty($job['schedule_date'])): ?>
                                <p class="mb-0 text-muted">
                                    <i class="fas fa-calendar"></i> Scheduled: <?php echo date('M d, Y', strtotime($job['schedule_date'])); ?>
                                    <?php if(!empty($job['schedule_time'])): ?>
                                        <span class="ml-1"><i class="fas fa-clock"></i> <?php echo date('h:i A', strtotime($job['schedule_time'])); ?></span>
                                    <?php endif; ?>
                                </p>
                            <?php endif; ?>
                        </div>
                        <div class="text-right">
                            <div class="stat-number"><?php echo $job['progress']; ?>%</div>
                            <div class="stat-label">Current Progress</div>
                            <div class="mt-2">
                                <span class="hold-pill <?php echo htmlspecialchars($payment_hold['class']); ?>">
                                    Hold: <?php echo htmlspecialchars($payment_hold['label']); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <form method="POST">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="order_id" value="<?php echo $job['id']; ?>">
                    
                    <div class="section-header">Job Steps</div>
                    <div class="job-steps" data-job-id="<?php echo $job['id']; ?>">
                        <div class="step-item" data-threshold="10">
                            <span class="step-index">1</span>Prep materials & layout
                        </div>
                        <div class="step-item" data-threshold="30">
                            <span class="step-index">2</span>Hoop & machine setup
                        </div>
                        <div class="step-item" data-threshold="55">
                            <span class="step-index">3</span>Stitching in progress
                        </div>
                        <div class="step-item" data-threshold="80">
                            <span class="step-index">4</span>Finishing & trimming
                        </div>
                        <div class="step-item" data-threshold="100">
                            <span class="step-index">5</span>Quality check & packaging
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Progress (%)</label>
                        <input type="range" name="progress" class="progress-slider" 
                               min="0" max="100" value="<?php echo $job['progress']; ?>"
                               oninput="document.getElementById('progressValue<?php echo $job['id']; ?>').textContent = this.value + '%'; updateSteps(<?php echo $job['id']; ?>, this.value);">
                        <div class="text-center">
                            <span id="progressValue<?php echo $job['id']; ?>" class="badge badge-primary">
                                <?php echo $job['progress']; ?>%
                            </span>
                        </div>
                    </div>

                    <div class="form-group">
                        <div class="section-header">Order Status</div>
                        <div class="status-options">
                            <div class="status-option" onclick="selectStatus('in_progress', <?php echo $job['id']; ?>)">
                                <i class="fas fa-spinner fa-2x mb-2"></i>
                                <div>In Progress</div>
                                <input type="radio" name="status" value="in_progress" 
                                       <?php echo $job['status'] == 'in_progress' ? 'checked' : ''; ?> 
                                       style="display: none;">
                            </div>
                            
                            <div class="status-option" onclick="selectStatus('completed', <?php echo $job['id']; ?>)">
                                <i class="fas fa-check-circle fa-2x mb-2"></i>
                                <div>Completed</div>
                                <input type="radio" name="status" value="completed" style="display: none;">
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Add Notes (Optional)</label>
                        <textarea name="staff_notes" class="form-control" rows="3"
                                  placeholder="Add any notes about this update..."></textarea>
                    </div>

                    <?php if(!$has_photo): ?>
                        <div class="alert alert-warning">
                            Please upload a progress photo before updating this job.
                            <a href="upload_photos.php">Upload a photo</a>.
                        </div>
                    <?php endif; ?>

                    <div class="text-right">
                        <button type="submit" name="update_status" class="btn btn-primary" <?php echo $has_photo ? '' : 'disabled'; ?>>
                            <i class="fas fa-save"></i> Update Status
                        </button>
                    </div>
                </form>
                
                <form method="POST" class="proof-upload-form mt-3" enctype="multipart/form-data">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="order_id" value="<?php echo $job['id']; ?>">
                    <input type="hidden" name="design_file" value="">
                    <div class="section-header">Upload Design Proof</div>
                    <div class="form-group">
                        <label>Proof File (Image)</label>
                        <input type="file" name="proof_file" class="form-control" accept="image/*" required>
                        <small class="text-muted">Upload the proof image to send for client approval.</small>
                    </div>
                    <div class="form-group">
                        <label>Notes (Optional)</label>
                        <textarea name="provider_notes" class="form-control" rows="2" placeholder="Add any notes for the client review."></textarea>
                    </div>
                    <div class="text-right">
                        <button type="submit" name="upload_proof" class="btn btn-outline-primary">
                            <i class="fas fa-upload"></i> Send Proof for Approval
                        </button>
                    </div>
                </form>

                <form method="POST" class="mt-3">
                    <input type="hidden" name="order_id" value="<?php echo $job['id']; ?>">
                    <div class="section-header">Escalation Path</div>
                    <div class="escalation-panel">
                        <p class="text-muted mb-2">Use this when you need a decision or you're blocked. These requests alert the shop owner (and the client if clarification is needed).</p>
                        <div class="form-group">
                            <label>Escalation Details</label>
                            <textarea name="escalation_note" class="form-control" rows="3" placeholder="Describe what you need to move forward..." required></textarea>
                        </div>
                        <div class="escalation-actions">
                            <button type="submit" name="escalate_issue" value="needs_clarification" class="btn btn-warning">
                                <i class="fas fa-question-circle"></i> Needs Clarification
                            </button>
                            <button type="submit" name="escalate_issue" value="blocked" class="btn btn-danger">
                                <i class="fas fa-ban"></i> Blocked
                            </button>
                        </div>
                    </div>
                </form>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="card">
                <div class="text-center p-4">
                    <i class="fas fa-clipboard-list fa-3x text-muted mb-3"></i>
                    <h4>No Jobs to Update</h4>
                    <p class="text-muted">You don't have any assigned jobs that need status updates.</p>
                    <a href="dashboard.php" class="btn btn-primary">Back to Dashboard</a>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function updateSteps(jobId, progress) {
            const steps = document.querySelectorAll(`.job-steps[data-job-id="${jobId}"] .step-item`);
            steps.forEach(step => {
                const threshold = Number(step.getAttribute('data-threshold'));
                if(progress >= threshold) {
                    step.classList.add('completed');
                } else {
                    step.classList.remove('completed');
                }
            });
        }

        // Status selection
        function selectStatus(status, jobId) {
            // Remove selected class from all options in this job
            const jobCard = event.currentTarget.closest('.card');
            jobCard.querySelectorAll('.status-option').forEach(option => {
                option.classList.remove('selected');
            });
            
            // Add selected class to clicked option
            event.currentTarget.classList.add('selected');
            
            // Check the radio button
            const radio = event.currentTarget.querySelector('input[type="radio"]');
            radio.checked = true;
        }
        
        // Initialize status selection
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.status-option input[type="radio"]:checked').forEach(radio => {
                radio.closest('.status-option').classList.add('selected');
            });
            document.querySelectorAll('.progress-slider').forEach(slider => {
                const jobId = slider.closest('.card').querySelector('input[name="order_id"]').value;
                updateSteps(jobId, slider.value);
            });
            
            document.querySelectorAll('.proof-upload-form').forEach(form => {
                form.addEventListener('submit', async function(event) {
                    event.preventDefault();
                    const fileInput = form.querySelector('input[type="file"]');
                    if(!fileInput || !fileInput.files.length) {
                        alert('Please choose a proof file to upload.');
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