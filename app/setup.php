<?php
require_once __DIR__ . '/auth.php';

if ((int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
    exit('Initial setup is already complete. <a href="login.php">Login</a>');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $name = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if ($name === '' || strlen($name)>150 || $username === '' || strlen($username)>100 || strlen($password) < 12) {
        $error = 'Enter a valid name, username and a password of at least 12 characters.';
    } else {
        $pdo = db();
        $lockName = hash('sha256', 'inventory-bootstrap:' . $pdo->query('SELECT DATABASE()')->fetchColumn());
        $lock = $pdo->prepare('SELECT GET_LOCK(?,10)');
        $lock->execute([$lockName]);
        $locked = (int)$lock->fetchColumn() === 1;
        try {
            if (!$locked) throw new DomainException('Setup is busy. Please try again.');
            $pdo->beginTransaction();
            if ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) throw new DomainException('Initial setup is already complete. Sign in to continue.');
            $stmt = $pdo->prepare('INSERT INTO users (full_name, username, password_hash, role) VALUES (?, ?, ?, "admin")');
            $stmt->execute([$name, $username, password_hash($password, PASSWORD_DEFAULT)]);
            audit('initial_admin_created', 'user', (int)$pdo->lastInsertId(), $username);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $exception instanceof DomainException ? $exception->getMessage() : 'Unable to complete setup.';
        } finally {
            if ($locked) $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
        }
        if ($error === '') { header('Location: login.php'); exit; }
    }
}
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400..800&display=swap" rel="stylesheet"><title>Setup</title><link rel="stylesheet" href="style.css?v=<?=filemtime(__DIR__ . '/style.css')?>"></head>
<body class="auth-page"><div class="auth-card">
<div class="brand"><span class="brand-mark">◆</span><div>INVENTORY<span>MANAGER</span></div></div>
<h2>First Admin</h2>
<?php if ($error): ?><div class="alert"><?=htmlspecialchars($error)?></div><?php endif; ?>
<form method="post">
<input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf())?>">
<label>Full name</label><input name="full_name" required>
<label>Username</label><input name="username" required>
<label>Password</label><input type="password" name="password" minlength="12" required>
<button class="btn btn-glow">Create Admin</button>
</form></div></body></html>
