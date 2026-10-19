<?php
declare(strict_types=1);
require_once __DIR__ . '/credential_totp_helpers.php';
requireLogin();

header('Cache-Control: no-store, private, max-age=0, must-revalidate');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

$adminTargetFlow = array_key_exists('user_id', $_GET) || array_key_exists('target_user_id', $_POST);
$error = '';
$message = '';
$qrSvg = null;
$secret = null;

if ($adminTargetFlow) {
    if (!canAdmin()) {
        http_response_code(403);
        exit('403 Forbidden');
    }
    requirePermission('users', 'edit');
    $rawTargetId = $_GET['user_id'] ?? $_POST['target_user_id'] ?? null;
    $targetUserId = filter_var($rawTargetId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
    if (!$targetUserId) {
        http_response_code(400);
        exit('Invalid user selection.');
    }
    $targetQuery = db()->prepare('SELECT id,full_name,username,active FROM users WHERE id=? LIMIT 1');
    $targetQuery->execute([$targetUserId]);
    $targetUser = $targetQuery->fetch();
    if (!$targetUser) {
        http_response_code(404);
        exit('The selected account was not found.');
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        verifyCsrf();
        $action = (string)($_POST['action'] ?? '');
        try {
            if ($action === 'start') {
                $before = credentialTotpState($targetUserId);
                if (!(int)$before['required']) throw new DomainException('Enable the credential TOTP requirement before setting up an authenticator.');
                if (($before['status'] ?? null) === 'active') throw new DomainException('This account already has an active authenticator. Reset it before replacement.');
                $alreadyPending = ($before['status'] ?? null) === 'pending' && !credentialTotpPendingExpired($before)
                    && is_string($before['pending_secret_enc'] ?? null) && $before['pending_secret_enc'] !== '';
                credentialTotpEnsurePendingEnrollment($targetUserId);
                if (!$alreadyPending) {
                    audit('credential_totp_enrollment_started', 'user', $targetUserId, json_encode([
                        'actor_id' => (int)currentUser()['id'], 'target_user_id' => $targetUserId,
                        'target_username' => $targetUser['username'],
                    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                }
                header('Location: credential-totp-setup.php?user_id=' . $targetUserId, true, 303);
                exit;
            }
            if ($action === 'verify_enrollment') {
                $before = credentialTotpState($targetUserId);
                if (($before['status'] ?? null) !== 'pending') throw new DomainException('There is no pending authenticator enrollment to verify.');
                if (!credentialTotpVerifyAttempt($targetUserId, (string)($_POST['code'] ?? ''), true, false)) {
                    $after = credentialTotpState($targetUserId);
                    $error = credentialTotpPendingExpired($before) || ($after['status'] ?? null) === 'reset_required'
                        ? 'The setup period expired. Start a new enrollment to generate a fresh QR code.'
                        : 'The code is invalid or already used. Enter a current six-digit code from the intended user’s authenticator.';
                } else {
                    audit('credential_totp_enrollment_activated', 'user', $targetUserId, json_encode([
                        'actor_id' => (int)currentUser()['id'], 'target_user_id' => $targetUserId,
                        'target_username' => $targetUser['username'],
                    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                    setFlash('success', 'Authenticator enrollment verified and activated. The user must verify with their authenticator when accessing Credentials.');
                    header('Location: users.php?edit_user=' . $targetUserId, true, 303);
                    exit;
                }
            } else {
                throw new DomainException('Unsupported authenticator management operation.');
            }
        } catch (DomainException $exception) {
            $error = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log('Admin credential TOTP management failed: ' . get_class($exception) . ' at ' . basename($exception->getFile()) . ':' . $exception->getLine());
            $error = 'Unable to complete authenticator management. Please try again.';
        }
    }

    $state = credentialTotpPageState($targetUserId);
    $pendingExpired = credentialTotpPendingExpired($state);
    if ((int)$state['required'] && ($state['status'] ?? null) === 'pending' && !$pendingExpired
        && is_string($state['pending_secret_enc'] ?? null) && $state['pending_secret_enc'] !== '') {
        try {
            $secret = decryptCredentialValue((string)$state['pending_secret_enc']);
            $qrSvg = credentialTotpQrSvg(credentialTotpProvisioningUri($secret, (string)$targetUser['username']));
        } catch (Throwable $exception) {
            error_log('Admin credential TOTP QR generation failed: ' . get_class($exception) . ' at ' . basename($exception->getFile()) . ':' . $exception->getLine());
            $secret = null;
            $qrSvg = null;
            $error = 'Authenticator setup is temporarily unavailable.';
        }
    }

    if (($_GET['qr'] ?? '') === '1') {
        if (!canAdmin() || ($state['status'] ?? null) !== 'pending' || $pendingExpired || $qrSvg === null) {
            http_response_code(404);
            exit('Authenticator QR is unavailable.');
        }
        header('Content-Type: image/svg+xml; charset=utf-8');
        header('Content-Disposition: inline; filename="credential-authenticator.svg"');
        echo $qrSvg;
        exit;
    }

    $pageTitle = 'Credential Authenticator Setup';
    require __DIR__ . '/header.php';
    ?>
    <section class="card credential-totp-setup-page">
        <div class="section-heading"><div><p class="eyebrow">ADMINISTRATOR SETUP</p><h1>Set up Credentials authenticator</h1><p class="muted">Selected account: <strong><?=htmlspecialchars((string)$targetUser['full_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?></strong> (<?=htmlspecialchars((string)$targetUser['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>).</p></div></div>
        <?php if ($error !== ''): ?><div class="alert error" role="alert"><?=htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?></div><?php endif; ?>
        <?php if ($message !== ''): ?><div class="alert success" role="status"><?=htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?></div><?php endif; ?>
        <div class="credential-totp-summary"><span>Authenticator enrollment</span><strong><?=htmlspecialchars(credentialTotpStatusLabel($state), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?></strong></div>
        <?php if (!(int)$state['required']): ?>
            <p class="muted">Credential TOTP is not required. Enable the policy on Edit User before setting up an authenticator.</p>
        <?php elseif (($state['status'] ?? null) === 'active'): ?>
            <p>The authenticator is active. Its secret is never shown again. Reset enrollment on Edit User before replacing it.</p>
        <?php elseif ($secret !== null && $qrSvg !== null): ?>
            <div class="alert info" role="note">Have the selected user present to scan this QR code or enter the key on their authenticator device. Do not photograph, forward, or store the setup key. This setup expires in 15 minutes.</div>
            <div class="credential-totp-enrollment"><div class="credential-totp-qr"><?=$qrSvg?></div><div><p><strong>Manual setup key for <?=htmlspecialchars((string)$targetUser['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?></strong></p><code class="credential-totp-manual-key"><?=htmlspecialchars($secret, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?></code><p class="muted">Use a time-based, six-digit code with a 30-second period.</p></div></div>
            <form method="post" class="credential-totp-verify-form" autocomplete="off"><input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>"><input type="hidden" name="target_user_id" value="<?=$targetUserId?>"><input type="hidden" name="action" value="verify_enrollment"><label for="credential-totp-code">Verify enrollment with the intended user’s current code</label><input id="credential-totp-code" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required><button class="btn btn-primary" type="submit">Verify Enrollment</button></form>
        <?php else: ?>
            <?php if (($state['status'] ?? null) === 'pending' && $pendingExpired): ?><p class="muted">The previous setup expired. Starting again creates a fresh secret and QR code.</p><?php else: ?><p class="muted">Generate a unique pending secret for this account. The account is not enrolled until a current code is verified.</p><?php endif; ?>
            <form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>"><input type="hidden" name="target_user_id" value="<?=$targetUserId?>"><input type="hidden" name="action" value="start"><button class="btn btn-primary" type="submit">Set Up Authenticator</button></form>
        <?php endif; ?>
        <p class="muted"><a href="users.php?edit_user=<?=$targetUserId?>">Return to Edit User</a></p>
    </section>
    <?php
    require __DIR__ . '/footer.php';
    exit;
}

// Regular users may verify an Admin-enrolled active authenticator, but this page
// never generates, displays, replaces, resets, enables, or disables their secret.
$userId = (int)currentUser()['id'];
if (!canPerform('credentials', 'view')) {
    http_response_code(403);
    exit('403 Forbidden');
}
$state = credentialTotpPageState($userId);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verifyCsrf();
    try {
        if ((string)($_POST['action'] ?? '') !== 'verify' || !(int)$state['required'] || ($state['status'] ?? null) !== 'active') {
            throw new DomainException('Authenticator setup and policy changes must be handled by an Admin.');
        }
        if (!credentialTotpVerifyAttempt($userId, (string)($_POST['code'] ?? ''), false)) {
            $error = 'The code is invalid, expired, or already used. Check your authenticator and try a current code.';
        } else {
            $message = 'Credential access verified for this session.';
            credentialTotpRedirectAfterVerification();
        }
    } catch (DomainException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('Credential TOTP verification failed: ' . get_class($exception) . ' at ' . basename($exception->getFile()) . ':' . $exception->getLine());
        $error = 'Unable to verify the code. Please try again.';
    }
}

$pageTitle = 'Credential Security & 2FA';
require __DIR__ . '/header.php';
?>
<section class="card credential-totp-setup-page">
    <div class="section-heading"><div><p class="eyebrow">CREDENTIAL SECURITY</p><h1>Authenticator verification</h1><p class="muted">This verification protects credential features only. Inventory and other permitted modules remain available.</p></div></div>
    <?php if ($error !== ''): ?><div class="alert error" role="alert"><?=htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?></div><?php endif; ?>
    <?php if ($message !== ''): ?><div class="alert success" role="status"><?=htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?></div><?php endif; ?>
    <?php if (!(int)$state['required']): ?>
        <p class="muted">Credential 2FA is not required for this account.</p>
    <?php elseif (($state['status'] ?? null) === 'active' && credentialTotpSessionVerified()): ?>
        <p>Credential access is verified for this session.</p>
        <?php if (canPerform('credentials', 'view')): ?><a class="btn btn-primary" href="credentials.php">Continue to Credentials</a><?php else: ?><p class="muted">Your account does not currently have credential permissions. Contact an Admin if access is required.</p><?php endif; ?>
    <?php elseif (($state['status'] ?? null) === 'active'): ?>
        <p>Enter the current code from your Admin-configured authenticator. Verification lasts 15 minutes for this session.</p>
        <form method="post" class="credential-totp-verify-form" autocomplete="off"><input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>"><input type="hidden" name="action" value="verify"><label for="credential-totp-code">6-digit authenticator code</label><input id="credential-totp-code" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required><button class="btn btn-primary" type="submit">Verify and continue</button></form>
    <?php else: ?>
        <p class="muted">An Admin must finish authenticator setup for this account before credential access can continue. Your verification code cannot configure or replace an authenticator.</p>
        <?php if (canAdmin()): ?><a class="btn" href="users.php?edit_user=<?=$userId?>">Open Admin setup for this account</a><?php endif; ?>
    <?php endif; ?>
    <p class="muted credential-totp-recovery">If you lose access to your authenticator, contact an Admin. There is no self-service reset.</p>
</section>
<?php require __DIR__ . '/footer.php'; ?>
