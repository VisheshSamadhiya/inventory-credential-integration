<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/email_service.php';
require_once dirname(__DIR__) . '/order_notifications.php';

$db = db();
$result = processOrderEmailOutbox($db, static function (string $recipient, string $subject, string $html, string $text): void {
    sendMail($recipient, $subject, $html, $text);
});
echo sprintf("Order email worker: %d sent, %d retrying, %d failed, %d processed.\n", $result['sent'], $result['retrying'], $result['failed'], $result['processed']);
if ($result['failed'] > 0) exit(1);
