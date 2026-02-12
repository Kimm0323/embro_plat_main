<?php
session_start();
require_once '../config/db.php';

if (isset($_SESSION['user'])) {
    $user = $_SESSION['user'];
    log_audit(
        $pdo,
        (int) $user['id'],
        $user['role'],
        'logout',
        'users',
        (int) $user['id'],
        [],
        []
    );
}

session_destroy();
header("Location: login.php");
exit();
?>