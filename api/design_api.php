<?php
session_start();
require_once '../config/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$user = $_SESSION['user'];

function fetch_order_for_user(PDO $pdo, int $orderId, array $user): ?array {
    $role = $user['role'] ?? '';

    if ($role === 'sys_admin') {
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
        $stmt->execute([$orderId]);
        return $stmt->fetch() ?: null;
    }

    if ($role === 'client') {
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ? AND client_id = ?');
        $stmt->execute([$orderId, $user['id']]);
        return $stmt->fetch() ?: null;
    }

    if ($role === 'owner') {
        $stmt = $pdo->prepare('
            SELECT o.*
            FROM orders o
            JOIN shops s ON s.id = o.shop_id
            WHERE o.id = ? AND s.owner_id = ?
        ');
        $stmt->execute([$orderId, $user['id']]);
        return $stmt->fetch() ?: null;
    }

    if ($role === 'employee') {
        $stmt = $pdo->prepare('
            SELECT o.*
            FROM orders o
            JOIN shop_employees se ON se.shop_id = o.shop_id
            WHERE o.id = ? AND se.user_id = ? AND se.status = "active"
        ');
        $stmt->execute([$orderId, $user['id']]);
        return $stmt->fetch() ?: null;
    }

    return null;
}

function normalize_design_payload($designData): string {
    if (is_string($designData)) {
        return $designData;
    }

    return json_encode($designData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $payload = json_decode(file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            http_response_code(422);
            echo json_encode(['error' => 'Invalid JSON payload']);
            exit();
        }

        $orderId = isset($payload['order_id']) ? (int) $payload['order_id'] : 0;
        $designData = $payload['design_data'] ?? null;
        $versionLabel = isset($payload['version_label']) ? sanitize($payload['version_label']) : null;
        $previewImage = isset($payload['preview_image']) ? sanitize($payload['preview_image']) : null;

        if ($orderId <= 0 || $designData === null) {
            http_response_code(422);
            echo json_encode(['error' => 'order_id and design_data are required']);
            exit();
        }

        $order = fetch_order_for_user($pdo, $orderId, $user);
        if (!$order) {
            http_response_code(404);
            echo json_encode(['error' => 'Order not found']);
            exit();
        }

        $designPayload = normalize_design_payload($designData);

        $pdo->beginTransaction();
        $versionStmt = $pdo->prepare('SELECT MAX(version_number) FROM design_versions WHERE order_id = ?');
        $versionStmt->execute([$orderId]);
        $currentVersion = (int) $versionStmt->fetchColumn();
        $nextVersion = $currentVersion + 1;

        $insertStmt = $pdo->prepare('
            INSERT INTO design_versions (order_id, version_number, version_label, design_data, preview_image, created_by, created_by_role)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        $insertStmt->execute([
            $orderId,
            $nextVersion,
            $versionLabel,
            $designPayload,
            $previewImage,
            $user['id'],
            $user['role'] ?? null
        ]);
        $versionId = (int) $pdo->lastInsertId();
        $pdo->commit();

        echo json_encode([
            'message' => 'Design saved',
            'data' => [
                'id' => $versionId,
                'order_id' => $orderId,
                'version_number' => $nextVersion,
                'version_label' => $versionLabel,
                'preview_image' => $previewImage
            ]
        ]);
        exit();
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $orderId = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;
        if ($orderId <= 0) {
            http_response_code(422);
            echo json_encode(['error' => 'order_id is required']);
            exit();
        }

        $order = fetch_order_for_user($pdo, $orderId, $user);
        if (!$order) {
            http_response_code(404);
            echo json_encode(['error' => 'Order not found']);
            exit();
        }

        if (isset($_GET['versions'])) {
            $listStmt = $pdo->prepare('
                SELECT dv.id, dv.version_number, dv.version_label, dv.preview_image, dv.created_at,
                       u.fullname AS created_by
                FROM design_versions dv
                LEFT JOIN users u ON u.id = dv.created_by
                WHERE dv.order_id = ?
                ORDER BY dv.version_number DESC
            ');
            $listStmt->execute([$orderId]);
            $versions = $listStmt->fetchAll();
            echo json_encode(['data' => $versions]);
            exit();
        }

        $versionId = isset($_GET['version_id']) ? (int) $_GET['version_id'] : 0;
        $versionNumber = isset($_GET['version']) ? (int) $_GET['version'] : 0;

        if ($versionId > 0) {
            $stmt = $pdo->prepare('
                SELECT dv.*, u.fullname AS created_by
                FROM design_versions dv
                LEFT JOIN users u ON u.id = dv.created_by
                WHERE dv.order_id = ? AND dv.id = ?
            ');
            $stmt->execute([$orderId, $versionId]);
        } elseif ($versionNumber > 0) {
            $stmt = $pdo->prepare('
                SELECT dv.*, u.fullname AS created_by
                FROM design_versions dv
                LEFT JOIN users u ON u.id = dv.created_by
                WHERE dv.order_id = ? AND dv.version_number = ?
            ');
            $stmt->execute([$orderId, $versionNumber]);
        } else {
            $stmt = $pdo->prepare('
                SELECT dv.*, u.fullname AS created_by
                FROM design_versions dv
                LEFT JOIN users u ON u.id = dv.created_by
                WHERE dv.order_id = ?
                ORDER BY dv.version_number DESC
                LIMIT 1
            ');
            $stmt->execute([$orderId]);
        }

        $version = $stmt->fetch();
        if (!$version) {
            http_response_code(404);
            echo json_encode(['error' => 'Design version not found']);
            exit();
        }

        echo json_encode(['data' => $version]);
        exit();
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => 'Failed to process design request']);
}
?>
