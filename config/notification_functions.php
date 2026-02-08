<?php
// config/notification_functions.php

/**
 * Create a notification log entry.
 *
 * @param PDO $pdo
 * @param int $user_id
 * @param int|null $order_id

 * @param string $type
 * @param string $message
 * @return void
 */
function create_notification(PDO $pdo, int $user_id, ?int $order_id, string $type, string $message): void {
    $preference_stmt = $pdo->prepare("
        SELECT enabled
        FROM notification_preferences
        WHERE user_id = ? AND event_key = ? AND channel = 'in_app'
        LIMIT 1
    ");
    $preference_stmt->execute([$user_id, $type]);
    $preference_enabled = $preference_stmt->fetchColumn();

    if ($preference_enabled !== false && (int) $preference_enabled !== 1) {
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO notifications (user_id, order_id, type, message, read_at, created_at)
        VALUES (?, ?, ?, ?, NULL, NOW())
    ");

    $stmt->execute([$user_id, $order_id, $type, $message]);
}
?>



