<?php
session_start();
require_once '../config/db.php';
require_once 'partials.php';
require_role('sys_admin');

$registeredAccounts = $pdo->query("
    SELECT id, fullname, email, phone, role, status, created_at, last_login
    FROM users
    ORDER BY created_at DESC
")->fetchAll();

$approvedAccounts = $pdo->query("
    SELECT id, fullname, email, phone, role, status, created_at, last_login
    FROM users
    WHERE status = 'active' AND role IN ('client', 'owner')
    ORDER BY created_at DESC
")->fetchAll();

$shopColumns = $pdo->query("SHOW COLUMNS FROM shops")->fetchAll(PDO::FETCH_COLUMN);
$hasRejectionReason = in_array('rejection_reason', $shopColumns, true);
$hasRejectedAt = in_array('rejected_at', $shopColumns, true);

$rejectionReasonSelect = $hasRejectionReason ? 's.rejection_reason' : 'NULL AS rejection_reason';
$rejectedAtSelect = $hasRejectedAt ? 's.rejected_at' : 'NULL AS rejected_at';

$orderBy = $hasRejectedAt ? 's.rejected_at DESC, u.id DESC' : 'u.id DESC';

$rejectedOwners = $pdo->query("
    SELECT u.id, u.fullname, u.email, u.phone, u.status, s.shop_name,
           $rejectionReasonSelect,
           $rejectedAtSelect
    FROM users u
    JOIN shops s ON s.owner_id = u.id
    WHERE u.role = 'owner' AND (u.status = 'rejected' OR s.status = 'rejected')
    ORDER BY $orderBy
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Registry - System Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .registry-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 1.5rem;
            margin: 2rem 0;
        }

        .registry-card {
            grid-column: span 12;
        }

        .registry-card table {
            margin-bottom: 0;
        }

        .muted-cell {
            color: var(--gray-500);
            font-size: 0.85rem;
        }
    </style>
</head>
<body>
    <?php sys_admin_nav('accounts'); ?>

    <div class="container">
        <div class="dashboard-header fade-in">
            <div class="d-flex justify-between align-center">
                <div>
                    <h2>Account Registry</h2>
                    <p class="text-muted">Review registered accounts, approved members, and rejected shop owners.</p>
                </div>
                <span class="badge badge-info"><i class="fas fa-users"></i> Registry</span>
            </div>
        </div>

        <div class="registry-grid">
            <div class="card registry-card">
                <div class="card-header">
                    <h3><i class="fas fa-address-book text-primary"></i> Registered Accounts</h3>
                    <p class="text-muted">All users currently registered in the system.</p>
                </div>
                <?php if (empty($registeredAccounts)): ?>
                    <div class="empty-state">
                        <i class="fas fa-user-slash fa-2x mb-2"></i>
                        <p class="mb-0">No registered accounts found.</p>
                    </div>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Contact</th>
                                <th>Registered</th>
                                <th>Last Login</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($registeredAccounts as $account): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($account['fullname'] ?? '—'); ?></td>
                                    <td><span class="badge badge-info"><?php echo ucfirst($account['role']); ?></span></td>
                                    <td><?php echo ucfirst($account['status'] ?? '—'); ?></td>
                                    <td>
                                        <div><?php echo htmlspecialchars($account['email'] ?? '—'); ?></div>
                                        <small class="text-muted"><?php echo htmlspecialchars($account['phone'] ?? 'N/A'); ?></small>
                                    </td>
                                    <td><?php echo $account['created_at'] ? date('M d, Y', strtotime($account['created_at'])) : '—'; ?></td>
                                    <td class="muted-cell"><?php echo $account['last_login'] ? date('M d, Y H:i', strtotime($account['last_login'])) : 'Never'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <div class="card registry-card">
                <div class="card-header">
                    <h3><i class="fas fa-user-check text-success"></i> Approved Users & Owners</h3>
                    <p class="text-muted">Active clients and shop owners with approved access.</p>
                </div>
                <?php if (empty($approvedAccounts)): ?>
                    <div class="empty-state">
                        <i class="fas fa-user-check fa-2x mb-2"></i>
                        <p class="mb-0">No approved users found.</p>
                    </div>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Role</th>
                                <th>Contact</th>
                                <th>Registered</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($approvedAccounts as $account): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($account['fullname'] ?? '—'); ?></td>
                                    <td><span class="badge badge-success"><?php echo ucfirst($account['role']); ?></span></td>
                                    <td>
                                        <div><?php echo htmlspecialchars($account['email'] ?? '—'); ?></div>
                                        <small class="text-muted"><?php echo htmlspecialchars($account['phone'] ?? 'N/A'); ?></small>
                                    </td>
                                    <td><?php echo $account['created_at'] ? date('M d, Y', strtotime($account['created_at'])) : '—'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <div class="card registry-card">
                <div class="card-header">
                    <h3><i class="fas fa-user-times text-danger"></i> Rejected Owners</h3>
                    <p class="text-muted">Shop owners who were rejected along with their stated reasons.</p>
                </div>
                <?php if (empty($rejectedOwners)): ?>
                    <div class="empty-state">
                        <i class="fas fa-user-times fa-2x mb-2"></i>
                        <p class="mb-0">No rejected owners recorded.</p>
                    </div>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Owner</th>
                                <th>Shop</th>
                                <th>Reason</th>
                                <th>Rejected At</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rejectedOwners as $owner): ?>
                                <tr>
                                    <td>
                                        <div><?php echo htmlspecialchars($owner['fullname'] ?? '—'); ?></div>
                                        <small class="text-muted"><?php echo htmlspecialchars($owner['email'] ?? '—'); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($owner['shop_name'] ?? '—'); ?></td>
                                    <td><?php echo htmlspecialchars($owner['rejection_reason'] ?? 'Reason not recorded'); ?></td>
                                    <td class="muted-cell"><?php echo $owner['rejected_at'] ? date('M d, Y H:i', strtotime($owner['rejected_at'])) : '—'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php sys_admin_footer(); ?>
</body>
</html>
