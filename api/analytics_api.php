<?php
session_start();
require_once '../config/db.php';

header('Content-Type: application/json');

if (!is_active_user() || $_SESSION['user']['role'] !== 'sys_admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

try {
    $overview = $pdo->query("
        SELECT
            (SELECT COUNT(*) FROM users) as total_users,
            (SELECT COUNT(*) FROM shops) as total_shops,
            (SELECT COUNT(*) FROM orders) as total_orders,
            (SELECT COUNT(*) FROM orders WHERE status = 'completed' AND completed_at IS NOT NULL) as completed_orders,
            (SELECT COUNT(*) FROM orders WHERE status = 'pending') as pending_orders,
            (SELECT COUNT(*) FROM orders WHERE status IN ('accepted', 'in_progress')) as active_orders,
            (SELECT COUNT(*) FROM orders WHERE status = 'cancelled') as cancelled_orders,
            (SELECT COUNT(*) FROM orders WHERE payment_status = 'paid') as paid_orders,
            (SELECT COUNT(*) FROM orders WHERE payment_status = 'pending') as pending_payments,
            (SELECT COALESCE(SUM(price), 0) FROM orders WHERE payment_status = 'paid') as total_revenue,
            (SELECT COALESCE(AVG(price), 0) FROM orders WHERE status = 'completed' AND price IS NOT NULL) as avg_order_value
    ")->fetch();

    $total_orders = (int) ($overview['total_orders'] ?? 0);
    $overview['completion_rate'] = $total_orders > 0
        ? round(($overview['completed_orders'] / $total_orders) * 100, 1)
        : 0;
    $overview['cancellation_rate'] = $total_orders > 0
        ? round((($overview['cancelled_orders'] ?? 0) / $total_orders) * 100, 1)
        : 0;
    $overview['payment_rate'] = $total_orders > 0
        ? round((($overview['paid_orders'] ?? 0) / $total_orders) * 100, 1)
        : 0;
        
    echo json_encode([
        'data' => $overview,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to load analytics data']);
}
