<?php
session_start();
require_once '../config/db.php';
require_once '../config/constants.php';

header('Content-Type: application/json');

if (!is_active_user()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$user = $_SESSION['user'];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $payload = $_POST;
        if (empty($payload)) {
            $payload = json_decode(file_get_contents('php://input'), true) ?? [];
        }

        $order_id = (int) ($payload['order_id'] ?? 0);
        $status = sanitize($payload['status'] ?? '');
        $progress = (int) ($payload['progress'] ?? 0);
        $notes = sanitize($payload['notes'] ?? '');

        if ($order_id <= 0 || $status === '') {
            http_response_code(422);
            echo json_encode(['error' => 'order_id and status are required']);
            exit();
        }

        $order = null;
        $staff_id = null;
        if ($user['role'] === 'owner') {
            $shop_stmt = $pdo->prepare("SELECT id FROM shops WHERE owner_id = ?");
            $shop_stmt->execute([$user['id']]);
            $shop = $shop_stmt->fetch();
            if ($shop) {
                $order_stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND shop_id = ? LIMIT 1");
                $order_stmt->execute([$order_id, $shop['id']]);
                $order = $order_stmt->fetch();
            }
        } elseif ($user['role'] === 'staff') {
            $permissions = fetch_staff_permissions($pdo, (int) $user['id']);
            if (empty($permissions['update_status'])) {
                http_response_code(403);
                echo json_encode(['error' => 'Permission denied']);
                exit();
            }
            $staff_id = (int) $user['id'];
            $order_stmt = $pdo->prepare("
                SELECT o.*
                FROM orders o
                LEFT JOIN job_schedule js ON js.order_id = o.id AND js.staff_id = ?
                WHERE o.id = ? AND (o.assigned_to = ? OR js.staff_id = ?)
                LIMIT 1
            ");
            $order_stmt->execute([$staff_id, $order_id, $staff_id, $staff_id]);
            $order = $order_stmt->fetch();
        } else {
            http_response_code(403);
            echo json_encode(['error' => 'Role not permitted']);
            exit();
        }

        if (!$order) {
            http_response_code(404);
            echo json_encode(['error' => 'Order not found']);
            exit();
        }

        $order_state = $order;
        $order_state['id'] = $order_id;
        [$can_transition, $transition_error] = order_workflow_validate_order_status($pdo, $order_state, $status);
        if(!$can_transition) {
            http_response_code(422);
            echo json_encode(['error' => $transition_error ?: 'Status transition not allowed']);
            exit();
        }

        $update_stmt = $pdo->prepare("
            UPDATE orders
            SET status = ?, progress = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $update_stmt->execute([$status, $progress, $order_id]);

        record_order_status_history($pdo, $order_id, $status, $progress, $notes !== '' ? $notes : null, $staff_id);

        echo json_encode(['message' => 'Order status updated']);
        exit();
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit();
    }

    $orders = [];

    if ($user['role'] === 'owner') {
        $shop_stmt = $pdo->prepare("SELECT id FROM shops WHERE owner_id = ?");
        $shop_stmt->execute([$user['id']]);
        $shop = $shop_stmt->fetch();

        if ($shop) {
            $orders_stmt = $pdo->prepare("
                SELECT o.*, u.fullname as client_name
                FROM orders o
                JOIN users u ON o.client_id = u.id
                WHERE o.shop_id = ?
                ORDER BY o.created_at DESC
            ");
            $orders_stmt->execute([$shop['id']]);
            $orders = $orders_stmt->fetchAll();
        }
    } elseif ($user['role'] === 'client') {
        $orders_stmt = $pdo->prepare("
            SELECT o.*, s.shop_name
            FROM orders o
            JOIN shops s ON o.shop_id = s.id
            WHERE o.client_id = ?
            ORDER BY o.created_at DESC
        ");
        $orders_stmt->execute([$user['id']]);
        $orders = $orders_stmt->fetchAll();
    } elseif ($user['role'] === 'staff') {
        $permissions = fetch_staff_permissions($pdo, (int) $user['id']);
        if (empty($permissions['view_jobs'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Permission denied']);
            exit();
        }
        $orders_stmt = $pdo->prepare("
            SELECT o.*, u.fullname as client_name, s.shop_name
            FROM orders o
            JOIN users u ON o.client_id = u.id
            JOIN shops s ON o.shop_id = s.id
            LEFT JOIN job_schedule js ON js.order_id = o.id AND js.staff_id = ?
            WHERE (o.assigned_to = ? OR js.staff_id = ?)
            ORDER BY o.scheduled_date ASC, o.created_at DESC
        ");
        $orders_stmt->execute([$user['id'], $user['id'], $user['id']]);
        $orders = $orders_stmt->fetchAll();
    } else {
        http_response_code(403);
        echo json_encode(['error' => 'Role not permitted']);
        exit();
    }

    echo json_encode(['data' => $orders]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to load orders']);
}
