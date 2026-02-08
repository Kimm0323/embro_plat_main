<?php
session_start();
require_once '../config/db.php';
require_role(['hr','owner']);

$user = $_SESSION['user'];
$role = $user['role'];
$display_name = htmlspecialchars($user['fullname'] ?? 'User');

$shop_id = null;
$shop_name = null;

if ($role === 'hr') {
    $hr_stmt = $pdo->prepare("
        SELECT se.shop_id, s.shop_name
        FROM shop_staffs se
        JOIN shops s ON se.shop_id = s.id
        WHERE se.user_id = ? AND se.staff_role = 'hr' AND se.status = 'active'
    ");
    $hr_stmt->execute([$user['id']]);
    $hr_shop = $hr_stmt->fetch();
    if ($hr_shop) {
        $shop_id = (int) $hr_shop['shop_id'];
        $shop_name = $hr_shop['shop_name'];
    }
} elseif ($role === 'owner') {
    $shop_stmt = $pdo->prepare("SELECT id, shop_name FROM shops WHERE owner_id = ?");
    $shop_stmt->execute([$user['id']]);
    $shop = $shop_stmt->fetch();
    if ($shop) {
        $shop_id = (int) $shop['id'];
        $shop_name = $shop['shop_name'];
    }
}

if (!$shop_id) {
    die("No shop assignment found for this account.");
}

$errors = [];
$success = null;

if (isset($_POST['generate_payroll']) && $role === 'hr') {
    $pay_period_start = $_POST['pay_period_start'] ?? '';
    $pay_period_end = $_POST['pay_period_end'] ?? '';

    if (!$pay_period_start || !$pay_period_end) {
        $errors[] = 'Please select a valid pay period.';
    } elseif (strtotime($pay_period_start) > strtotime($pay_period_end)) {
        $errors[] = 'Pay period end must be on or after the start date.';
    }

    if (empty($errors)) {
        $staff_stmt = $pdo->prepare("
            SELECT s.id AS staff_id, s.salary, u.fullname, u.email
            FROM staffs s
            JOIN users u ON s.user_id = u.id
            JOIN shop_staffs ss ON ss.user_id = u.id
            WHERE ss.shop_id = ?
              AND ss.status = 'active'
              AND s.status = 'active'
        ");
        $staff_stmt->execute([$shop_id]);
        $staff_members = $staff_stmt->fetchAll();

        $existing_stmt = $pdo->prepare("
            SELECT staff_id
            FROM payroll
            WHERE pay_period_start = ?
              AND pay_period_end = ?
        ");
        $existing_stmt->execute([$pay_period_start, $pay_period_end]);
        $existing_ids = $existing_stmt->fetchAll(PDO::FETCH_COLUMN);
        $existing_ids = array_map('intval', $existing_ids);

        $created = 0;
        $skipped = 0;

        $insert_stmt = $pdo->prepare("
            INSERT INTO payroll (staff_id, pay_period_start, pay_period_end, basic_salary, allowances, deductions, net_salary, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'draft')
        ");

        foreach ($staff_members as $staff) {
            $staff_id = (int) $staff['staff_id'];
            if (in_array($staff_id, $existing_ids, true)) {
                $skipped++;
                continue;
            }
            $basic_salary = $staff['salary'] !== null ? (float) $staff['salary'] : 0.0;
            $allowances = 0.0;
            $deductions = 0.0;
            $net_salary = $basic_salary + $allowances - $deductions;
            $insert_stmt->execute([
                $staff_id,
                $pay_period_start,
                $pay_period_end,
                $basic_salary,
                $allowances,
                $deductions,
                $net_salary,
            ]);
            $created++;
        }

        $success = "Generated payroll drafts for {$created} staff. Skipped {$skipped} existing entries.";
    }
}

if (isset($_POST['update_payroll']) && $role === 'hr') {
    $payroll_id = (int) ($_POST['payroll_id'] ?? 0);
    $allowances = isset($_POST['allowances']) ? (float) $_POST['allowances'] : 0.0;
    $deductions = isset($_POST['deductions']) ? (float) $_POST['deductions'] : 0.0;

    $check_stmt = $pdo->prepare("
        SELECT p.id, p.basic_salary
        FROM payroll p
        JOIN staffs s ON p.staff_id = s.id
        JOIN users u ON s.user_id = u.id
        JOIN shop_staffs ss ON ss.user_id = u.id
        WHERE p.id = ?
          AND p.status = 'draft'
          AND ss.shop_id = ?
          AND ss.status = 'active'
    ");
    $check_stmt->execute([$payroll_id, $shop_id]);
    $payroll_row = $check_stmt->fetch();
    if ($payroll_row) {
        $basic_salary = (float) ($payroll_row['basic_salary'] ?? 0);
        $net_salary = $basic_salary + $allowances - $deductions;
        $update_stmt = $pdo->prepare("
            UPDATE payroll
            SET allowances = ?, deductions = ?, net_salary = ?
            WHERE id = ?
        ");
        $update_stmt->execute([$allowances, $deductions, $net_salary, $payroll_id]);
        $success = 'Payroll draft updated.';
    } else {
        $errors[] = 'Unable to update that payroll draft.';
    }
}

if (isset($_POST['submit_payroll']) && $role === 'hr') {
    $payroll_id = (int) ($_POST['payroll_id'] ?? 0);

    $check_stmt = $pdo->prepare("
        SELECT p.id
        FROM payroll p
        JOIN staffs s ON p.staff_id = s.id
        JOIN users u ON s.user_id = u.id
        JOIN shop_staffs ss ON ss.user_id = u.id
        WHERE p.id = ?
          AND p.status = 'draft'
          AND ss.shop_id = ?
          AND ss.status = 'active'
    ");
    $check_stmt->execute([$payroll_id, $shop_id]);
    if ($check_stmt->fetch()) {
        $update_stmt = $pdo->prepare("
            UPDATE payroll
            SET status = 'pending_owner'
            WHERE id = ?
        ");
        $update_stmt->execute([$payroll_id]);
        $success = 'Payroll submitted for owner approval.';
    } else {
        $errors[] = 'Unable to submit that payroll entry.';
    }
}

if (isset($_POST['approve_payroll']) && $role === 'owner') {
    $payroll_id = (int) ($_POST['payroll_id'] ?? 0);

    $check_stmt = $pdo->prepare("
        SELECT p.id
        FROM payroll p
        JOIN staffs s ON p.staff_id = s.id
        JOIN users u ON s.user_id = u.id
        JOIN shop_staffs ss ON ss.user_id = u.id
        WHERE p.id = ?
          AND p.status = 'pending_owner'
          AND ss.shop_id = ?
          AND ss.status = 'active'
    ");
    $check_stmt->execute([$payroll_id, $shop_id]);
    if ($check_stmt->fetch()) {
        $update_stmt = $pdo->prepare("
            UPDATE payroll
            SET status = 'approved'
            WHERE id = ?
        ");
        $update_stmt->execute([$payroll_id]);
        $success = 'Payroll entry approved.';
    } else {
        $errors[] = 'Unable to approve that payroll entry.';
    }
}

if (isset($_POST['reject_payroll']) && $role === 'owner') {
    $payroll_id = (int) ($_POST['payroll_id'] ?? 0);

    $check_stmt = $pdo->prepare("
        SELECT p.id
        FROM payroll p
        JOIN staffs s ON p.staff_id = s.id
        JOIN users u ON s.user_id = u.id
        JOIN shop_staffs ss ON ss.user_id = u.id
        WHERE p.id = ?
          AND p.status = 'pending_owner'
          AND ss.shop_id = ?
          AND ss.status = 'active'
    ");
    $check_stmt->execute([$payroll_id, $shop_id]);
    if ($check_stmt->fetch()) {
        $update_stmt = $pdo->prepare("
            UPDATE payroll
            SET status = 'draft'
            WHERE id = ?
        ");
        $update_stmt->execute([$payroll_id]);
        $success = 'Payroll entry sent back to draft.';
    } else {
        $errors[] = 'Unable to reject that payroll entry.';
    }
}

if (isset($_POST['mark_paid']) && $role === 'owner') {
    $payroll_id = (int) ($_POST['payroll_id'] ?? 0);

    $check_stmt = $pdo->prepare("
        SELECT p.id
        FROM payroll p
        JOIN staffs s ON p.staff_id = s.id
        JOIN users u ON s.user_id = u.id
        JOIN shop_staffs ss ON ss.user_id = u.id
        WHERE p.id = ?
          AND p.status = 'approved'
          AND ss.shop_id = ?
          AND ss.status = 'active'
    ");
    $check_stmt->execute([$payroll_id, $shop_id]);
    if ($check_stmt->fetch()) {
        $update_stmt = $pdo->prepare("
            UPDATE payroll
            SET status = 'paid', paid_at = NOW()
            WHERE id = ?
        ");
        $update_stmt->execute([$payroll_id]);
        $success = 'Payroll entry marked as paid.';
    } else {
        $errors[] = 'Unable to mark that payroll entry as paid.';
    }
}

$payslip = null;
if (isset($_GET['payslip_id'])) {
    $payslip_id = (int) $_GET['payslip_id'];
    $payslip_stmt = $pdo->prepare("
        SELECT p.*, u.fullname, u.email, s.department, s.position
        FROM payroll p
        JOIN staffs s ON p.staff_id = s.id
        JOIN users u ON s.user_id = u.id
        JOIN shop_staffs ss ON ss.user_id = u.id
        WHERE p.id = ?
          AND ss.shop_id = ?
          AND ss.status = 'active'
        LIMIT 1
    ");
    $payslip_stmt->execute([$payslip_id, $shop_id]);
    $payslip = $payslip_stmt->fetch();
}

$payroll_stmt = $pdo->prepare("
    SELECT p.*, u.fullname, u.email, s.department, s.position
    FROM payroll p
    JOIN staffs s ON p.staff_id = s.id
    JOIN users u ON s.user_id = u.id
    JOIN shop_staffs ss ON ss.user_id = u.id
    WHERE ss.shop_id = ?
      AND ss.status = 'active'
    ORDER BY p.pay_period_end DESC, p.created_at DESC
");
$payroll_stmt->execute([$shop_id]);
$payroll_entries = $payroll_stmt->fetchAll();

$pending_count = 0;
$total_pending = 0.0;
$total_paid = 0.0;

foreach ($payroll_entries as $entry) {
    if ($entry['status'] === 'pending_owner') {
        $pending_count++;
        $total_pending += (float) ($entry['net_salary'] ?? 0);
    }
    if ($entry['status'] === 'paid') {
        $total_paid += (float) ($entry['net_salary'] ?? 0);
    }
}

$status_labels = [
    'draft' => 'Draft',
    'pending_owner' => 'Pending Owner',
    'approved' => 'Approved',
    'paid' => 'Paid',
];

$dashboard_link = $role === 'owner' ? '../owner/dashboard.php' : 'dashboard.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payroll &amp; Compensation Module</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .payroll-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 1.5rem;
            margin: 2rem 0;
        }

        .payroll-kpi {
            grid-column: span 3;
        }

        .payroll-kpi .metric {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }

        .payroll-kpi .metric i {
            font-size: 1.5rem;
        }

        .purpose-card,
        .workflow-card {
            grid-column: span 12;
        }

        .periods-card {
            grid-column: span 7;
        }

        .approvals-card,
        .exceptions-card {
            grid-column: span 5;
        }

        .automation-card {
            grid-column: span 6;
        }

        .approval-item,
        .automation-item,
        .workflow-step {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 1rem;
            background: white;
        }

        .approval-item + .approval-item,
        .automation-item + .automation-item,
        .workflow-step + .workflow-step {
            margin-top: 1rem;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 1rem;
        }

        .form-grid .span-6 {
            grid-column: span 6;
        }

        .form-grid .span-12 {
            grid-column: span 12;
        }

        .payslip-card {
            margin-top: 1.5rem;
            padding: 1.5rem;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            background: white;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar--compact">
        <div class="container d-flex justify-between align-center">
            <a href="<?php echo $dashboard_link; ?>" class="navbar-brand">
                <i class="fas fa-people-group"></i> <?php echo $display_name; ?>
            </a>
            <ul class="navbar-nav">
                <li><a href="<?php echo $dashboard_link; ?>" class="nav-link">Dashboard</a></li>
                <?php if ($role === 'hr'): ?>
                    <li><a href="hiring_management.php" class="nav-link">Hiring</a></li>
                    <li><a href="staff_productivity_performance.php" class="nav-link">Productivity</a></li>
                    <li><a href="payroll_compensation.php" class="nav-link active">Payroll</a></li>
                    <li><a href="analytics_reporting.php" class="nav-link">Analytics</a></li>
                <?php else: ?>
                    <li><a href="payroll_compensation.php" class="nav-link active">Payroll</a></li>
                <?php endif; ?>
                <li><a href="../auth/logout.php" class="nav-link">Logout</a></li>
            </ul>
        </div>
    </nav>

    <main class="container">
        <section class="page-header">
            <div>
                <h1>Payroll &amp; Compensation</h1>
                <p class="text-muted">Generate payroll for <?php echo htmlspecialchars($shop_name); ?> and track approvals.</p>
            </div>
            <span class="badge">Logged in as <?php echo $display_name; ?></span>
        </section>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php elseif ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <?php if ($payslip): ?>
            <div class="payslip-card">
                <h2>Payslip: <?php echo htmlspecialchars($payslip['fullname']); ?></h2>
                <p class="text-muted">Period: <?php echo htmlspecialchars($payslip['pay_period_start']); ?> to <?php echo htmlspecialchars($payslip['pay_period_end']); ?></p>
                <div class="form-grid">
                    <div class="span-6">
                        <p><strong>Department:</strong> <?php echo htmlspecialchars($payslip['department'] ?? '—'); ?></p>
                        <p><strong>Position:</strong> <?php echo htmlspecialchars($payslip['position'] ?? '—'); ?></p>
                        <p><strong>Email:</strong> <?php echo htmlspecialchars($payslip['email']); ?></p>
                    </div>
                    <div class="span-6">
                        <p><strong>Basic salary:</strong> ₱<?php echo number_format((float) ($payslip['basic_salary'] ?? 0), 2); ?></p>
                        <p><strong>Allowances:</strong> ₱<?php echo number_format((float) ($payslip['allowances'] ?? 0), 2); ?></p>
                        <p><strong>Deductions:</strong> ₱<?php echo number_format((float) ($payslip['deductions'] ?? 0), 2); ?></p>
                        <p><strong>Net salary:</strong> ₱<?php echo number_format((float) ($payslip['net_salary'] ?? 0), 2); ?></p>
                        <p><strong>Status:</strong> <?php echo htmlspecialchars(ucfirst($payslip['status'])); ?></p>
                    </div>
                </div>
            </div>
        <?php endif; ?>

            <section class="payroll-grid">
            <div class="card payroll-kpi">
                <div class="metric">
                    <div>
                        <h3>Pending approvals</h3>
                        <p class="value"><?php echo $pending_count; ?></p>
                        <p class="text-muted">Awaiting owner sign-off</p>
                    </div>
                    <i class="fas fa-hourglass-half text-warning"></i>
                </div>
            </div>
            <div class="card payroll-kpi">
                <div class="metric">
                    <div>
                        <h3>Pending total</h3>
                        <p class="value">₱<?php echo number_format($total_pending, 2); ?></p>
                        <p class="text-muted">Upcoming payouts</p>
                    </div>
                    <i class="fas fa-wallet text-primary"></i>
                </div>
            </div>
            <div class="card payroll-kpi">
                <div class="metric">
                    <div>
                        <h3>Paid total</h3>
                        <p class="value">₱<?php echo number_format($total_paid, 2); ?></p>
                        <p class="text-muted">Approved payroll</p>
                    </div>
                    <i class="fas fa-circle-check text-success"></i>
                </div>
            </div>
            <div class="card payroll-kpi">
                <div class="metric">
                    <div>
                        <h3>Payroll entries</h3>
                        <p class="value"><?php echo count($payroll_entries); ?></p>
                        <p class="text-muted">Total records</p>
                    </div>
                    <i class="fas fa-file-invoice-dollar text-info"></i>
                </div>
            </div>

            <div class="card periods-card">
                <h2>Generate payroll</h2>
                <?php if ($role === 'hr'): ?>
                    <form method="POST" class="form-grid">
                        <?php echo csrf_field(); ?>
                        <div class="form-group span-6">
                            <label>Pay period start</label>
                            <input type="date" name="pay_period_start" required>
                        </div>
                        <div class="form-group span-6">
                            <label>Pay period end</label>
                            <input type="date" name="pay_period_end" required>
                        </div>
                        <div class="form-group span-12">
                            <button type="submit" name="generate_payroll" class="btn btn-primary">Generate payroll</button>
                        </div>
                    </form>
                <?php else: ?>
                    <p class="text-muted">Only HR can generate payroll entries for a new pay period.</p>
                <?php endif; ?>
            </div>

            <div class="card approvals-card">
                <h2>Owner approvals</h2>
                <p class="text-muted">Owner can approve payroll entries once verified.</p>
                <p><strong>Pending:</strong> <?php echo $pending_count; ?></p>
                <p><strong>Pending total:</strong> ₱<?php echo number_format($total_pending, 2); ?></p>
            </div>

            <div class="card workflow-card">
                <h2>Payroll register</h2>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Staff</th>
                                <th>Period</th>
                                <th>Net salary</th>
                                <th>Status</th>
                                <th>Paid at</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($payroll_entries)): ?>
                                <tr>
                                    <td colspan="6">No payroll entries yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($payroll_entries as $entry): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($entry['fullname']); ?></strong>
                                            <div class="text-muted"><?php echo htmlspecialchars($entry['position'] ?? ''); ?></div>
                                        </td>
                                        <td><?php echo htmlspecialchars($entry['pay_period_start']); ?> - <?php echo htmlspecialchars($entry['pay_period_end']); ?></td>
                                        <td>₱<?php echo number_format((float) ($entry['net_salary'] ?? 0), 2); ?></td>
                                        <td><?php echo htmlspecialchars($status_labels[$entry['status']] ?? ucfirst($entry['status'])); ?></td>
                                        <td><?php echo $entry['paid_at'] ? htmlspecialchars(date('M d, Y', strtotime($entry['paid_at']))) : '—'; ?></td>
                                        <td>
                                            <div class="table-actions">
                                                <a class="btn btn-secondary" href="payroll_compensation.php?payslip_id=<?php echo $entry['id']; ?>">View payslip</a>
                                                <?php if ($role === 'hr' && $entry['status'] === 'draft'): ?>
                                                    <form method="POST" class="form-grid" style="margin-top: 0.75rem;">
                                                        <?php echo csrf_field(); ?>
                                                        <input type="hidden" name="payroll_id" value="<?php echo $entry['id']; ?>">
                                                        <div class="form-group span-6">
                                                            <label>Allowances</label>
                                                            <input type="number" step="0.01" name="allowances" value="<?php echo htmlspecialchars($entry['allowances'] ?? 0); ?>">
                                                        </div>
                                                        <div class="form-group span-6">
                                                            <label>Deductions</label>
                                                            <input type="number" step="0.01" name="deductions" value="<?php echo htmlspecialchars($entry['deductions'] ?? 0); ?>">
                                                        </div>
                                                        <div class="form-group span-12">
                                                            <button type="submit" name="update_payroll" class="btn btn-secondary">Update draft</button>
                                                            <button type="submit" name="submit_payroll" class="btn btn-primary">Submit for approval</button>
                                                        </div>
                                                    </form>
                                                <?php elseif ($role === 'owner' && $entry['status'] === 'pending_owner'): ?>
                                                    <form method="POST" onsubmit="return confirm('Approve this payroll entry?');">
                                                        <?php echo csrf_field(); ?>
                                                        <input type="hidden" name="payroll_id" value="<?php echo $entry['id']; ?>">
                                                        <button type="submit" name="approve_payroll" class="btn btn-primary">Approve</button>
                                                    </form>
                                                    <form method="POST" onsubmit="return confirm('Reject this payroll entry?');">
                                                        <?php echo csrf_field(); ?>
                                                        <input type="hidden" name="payroll_id" value="<?php echo $entry['id']; ?>">
                                                        <button type="submit" name="reject_payroll" class="btn btn-secondary">Reject</button>
                                                    </form>
                                                <?php elseif ($role === 'owner' && $entry['status'] === 'approved'): ?>
                                                    <form method="POST" onsubmit="return confirm('Mark this payroll entry as paid?');">
                                                        <?php echo csrf_field(); ?>
                                                        <input type="hidden" name="payroll_id" value="<?php echo $entry['id']; ?>">
                                                        <button type="submit" name="mark_paid" class="btn btn-primary">Mark paid</button>
                                                    </form>
                                                    <a class="btn btn-secondary" href="payroll_compensation.php?payslip_id=<?php echo $entry['id']; ?>">Release payslip</a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </main>
</body>
</html>
