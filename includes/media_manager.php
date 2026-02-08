<?php
require_once __DIR__ . '/../config/constants.php';

function media_upload_base_dir(): string {
    return dirname(__DIR__) . '/assets/uploads';
}

function media_upload_dir(string $subdir = ''): string {
    $base = media_upload_base_dir();
    $subdir = trim($subdir, '/');
    $path = $subdir === '' ? $base : $base . '/' . $subdir;
    if (!is_dir($path)) {
        mkdir($path, 0755, true);
    }
    return $path;
}

function media_public_path(string $subdir, string $filename): string {
    $subdir = trim($subdir, '/');
    return $subdir === '' ? $filename : $subdir . '/' . $filename;
}

function delete_media_file(string $subdir, ?string $filename): bool {
    if (!$filename) {
        return false;
    }
    $safe_name = basename($filename);
    $path = media_upload_dir($subdir) . '/' . $safe_name;
    if (is_file($path)) {
        return unlink($path);
    }
    return false;
}

function save_uploaded_media(
    array $file,
    array $allowed_extensions,
    int $max_size,
    string $subdir,
    string $prefix,
    ?string $identifier = null,
    ?string $existing_filename = null
): array {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'Upload failed. Please try again.'];
    }

    $file_ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    $file_size = (int) ($file['size'] ?? 0);

    if (!in_array($file_ext, $allowed_extensions, true)) {
        return ['success' => false, 'error' => 'Unsupported file type.'];
    }

    if ($file_size > $max_size) {
        return ['success' => false, 'error' => 'File size exceeds the limit.'];
    }

    $safe_prefix = preg_replace('/[^a-z0-9_-]+/i', '_', $prefix);
    $identifier = $identifier ? $identifier . '_' : '';
    $filename = $identifier . uniqid($safe_prefix . '_', true) . '.' . $file_ext;

    $target_dir = media_upload_dir($subdir);
    $destination = $target_dir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        return ['success' => false, 'error' => 'Failed to save file.'];
    }

    if ($existing_filename && $existing_filename !== $filename) {
        delete_media_file($subdir, $existing_filename);
    }

    return ['success' => true, 'filename' => $filename, 'path' => media_public_path($subdir, $filename)];
}

function collect_media_references(PDO $pdo): array {
    $references = [
        '' => [],
        'designs' => [],
        'payments' => [],
        'job_photos' => [],
        'permits' => [],
        'logos' => [],
        'portfolio' => [],
    ];

    $design_stmt = $pdo->query("SELECT design_file FROM orders WHERE design_file IS NOT NULL AND design_file <> ''");
    foreach ($design_stmt->fetchAll(PDO::FETCH_COLUMN) as $file) {
        $references['designs'][] = basename((string) $file);
    }

    $preview_stmt = $pdo->query("SELECT preview_file FROM design_versions WHERE preview_file IS NOT NULL AND preview_file <> ''");
    foreach ($preview_stmt->fetchAll(PDO::FETCH_COLUMN) as $file) {
        $references['designs'][] = basename((string) $file);
    }

    $payment_stmt = $pdo->query("SELECT proof_file FROM payments WHERE proof_file IS NOT NULL AND proof_file <> ''");
    foreach ($payment_stmt->fetchAll(PDO::FETCH_COLUMN) as $file) {
        $references['payments'][] = basename((string) $file);
    }

    $photo_stmt = $pdo->query("SELECT photo_url FROM order_photos WHERE photo_url IS NOT NULL AND photo_url <> ''");
    foreach ($photo_stmt->fetchAll(PDO::FETCH_COLUMN) as $file) {
        $file = (string) $file;
        $parts = explode('/', $file, 2);
        if (count($parts) === 2) {
            $references[$parts[0]][] = basename($parts[1]);
        } else {
            $references['job_photos'][] = basename($file);
        }
    }

    $shop_stmt = $pdo->query("SELECT permit_file, logo FROM shops WHERE permit_file IS NOT NULL OR logo IS NOT NULL");
    foreach ($shop_stmt->fetchAll(PDO::FETCH_ASSOC) as $shop) {
        if (!empty($shop['permit_file'])) {
            $references['permits'][] = basename((string) $shop['permit_file']);
        }
        if (!empty($shop['logo'])) {
            $references['logos'][] = basename((string) $shop['logo']);
        }
    }

    $portfolio_stmt = $pdo->query("SELECT image_path FROM shop_portfolio WHERE image_path IS NOT NULL AND image_path <> ''");
    foreach ($portfolio_stmt->fetchAll(PDO::FETCH_COLUMN) as $file) {
        $file = (string) $file;
        $parts = explode('/', $file, 2);
        if (count($parts) === 2) {
            $references[$parts[0]][] = basename($parts[1]);
        } else {
            $references['portfolio'][] = basename($file);
        }
    }

    return $references;
}

function cleanup_media(PDO $pdo, int $retention_days = MEDIA_RETENTION_DAYS): void {
    if ($retention_days <= 0) {
        return;
    }

    $references = collect_media_references($pdo);
    $cutoff = time() - ($retention_days * 86400);

    foreach ($references as $subdir => $ref_files) {
        $dir = media_upload_dir($subdir);
        if (!is_dir($dir)) {
            continue;
        }
        $ref_lookup = array_fill_keys($ref_files, true);
        $entries = scandir($dir);
        if ($entries === false) {
            continue;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (!is_file($path)) {
                continue;
            }
            if (isset($ref_lookup[$entry])) {
                continue;
            }
            $mtime = filemtime($path);
            if ($mtime !== false && $mtime < $cutoff) {
                unlink($path);
            }
        }
    }
}
