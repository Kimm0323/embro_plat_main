<?php
require_once __DIR__ . '/constants.php';

function payment_status_transitions(): array {
    return [
        'unpaid' => ['pending'],
        'pending' => ['paid', 'rejected'],
        'rejected' => ['pending'],
        'paid' => ['refund_pending', 'refunded'],
        'refund_pending' => ['refunded', 'paid'],
        'refunded' => [],
    ];
}

function can_transition_payment_status(string $current, string $next): bool {
    if ($current === $next) {
        return true;
    }

    $transitions = payment_status_transitions();
    return in_array($next, $transitions[$current] ?? [], true);
}

function generate_invoice_number(string $order_number): string {
    return 'INV-' . $order_number;
}

function generate_receipt_number(int $payment_id, string $issued_at): string {
    $date = date('Ymd', strtotime($issued_at));
    return 'RCPT-' . $date . '-' . str_pad((string) $payment_id, 5, '0', STR_PAD_LEFT);
}

function determine_invoice_status(string $order_status, string $payment_status): string {
    if ($order_status === STATUS_CANCELLED) {
        return $payment_status === 'refunded' ? 'refunded' : 'cancelled';
    }

    if ($payment_status === 'refunded') {
        return 'refunded';
    }

    if ($payment_status === 'paid') {
        return 'paid';
    }

    return 'open';
}

function ensure_order_invoice(PDO $pdo, int $order_id, string $order_number, float $amount, string $status): array {
    $stmt = $pdo->prepare("SELECT * FROM order_invoices WHERE order_id = ?");
    $stmt->execute([$order_id]);
    $invoice = $stmt->fetch();

    if (!$invoice) {
        $invoice_number = generate_invoice_number($order_number);
        $issued_at = date('Y-m-d H:i:s');
        $insert_stmt = $pdo->prepare("
            INSERT INTO order_invoices (order_id, invoice_number, amount, status, issued_at)
            VALUES (?, ?, ?, ?, ?)
        ");
        $insert_stmt->execute([$order_id, $invoice_number, $amount, $status, $issued_at]);

        $stmt = $pdo->prepare("SELECT * FROM order_invoices WHERE order_id = ?");
        $stmt->execute([$order_id]);
        return $stmt->fetch();
    }

    if ($status !== $invoice['status']) {
        $update_stmt = $pdo->prepare("UPDATE order_invoices SET status = ?, updated_at = NOW() WHERE order_id = ?");
        $update_stmt->execute([$status, $order_id]);
        $invoice['status'] = $status;
    }

    return $invoice;
}

function ensure_payment_receipt(PDO $pdo, int $payment_id, int $issued_by, string $issued_at): array {
    $stmt = $pdo->prepare("SELECT * FROM payment_receipts WHERE payment_id = ?");
    $stmt->execute([$payment_id]);
    $receipt = $stmt->fetch();

    if (!$receipt) {
        $receipt_number = generate_receipt_number($payment_id, $issued_at);
        $insert_stmt = $pdo->prepare("
            INSERT INTO payment_receipts (payment_id, receipt_number, issued_by, issued_at)
            VALUES (?, ?, ?, ?)
        ");
        $insert_stmt->execute([$payment_id, $receipt_number, $issued_by, $issued_at]);

        $stmt = $pdo->prepare("SELECT * FROM payment_receipts WHERE payment_id = ?");
        $stmt->execute([$payment_id]);
        return $stmt->fetch();
    }

    return $receipt;
}

function payment_hold_status(string $order_status, string $payment_status): array {
    $normalized_order_status = strtolower($order_status);
    $normalized_payment_status = strtolower($payment_status);

    if ($normalized_payment_status === 'unpaid') {
        return ['label' => 'Not funded', 'class' => 'hold-unfunded'];
    }

    if (in_array($normalized_payment_status, ['rejected', 'refunded'], true)) {
        return ['label' => 'Hold released', 'class' => 'hold-released'];
    }

    if ($normalized_order_status === STATUS_COMPLETED && $normalized_payment_status === 'paid') {
        return ['label' => 'Release ready', 'class' => 'hold-ready'];
    }

    if ($normalized_order_status === STATUS_CANCELLED) {
        return ['label' => 'Hold cleared', 'class' => 'hold-cleared'];
    }

    if (in_array($normalized_payment_status, ['pending', 'paid', 'refund_pending'], true)) {
        return ['label' => 'On hold', 'class' => 'hold-active'];
    }

    return ['label' => 'Pending review', 'class' => 'hold-pending'];
}
?>
