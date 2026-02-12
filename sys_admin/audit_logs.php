<?php
session_start();
require_once '../config/db.php';
require_once 'partials.php';
require_role('sys_admin');

$pageSize = 25;
$page = max(1, (int) ($_GET['page'] ?? 1));
$search = sanitize($_GET['q'] ?? '');
$actionFilter = sanitize($_GET['action'] ?? '');
$roleFilter = sanitize($_GET['role'] ?? '');
$entityFilter = sanitize($_GET['entity_type'] ?? '');
$startDate = sanitize($_GET['start_date'] ?? '');
$endDate = sanitize($_GET['end_date'] ?? '');

$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(a.action LIKE :search OR a.entity_type LIKE :search OR a.ip_address LIKE :search OR u.fullname LIKE :search OR u.email LIKE :search)";
    $params['search'] = '%' . $search . '%';
}

if ($actionFilter !== '') {
    $where[] = "a.action = :action";
    $params['action'] = $actionFilter;
}

if ($roleFilter !== '') {
    $where[] = "a.actor_role = :role";
    $params['role'] = $roleFilter;
}

if ($entityFilter !== '') {
    $where[] = "a.entity_type = :entity_type";
    $params['entity_type'] = $entityFilter;
}

if ($startDate !== '') {
    $where[] = "a.created_at >= :start_date";
    $params['start_date'] = $startDate . ' 00:00:00';
}

if ($endDate !== '') {
    $where[] = "a.created_at <= :end_date";
    $params['end_date'] = $endDate . ' 23:59:59';
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$countStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM audit_logs a
    LEFT JOIN users u ON u.id = a.actor_id
    $whereSql
");
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $pageSize));
$page = min($page, $totalPages);
$offset = ($page - 1) * $pageSize;

$logStmt = $pdo->prepare("
    SELECT a.*, u.fullname AS actor_name, u.email AS actor_email
    FROM audit_logs a
    LEFT JOIN users u ON u.id = a.actor_id
    $whereSql
    ORDER BY a.created_at DESC
    LIMIT :limit OFFSET :offset
");
foreach ($params as $key => $value) {
    $logStmt->bindValue(':' . $key, $value);
}
$logStmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
$logStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$logStmt->execute();
$logs = $logStmt->fetchAll();

$actions = $pdo->query("SELECT DISTINCT action FROM audit_logs ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);
$roles = $pdo->query("SELECT DISTINCT actor_role FROM audit_logs WHERE actor_role IS NOT NULL ORDER BY actor_role")->fetchAll(PDO::FETCH_COLUMN);
$entities = $pdo->query("SELECT DISTINCT entity_type FROM audit_logs ORDER BY entity_type")->fetchAll(PDO::FETCH_COLUMN);

$alertWindowHours = 24;
$suspiciousCountStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM audit_logs
    WHERE action = 'suspicious_activity'
      AND created_at >= (NOW() - INTERVAL $alertWindowHours HOUR)
");
$suspiciousCountStmt->execute();
$suspiciousCount = (int) $suspiciousCountStmt->fetchColumn();

$suspiciousStmt = $pdo->prepare("
    SELECT a.*, u.fullname AS actor_name
    FROM audit_logs a
    LEFT JOIN users u ON u.id = a.actor_id
    WHERE a.action = 'suspicious_activity'
    ORDER BY a.created_at DESC
    LIMIT 5
");
$suspiciousStmt->execute();
$suspiciousLogs = $suspiciousStmt->fetchAll();

$queryParams = $_GET;
unset($queryParams['page']);
$baseQuery = http_build_query($queryParams);
$baseQuery = $baseQuery ? $baseQuery . '&' : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Logs - System Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .audit-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 1.5rem;
            margin: 2rem 0;
        }

        .audit-card {
            grid-column: span 12;
        }

        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
        }

        .log-meta {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .log-json {
            max-width: 320px;
            font-size: 0.8rem;
            white-space: pre-wrap;
            word-break: break-word;
            color: var(--gray-600);
            background: var(--gray-100);
            border-radius: var(--radius);
            padding: 0.5rem;
        }

        .pagination {
            display: flex;
            gap: 0.5rem;
            justify-content: flex-end;
            align-items: center;
            margin-top: 1rem;
        }
    </style>
</head>
<body>
    <?php sys_admin_nav('audit_logs'); ?>

    <div class="container">
        <div class="dashboard-header fade-in">
            <div class="d-flex justify-between align-center">
                <div>
                    <h2>Audit Logs</h2>
                    <p class="text-muted">Monitor security-sensitive activity across the platform.</p>
                </div>
                <span class="badge badge-info"><i class="fas fa-clipboard-list"></i> Audit Trail</span>
            </div>
        </div>

        <div class="audit-grid">
            <div class="card audit-card">
                <div class="card-header">
                    <h3><i class="fas fa-filter text-primary"></i> Filter Activity</h3>
                    <p class="text-muted">Filter by action, user role, entity, or date.</p>
                </div>
                <form method="GET" class="filters-grid">
                    <div class="form-group">
                        <label>Search</label>
                        <input type="text" name="q" class="form-control" placeholder="User, IP, action" value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="form-group">
                        <label>Action</label>
                        <select name="action" class="form-control">
                            <option value="">All actions</option>
                            <?php foreach ($actions as $action): ?>
                                <option value="<?php echo htmlspecialchars($action); ?>" <?php echo $action === $actionFilter ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($action); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Actor Role</label>
                        <select name="role" class="form-control">
                            <option value="">All roles</option>
                            <?php foreach ($roles as $role): ?>
                                <option value="<?php echo htmlspecialchars($role); ?>" <?php echo $role === $roleFilter ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars(ucfirst($role)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Entity</label>
                        <select name="entity_type" class="form-control">
                            <option value="">All entities</option>
                            <?php foreach ($entities as $entity): ?>
                                <option value="<?php echo htmlspecialchars($entity); ?>" <?php echo $entity === $entityFilter ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($entity); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Start Date</label>
                        <input type="date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($startDate); ?>">
                    </div>
                    <div class="form-group">
                        <label>End Date</label>
                        <input type="date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($endDate); ?>">
                    </div>
                    <div class="form-group d-flex align-end gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Apply Filters
                        </button>
                        <a href="audit_logs.php" class="btn btn-outline-secondary">
                            <i class="fas fa-undo"></i> Clear
                        </a>
                    </div>
                </form>
            </div>

            <div class="card audit-card">
                <div class="card-header">
                    <h3><i class="fas fa-shield-alt text-danger"></i> Suspicious Activity Alerts</h3>
                    <p class="text-muted">
                        <?php echo number_format($suspiciousCount); ?> alert<?php echo $suspiciousCount === 1 ? '' : 's'; ?> in the last <?php echo $alertWindowHours; ?> hours.
                    </p>
                </div>
                <?php if (empty($suspiciousLogs)): ?>
                    <div class="empty-state">
                        <i class="fas fa-shield-check fa-2x mb-2"></i>
                        <p class="mb-0">No suspicious activity alerts logged recently.</p>
                    </div>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Detected At</th>
                                <th>Actor</th>
                                <th>Details</th>
                                <th>IP Address</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($suspiciousLogs as $alert): ?>
                                <tr>
                                    <td><?php echo $alert['created_at'] ? date('M d, Y H:i', strtotime($alert['created_at'])) : '—'; ?></td>
                                    <td>
                                        <div><?php echo htmlspecialchars($alert['actor_name'] ?? 'System'); ?></div>
                                        <small class="text-muted"><?php echo htmlspecialchars($alert['actor_role'] ?? 'system'); ?></small>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($alert['new_values'] ?? 'No details provided.'); ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($alert['ip_address'] ?? '—'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <div class="card audit-card">
                <div class="card-header">
                    <h3><i class="fas fa-list text-success"></i> Activity Feed</h3>
                    <p class="text-muted">Showing <?php echo number_format($totalRows); ?> total log entries.</p>
                </div>
                <?php if (empty($logs)): ?>
                    <div class="empty-state">
                        <i class="fas fa-clipboard fa-2x mb-2"></i>
                        <p class="mb-0">No audit logs match your filters.</p>
                    </div>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Action</th>
                                <th>Actor</th>
                                <th>Entity</th>
                                <th>Changes</th>
                                <th>IP Address</th>
                                <th>Logged At</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td>
                                        <div><strong><?php echo htmlspecialchars($log['action']); ?></strong></div>
                                        <small class="text-muted"><?php echo htmlspecialchars($log['actor_role'] ?? 'system'); ?></small>
                                    </td>
                                    <td>
                                        <div class="log-meta">
                                            <span><?php echo htmlspecialchars($log['actor_name'] ?? 'System'); ?></span>
                                            <small class="text-muted"><?php echo htmlspecialchars($log['actor_email'] ?? '—'); ?></small>
                                        </div>
                                    </td>
                                    <td>
                                        <div><?php echo htmlspecialchars($log['entity_type']); ?></div>
                                        <small class="text-muted">ID: <?php echo htmlspecialchars($log['entity_id'] ?? '—'); ?></small>
                                    </td>
                                    <td>
                                        <?php if (!empty($log['old_values']) || !empty($log['new_values'])): ?>
                                            <div class="log-json">
                                                <?php if (!empty($log['old_values'])): ?>
                                                    <div><strong>Old:</strong> <?php echo htmlspecialchars($log['old_values']); ?></div>
                                                <?php endif; ?>
                                                <?php if (!empty($log['new_values'])): ?>
                                                    <div><strong>New:</strong> <?php echo htmlspecialchars($log['new_values']); ?></div>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted">No changes recorded.</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($log['ip_address'] ?? '—'); ?></td>
                                    <td><?php echo $log['created_at'] ? date('M d, Y H:i', strtotime($log['created_at'])) : '—'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <div class="pagination">
                        <span class="text-muted">Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
                        <?php if ($page > 1): ?>
                            <a class="btn btn-outline-secondary btn-sm" href="?<?php echo $baseQuery; ?>page=<?php echo $page - 1; ?>">Previous</a>
                        <?php endif; ?>
                        <?php if ($page < $totalPages): ?>
                            <a class="btn btn-outline-secondary btn-sm" href="?<?php echo $baseQuery; ?>page=<?php echo $page + 1; ?>">Next</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php sys_admin_footer(); ?>
</body>
</html>
