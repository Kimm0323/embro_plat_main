<?php
// save_design.php
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
  exit;
}

$imageData = $_POST['imageData'] ?? '';
$garmentColor = $_POST['garmentColor'] ?? '#ffffff';

if (!$imageData) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Missing imageData']);
  exit;
}

// Basic hex color validation
if (!preg_match('/^#[0-9a-fA-F]{6}$/', $garmentColor)) {
  $garmentColor = '#ffffff';
}

// Must be a PNG dataURL
if (!preg_match('/^data:image\/png;base64,/', $imageData)) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Invalid image data format (expected data:image/png;base64,...)']);
  exit;
}

// Strip prefix
$base64 = preg_replace('/^data:image\/png;base64,/', '', $imageData);
$base64 = str_replace(' ', '+', $base64); // fix for some encoders

$binary = base64_decode($base64);
if ($binary === false) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Base64 decode failed']);
  exit;
}

// Ensure uploads directory exists
$uploadDir = __DIR__ . '/uploads/';
if (!is_dir($uploadDir)) {
  if (!mkdir($uploadDir, 0775, true)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not create uploads directory']);
    exit;
  }
}

// Generate filename
$timestamp = date('Ymd_His');
$rand = bin2hex(random_bytes(4));
$filename = "design_{$timestamp}_{$rand}.png";
$filePath = $uploadDir . $filename;

if (file_put_contents($filePath, $binary) === false) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Failed to write file']);
  exit;
}

// Save metadata (optional)
$meta = [
  'garmentColor' => $garmentColor,
  'savedAt' => date('c'),
  'filename' => $filename,
];
file_put_contents($uploadDir . $filename . '.json', json_encode($meta, JSON_PRETTY_PRINT));

echo json_encode([
  'ok' => true,
  'filename' => $filename,
  'path' => 'uploads/' . $filename,
  'garmentColor' => $garmentColor
]);
