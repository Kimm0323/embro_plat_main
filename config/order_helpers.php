<?php
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/order_workflow.php';

function order_status_transitions(): array {
    return order_workflow_status_transitions();
}

function can_transition_order_status(string $current, string $next): bool {
    return order_workflow_can_transition_order_status($current, $next);
}

function is_terminal_order_status(string $status): bool {
    return in_array($status, [STATUS_COMPLETED, STATUS_CANCELLED], true);
}

function record_order_status_history(
    PDO $pdo,
    int $order_id,
    string $status,
    int $progress = 0,
    ?string $notes = null,
    ?int $staff_id = null
): void {
    $stmt = $pdo->prepare("
        INSERT INTO order_status_history (order_id, staff_id, status, progress, notes)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$order_id, $staff_id, $status, $progress, $notes]);
}
?>