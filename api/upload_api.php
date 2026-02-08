<?php
session_start();
require_once '../config/db.php';
require_once '../config/constants.php';
require_once '../includes/media_manager.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

if (!isset($_FILES['file'])) {
    http_response_code(422);
    echo json_encode(['error' => 'No file uploaded']);
    exit();
}

$file = $_FILES['file'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    http_response_code(422);
    echo json_encode(['error' => 'Upload failed']);
    exit();
}

$upload = save_uploaded_media($file, ALLOWED_IMAGE_TYPES, MAX_FILE_SIZE, '', 'upload');
if (!$upload['success']) {
    http_response_code(422);
    echo json_encode(['error' => $upload['error']]);
    exit();
}

cleanup_media($pdo);

echo json_encode([
    'message' => 'Upload successful',
    'file' => [
        'name' => $upload['filename'],
        'path' => 'assets/uploads/' . $upload['path']
    ]
]);
