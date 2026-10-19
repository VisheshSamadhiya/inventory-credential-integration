<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/login_security.php';

if (currentUser()) {
    header('Location: dashboard.php'); exit;
}

$error = '';
$notice = (string)($_SESSION['credential_recovery_notice'] ?? '');
$count = (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $username = is_string($_POST['username'] ?? null) ? trim($_POST['username']) : '';
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    try {
        $attemptAllowed = reserveLoginAttempt($username, trustedRequestIpAddress());
    } catch (Throwable $exception) {
        error_log('Login rate limiter unavailable: ' . get_class($exception));
        http_response_code(503);
        exit('Sign-in is temporarily unavailable.');
    }
    if (!$attemptAllowed) {
        http_response_code(429);
        header('Retry-After: 900');
        exit('Too many sign-in attempts. Please try again later.');
    }
    $stmt = db()->prepare('SELECT * FROM users WHERE username = ? AND active = 1 LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        clearSuccessfulLoginAttempts($username);
        $credentialRecovery = $_SESSION['credential_form_recovery'] ?? null;
        session_regenerate_id(true);
        unset($_SESSION['csrf']);
        unset($_SESSION['session_expired']);
        $_SESSION['user'] = [
            'id' => (int)$user['id'],
            'full_name' => $user['full_name'],
            'username' => $user['username'],
            'role' => $user['role'],
            'auth_version' => (int)$user['auth_version'],
        ];
        $_SESSION['last_activity'] = time();
        audit('login');
        if (is_array($credentialRecovery) && in_array(($credentialRecovery['action'] ?? ''), ['create', 'update'], true)) {
            $recoveryUrl = 'credentials.php?recover=1';
            if ($credentialRecovery['action'] === 'update' && ctype_digit((string)($credentialRecovery['credential_id'] ?? ''))) {
                $recoveryUrl .= '&edit=' . (int)$credentialRecovery['credential_id'];
            } elseif ($credentialRecovery['action'] === 'create') {
                $recoveryUrl .= '&add=1';
            }
            header('Location: ' . $recoveryUrl); exit;
        }
        header('Location: dashboard.php'); exit;
    }
    $error = 'Invalid username or password.';
}
?>
<!doctype html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400..800&display=swap" rel="stylesheet"><title>Inventory Manager</title><link rel="stylesheet" href="style.css?v=<?=filemtime(__DIR__ . '/style.css')?>"></head>
<body class="auth-page">
<div class="auth-card">
    <div class="brand"><span class="brand-mark">◆</span><div>INVENTORY<span>MANAGER</span></div></div>
    <p class="muted">Secure inventory control</p>
    <?php if ($count === 0): ?><a class="btn btn-glow" href="setup.php">Create first admin</a><?php else: ?>
    <?php if ($error): ?><div class="alert"><?=htmlspecialchars($error)?></div><?php endif; ?>
    <?php if ($notice): ?><div class="alert" role="status"><?=htmlspecialchars($notice, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?></div><?php endif; ?>
    <form method="post">
        <input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf())?>">
        <label>Username</label><input name="username" autocomplete="username" placeholder="Username" required autofocus>
        <label>Password</label><input type="password" name="password" autocomplete="current-password" placeholder="Password" required>
        <button class="btn btn-glow" type="submit">Sign in</button>
    </form>
    <?php endif; ?>
</div>
</body></html>
