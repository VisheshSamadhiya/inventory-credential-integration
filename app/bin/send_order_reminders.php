<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/order_email.php';
require_once dirname(__DIR__) . '/email_service.php';

function redactSecrets(string $message): string
{
    return emailSafeError($message);
}

function reminderError(string $message): void
{
    error_log('Order reminder: ' . redactSecrets($message));
}

$args = $argv ?? [];
$command = $args[1] ?? '';
if (in_array($command, ['--preview', '--send-report'], true)) {
    try {
        $orders = orderReportRows(db());
        $allowInternalUrl = filter_var(envv('APP_URL_ACCESSIBLE_INTERNAL', 'false'), FILTER_VALIDATE_BOOLEAN);
        $previewPath = $command === '--preview' ? (string)($args[2] ?? '') : null;
        $recipients = $command === '--send-report' ? orderEmailRecipients(envv('ADMIN_EMAILS')) : [];
        $result = executeOrderReportCommand($command, $orders, envv('APP_URL'), $allowInternalUrl, $recipients, static function (string $recipient, string $subject, string $html, string $text): void {
            sendMail($recipient, $subject, $html, $text);
        }, $previewPath);
        if ($command === '--preview') {
            if ($result['path'] !== null) {
                echo "HTML report preview written to {$result['path']}; no email sent.\n";
            } else {
                echo $result['html'] . "\n";
            }
            exit(0);
        }
        foreach ($result['failures'] as $recipient => $error) reminderError('full report delivery failed for recipient ' . $recipient . ': ' . $error);
        echo sprintf("Full order report complete: %d sent, %d failed, %d orders included.\n", $result['sent'], $result['failed'], $result['orders']);
        exit($result['failed'] > 0 ? 1 : 0);
    } catch (Throwable $e) {
        reminderError('full report could not be generated or sent: ' . $e->getMessage());
        fwrite(STDERR, "Report failed; see container logs for details.\n");
        exit(1);
    }
}
if (isset($args[1]) && $args[1] === '--test-email') {
    $to = trim((string)($args[2] ?? ''));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        fwrite(STDERR, "Usage: php bin/send_order_reminders.php --test-email recipient@example.com\n");
        exit(2);
    }
    try {
        sendMail($to, 'Inventory Manager SMTP test', "This is a test email from Inventory Manager. No orders or reminder records were changed.\n");
        echo "Test email sent to {$to}.\n";
        exit(0);
    } catch (Throwable $e) {
        reminderError('test email failed: ' . $e->getMessage());
        fwrite(STDERR, "Test email failed; see container logs for details.\n");
        exit(1);
    }
}

$recipients = array_values(array_unique(array_filter(array_map('trim', explode(',', envv('ADMIN_EMAILS'))), static fn($email) => filter_var($email, FILTER_VALIDATE_EMAIL))));
if (!$recipients) { reminderError('skipped: no valid ADMIN_EMAILS configured.'); exit(0); }

// MariaDB can accept connections after the health check during restart; retry briefly with bounded backoff.
$db = null;
for ($try = 1; $try <= 5; $try++) {
    try { $db = db(); break; }
    catch (PDOException $e) {
        reminderError(sprintf('database connection attempt %d/5 failed (%s).', $try, $e->getCode()));
        if ($try < 5) sleep(min(2 ** ($try - 1), 8));
    }
}
if (!$db instanceof PDO) { fwrite(STDERR, "Reminder run stopped: database unavailable after retries.\n"); exit(1); }

try {
    $orders = $db->query("SELECT t.id,t.quantity,COALESCE(t.vendor_name_snapshot,t.vendor,v.vendor_name) vendor,t.transaction_at,t.status,t.total_price,p.name product_name,DATEDIFF(UTC_DATE(),DATE(t.transaction_at)) days_pending FROM inventory_transactions t JOIN products p ON p.id=t.product_id LEFT JOIN vendors v ON v.id=t.vendor_id WHERE t.transaction_type='ordered' AND t.status='Pending' AND t.transaction_at <= UTC_TIMESTAMP() - INTERVAL 7 DAY ORDER BY t.transaction_at,t.id")->fetchAll();
} catch (Throwable $e) { reminderError('could not query due orders (' . $e->getCode() . ').'); exit(1); }

$hasSuccessfulAttempt = $db->prepare("SELECT 1 FROM order_reminder_attempts WHERE order_id=? AND recipient_email=? AND outcome='sent' AND attempted_at > UTC_TIMESTAMP() - INTERVAL 7 DAY LIMIT 1");
$record = $db->prepare('INSERT INTO order_reminder_attempts(order_id,recipient_email,attempted_at,outcome,error_message) VALUES(?,?,UTC_TIMESTAMP(),?,?)');
$sent = 0; $failed = 0;
foreach ($orders as $order) {
    foreach ($recipients as $recipient) {
        $hasSuccessfulAttempt->execute([(int)$order['id'], $recipient]);
        if ($hasSuccessfulAttempt->fetchColumn()) continue;
        $outcome = 'sent'; $error = null;
        for ($try = 1; $try <= 2; $try++) {
            try {
                $allowInternalUrl = filter_var(envv('APP_URL_ACCESSIBLE_INTERNAL', 'false'), FILTER_VALIDATE_BOOLEAN);
                $reminderHtml = weeklyReminderHtml([$order], envv('APP_URL'), $allowInternalUrl);
                sendMail($recipient, 'Inventory Manager — weekly pending order reminder', $reminderHtml, weeklyReminderText([$order], envv('APP_URL'), $allowInternalUrl));
                break;
            }
            catch (Throwable $e) {
                $outcome = 'failed';
                $error = redactSecrets($e->getMessage());
                if ($try === 1) sleep(2);
            }
        }
        try { $record->execute([(int)$order['id'], $recipient, $outcome, $error]); }
        catch (Throwable $e) { reminderError(sprintf('could not record result for order %d and recipient %s (%s).', (int)$order['id'], $recipient, $e->getCode())); }
        if ($outcome === 'sent') $sent++; else { $failed++; reminderError(sprintf('delivery failed for order %d and recipient %s: %s', (int)$order['id'], $recipient, $error ?? 'unknown error')); }
    }
}
echo sprintf("Reminder run complete: %d recipient deliveries succeeded, %d failed, %d due orders checked.\n", $sent, $failed, count($orders));
if ($failed > 0) exit(1);
