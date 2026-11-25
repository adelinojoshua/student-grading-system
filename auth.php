<?php
// Use one session for login, logout, and the original CRUD form security token.
if (session_status() !== PHP_SESSION_ACTIVE) {
    $usingHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'cookie_secure' => $usingHttps,
        'use_strict_mode' => true,
    ]);
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
}

function require_admin_login(): void {
    if (empty($_SESSION['admin_id'])) {
        header('Location: login.php');
        exit;
    }
    header('Cache-Control: no-store, no-cache, must-revalidate, private');
}

function is_local_request(): bool {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return in_array($ip, ['127.0.0.1', '::1'], true);
}
