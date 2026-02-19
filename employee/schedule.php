<?php
session_start();
require_once '../config/db.php';
require_role(['staff','employee']);

$staff_id = $_SESSION['user']['id'];

$emp_stmt = $pdo->prepare("
    SELECT se.*, s.shop_name, s.logo, s.phone AS shop_phone, s.email AS shop_email,
           s.address AS shop_address, s.owner_id,
           o.fullname AS owner_name, o.email AS owner_email, o.phone AS owner_phone
    FROM shop_staffs se
    JOIN shops s ON se.shop_id = s.id
    LEFT JOIN users o ON s.owner_id = o.id
    WHERE se.user_id = ? AND se.status = 'active'
");
$emp_stmt->execute([$staff_id]);
$staff = $emp_stmt->fetch();

if(!$staff) {
    die("You are not assigned to any shop. Please contact your shop owner.");
}

$staff_permissions = fetch_staff_permissions($pdo, $staff_id);
require_staff_permission($pdo, $staff_id, 'view_jobs');

$month_param = $_GET['month'] ?? date('Y-m');
$month_dt = DateTime::createFromFormat('Y-m', $month_param);
if(!$month_dt) {
    $month_dt = new DateTime('first day of this month');
}
$month_dt->setDate((int) $month_dt->format('Y'), (int) $month_dt->format('m'), 1);

$month_start = $month_dt->format('Y-m-01');
$month_end = $month_dt->format('Y-m-t');
$month_label = $month_dt->format('F Y');

$prev_month = (clone $month_dt)->modify('-1 month')->format('Y-m');
$next_month = (clone $month_dt)->modify('+1 month')->format('Y-m');

$schedule_stmt = $pdo->prepare("
    SELECT
        o.id AS order_id,
        o.order_number,
        o.service_type,
        o.quantity,
        o.design_description,
        o.client_notes,
        o.shop_notes,
        o.status AS order_status,
        o.progress,
        o.created_at,
        u.fullname AS client_name,
        u.email AS client_email,
        u.phone AS client_phone,
        COALESCE(js.scheduled_date, o.scheduled_date) AS schedule_date,
        js.scheduled_time AS schedule_time,
        COALESCE(js.status, o.status) AS schedule_status,
        js.task_description
    FROM orders o
    JOIN users u ON o.client_id = u.id
    LEFT JOIN job_schedule js ON js.order_id = o.id AND js.staff_id = ?
    WHERE (o.assigned_to = ? OR js.staff_id = ?)
       AND COALESCE(js.scheduled_date, o.scheduled_date) BETWEEN ? AND ?
    ORDER BY schedule_date ASC, schedule_time ASC, o.created_at DESC
");
$schedule_stmt->execute([$staff_id, $staff_id, $staff_id, $month_start, $month_end]);
$schedule = $schedule_stmt->fetchAll();

$calendar_orders = [];
foreach($schedule as $item) {
    if(empty($item['schedule_date'])) {
        continue;
    }
    $calendar_orders[$item['schedule_date']][] = $item;
}

$selected_date = $_GET['date'] ?? date('Y-m-d');
$is_selected_in_month = preg_match('/^\d{4}-\d{2}-\d{2}$/', $selected_date)
    && $selected_date >= $month_start
    && $selected_date <= $month_end;

if(!$is_selected_in_month) {
    $selected_date = $month_start;
}

if(!isset($calendar_orders[$selected_date]) && !empty($calendar_orders)) {
    $selected_date = array_key_first($calendar_orders);
}

$selected_orders = $calendar_orders[$selected_date] ?? [];

$first_day_of_month = (int) $month_dt->format('N');
$days_in_month = (int) $month_dt->format('t');
$calendar_cells = [];

for($i = 1; $i < $first_day_of_month; $i++) {
    $calendar_cells[] = null;
}
for($day = 1; $day <= $days_in_month; $day++) {
    $calendar_cells[] = $day;
}
while(count($calendar_cells) % 7 !== 0) {
    $calendar_cells[] = null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Schedule - <?php echo htmlspecialchars($staff['shop_name']); ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
       .schedule-layout {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.5rem;
        }
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, minmax(0, 1fr));
            gap: 0.5rem;
        }
        .calendar-weekday {
            text-transform: uppercase;
            font-size: 0.75rem;
            font-weight: 700;
            text-align: center;
            color: #6c757d;
            padding: 0.5rem 0;
        }
        .calendar-cell {
            min-height: 92px;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 0.6rem;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            text-decoration: none;
            color: #111827;
            background: #fff;
        }
        .calendar-cell.empty {
            background: #f8fafc;
            border-style: dashed;
        }
        .calendar-cell:hover {
            border-color: #4361ee;
            transform: translateY(-1px);
        }
        .calendar-cell.active {
            border-color: #4361ee;
            box-shadow: 0 0 0 2px rgba(67, 97, 238, 0.12);
        }
        .day-number {
            font-weight: 700;
        }
        .order-count {
            font-size: 0.78rem;
            color: #4361ee;
            font-weight: 600;
        }
        .owner-contact li {
            margin-bottom: 0.4rem;
        }
        @media (max-width: 992px) {
            .schedule-layout {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="container d-flex justify-between align-center">
            <a href="dashboard.php" class="navbar-brand">
                <i class="fas fa-user-tie"></i> staff Dashboard
            </a>
            <ul class="navbar-nav">
                <li><a href="dashboard.php" class="nav-link">Dashboard</a></li>
                <?php if(!empty($staff_permissions['view_jobs'])): ?>
                    <li><a href="assigned_jobs.php" class="nav-link">My Jobs</a></li>
                    <li><a href="schedule.php" class="nav-link active">Schedule</a></li>
                <?php endif; ?>
                <?php if(!empty($staff_permissions['update_status'])): ?>
                    <li><a href="update_status.php" class="nav-link">Update Status</a></li>
                <?php endif; ?>
                <?php if(!empty($staff_permissions['upload_photos'])): ?>
                    <li><a href="upload_photos.php" class="nav-link">Upload Photos</a></li>
                <?php endif; ?>
                <li class="dropdown">
                    <a href="#" class="nav-link dropdown-toggle">
                        <i class="fas fa-user"></i> <?php echo $_SESSION['user']['fullname']; ?>
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
           <div class="d-flex justify-between align-center flex-wrap gap-2">
                <div>
                    <h2>My Schedule Calendar</h2>
                    <p class="text-muted">See which orders you will handle each day and review owner-provided order details.</p>
                </div>
                <div class="d-flex gap-2">
                    <a class="btn btn-outline" href="?month=<?php echo urlencode($prev_month); ?>&date=<?php echo urlencode($selected_date); ?>"><i class="fas fa-chevron-left"></i> Previous</a>
                    <span class="btn btn-light"><?php echo htmlspecialchars($month_label); ?></span>
                    <a class="btn btn-outline" href="?month=<?php echo urlencode($next_month); ?>&date=<?php echo urlencode($selected_date); ?>">Next <i class="fas fa-chevron-right"></i></a>
                </div>
            </div>
        </div>

        <div class="schedule-layout">
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-calendar-alt text-primary"></i> Monthly Assignment Calendar</h3>
                </div>
            <div class="calendar-grid mb-2">
                    <?php foreach(['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $weekday): ?>
                        <div class="calendar-weekday"><?php echo $weekday; ?></div>
                    <?php endforeach; ?>
                </div>

                <div class="calendar-grid">
                    <?php foreach($calendar_cells as $day): ?>
                        <?php if($day === null): ?>
                            <div class="calendar-cell empty"></div>
                        <?php else: ?>
                            <?php $cell_date = $month_dt->format('Y-m-') . str_pad((string) $day, 2, '0', STR_PAD_LEFT); ?>
                            <?php $order_count = isset($calendar_orders[$cell_date]) ? count($calendar_orders[$cell_date]) : 0; ?>
                            <?php $active_class = $cell_date === $selected_date ? 'active' : ''; ?>
                            <a class="calendar-cell <?php echo $active_class; ?>" href="?month=<?php echo urlencode($month_dt->format('Y-m')); ?>&date=<?php echo urlencode($cell_date); ?>">
                                <span class="day-number"><?php echo $day; ?></span>
                                <?php if($order_count > 0): ?>
                                    <span class="order-count"><?php echo $order_count; ?> order<?php echo $order_count > 1 ? 's' : ''; ?></span>
                                <?php else: ?>
                                    <span class="text-muted" style="font-size:0.78rem;">No tasks</span>
                                <?php endif; ?>
                            </a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-store text-primary"></i> Owner Information</h3>
                </div>
                <ul class="list-unstyled owner-contact mb-0">
                    <li><strong>Shop:</strong> <?php echo htmlspecialchars($staff['shop_name']); ?></li>
                    <li><strong>Owner:</strong> <?php echo htmlspecialchars($staff['owner_name'] ?? 'N/A'); ?></li>
                    <li><strong>Owner Email:</strong> <?php echo htmlspecialchars($staff['owner_email'] ?? 'N/A'); ?></li>
                    <li><strong>Owner Phone:</strong> <?php echo htmlspecialchars($staff['owner_phone'] ?? 'N/A'); ?></li>
                    <li><strong>Shop Email:</strong> <?php echo htmlspecialchars($staff['shop_email'] ?? 'N/A'); ?></li>
                    <li><strong>Shop Phone:</strong> <?php echo htmlspecialchars($staff['shop_phone'] ?? 'N/A'); ?></li>
                    <li><strong>Address:</strong> <?php echo htmlspecialchars($staff['shop_address'] ?? 'N/A'); ?></li>
                </ul>
            </div>
        </div>

        <div class="card mt-4">
            <div class="card-header d-flex justify-between align-center flex-wrap gap-2">
                <h3><i class="fas fa-clipboard-list text-primary"></i> Orders for <?php echo date('M d, Y', strtotime($selected_date)); ?></h3>
                <span class="badge badge-primary"><?php echo count($selected_orders); ?> assigned</span>
            </div>

            <?php if(!empty($selected_orders)): ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Order #</th>
                                <th>Service</th>
                                <th>Client</th>
                                <th>Time</th>
                                <th>Status</th>
                                <th>Quantity</th>
                                <th>Owner Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($selected_orders as $item): ?>
                                <tr>
                                    <td>#<?php echo htmlspecialchars($item['order_number']); ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($item['service_type']); ?></strong>
                                        <?php if(!empty($item['design_description'])): ?>
                                            <div class="text-muted" style="font-size:0.82rem;">
                                                <?php echo htmlspecialchars($item['design_description']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div><?php echo htmlspecialchars($item['client_name']); ?></div>
                                        <div class="text-muted" style="font-size:0.82rem;"><?php echo htmlspecialchars($item['client_email'] ?: 'No email'); ?></div>
                                        <div class="text-muted" style="font-size:0.82rem;"><?php echo htmlspecialchars($item['client_phone'] ?: 'No phone'); ?></div>
                                    </td>
                                    <td><?php echo !empty($item['schedule_time']) ? date('h:i A', strtotime($item['schedule_time'])) : 'Time TBD'; ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo in_array($item['schedule_status'], ['completed'], true) ? 'success' : (in_array($item['schedule_status'], ['cancelled'], true) ? 'danger' : 'warning'); ?>">
                                            <?php echo ucwords(str_replace('_', ' ', $item['schedule_status'])); ?>
                                        </span>
                                        <div class="text-muted" style="font-size:0.8rem;">Progress: <?php echo (int) $item['progress']; ?>%</div>
                                    </td>
                                    <td><?php echo (int) $item['quantity']; ?></td>
                                    <td>
                                        <div><?php echo htmlspecialchars($item['shop_notes'] ?: 'No owner notes'); ?></div>
                                        <?php if(!empty($item['task_description'])): ?>
                                            <div class="text-muted" style="font-size:0.82rem;">Task: <?php echo htmlspecialchars($item['task_description']); ?></div>
                                        <?php endif; ?>
                                        <?php if(!empty($item['client_notes'])): ?>
                                            <div class="text-muted" style="font-size:0.82rem;">Client note: <?php echo htmlspecialchars($item['client_notes']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center p-4">
                    <i class="fas fa-calendar-times fa-3x text-muted mb-3"></i>
                    <h4>No Orders Scheduled</h4>
                    <p class="text-muted">No orders are assigned for this date yet.</p>
                </div>
           <?php endif; ?>
        </div>
    </div>
</body>
</html>
