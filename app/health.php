<?php
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    db()->query('SELECT 1')->fetchColumn();
    echo '{"status":"ok"}';
} catch (Throwable $exception) {
    error_log('Health check database probe failed.');
    http_response_code(503);
    echo '{"status":"unavailable"}';
}
