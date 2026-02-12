<?php

function fetch_order_analytics(PDO $pdo, ?array $shopIds = null): array {
    $params = [];
    $shopClause = '';

    if (is_array($shopIds)) {
        if (count($shopIds) === 0) {
            $shopClause = ' AND 0=1';
        } else {
            $placeholders = implode(',', array_fill(0, count($shopIds), '?'));
            $shopClause = " AND shop_id IN ($placeholders)";
            $params = $shopIds;
        }
    }

    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) as total_orders,
            SUM(status = 'completed' AND completed_at IS NOT NULL) as completed_orders,
            SUM(status = 'pending') as pending_orders,
            SUM(status IN ('accepted', 'in_progress')) as active_orders,
            SUM(status = 'cancelled') as cancelled_orders,
            SUM(payment_status = 'paid') as paid_orders,
            SUM(payment_status = 'pending') as pending_payments,
            COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN price ELSE 0 END), 0) as total_revenue,
            COALESCE(AVG(CASE WHEN status = 'completed' AND price IS NOT NULL THEN price END), 0) as avg_order_value
        FROM orders
        WHERE 1=1 {$shopClause}
    ");
    $stmt->execute($params);
    $overview = $stmt->fetch() ?: [];

    return [
        'total_orders' => (int) ($overview['total_orders'] ?? 0),
        'completed_orders' => (int) ($overview['completed_orders'] ?? 0),
        'pending_orders' => (int) ($overview['pending_orders'] ?? 0),
        'active_orders' => (int) ($overview['active_orders'] ?? 0),
        'cancelled_orders' => (int) ($overview['cancelled_orders'] ?? 0),
        'paid_orders' => (int) ($overview['paid_orders'] ?? 0),
        'pending_payments' => (int) ($overview['pending_payments'] ?? 0),
        'total_revenue' => (float) ($overview['total_revenue'] ?? 0),
        'avg_order_value' => (float) ($overview['avg_order_value'] ?? 0),
    ];
}

function fetch_staff_count(PDO $pdo, ?array $shopIds = null): int {
    $params = [];
    $shopClause = '';

    if (is_array($shopIds)) {
        if (count($shopIds) === 0) {
            $shopClause = ' AND 0=1';
        } else {
            $placeholders = implode(',', array_fill(0, count($shopIds), '?'));
            $shopClause = " AND ss.shop_id IN ($placeholders)";
            $params = $shopIds;
        }
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total
        FROM shop_staffs ss
        INNER JOIN users u ON u.id = ss.user_id
        WHERE ss.status = 'active'
          AND u.status = 'active'
          {$shopClause}
    ");
    $stmt->execute($params);

    return (int) $stmt->fetchColumn();
}
