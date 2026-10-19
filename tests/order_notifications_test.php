<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/order_notifications.php';
require_once __DIR__ . '/../app/email_service.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$newOrder = orderEventEmail('new_order', [
    'id' => 42, 'product_name' => '<Laptop & dock>', 'quantity' => 3,
    'requester_name' => 'R <Requester>', 'reason' => 'Replacement',
    'order_date' => '2026-10-15 10:30:00',
]);
$assert(str_contains($newOrder['subject'], 'New inventory order'), 'New order subject is present.');
$assert(str_contains($newOrder['html'], 'Order date') && str_contains($newOrder['html'], 'Pending'), 'New order HTML includes date and Pending status.');
$assert(str_contains($newOrder['text'], 'Requester: R <Requester>') && str_contains($newOrder['text'], 'Quantity: 3'), 'New order text includes requester and quantity.');
$assert(str_contains($newOrder['html'], '&lt;Laptop &amp; dock&gt;') && !str_contains($newOrder['html'], '<Laptop'), 'Order values are escaped in HTML.');

$approved = orderEventEmail('approved', ['id'=>42,'product_name'=>'Laptop','quantity'=>3,'requester_name'=>'Requester One','actor_name'=>'Approver','event_at'=>'2026-10-15 11:00:00']);
$assert(str_contains($approved['text'], 'Requester: Requester One') && str_contains($approved['text'], 'Status: Approved') && str_contains($approved['text'], 'Approver: Approver') && str_contains($approved['text'], 'Approved at:'), 'Approval email includes requester, status, approver and timestamp.');
$rejected = orderEventEmail('rejected', ['id'=>43,'product_name'=>'Monitor','quantity'=>2,'requester_name'=>'Requester Two','actor_name'=>'Rejector <b>','event_at'=>'2026-10-15 11:05:00','rejection_reason'=>'Budget & timing']);
$assert(str_contains($rejected['text'], 'Requester: Requester Two') && str_contains($rejected['text'], 'Status: Rejected') && str_contains($rejected['text'], 'Rejection reason: Budget & timing') && str_contains($rejected['text'], 'Rejected by: Rejector <b>') && str_contains($rejected['text'], 'Rejected at:'), 'Rejection email includes requester, status, reason, user and timestamp.');
$assert(str_contains($rejected['html'], 'Rejector &lt;b&gt;') && str_contains($rejected['html'], 'Budget &amp; timing'), 'Rejection values are escaped in HTML.');

$keyA = orderNotificationEventKey('approved', 42, 'person@example.invalid');
$assert($keyA === orderNotificationEventKey('approved', 42, ' PERSON@example.invalid '), 'Event identifiers are stable for normalized recipients.');
$assert($keyA !== orderNotificationEventKey('rejected', 42, 'person@example.invalid'), 'Different status events have distinct event identifiers.');
$assert(orderNotificationRecipients('admin@example.invalid', null) === ['admin@example.invalid'], 'Admin-only notifications are retained when requester email is missing.');
$assert(orderNotificationRecipients('', 'requester@example.invalid') === ['requester@example.invalid'], 'Requester-only notifications are retained when ADMIN_EMAILS is empty.');
$assert(orderNotificationRecipients('admin@example.invalid', 'requester@example.invalid') === ['admin@example.invalid','requester@example.invalid'], 'Admin and requester notifications are both selected.');
$assert(orderNotificationRecipients('Admin@example.invalid,ADMIN@example.invalid', 'admin@EXAMPLE.invalid') === ['Admin@example.invalid'], 'Admin and requester addresses are deduplicated case-insensitively.');
$assert(orderEmailRecipients('Admin@example.invalid, ADMIN@example.invalid, invalid-address') === ['Admin@example.invalid'], 'Configured admin recipients are validated and deduplicated case-insensitively.');
$requestToken = str_repeat('a', 64);
$assert(orderRequestTokenMatches($requestToken, $requestToken, null), 'Current order form token is accepted.');
$assert(orderRequestTokenMatches($requestToken, str_repeat('b', 64), $requestToken), 'A replay of a recently saved order token can be recognized.');
$assert(!orderRequestTokenMatches(str_repeat('c', 64), $requestToken, str_repeat('b', 64)), 'An unrelated order form token is rejected.');
$assert(orderEmailRetryDelay(1) === 60 && orderEmailRetryDelay(2) === 300 && orderEmailRetryDelay(99) === 21600, 'Retry delay grows and remains bounded.');
$assert(!enqueueOrderNotification(new class extends PDO { public function __construct() {} }, 'approved', ['id'=>42], 'invalid-address'), 'Invalid recipients are ignored without opening a database connection.');

$oldUser = getenv('SMTP_USER');
$oldPassword = getenv('SMTP_PASSWORD');
putenv('SMTP_USER=mail-user-secret');
putenv('SMTP_PASSWORD=mail-password-secret');
$safe = emailSafeError('login mail-user-secret failed: mail-password-secret');
$assert(!str_contains($safe, 'mail-user-secret') && !str_contains($safe, 'mail-password-secret'), 'SMTP credentials are redacted from errors.');
$oldUser === false ? putenv('SMTP_USER') : putenv('SMTP_USER=' . $oldUser);
$oldPassword === false ? putenv('SMTP_PASSWORD') : putenv('SMTP_PASSWORD=' . $oldPassword);

echo "PASS: order event email templates, HTML escaping, unique event keys, and bounded retry policy\n";
