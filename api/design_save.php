<?php
session_start();
require_once '../config/db.php';
require_once '../config/constants.php';
require_once '../includes/media_manager.php';

header('Content-Type: application/json');

if (!check_role('client')) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

$project_id = (int) ($_POST['project_id'] ?? 0);
$design_json = trim($_POST['design_json'] ?? '');

if ($project_id <= 0 || $design_json === '') {
    http_response_code(422);
    echo json_encode(['error' => 'Project ID and design JSON are required.']);
    exit();
}

$project_stmt = $pdo->prepare("SELECT id FROM design_projects WHERE id = ? AND client_id = ?");
$project_stmt->execute([$project_id, $_SESSION['user']['id']]);
$project = $project_stmt->fetch();

if (!$project) {
    http_response_code(404);
    echo json_encode(['error' => 'Project not found.']);
    exit();
}

$preview_file = null;
if (isset($_FILES['png_preview']) && $_FILES['png_preview']['error'] !== UPLOAD_ERR_NO_FILE) {
    $file = $_FILES['png_preview'];
    $upload = save_uploaded_media(
        $file,
        ['png'],
        MAX_FILE_SIZE,
        'designs',
        'design_preview',
        (string) $project_id
    );
    if (!$upload['success']) {
        http_response_code(422);
        echo json_encode(['error' => $upload['error'] === 'File size exceeds the limit.'
            ? 'Preview image exceeds the size limit.'
            : $upload['error']]);
        exit();
    }
    $preview_file = $upload['filename'];
}

try {
    $pdo->beginTransaction();

    $version_stmt = $pdo->prepare("SELECT COALESCE(MAX(version_no), 0) AS max_version FROM design_versions WHERE project_id = ? FOR UPDATE");
    $version_stmt->execute([$project_id]);
    $next_version = ((int) ($version_stmt->fetchColumn() ?? 0)) + 1;

    $insert_stmt = $pdo->prepare("
        INSERT INTO design_versions (project_id, version_no, design_json, preview_file, created_by)
        VALUES (?, ?, ?, ?, ?)
    ");
    $insert_stmt->execute([
        $project_id,
        $next_version,
        $design_json,
        $preview_file,
        $_SESSION['user']['id']
    ]);

    $version_id = (int) $pdo->lastInsertId();
    $pdo->commit();
} catch (PDOException $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save design version.']);
    exit();
}

cleanup_media($pdo);

echo json_encode([
    'message' => 'Design version saved.',
    'version' => [
        'id' => $version_id,
        'version_no' => $next_version,
        'preview_file' => $preview_file,
        'preview_url' => $preview_file ? '../assets/uploads/designs/' . $preview_file : null
    ]
]);
