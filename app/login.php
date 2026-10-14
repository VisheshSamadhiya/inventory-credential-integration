<?php
require_once __DIR__ . '/auth.php';

if (currentUser()) {
    header('Location: dashboard.php'); exit;
}

$error = '';
$count = (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $stmt = db()->prepare('SELECT * FROM users WHERE username = ? AND active = 1 LIMIT 1');
    $stmt->execute([trim($_POST['username'] ?? '')]);
    $user = $stmt->fetch();

    if ($user && password_verify($_POST['password'] ?? '', $user['password_hash'])) {
        session_regenerate_id(true);
        unset($_SESSION['csrf']);
        $_SESSION['user'] = [
            'id' => (int)$user['id'],
            'full_name' => $user['full_name'],
            'username' => $user['username'],
            'role' => $user['role'],
            'auth_version' => (int)$user['auth_version'],
        ];
        $_SESSION['last_activity'] = time();
        audit('login');
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
    <form method="post">
        <input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf())?>">
        <label>Username</label><input name="username" autocomplete="username" placeholder="Username" required autofocus>
        <label>Password</label><input type="password" name="password" autocomplete="current-password" placeholder="Password" required>
        <button class="btn btn-glow" type="submit">Sign in</button>
    </form>
    <?php endif; ?>
</div>
</body></html>
