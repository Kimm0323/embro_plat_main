<?php
session_start();
require_once '../config/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

$scope = sanitize($_GET['scope'] ?? 'all');
$methods = $scope === 'payment_submission'
    ? payment_methods_for_submission()
    : available_payment_methods();

echo json_encode([
    'data' => $methods,
]);
