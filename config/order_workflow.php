<?php
require_once __DIR__ . '/constants.php';

function order_workflow_status_transitions(): array {
    return [
        STATUS_PENDING => [STATUS_ACCEPTED, STATUS_CANCELLED],
        STATUS_ACCEPTED => [STATUS_IN_PROGRESS, STATUS_CANCELLED],
        STATUS_IN_PROGRESS => [STATUS_COMPLETED, STATUS_CANCELLED],
        STATUS_COMPLETED => [],
        STATUS_CANCELLED => [],
    ];
}

function order_workflow_fulfillment_transitions(): array {
    return [
        FULFILLMENT_PENDING => [FULFILLMENT_READY_FOR_PICKUP, FULFILLMENT_OUT_FOR_DELIVERY, FULFILLMENT_FAILED],
        FULFILLMENT_READY_FOR_PICKUP => [FULFILLMENT_CLAIMED, FULFILLMENT_FAILED],
        FULFILLMENT_OUT_FOR_DELIVERY => [FULFILLMENT_DELIVERED, FULFILLMENT_FAILED],
        FULFILLMENT_DELIVERED => [FULFILLMENT_CLAIMED],
        FULFILLMENT_CLAIMED => [],
        FULFILLMENT_FAILED => [],
    ];
}

function order_workflow_can_transition(array $transitions, string $current, string $next): bool {
    if ($current === $next) {
        return true;
    }

    return in_array($next, $transitions[$current] ?? [], true);
}

function order_workflow_can_transition_order_status(string $current, string $next): bool {
    return order_workflow_can_transition(order_workflow_status_transitions(), $current, $next);
}

function order_workflow_is_design_approved(PDO $pdo, int $order_id): bool {
    $approval_stmt = $pdo->prepare("
        SELECT o.design_approved, da.status
        FROM orders o
        LEFT JOIN design_approvals da ON da.order_id = o.id
        WHERE o.id = ?
        LIMIT 1
    ");
    $approval_stmt->execute([$order_id]);
    $approval = $approval_stmt->fetch();

    if(!$approval) {
        return false;
    }

    return (int) $approval['design_approved'] === 1 || ($approval['status'] ?? '') === 'approved';
}

function order_workflow_validate_order_status(PDO $pdo, array $order, string $next_status): array {
    $current_status = $order['status'] ?? '';
    if($current_status === '') {
        return [false, 'Current status is missing.'];
    }

    if(!order_workflow_can_transition_order_status($current_status, $next_status)) {
        return [false, 'Status transition not allowed from the current state.'];
    }

    if($next_status === STATUS_IN_PROGRESS && !order_workflow_is_design_approved($pdo, (int) $order['id'])) {
        return [false, 'Design proof approval is required before production can begin.'];
    }

    return [true, null];
}

function order_workflow_has_qc_pass(PDO $pdo, int $order_id): bool {
    $stmt = $pdo->prepare("
        SELECT id
        FROM finished_goods
        WHERE order_id = ?
        LIMIT 1
    ");
    $stmt->execute([$order_id]);
    return (bool) $stmt->fetchColumn();
}

function order_workflow_validate_fulfillment_status(PDO $pdo, int $order_id, string $current_status, string $next_status): array {
    if(!order_workflow_can_transition(order_workflow_fulfillment_transitions(), $current_status, $next_status)) {
        return [false, 'Status transition is not allowed from the current state.'];
    }

    $requires_qc = in_array(
        $next_status,
        [FULFILLMENT_READY_FOR_PICKUP, FULFILLMENT_OUT_FOR_DELIVERY, FULFILLMENT_DELIVERED, FULFILLMENT_CLAIMED],
        true
    );
    if($requires_qc && !order_workflow_has_qc_pass($pdo, $order_id)) {
        return [false, 'QC approval is required before delivery or pickup can begin.'];
    }

    return [true, null];
}

function order_workflow_is_delivery_confirmed(PDO $pdo, int $order_id): bool {
    $stmt = $pdo->prepare("
        SELECT status
        FROM order_fulfillments
        WHERE order_id = ?
        LIMIT 1
    ");
    $stmt->execute([$order_id]);
    $status = $stmt->fetchColumn();

    return in_array($status, [FULFILLMENT_DELIVERED, FULFILLMENT_CLAIMED], true);
}

function order_workflow_validate_payment_release(PDO $pdo, int $order_id): array {
    if(!order_workflow_is_delivery_confirmed($pdo, $order_id)) {
        return [false, 'Delivery confirmation is required before payment release.'];
    }

    return [true, null];
}
?>
