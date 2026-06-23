<?php
// =========================================
// includes/auth.php  —  后台鉴权
// =========================================

function auth_start(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_name('picker_admin');
        session_start();
    }
}

function auth_check(): void {
    auth_start();
    if (empty($_SESSION['admin_id'])) {
        header('Location: ' . admin_url('login.php'));
        exit;
    }
}

function auth_login(int $id, string $username): void {
    auth_start();
    session_regenerate_id(true);
    $_SESSION['admin_id']       = $id;
    $_SESSION['admin_username'] = $username;
}

function auth_logout(): void {
    auth_start();
    session_destroy();
}

function admin_url(string $page = ''): string {
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    // 如果当前已在 admin/ 目录内，base 已包含 admin
    if (substr($base, -6) !== '/admin') {
        $base .= '/admin';
    }
    return $base . '/' . ltrim($page, '/');
}

function csrf_token(): string {
    auth_start();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_verify(): void {
    if (($_POST['_csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) {
        http_response_code(403);
        die('CSRF token mismatch');
    }
}
