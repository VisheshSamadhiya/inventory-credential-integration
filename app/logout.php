<?php
require_once __DIR__ . '/auth.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed');
}
verifyCsrf();
try {
    if (currentUser()) audit('logout');
} catch (Throwable $exception) {
    error_log('Logout audit failed: ' . $exception->getMessage());
} finally {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $cookie = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $cookie['path'] ?: '/',
            'domain' => $cookie['domain'],
            'secure' => $cookie['secure'],
            'httponly' => $cookie['httponly'],
            'samesite' => $cookie['samesite'] ?: 'Lax',
        ]);
    }
    session_destroy();
}
header('Location: login.php');
exit;
