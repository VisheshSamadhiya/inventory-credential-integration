<?php
require_once __DIR__ . '/../app/auth.php';

$_SESSION['csrf'] = 'expected-token';
if (($argv[1] ?? '') !== 'missing') $_POST['csrf'] = 'invalid-token';
register_shutdown_function(static function (): void {
    echo '|http=' . http_response_code();
});
verifyCsrf();
echo 'CSRF check unexpectedly accepted the request.';
