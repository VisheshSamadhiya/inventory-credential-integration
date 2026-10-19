<?php
require_once __DIR__ . '/../app/auth.php';

$scenario = $argv[1] ?? 'invalid';
http_response_code(200);
if ($scenario !== 'expired') $_SESSION['csrf'] = 'expected-token';
if ($scenario === 'valid') $_POST['csrf'] = 'expected-token';
elseif ($scenario === 'expired') $_POST['csrf'] = 'token-from-expired-session';
elseif ($scenario !== 'missing') $_POST['csrf'] = 'invalid-token';
register_shutdown_function(static function (): void {
    echo '|http=' . http_response_code();
});
verifyCsrf();
echo 'CSRF token accepted';
