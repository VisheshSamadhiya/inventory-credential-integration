<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';

/** Reserve an attempt across sessions/processes before doing password work. */
function reserveLoginAttempt(string $username, ?string $ip): bool
{
    $pdo = db();
    $buckets = [
        hash('sha256', 'account:' . mb_strtolower(trim($username), 'UTF-8')) => 10,
        hash('sha256', 'address:' . ($ip ?? 'unknown')) => 100,
    ];
    ksort($buckets);
    $pdo->beginTransaction();
    try {
        foreach ($buckets as $key => $limit) {
            $pdo->prepare('INSERT IGNORE INTO login_attempt_limits(bucket_key,attempts,expires_at) VALUES(?,0,UTC_TIMESTAMP()+INTERVAL 15 MINUTE)')->execute([$key]);
            $lock = $pdo->prepare('SELECT attempts,expires_at<=UTC_TIMESTAMP() expired FROM login_attempt_limits WHERE bucket_key=? FOR UPDATE');
            $lock->execute([$key]);
            $row = $lock->fetch();
            if ((int)$row['expired']) {
                $pdo->prepare('UPDATE login_attempt_limits SET attempts=0,expires_at=UTC_TIMESTAMP()+INTERVAL 15 MINUTE WHERE bucket_key=?')->execute([$key]);
            } elseif ((int)$row['attempts'] >= $limit) {
                $pdo->rollBack();
                return false;
            }
            $pdo->prepare('UPDATE login_attempt_limits SET attempts=attempts+1 WHERE bucket_key=?')->execute([$key]);
        }
        $pdo->commit();
        $pdo->exec('DELETE FROM login_attempt_limits WHERE expires_at<UTC_TIMESTAMP() LIMIT 100');
        return true;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
}

function clearSuccessfulLoginAttempts(string $username): void
{
    db()->prepare('DELETE FROM login_attempt_limits WHERE bucket_key=?')->execute([
        hash('sha256', 'account:' . mb_strtolower(trim($username), 'UTF-8')),
    ]);
}
