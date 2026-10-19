<?php
declare(strict_types=1);

require_once __DIR__ . '/credential_helpers.php';

const CREDENTIAL_TOTP_SESSION_SECONDS = 900;
const CREDENTIAL_TOTP_MAX_FAILURES = 5;
const CREDENTIAL_TOTP_LOCK_SECONDS = 600;
const CREDENTIAL_TOTP_PENDING_SECONDS = 900;

function credentialTotpSafeReturnTarget(mixed $target): ?string
{
    if (!is_string($target) || $target === '' || strlen($target) > 4096 || preg_match('/[\x00-\x1f\x7f]/', $target) || str_contains($target, '\\')) return null;
    $parts = parse_url($target);
    if (!is_array($parts) || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment'])) return null;
    $path = (string)($parts['path'] ?? '');
    $decodedPath = rawurldecode($path);
    if ($path === '' || str_starts_with($path, '//') || preg_match('~(?:^|/)\.\.(?:/|$)~', $decodedPath)) return null;
    $allowedPages = ['credentials.php', 'credential-access.php', 'credential-dashboard-details.php', 'audit_logs.php'];
    if (!in_array(basename($decodedPath), $allowedPages, true)) return null;
    $query = (string)($parts['query'] ?? '');
    if (strlen($query) > 2048) return null;
    if ($query !== '') {
        parse_str($query, $queryValues);
        foreach (array_keys($queryValues) as $name) {
            if (preg_match('/(?:password|secret|token|csrf|code|key)/i', (string)$name)) return null;
        }
    }
    return $path . ($query !== '' ? '?' . $query : '');
}

function credentialTotpRememberReturnTarget(): void
{
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') return;
    $target = credentialTotpSafeReturnTarget($_SERVER['REQUEST_URI'] ?? null);
    if ($target !== null) $_SESSION['credential_totp_return_to'] = $target;
}

function credentialTotpRedirectAfterVerification(): void
{
    $target = credentialTotpSafeReturnTarget($_SESSION['credential_totp_return_to'] ?? null);
    unset($_SESSION['credential_totp_return_to']);
    if ($target === null && canPerform('credentials', 'view')) $target = 'credentials.php';
    if ($target !== null) {
        header('Location: ' . $target, true, 303);
        exit;
    }
}

function credentialTotpState(int $userId, bool $forUpdate = false): array
{
    $sql = 'SELECT required,status,secret_enc,pending_secret_enc,last_accepted_step,failed_attempts,locked_until,generation,updated_at FROM user_credential_totp WHERE user_id=? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '');
    $stmt = db()->prepare($sql);
    $stmt->execute([$userId]);
    $state = $stmt->fetch();
    return is_array($state) ? $state : [
        'required' => 0, 'status' => null, 'secret_enc' => null, 'pending_secret_enc' => null,
        'last_accepted_step' => -1, 'failed_attempts' => 0, 'locked_until' => null, 'generation' => 0, 'updated_at' => null,
    ];
}

function credentialTotpPendingExpired(array $state, ?int $now = null): bool
{
    if (($state['status'] ?? null) !== 'pending') return false;
    $updatedAt = $state['updated_at'] ?? null;
    $timestamp = is_string($updatedAt) && $updatedAt !== '' ? strtotime($updatedAt . ' UTC') : false;
    return $timestamp === false || ($now ?? time()) - $timestamp >= CREDENTIAL_TOTP_PENDING_SECONDS;
}

function credentialTotpPageState(int $userId): array
{
    try {
        return credentialTotpState($userId);
    } catch (Throwable $exception) {
        error_log('Credential TOTP page state unavailable: ' . get_class($exception) . ' at ' . basename($exception->getFile()) . ':' . $exception->getLine());
        http_response_code(503);
        exit('Credential security settings are temporarily unavailable.');
    }
}

function credentialTotpEnsurePendingEnrollment(int $userId): array
{
    $db = db();
    $db->beginTransaction();
    try {
        $state = credentialTotpState($userId, true);
        if (!(int)$state['required']) throw new DomainException('Credential 2FA is not required for this account.');
        if (($state['status'] ?? null) === 'active') throw new DomainException('This account already has an active authenticator.');
        if (($state['status'] ?? null) === 'pending' && !credentialTotpPendingExpired($state) && is_string($state['pending_secret_enc'] ?? null) && $state['pending_secret_enc'] !== '') {
            $db->commit();
            return $state;
        }
        $encrypted = encryptCredentialValue(credentialTotpSecret());
        $update = $db->prepare("UPDATE user_credential_totp SET status='pending',secret_enc=NULL,pending_secret_enc=?,last_accepted_step=-1,failed_attempts=0,locked_until=NULL WHERE user_id=? AND required=1");
        $update->execute([$encrypted, $userId]);
        if ($update->rowCount() !== 1) throw new RuntimeException('Credential enrollment state could not be verified.');
        $db->commit();
        return credentialTotpState($userId);
    } catch (Throwable $exception) {
        if ($db->inTransaction()) $db->rollBack();
        throw $exception;
    }
}

function credentialTotpResetEnrollment(int $userId, int $actorId): void
{
    db()->prepare("INSERT INTO user_credential_totp(user_id,required,status,secret_enc,pending_secret_enc,last_accepted_step,failed_attempts,locked_until,generation,updated_by) VALUES(?,1,'reset_required',NULL,NULL,-1,0,NULL,2,?) ON DUPLICATE KEY UPDATE required=1,status='reset_required',secret_enc=NULL,pending_secret_enc=NULL,last_accepted_step=-1,failed_attempts=0,locked_until=NULL,generation=generation+1,updated_by=VALUES(updated_by)")
        ->execute([$userId, $actorId]);
}

function credentialTotpSetRequired(int $userId, bool $required, int $actorId, ?array $knownState = null): void
{
    $state = $knownState ?? credentialTotpState($userId, true);
    if ((bool)$state['required'] === $required) return;
    if ($required) {
        db()->prepare("INSERT INTO user_credential_totp(user_id,required,updated_by) VALUES(?,1,?) ON DUPLICATE KEY UPDATE required=1,status=IF(status IN ('active','pending','reset_required'),'reset_required',NULL),secret_enc=NULL,pending_secret_enc=NULL,last_accepted_step=-1,failed_attempts=0,locked_until=NULL,generation=generation+1,updated_by=VALUES(updated_by)")
            ->execute([$userId, $actorId]);
    } elseif ((int)$state['generation'] > 0) {
        db()->prepare("UPDATE user_credential_totp SET required=0,status=NULL,secret_enc=NULL,pending_secret_enc=NULL,last_accepted_step=-1,failed_attempts=0,locked_until=NULL,generation=generation+1,updated_by=? WHERE user_id=?")
            ->execute([$actorId, $userId]);
    }
}

function credentialTotpStatusLabel(array $state): string
{
    if (credentialTotpPendingExpired($state)) return 'Enrollment expired';
    return match ((string)($state['status'] ?? '')) {
        'pending' => 'Enrollment pending',
        'active' => 'Active',
        'reset_required' => 'Reset required',
        default => 'Not configured',
    };
}

function credentialTotpSecret(int $length = 32): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $random = random_bytes($length);
    $secret = '';
    for ($i = 0; $i < $length; $i++) $secret .= $alphabet[ord($random[$i]) & 31];
    return $secret;
}

function credentialTotpCode(string $secret, int $step): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split(strtoupper($secret)) as $character) {
        $index = strpos($alphabet, $character);
        if ($index === false) throw new InvalidArgumentException('Invalid TOTP secret.');
        $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
    }
    $key = '';
    foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $key .= chr(bindec($byte));
    $counter = pack('N2', ($step >> 32) & 0xffffffff, $step & 0xffffffff);
    $hash = hash_hmac('sha1', $counter, $key, true);
    $offset = ord($hash[19]) & 0x0f;
    $value = ((ord($hash[$offset]) & 0x7f) << 24)
        | ((ord($hash[$offset + 1]) & 0xff) << 16)
        | ((ord($hash[$offset + 2]) & 0xff) << 8)
        | (ord($hash[$offset + 3]) & 0xff);
    return str_pad((string)($value % 1000000), 6, '0', STR_PAD_LEFT);
}

function credentialTotpMatchedStep(string $secret, string $submitted, ?int $now = null): ?int
{
    if (!preg_match('/^\d{6}$/D', $submitted)) return null;
    $current = intdiv($now ?? time(), 30);
    foreach ([$current, $current - 1, $current + 1] as $step) {
        if ($step >= 0 && hash_equals(credentialTotpCode($secret, $step), $submitted)) return $step;
    }
    return null;
}

function credentialTotpProvisioningUri(string $secret, string $username): string
{
    $issuer = 'Inventory Manager';
    $label = rawurlencode($issuer . ':' . $username);
    $uri = 'otpauth://totp/' . $label . '?secret=' . rawurlencode($secret)
        . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
    if (strlen($uri) > 192) {
        $label = rawurlencode($issuer . ':account-' . substr(hash('sha256', $username), 0, 10));
        $uri = 'otpauth://totp/' . $label . '?secret=' . rawurlencode($secret)
            . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
    }
    return $uri;
}

/** Generate a self-contained version 8-L QR SVG without sending the TOTP URI to a third party. */
function credentialTotpQrSvg(string $text): string
{
    $version = 8;
    $size = 17 + 4 * $version;
    $bytes = array_values(unpack('C*', $text) ?: []);
    if (count($bytes) > 192) throw new RuntimeException('TOTP provisioning data is too long for the QR encoder.');
    $bits = [];
    $append = static function (int $value, int $length) use (&$bits): void {
        for ($i = $length - 1; $i >= 0; $i--) $bits[] = ($value >> $i) & 1;
    };
    $append(4, 4); $append(count($bytes), 8);
    foreach ($bytes as $byte) $append($byte, 8);
    $capacityBits = 194 * 8;
    for ($i = 0, $n = min(4, $capacityBits - count($bits)); $i < $n; $i++) $bits[] = 0;
    while (count($bits) % 8 !== 0) $bits[] = 0;
    $data = [];
    for ($i = 0; $i < count($bits); $i += 8) {
        $byte = 0;
        for ($j = 0; $j < 8; $j++) $byte = ($byte << 1) | $bits[$i + $j];
        $data[] = $byte;
    }
    for ($pad = 0; count($data) < 194; $pad++) $data[] = ($pad % 2 === 0) ? 0xec : 0x11;

    $multiply = static function (int $x, int $y): int {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = (($z << 1) ^ (($z >> 7) * 0x11d)) & 0xff;
            $z ^= (($y >> $i) & 1) * $x;
        }
        return $z;
    };
    $divisor = array_fill(0, 24, 0); $divisor[23] = 1; $root = 1;
    for ($i = 0; $i < 24; $i++) {
        for ($j = 0; $j < 24; $j++) {
            $divisor[$j] = $multiply($divisor[$j], $root);
            if ($j + 1 < 24) $divisor[$j] ^= $divisor[$j + 1];
        }
        $root = $multiply($root, 2);
    }
    $blocks = [array_slice($data, 0, 97), array_slice($data, 97, 97)];
    $eccBlocks = [];
    foreach ($blocks as $block) {
        $remainder = array_fill(0, 24, 0);
        foreach ($block as $byte) {
            $factor = $byte ^ $remainder[0];
            array_shift($remainder); $remainder[] = 0;
            for ($i = 0; $i < 24; $i++) $remainder[$i] ^= $multiply($divisor[$i], $factor);
        }
        $eccBlocks[] = $remainder;
    }
    $codewords = [];
    for ($i = 0; $i < 97; $i++) { $codewords[] = $blocks[0][$i]; $codewords[] = $blocks[1][$i]; }
    for ($i = 0; $i < 24; $i++) { $codewords[] = $eccBlocks[0][$i]; $codewords[] = $eccBlocks[1][$i]; }
    $dataBits = [];
    foreach ($codewords as $byte) for ($i = 7; $i >= 0; $i--) $dataBits[] = ($byte >> $i) & 1;

    $matrix = array_fill(0, $size, array_fill(0, $size, null));
    $function = array_fill(0, $size, array_fill(0, $size, false));
    $set = static function (int $x, int $y, bool $dark) use (&$matrix, &$function, $size): void {
        if ($x >= 0 && $y >= 0 && $x < $size && $y < $size) { $matrix[$y][$x] = $dark; $function[$y][$x] = true; }
    };
    foreach ([[0,0],[$size - 7,0],[0,$size - 7]] as [$left,$top]) {
        for ($dy = -1; $dy <= 7; $dy++) for ($dx = -1; $dx <= 7; $dx++) {
            $x = $left + $dx; $y = $top + $dy;
            $dark = $dx >= 0 && $dx <= 6 && $dy >= 0 && $dy <= 6
                && ($dx === 0 || $dx === 6 || $dy === 0 || $dy === 6 || ($dx >= 2 && $dx <= 4 && $dy >= 2 && $dy <= 4));
            $set($x, $y, $dark);
        }
    }
    for ($i = 8; $i < $size - 8; $i++) { $set(6, $i, $i % 2 === 0); $set($i, 6, $i % 2 === 0); }
    foreach ([6,24,42] as $cy) foreach ([6,24,42] as $cx) {
        if (($cx === 6 && $cy === 6) || ($cx === $size - 7 && $cy === 6) || ($cx === 6 && $cy === $size - 7)) continue;
        for ($dy = -2; $dy <= 2; $dy++) for ($dx = -2; $dx <= 2; $dx++) $set($cx + $dx, $cy + $dy, max(abs($dx), abs($dy)) !== 1);
    }
    // Version 8 information (BCH polynomial 0x1f25).
    $versionBits = $version << 12;
    for ($i = 17; $i >= 12; $i--) if (($versionBits >> $i) & 1) $versionBits ^= 0x1f25 << ($i - 12);
    $versionBits |= $version << 12;
    for ($i = 0; $i < 18; $i++) {
        $bit = (($versionBits >> $i) & 1) !== 0;
        $a = $size - 11 + ($i % 3); $b = intdiv($i, 3);
        $set($a, $b, $bit); $set($b, $a, $bit);
    }
    // Reserve both format-information copies and the fixed dark module before
    // placing payload bits so they cannot consume QR data positions.
    for ($i = 0; $i <= 5; $i++) $set(8, $i, false);
    $set(8, 7, false); $set(8, 8, false); $set(7, 8, false);
    for ($i = 9; $i < 15; $i++) $set(14 - $i, 8, false);
    for ($i = 0; $i < 8; $i++) $set($size - 1 - $i, 8, false);
    for ($i = 8; $i < 15; $i++) $set(8, $size - 15 + $i, false);
    $set(8, $size - 8, true);
    $index = 0; $up = true;
    for ($right = $size - 1; $right >= 1; $right -= 2) {
        if ($right === 6) $right = 5;
        for ($vertical = 0; $vertical < $size; $vertical++) {
            $y = $up ? $size - 1 - $vertical : $vertical;
            for ($offset = 0; $offset < 2; $offset++) {
                $x = $right - $offset;
                if ($function[$y][$x]) continue;
                $bit = $dataBits[$index++] ?? 0;
                $matrix[$y][$x] = (bool)($bit ^ ((($x + $y) % 2) === 0));
            }
        }
        $up = !$up;
    }
    if ($index !== count($dataBits)) throw new RuntimeException('QR module layout is inconsistent: ' . ($index - count($dataBits)) . ' remainder modules.');
    // Error correction level L and mask 0 format information.
    $formatData = (1 << 3);
    $formatRemainder = $formatData << 10;
    for ($i = 14; $i >= 10; $i--) if (($formatRemainder >> $i) & 1) $formatRemainder ^= 0x537 << ($i - 10);
    $format = (($formatData << 10) | $formatRemainder) ^ 0x5412;
    for ($i = 0; $i <= 5; $i++) $set(8, $i, (($format >> $i) & 1) !== 0);
    $set(8, 7, (($format >> 6) & 1) !== 0); $set(8, 8, (($format >> 7) & 1) !== 0); $set(7, 8, (($format >> 8) & 1) !== 0);
    for ($i = 9; $i < 15; $i++) $set(14 - $i, 8, (($format >> $i) & 1) !== 0);
    for ($i = 0; $i < 8; $i++) $set($size - 1 - $i, 8, (($format >> $i) & 1) !== 0);
    for ($i = 8; $i < 15; $i++) $set(8, $size - 15 + $i, (($format >> $i) & 1) !== 0);
    $set(8, $size - 8, true);

    $quiet = 4; $rects = [];
    for ($y = 0; $y < $size; $y++) for ($x = 0; $x < $size; $x++) if ($matrix[$y][$x] === true) $rects[] = 'M' . ($x + $quiet) . ',' . ($y + $quiet) . 'h1v1h-1z';
    $view = $size + 2 * $quiet;
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $view . ' ' . $view . '" role="img" aria-label="Authenticator setup QR code" shape-rendering="crispEdges"><rect width="100%" height="100%" fill="#fff"/><path d="' . implode('', $rects) . '" fill="#000"/></svg>';
}

function requireCredentialTwoFactor(): void
{
    $user = currentUser();
    if (!$user) return;
    try {
        $state = credentialTotpState((int)$user['id']);
    } catch (Throwable $exception) {
        error_log('Credential TOTP authorization state unavailable: ' . get_class($exception) . ' at ' . basename($exception->getFile()) . ':' . $exception->getLine());
        http_response_code(503);
        exit('Credential access is temporarily unavailable.');
    }
    if (!(int)$state['required']) return;
    $session = $_SESSION['credential_totp_verified'] ?? null;
    $verified = is_array($session)
        && (int)($session['user_id'] ?? 0) === (int)$user['id']
        && (int)($session['generation'] ?? -1) === (int)$state['generation']
        && time() - (int)($session['verified_at'] ?? 0) <= CREDENTIAL_TOTP_SESSION_SECONDS
        && time() >= (int)($session['verified_at'] ?? 0);
    if (($state['status'] ?? null) === 'active' && $verified) return;
    unset($_SESSION['credential_totp_verified']);
    credentialTotpRememberReturnTarget();
    if (($state['status'] ?? null) !== 'active' && canAdmin()) {
        header('Location: credential-totp-setup.php?user_id=' . (int)$user['id']);
        exit;
    }
    header('Location: credential-totp-setup.php');
    exit;
}

function credentialTotpSessionVerified(): bool
{
    $user = currentUser();
    if (!$user) return false;
    try {
        $state = credentialTotpState((int)$user['id']);
    } catch (Throwable $exception) {
        error_log('Credential TOTP session state unavailable: ' . get_class($exception) . ' at ' . basename($exception->getFile()) . ':' . $exception->getLine());
        return false;
    }
    if (!(int)$state['required']) return true;
    $session = $_SESSION['credential_totp_verified'] ?? null;
    return ($state['status'] ?? null) === 'active' && is_array($session)
        && (int)($session['user_id'] ?? 0) === (int)$user['id']
        && (int)($session['generation'] ?? -1) === (int)$state['generation']
        && time() - (int)($session['verified_at'] ?? 0) <= CREDENTIAL_TOTP_SESSION_SECONDS
        && time() >= (int)($session['verified_at'] ?? 0);
}

function credentialTotpVerifyAttempt(int $userId, string $submitted, bool $enrollment, bool $establishSession = true): bool
{
    $db = db();
    $db->beginTransaction();
    try {
        $state = credentialTotpState($userId, true);
        if (!(int)$state['required'] || ($state['status'] !== 'active' && !$enrollment)) throw new DomainException('Credential verification is not available.');
        if ($enrollment && credentialTotpPendingExpired($state)) {
            $db->prepare("UPDATE user_credential_totp SET status='reset_required',pending_secret_enc=NULL,generation=generation+1,last_accepted_step=-1,failed_attempts=0,locked_until=NULL WHERE user_id=? AND status='pending'")
                ->execute([$userId]);
            $db->commit();
            unset($_SESSION['credential_totp_verified']);
            return false;
        }
        if ($state['locked_until'] && strtotime((string)$state['locked_until']) > time()) throw new DomainException('Too many attempts. Try again later.');
        $encoded = $enrollment ? $state['pending_secret_enc'] : $state['secret_enc'];
        if (!is_string($encoded) || $encoded === '') throw new DomainException('Begin a new authenticator enrollment.');
        $secret = decryptCredentialValue($encoded);
        $step = credentialTotpMatchedStep($secret, $submitted);
        if ($step === null || ($state['last_accepted_step'] !== null && $step <= (int)$state['last_accepted_step'])) {
            $failures = min(CREDENTIAL_TOTP_MAX_FAILURES, (int)$state['failed_attempts'] + 1);
            $lockUntil = $failures >= CREDENTIAL_TOTP_MAX_FAILURES ? date('Y-m-d H:i:s', time() + CREDENTIAL_TOTP_LOCK_SECONDS) : null;
            $db->prepare('UPDATE user_credential_totp SET failed_attempts=?,locked_until=? WHERE user_id=?')->execute([$failures, $lockUntil, $userId]);
            $db->commit();
            return false;
        }
        if ($enrollment) {
            $stmt = $db->prepare("UPDATE user_credential_totp SET secret_enc=pending_secret_enc,pending_secret_enc=NULL,status='active',last_accepted_step=?,failed_attempts=0,locked_until=NULL WHERE user_id=? AND status='pending'");
        } else {
            $stmt = $db->prepare('UPDATE user_credential_totp SET last_accepted_step=?,failed_attempts=0,locked_until=NULL WHERE user_id=? AND status=\'active\' AND last_accepted_step < ?');
        }
        $enrollment ? $stmt->execute([$step, $userId]) : $stmt->execute([$step, $userId, $step]);
        if ($stmt->rowCount() !== 1) { $db->rollBack(); return false; }
        $generation = (int)$state['generation'];
        $db->commit();
        if ($establishSession) $_SESSION['credential_totp_verified'] = ['user_id' => $userId, 'generation' => $generation, 'verified_at' => time()];
        return true;
    } catch (Throwable $exception) {
        if ($db->inTransaction()) $db->rollBack();
        throw $exception;
    }
}
