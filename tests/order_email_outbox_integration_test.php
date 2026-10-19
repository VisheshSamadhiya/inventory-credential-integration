<?php
declare(strict_types=1);
$appRoot = rtrim((string)(getenv('APP_ROOT') ?: dirname(__DIR__)), '/');
$appDirectory = is_file($appRoot . '/config.php') ? $appRoot : $appRoot . '/app';
require_once $appDirectory . '/config.php';
require_once $appDirectory . '/email_service.php';
require_once $appDirectory . '/order_notifications.php';

$db = db();
$nonce = bin2hex(random_bytes(6));
$recipients = [
    "outbox-test-{$nonce}-new@example.invalid",
    "outbox-test-{$nonce}-approved@example.invalid",
    "outbox-test-{$nonce}-rejected@example.invalid",
    "outbox-test-{$nonce}-terminal@example.invalid",
];
$orderId = random_int(100000000, 900000000);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

try {
    $productId = (int)$db->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn();
    $userId = (int)$db->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
    if ($productId > 0 && $userId > 0) {
        $requestKey = hash('sha256', $nonce . 'duplicate-order-submit');
        $insertOrder = $db->prepare("INSERT INTO inventory_transactions(product_id,transaction_type,quantity,previous_quantity,new_quantity,reason,created_by,transaction_at,request_key) VALUES(?,'ordered',1,0,0,'Outbox idempotency test',?,UTC_TIMESTAMP(),?)");
        $db->beginTransaction();
        $insertOrder->execute([$productId,$userId,$requestKey]);
        $duplicateRejected = false;
        try { $insertOrder->execute([$productId,$userId,$requestKey]); }
        catch (PDOException $exception) { $duplicateRejected = (int)($exception->errorInfo[1] ?? 0) === 1062; }
        $assert($duplicateRejected, 'The database accepted a repeated order form request key.');
        $db->rollBack();

        $transitionKey = hash('sha256', $nonce . 'repeat-status-update');
        $db->beginTransaction();
        $insertOrder->execute([$productId,$userId,$transitionKey]);
        $testOrderId = (int)$db->lastInsertId();
        $confirm = $db->prepare("UPDATE inventory_transactions SET status='Confirmed',confirmed_at=UTC_TIMESTAMP(),confirmed_by=? WHERE id=? AND transaction_type='ordered' AND status='Pending'");
        $confirm->execute([$userId,$testOrderId]);
        $firstTransition = $confirm->rowCount();
        if ($firstTransition === 1) enqueueOrderNotification($db, 'approved', ['id'=>$testOrderId,'product_name'=>'Status transition fixture','quantity'=>1,'actor_name'=>'Test approver','event_at'=>'2026-10-15 12:00:00'], $recipients[1]);
        $confirm->execute([$userId,$testOrderId]);
        $secondTransition = $confirm->rowCount();
        if ($secondTransition === 1) enqueueOrderNotification($db, 'approved', ['id'=>$testOrderId,'product_name'=>'Status transition fixture','quantity'=>1,'actor_name'=>'Test approver','event_at'=>'2026-10-15 12:00:00'], $recipients[1]);
        $assert($firstTransition === 1 && $secondTransition === 0, 'A repeated final status update was not rejected as non-pending.');
        $queuedCount = $db->prepare('SELECT COUNT(*) FROM order_email_outbox WHERE recipient_email=?');
        $queuedCount->execute([$recipients[1]]);
        $assert((int)$queuedCount->fetchColumn() === 1, 'A repeated status update created a duplicate notification.');
        $db->rollBack();

        $db->beginTransaction();
        $confirm->execute([$userId,$testOrderId + 100000000]);
        if ($confirm->rowCount() === 1) enqueueOrderNotification($db, 'approved', ['id'=>$testOrderId + 100000000,'product_name'=>'Missing order','quantity'=>1,'actor_name'=>'Test approver','event_at'=>'2026-10-15 12:00:00'], $recipients[1]);
        $queuedCount->execute([$recipients[1]]);
        $assert((int)$queuedCount->fetchColumn() === 0, 'A failed order transition created a notification.');
        $db->rollBack();

        $db->beginTransaction();
        $insertOrder->execute([$productId,$userId,hash('sha256', $nonce . 'repeated-rejection')]);
        $rejectedOrderId = (int)$db->lastInsertId();
        $reject = $db->prepare("UPDATE inventory_transactions SET status='Rejected',rejected_at=UTC_TIMESTAMP(),rejected_by=?,rejection_reason='Integration test' WHERE id=? AND transaction_type='ordered' AND status='Pending'");
        $reject->execute([$userId,$rejectedOrderId]);
        $firstRejection = $reject->rowCount();
        if ($firstRejection === 1) enqueueOrderNotification($db, 'rejected', ['id'=>$rejectedOrderId,'product_name'=>'Status transition fixture','quantity'=>1,'actor_name'=>'Test rejector','event_at'=>'2026-10-15 12:00:00','rejection_reason'=>'Integration test'], $recipients[2]);
        $reject->execute([$userId,$rejectedOrderId]);
        $secondRejection = $reject->rowCount();
        if ($secondRejection === 1) enqueueOrderNotification($db, 'rejected', ['id'=>$rejectedOrderId,'product_name'=>'Status transition fixture','quantity'=>1,'actor_name'=>'Test rejector','event_at'=>'2026-10-15 12:00:00','rejection_reason'=>'Integration test'], $recipients[2]);
        $assert($firstRejection === 1 && $secondRejection === 0, 'A repeated rejection update was not rejected as non-pending.');
        $queuedCount->execute([$recipients[2]]);
        $assert((int)$queuedCount->fetchColumn() === 1, 'A repeated rejection created a duplicate notification.');
        $db->rollBack();

        $db->beginTransaction();
        $reject->execute([$userId,$rejectedOrderId + 100000000]);
        if ($reject->rowCount() === 1) enqueueOrderNotification($db, 'rejected', ['id'=>$rejectedOrderId + 100000000,'product_name'=>'Missing order','quantity'=>1,'actor_name'=>'Test rejector','event_at'=>'2026-10-15 12:00:00'], $recipients[2]);
        $queuedCount->execute([$recipients[2]]);
        $assert((int)$queuedCount->fetchColumn() === 0, 'A failed rejection created a notification.');
        $db->rollBack();
    }

    $db->beginTransaction();
    $eventOrder = ['id'=>$orderId,'product_name'=>'Outbox test <product>','quantity'=>2,'requester_name'=>'Test requester','reason'=>'Requested','order_date'=>'2026-10-15 12:00:00'];
    $assert(enqueueOrderNotification($db, 'new_order', $eventOrder, $recipients[0]), 'First new-order event was not inserted.');
    $assert(!enqueueOrderNotification($db, 'new_order', $eventOrder, $recipients[0]), 'Duplicate new-order event was not ignored.');
    $assert(enqueueOrderNotification($db, 'approved', $eventOrder + ['actor_name'=>'Approver','event_at'=>'2026-10-15 12:05:00'], $recipients[1]), 'Approval event was not inserted.');
    $assert(enqueueOrderNotification($db, 'rejected', $eventOrder + ['actor_name'=>'Rejector','event_at'=>'2026-10-15 12:06:00','rejection_reason'=>'Test reason'], $recipients[2]), 'Rejection event was not inserted.');
    $assert(!enqueueOrderNotification($db, 'rejected', $eventOrder, 'bad-address'), 'Invalid recipient was queued.');
    $db->commit();

    $firstPass = processOrderEmailOutbox($db, static function (string $to, string $subject, string $html, string $text) use ($recipients): void {
        if ($to === $recipients[0]) throw new RuntimeException('simulated SMTP outage');
        if ($subject === '' || $html === '' || $text === '') throw new RuntimeException('empty notification content');
        if ($to === $recipients[1] && (!str_contains($html, 'Approver') || !str_contains($text, 'Approved'))) throw new RuntimeException('approval email omitted transition details');
        if ($to === $recipients[2] && (!str_contains($html, 'Test reason') || !str_contains($text, 'Rejector'))) throw new RuntimeException('rejection email omitted transition details');
    });
    $assert($firstPass === ['sent'=>2,'retrying'=>1,'failed'=>0,'processed'=>3], 'Worker did not send and retry the expected messages.');

    $retryId = orderNotificationEventKey('new_order', $orderId, $recipients[0]);
    $due = $db->prepare('UPDATE order_email_outbox SET available_at=UTC_TIMESTAMP() WHERE event_key=?');
    $due->execute([$retryId]);
    $secondPass = processOrderEmailOutbox($db, static function (): void {});
    $assert($secondPass === ['sent'=>1,'retrying'=>0,'failed'=>0,'processed'=>1], 'A retried SMTP delivery did not complete.');

    $terminalOrder = ['id'=>$orderId + 1,'product_name'=>'Terminal retry fixture','quantity'=>1];
    $db->beginTransaction();
    enqueueOrderNotification($db, 'rejected', $terminalOrder, $recipients[3]);
    $db->commit();
    for ($attempt = 1; $attempt <= 6; $attempt++) {
        $db->prepare("UPDATE order_email_outbox SET available_at=UTC_TIMESTAMP() WHERE recipient_email=? AND status='pending'")->execute([$recipients[3]]);
        $pass = processOrderEmailOutbox($db, static function (): void { throw new RuntimeException('simulated persistent SMTP failure'); });
        $assert($pass['processed'] === 1, 'Expected the terminal test message to be attempted.');
    }
    $check = $db->prepare('SELECT status,attempts,last_error FROM order_email_outbox WHERE recipient_email=?');
    $check->execute([$recipients[3]]);
    $terminal = $check->fetch();
    $assert($terminal && $terminal['status'] === 'failed' && (int)$terminal['attempts'] === 6, 'Retries did not stop at the configured attempt bound.');
    $assert($terminal['last_error'] === 'simulated persistent SMTP failure', 'A safe delivery error was not recorded.');

    echo "PASS: outbox duplicate suppression, simulated SMTP failure/retry, delivery and bounded terminal failure\n";
} finally {
    if ($db->inTransaction()) $db->rollBack();
    $cleanup = $db->prepare('DELETE FROM order_email_outbox WHERE recipient_email LIKE ?');
    $cleanup->execute(["outbox-test-{$nonce}-%@example.invalid"]);
}
