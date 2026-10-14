<?php
declare(strict_types=1);
require_once __DIR__ . '/currency.php';
require_once __DIR__ . '/currency.php';

function envv(string $key, string $default = ''): string
{
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}

$appEnvironment = envv('APP_ENV', 'local');
$sessionIdleTimeout = max(300, (int)envv('SESSION_IDLE_TIMEOUT', '1800'));
$sessionSecureCookie = $appEnvironment === 'production';
date_default_timezone_set('UTC');

ini_set('display_errors', $appEnvironment === 'production' ? '0' : '1');
ini_set('display_startup_errors', $appEnvironment === 'production' ? '0' : '1');
ini_set('log_errors', '1');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', $sessionSecureCookie ? '1' : '0');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.gc_maxlifetime', (string)$sessionIdleTimeout);

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
}

set_exception_handler(static function (Throwable $exception) use ($appEnvironment): void {
    error_log((string)$exception);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    if ($appEnvironment !== 'production') {
        echo '<pre>' . htmlspecialchars((string)$exception, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>';
        return;
    }
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>Request failed</title><p>An unexpected error occurred. Please try again later.</p></html>';
});

session_save_path('/tmp');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'httponly' => true,
    'secure' => $sessionSecureCookie,
    'samesite' => 'Lax',
]);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (isset($_SESSION['user']) && isset($_SESSION['last_activity'])
    && time() - (int)$_SESSION['last_activity'] > $sessionIdleTimeout) {
    $_SESSION = [];
    session_regenerate_id(true);
}
$_SESSION['last_activity'] = time();

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = envv('DB_HOST', 'db');
    $port = envv('DB_PORT', '3306');
    $name = envv('DB_NAME', 'inventory_manager');
    $user = envv('DB_USER', 'inventory_app');
    $pass = envv('DB_PASSWORD', '');

    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
        ]
    );
    return $pdo;
}
