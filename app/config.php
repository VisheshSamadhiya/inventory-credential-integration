<?php
declare(strict_types=1);
require_once __DIR__ . '/currency.php';

// Optional key=value file stored outside the document root. Explicit process
// environment variables take precedence, so hosting-panel settings still win.
$environmentFile = getenv('APP_ENV_FILE');
if (is_string($environmentFile) && $environmentFile !== '' && is_readable($environmentFile)) {
    foreach (file($environmentFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if (preg_match('/^[A-Z][A-Z0-9_]*$/', $key) !== 1 || getenv($key) !== false) continue;
        if (strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
            $value = substr($value, 1, -1);
        }
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
    }
}

function envv(string $key, string $default = ''): string
{
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}

$appEnvironment = envv('APP_ENV', 'local');
$sessionIdleTimeout = max(300, (int)envv('SESSION_IDLE_TIMEOUT', '1800'));
$sessionSecureCookie = $appEnvironment === 'production';
$forceHttps = filter_var(envv('APP_FORCE_HTTPS', $appEnvironment === 'production' ? 'true' : 'false'), FILTER_VALIDATE_BOOLEAN);
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

// Use PHP's configured session store unless the operator supplies a private,
// writable directory. Never force sessions into the shared world-writable /tmp.
$sessionPath = envv('SESSION_SAVE_PATH');
if ($sessionPath !== '') {
    if (!is_dir($sessionPath) || !is_writable($sessionPath)) {
        error_log('Configured session path is missing or not writable.');
        if (PHP_SAPI !== 'cli') {
            http_response_code(500);
            exit('Application configuration error.');
        }
    } else {
        session_save_path($sessionPath);
    }
}

if (PHP_SAPI !== 'cli' && $forceHttps) {
    $isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    if (!$isHttps) {
        $configuredUrl = parse_url(envv('APP_URL'));
        $host = is_array($configuredUrl) ? ($configuredUrl['host'] ?? '') : '';
        if ($host === '') $host = (string)($_SERVER['SERVER_NAME'] ?? '');
        if ($host === '' || preg_match('/^[a-z0-9.-]+$/i', $host) !== 1) {
            http_response_code(500);
            exit('Application URL is not configured for HTTPS.');
        }
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
        if (!str_starts_with($uri, '/') || str_starts_with($uri, '//')) $uri = '/';
        header('Location: https://' . $host . $uri, true, 301);
        exit;
    }
    header('Strict-Transport-Security: max-age=31536000');
}

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
}

set_exception_handler(static function (Throwable $exception) use ($appEnvironment): void {
    error_log((string)$exception);
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'Command failed: ' . get_class($exception) . ' at ' . basename($exception->getFile()) . ':' . $exception->getLine() . PHP_EOL);
        exit(1);
    }
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
    $_SESSION = ['session_expired' => true];
    session_regenerate_id(true);
}
$_SESSION['last_activity'] = time();

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = envv('DB_HOST', '127.0.0.1');
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
