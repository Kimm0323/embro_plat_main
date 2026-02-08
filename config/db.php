<?php
// Database Configuration
$host = "localhost";
$dbname = "embroidery_platform";
$username = "root";
$password = "";

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

require_once __DIR__ . '/auth_functions.php';
require_once __DIR__ . '/notification_functions.php';
require_once __DIR__ . '/order_helpers.php';
require_once __DIR__ . '/payment_helpers.php';

enforce_csrf_protection();

// Sanitize input
function sanitize($input) {
    return htmlspecialchars(strip_tags(trim($input)));
}

function log_audit(PDO $pdo, ?int $actorId, ?string $actorRole, string $action, string $entityType, ?int $entityId, array $oldValues = [], array $newValues = []): void {
    $stmt = $pdo->prepare("
        INSERT INTO audit_logs (actor_id, actor_role, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $oldPayload = !empty($oldValues) ? json_encode($oldValues) : null;
    $newPayload = !empty($newValues) ? json_encode($newValues) : null;

    $stmt->execute([
        $actorId,
        $actorRole,
        $action,
        $entityType,
        $entityId,
        $oldPayload,
        $newPayload,
        $ipAddress,
        $userAgent,
    ]);
}

function fetch_sys_admin_ids(PDO $pdo): array {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'sys_admin' AND status = 'active'");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function log_suspicious_activity(PDO $pdo, ?int $actorId, ?string $actorRole, string $reason, array $context = []): void {
    $payload = array_merge(['reason' => $reason], $context);
    log_audit($pdo, $actorId, $actorRole, 'suspicious_activity', 'security', null, [], $payload);

    $message = 'Suspicious activity detected: ' . $reason;
    if (!empty($context['email'])) {
        $message .= ' (Email: ' . $context['email'] . ')';
    }
    if (!empty($context['ip_address'])) {
        $message .= ' (IP: ' . $context['ip_address'] . ')';
    }

    foreach (fetch_sys_admin_ids($pdo) as $adminId) {
        create_notification($pdo, (int) $adminId, null, 'security_alert', $message);
    }
}



function fetch_unread_notification_count(PDO $pdo, int $user_id): int {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL");
    $stmt->execute([$user_id]);
    return (int) $stmt->fetchColumn();
}

function update_shop_rating_summary(PDO $pdo, int $shop_id): void {
    $summary_stmt = $pdo->prepare("
        SELECT COUNT(*) as rating_count, AVG(rating) as avg_rating
        FROM orders
        WHERE shop_id = ?
          AND rating IS NOT NULL
          AND rating > 0
          AND rating_status = 'approved'
    ");
    $summary_stmt->execute([$shop_id]);
    $summary = $summary_stmt->fetch();

    $rating_count = (int) ($summary['rating_count'] ?? 0);
    $avg_rating = $summary['avg_rating'] !== null ? (float) $summary['avg_rating'] : 0.0;

    $update_stmt = $pdo->prepare("UPDATE shops SET rating = ?, rating_count = ?, updated_at = NOW() WHERE id = ?");
    $update_stmt->execute([$avg_rating, $rating_count, $shop_id]);
}
?>