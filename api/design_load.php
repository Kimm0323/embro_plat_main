<?php
session_start();
require_once '../config/db.php';
require_once '../config/constants.php';

header('Content-Type: application/json');

if (!check_role('client')) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

$project_id = (int) ($_GET['project_id'] ?? 0);
$version_id = (int) ($_GET['version_id'] ?? 0);

if ($project_id <= 0) {
    http_response_code(422);
    echo json_encode(['error' => 'Project ID is required.']);
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

$versions_stmt = $pdo->prepare("
    SELECT id, version_no, preview_file, created_at
    FROM design_versions
    WHERE project_id = ?
    ORDER BY version_no DESC
");
$versions_stmt->execute([$project_id]);
$versions = $versions_stmt->fetchAll();

$selected_version = null;
if ($version_id > 0) {
    $selected_stmt = $pdo->prepare("
        SELECT id, version_no, design_json, preview_file, created_at
        FROM design_versions
        WHERE id = ? AND project_id = ?
    ");
    $selected_stmt->execute([$version_id, $project_id]);
    $selected_version = $selected_stmt->fetch();
} else {
    $selected_stmt = $pdo->prepare("
        SELECT id, version_no, design_json, preview_file, created_at
        FROM design_versions
        WHERE project_id = ?
        ORDER BY version_no DESC
        LIMIT 1
    ");
    $selected_stmt->execute([$project_id]);
    $selected_version = $selected_stmt->fetch();
}

$map_preview = function ($filename) {
    return $filename ? '../assets/uploads/designs/' . $filename : null;
};

if ($selected_version) {
    $selected_version['preview_url'] = $map_preview($selected_version['preview_file'] ?? null);
}

$versions = array_map(function ($version) use ($map_preview) {
    $version['preview_url'] = $map_preview($version['preview_file'] ?? null);
    return $version;
}, $versions);

echo json_encode([
    'project_id' => $project_id,
    'versions' => $versions,
    'selected_version' => $selected_version
]);
