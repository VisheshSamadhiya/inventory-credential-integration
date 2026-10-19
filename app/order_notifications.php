<?php
declare(strict_types=1);

require_once __DIR__ . '/order_email.php';

function orderRequestTokenMatches(string $token, mixed $currentToken, mixed $lastToken): bool
{
    if (!preg_match('/^[a-f0-9]{64}$/D', $token)) return false;
    return (is_string($currentToken) && hash_equals($currentToken, $token))
        || (is_string($lastToken) && hash_equals($lastToken, $token));
}

function orderNotificationEventKey(string $eventType, int $orderId, string $recipient): string
{
    if ($orderId < 1) throw new InvalidArgumentException('A valid order ID is required for a notification.');
    return 'order.' . $eventType . ':' . $orderId . ':' . hash('sha256', strtolower(trim($recipient)));
}

function orderNotificationRecipients(string $configuredAdmins, ?string $requesterEmail = null): array
{
    $addresses = array_merge(explode(',', $configuredAdmins), [$requesterEmail ?? '']);
    $recipients = [];
    $seen = [];
    foreach ($addresses as $address) {
        $address = trim((string)$address);
        if ($address === '' || filter_var($address, FILTER_VALIDATE_EMAIL) === false) continue;
        $key = strtolower($address);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $recipients[] = $address;
    }
    return $recipients;
}

function enqueueOrderNotification(PDO $db, string $eventType, array $order, string $recipient): bool
{
    $recipient = trim($recipient);
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) return false;
    $message = orderEventEmail($eventType, $order);
    $insert = $db->prepare(
        "INSERT IGNORE INTO order_email_outbox(event_key,event_type,recipient_email,subject,html_body,text_body,status,available_at)
         VALUES(?,?,?,?,?,?,'pending',UTC_TIMESTAMP())"
    );
    $insert->execute([orderNotificationEventKey($eventType, (int)$order['id'], $recipient), $eventType, $recipient, $message['subject'], $message['html'], $message['text']]);
    return $insert->rowCount() === 1;
}

function enqueueOrderNotifications(PDO $db, string $eventType, array $order, array $recipients): int
{
    $queued = 0;
    $seen = [];
    foreach (array_map(static fn($address): string => trim((string)$address), $recipients) as $recipient) {
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            error_log(sprintf('Order notification skipped for order %d event %s: invalid recipient address.', (int)($order['id'] ?? 0), $eventType));
            continue;
        }
        $recipientKey = strtolower($recipient);
        if (isset($seen[$recipientKey])) continue;
        $seen[$recipientKey] = true;
        if (enqueueOrderNotification($db, $eventType, $order, $recipient)) $queued++;
    }
    return $queued;
}

function orderEmailRetryDelay(int $attempt): int
{
    $delays = [60, 300, 900, 3600, 21600];
    return $delays[max(0, min(count($delays) - 1, $attempt - 1))];
}

function processOrderEmailOutbox(PDO $db, callable $send, int $batchSize = 20, int $maxAttempts = 6): array
{
    $batchSize = max(1, min(100, $batchSize));
    $maxAttempts = max(1, min(20, $maxAttempts));
    $db->beginTransaction();
    try {
        $rows = $db->query(
            "SELECT id,event_type,recipient_email,subject,html_body,text_body,attempts
             FROM order_email_outbox
             WHERE (status='pending' AND available_at<=UTC_TIMESTAMP())
                OR (status='processing' AND locked_at<UTC_TIMESTAMP()-INTERVAL 10 MINUTE)
             ORDER BY id LIMIT {$batchSize} FOR UPDATE SKIP LOCKED"
        )->fetchAll();
        $claim = $db->prepare("UPDATE order_email_outbox SET status='processing',locked_at=UTC_TIMESTAMP(),attempts=attempts+1 WHERE id=?");
        foreach ($rows as $row) $claim->execute([(int)$row['id']]);
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) $db->rollBack();
        throw $exception;
    }

    $result = ['sent' => 0, 'retrying' => 0, 'failed' => 0, 'processed' => count($rows)];
    $delivered = $db->prepare("UPDATE order_email_outbox SET status='sent',sent_at=UTC_TIMESTAMP(),locked_at=NULL,last_error=NULL WHERE id=? AND status='processing'");
    $retry = $db->prepare("UPDATE order_email_outbox SET status=?,available_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND),locked_at=NULL,last_error=? WHERE id=? AND status='processing'");
    foreach ($rows as $row) {
        $id = (int)$row['id'];
        $attempt = (int)$row['attempts'] + 1;
        try {
            if (!filter_var($row['recipient_email'], FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('The queued recipient address is invalid.');
            $send((string)$row['recipient_email'], (string)$row['subject'], (string)$row['html_body'], (string)$row['text_body']);
            $delivered->execute([$id]);
            $result['sent']++;
        } catch (Throwable $exception) {
            $safeError = emailSafeError($exception->getMessage());
            $terminal = $attempt >= $maxAttempts;
            $retry->execute([$terminal ? 'failed' : 'pending', orderEmailRetryDelay($attempt), $safeError, $id]);
            $result[$terminal ? 'failed' : 'retrying']++;
            error_log(sprintf('Order email outbox delivery failed (message_id=%d event=%s attempt=%d terminal=%s): %s', $id, (string)$row['event_type'], $attempt, $terminal ? 'yes' : 'no', $safeError));
        }
    }
    return $result;
}
