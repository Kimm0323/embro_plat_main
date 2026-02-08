<?php
session_start();
require_once '../config/db.php';
require_once 'partials.php';
require_role('sys_admin');

$type = $_GET['type'] ?? '';
$allowed = ['orders_turnaround', 'staff_performance', 'shop_performance'];

if (!in_array($type, $allowed, true)) {
    http_response_code(400);
    echo 'Invalid export type.';
    exit();
}

header('Content-Type: text/csv; charset=utf-8');
$filename = 'analytics_export_' . $type . '_' . date('Ymd_His') . '.csv';
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');

if ($type === 'orders_turnaround') {
    fputcsv($output, [
        'Order ID',
        'Shop',
        'Service',
        'Status',
        'Created At',
        'Completed At',
        'Turnaround (hrs)',
        'Assigned To',
    ]);

    $stmt = $pdo->query("
        SELECT
            o.id,
            s.shop_name,
            o.service_type,
            o.status,
            o.created_at,
            o.completed_at,
            TIMESTAMPDIFF(HOUR, o.created_at, o.completed_at) as turnaround_hours,
            u.fullname as assigned_to
        FROM orders o
        LEFT JOIN shops s ON o.shop_id = s.id
        LEFT JOIN users u ON o.assigned_to = u.id
        ORDER BY o.created_at DESC
    ");

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            $row['id'],
            $row['shop_name'] ?? 'N/A',
            $row['service_type'] ?? 'N/A',
            $row['status'],
            $row['created_at'],
            $row['completed_at'] ?? '',
            $row['turnaround_hours'] !== null ? number_format((float) $row['turnaround_hours'], 1, '.', '') : '',
            $row['assigned_to'] ?? 'Unassigned',
        ]);
    }
}

if ($type === 'staff_performance') {
    fputcsv($output, [
        'staff',
        'Total Assigned',
        'Active Orders',
        'Completed Orders',
        'Cancelled Orders',
        'Avg Completion (hrs)',
    ]);

    $stmt = $pdo->query("
        SELECT
            u.fullname,
            COUNT(o.id) as total_assigned,
            SUM(CASE WHEN o.status IN ('accepted', 'in_progress') THEN 1 ELSE 0 END) as active_orders,
            SUM(CASE WHEN o.status = 'completed' THEN 1 ELSE 0 END) as completed_orders,
            SUM(CASE WHEN o.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_orders,
            AVG(CASE WHEN o.status = 'completed' AND o.completed_at IS NOT NULL THEN TIMESTAMPDIFF(HOUR, o.created_at, o.completed_at) END) as avg_completion_hours
        FROM users u
        LEFT JOIN orders o ON o.assigned_to = u.id
        WHERE u.role = 'staff'
        GROUP BY u.id, u.fullname
        ORDER BY completed_orders DESC, active_orders DESC
    ");

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            $row['fullname'],
            $row['total_assigned'],
            $row['active_orders'],
            $row['completed_orders'],
            $row['cancelled_orders'],
            $row['avg_completion_hours'] !== null ? number_format((float) $row['avg_completion_hours'], 1, '.', '') : '',
        ]);
    }
}

if ($type === 'shop_performance') {
    fputcsv($output, [
        'Shop',
        'Total Orders',
        'Completed Orders',
        'Cancelled Orders',
        'Revenue',
        'Avg Completion (hrs)',
    ]);

    $stmt = $pdo->query("
        SELECT
            s.shop_name,
            COUNT(o.id) as total_orders,
            SUM(CASE WHEN o.status = 'completed' THEN 1 ELSE 0 END) as completed_orders,
            SUM(CASE WHEN o.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_orders,
            COALESCE(SUM(CASE WHEN o.status = 'completed' THEN o.price ELSE 0 END), 0) as revenue,
            AVG(CASE WHEN o.status = 'completed' AND o.completed_at IS NOT NULL THEN TIMESTAMPDIFF(HOUR, o.created_at, o.completed_at) END) as avg_completion_hours
        FROM shops s
        LEFT JOIN orders o ON s.id = o.shop_id
        GROUP BY s.id, s.shop_name
        ORDER BY revenue DESC, completed_orders DESC
    ");

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            $row['shop_name'],
            $row['total_orders'],
            $row['completed_orders'],
            $row['cancelled_orders'],
            number_format((float) $row['revenue'], 2, '.', ''),
            $row['avg_completion_hours'] !== null ? number_format((float) $row['avg_completion_hours'], 1, '.', '') : '',
        ]);
    }
}

fclose($output);
exit();
