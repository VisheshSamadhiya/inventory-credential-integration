<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/order_email.php';

$orders = [
    ['id'=>1,'quantity'=>2,'vendor'=>'<Vendor>','transaction_at'=>'2026-10-12 10:00:00','status'=>'Pending','total_price'=>'50.00','product_name'=>'<script>alert("x")</script>'],
    ['id'=>2,'quantity'=>1,'vendor'=>null,'transaction_at'=>'2026-10-11 10:00:00','status'=>'Confirmed','total_price'=>null,'product_name'=>'Mouse'],
    ['id'=>3,'quantity'=>4,'vendor'=>'Acme','transaction_at'=>'2026-10-10 10:00:00','status'=>'Rejected','total_price'=>'100.00','product_name'=>'Keyboard'],
    ['id'=>4,'quantity'=>1,'vendor'=>'Acme','transaction_at'=>'2026-10-09 10:00:00','status'=>null,'total_price'=>null,'product_name'=>'Cable'],
];
$counts = orderReportCounts($orders);
if ($counts !== ['Pending'=>1,'Confirmed'=>1,'Rejected'=>1,'NULL Status'=>1,'Total'=>4]) throw new RuntimeException('Order status counts are incorrect.');
$fixedNow = new DateTimeImmutable('2026-10-12 12:00:00', new DateTimeZone('Asia/Kolkata'));
$html = orderReportHtml($orders, 'http://localhost:8090', $fixedNow);
foreach (['Order status summary','12 Oct 2026, 12:00 PM IST','NULL Status','Pending','&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;','&lt;Vendor&gt;','name="viewport"'] as $expected) {
    if (!str_contains($html, $expected)) throw new RuntimeException('Email rendering omitted expected content: ' . $expected);
}
if (str_contains($html, '<script>')) throw new RuntimeException('Database value was not escaped.');
$external = orderReportHtml([], 'https://inventory.example.com', $fixedNow);
if (!str_contains($external, 'href="https://inventory.example.com"')) throw new RuntimeException('External View Orders link was omitted.');
$internal = orderReportHtml([], 'http://10.0.0.5:8090', $fixedNow);
if (str_contains($internal, 'View Orders')) throw new RuntimeException('Private View Orders link was shown without explicit configuration.');
$internalConfigured = orderReportHtml([], 'http://10.0.0.5:8090', $fixedNow, true);
if (!str_contains($internalConfigured, 'href="http://10.0.0.5:8090"')) throw new RuntimeException('Configured internal View Orders link was omitted.');
foreach (['ftp://inventory.example.com', 'http://localhost:8090', 'http://inventory.local', 'http://192.168.1.2'] as $unsafeUrl) {
    if (orderEmailViewUrl($unsafeUrl) !== null) throw new RuntimeException('Inaccessible/non-HTTP URL was accepted: ' . $unsafeUrl);
}
$plain = orderReportText($orders, '', $fixedNow);
if (!str_contains($plain, 'NULL Status: 1') || !str_contains($plain, 'Order #4')) throw new RuntimeException('Plain text alternative omitted order information.');
$weeklyHtml = weeklyReminderHtml([$orders[0] + ['days_pending'=>8]], '', false, $fixedNow);
$weeklyText = weeklyReminderText([$orders[0] + ['days_pending'=>8]], '', false, $fixedNow);
if (!str_contains($weeklyHtml, 'Weekly pending reminder') || !str_contains($weeklyHtml, 'at least seven days')) throw new RuntimeException('Weekly reminder HTML uses incorrect report wording.');
if (!str_contains($weeklyText, 'Weekly pending reminder') || !str_contains($weeklyText, 'Days pending: 8')) throw new RuntimeException('Weekly reminder plain text omitted its due order details.');
if (!orderReminderDue(new DateTimeImmutable('2026-10-05 11:59:59 UTC'), new DateTimeImmutable('2026-10-12 12:00:00 UTC'))) throw new RuntimeException('An order pending longer than seven days was not due.');
if (orderReminderDue(new DateTimeImmutable('2026-10-05 12:00:01 UTC'), new DateTimeImmutable('2026-10-12 12:00:00 UTC'))) throw new RuntimeException('An order pending less than seven days was due.');
if (orderReminderEligible(new DateTimeImmutable('2026-10-05 12:00:00 UTC'), new DateTimeImmutable('2026-10-12 11:59:59 UTC'))) throw new RuntimeException('A recent successful delivery was eligible too early.');
if (!orderReminderEligible(new DateTimeImmutable('2026-10-05 12:00:00 UTC'), new DateTimeImmutable('2026-10-12 12:00:00 UTC'))) throw new RuntimeException('A successful delivery was not eligible after seven days.');
if (!orderReminderEligible(null, $fixedNow)) throw new RuntimeException('An order with no successful delivery was not eligible for retry.');
$previewPath = tempnam(sys_get_temp_dir(), 'order-report-preview-');
if ($previewPath === false) throw new RuntimeException('Could not allocate a preview test file.');
$previewSendCount = 0;
$preview = executeOrderReportCommand('--preview', $orders, 'http://localhost:8090', false, [], static function () use (&$previewSendCount): void { $previewSendCount++; }, $previewPath);
if ($previewSendCount !== 0 || $preview['mode'] !== 'preview' || !is_file($previewPath) || !str_contains((string)file_get_contents($previewPath), 'Inventory Manager')) throw new RuntimeException('Preview command sent mail or failed to write HTML.');
unlink($previewPath);
$sentMessages = [];
$sendReport = executeOrderReportCommand('--send-report', $orders, 'https://inventory.example.com', false, ['one@example.com','two@example.com'], static function (string $recipient, string $subject, string $html, string $text) use (&$sentMessages): void { $sentMessages[] = [$recipient,$subject,$html,$text]; });
if ($sendReport['sent'] !== 2 || $sendReport['failed'] !== 0 || count($sentMessages) !== 2 || !str_contains($sentMessages[0][2], '<!doctype html>') || !str_contains($sentMessages[0][2], 'NULL Status') || !str_contains($sentMessages[0][3], 'Order #4')) throw new RuntimeException('Send report command did not deliver the complete HTML/text report independently to all recipients.');
if (orderEmailRecipients('bad-address,one@example.com, one@example.com') !== ['one@example.com']) throw new RuntimeException('ADMIN_EMAILS parsing did not filter and deduplicate recipients.');
$delivery = deliverOrderReport(['ok@example.com','fail@example.com'], static function (string $recipient): void {
    if (str_starts_with($recipient, 'fail')) throw new RuntimeException('simulated SMTP failure');
});
if ($delivery['sent'] !== 1 || $delivery['failed'] !== 1 || !isset($delivery['failures']['fail@example.com'])) throw new RuntimeException('Delivery outcomes were not recorded independently.');
echo "PASS: order email reports, reminder cadence, escaping, links, text alternatives, and delivery behavior\n";
