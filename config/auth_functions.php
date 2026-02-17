<?php
// config/auth_functions.php

/**
 * Check if user has required role
 * Check if user is logged in.
 */
function is_logged_in() {
    return isset($_SESSION['user']);
}

function is_active_user(): bool {
    if (!isset($_SESSION['user'])) {
        return false;
    }

    if (is_session_expired()) {
        end_user_session();
        return false;
    }

    touch_session_activity();

    return ($_SESSION['user']['status'] ?? null) === 'active';
}

/**
 *
 * @param string $required_role Role required to access the page
 */
function check_role($required_role) {
    if (!isset($_SESSION['user'])) {
        return false;
    }

    if (!is_active_user()) {
        return false;
    }

    if (is_array($required_role)) {
        return in_array($_SESSION['user']['role'], $required_role, true);
    }

    return $_SESSION['user']['role'] === $required_role;
}

/**
 * Redirect if not logged in or wrong role.
 *
 * @param string $required_role Role required to access the page
 */
function require_role($roles)
{
    if (!isset($_SESSION['user']['role'])) {
        header("Location: /auth/login.php");
        exit;
    }

    $userRole = $_SESSION['user']['role'];

    // Normalize to array
    if (!is_array($roles)) {
        $roles = [$roles];
    }

    if (!in_array($userRole, $roles, true)) {
        header("Location: /auth/login.php");
        exit;
    }
}

/**
 * Redirect to the appropriate dashboard based on the user's role.
 *
 * @param string $role
 * @param string $base_path
 */
function redirect_based_on_role($role, $base_path = '..') {
    switch ($role) {
        case 'sys_admin':
            header("Location: {$base_path}/sys_admin/dashboard.php");
            break;
        case 'owner':
            header("Location: {$base_path}/owner/dashboard.php");
            break;
        case 'hr':
            header("Location: {$base_path}/hr/dashboard.php");
            break;
        case 'staff':
            header("Location: {$base_path}/employee/dashboard.php");
            break;
        case 'client':
            header("Location: {$base_path}/client/dashboard.php");
            break;
        default:
            header("Location: {$base_path}/index.php");
    }
    exit();
}

function session_timeout_seconds(): int {
    if (defined('SESSION_TIMEOUT_SECONDS')) {
        return (int) SESSION_TIMEOUT_SECONDS;
    }

    return 1800;
}

function is_session_expired(): bool {
    if (!isset($_SESSION['last_activity'])) {
        return false;
    }

    return (time() - (int) $_SESSION['last_activity']) > session_timeout_seconds();
}

function touch_session_activity(): void {
    $_SESSION['last_activity'] = time();
}

function end_user_session(): void {
    session_unset();
    session_destroy();
}

function refresh_session_user_status(): bool {
    if (!isset($_SESSION['user']['id'])) {
        return false;
    }

    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) {
        return true;
    }

    $stmt = $pdo->prepare("SELECT status, role FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user']['id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        return false;
    }

    $_SESSION['user']['status'] = $user['status'];
    $_SESSION['user']['role'] = $user['role'];

    return $user['status'] === 'active';
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function csrf_token_from_request(): ?string {
    return $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
}

function verify_csrf_token(?string $token): bool {
    if (!$token || empty($_SESSION['csrf_token'])) {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $token);
}

function enforce_csrf_protection(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    if (!verify_csrf_token(csrf_token_from_request())) {
        http_response_code(403);
        echo 'Invalid CSRF token.';
        exit();
    }
}

function staff_permission_defaults(): array {
    return [
        'view_jobs' => true,
        'update_status' => true,
        'upload_photos' => true,
    ];
}

function fetch_staff_permissions(PDO $pdo, int $userId): array {
    $defaults = staff_permission_defaults();
    $stmt = $pdo->prepare("
        SELECT permissions 
        FROM shop_staffs 
        WHERE user_id = ? AND status = 'active'
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $permissionsJson = $stmt->fetchColumn();

    if (!$permissionsJson) {
        return $defaults;
    }

    $decoded = json_decode($permissionsJson, true);
    if (!is_array($decoded)) {
        return $defaults;
    }

    $permissions = [];
    foreach ($defaults as $key => $defaultValue) {
        $permissions[$key] = array_key_exists($key, $decoded) ? (bool) $decoded[$key] : $defaultValue;
    }

    return $permissions;
}

function require_staff_permission(PDO $pdo, int $userId, string $permissionKey): void {
    $permissions = fetch_staff_permissions($pdo, $userId);

    if (empty($permissions[$permissionKey])) {
        http_response_code(403);
        echo "You do not have permission to access this page.";
        exit();
    }
}
?>