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
    if ($name === '' || $username === '' || strlen($password) < 8) {
        $error = 'Name, username and a password of at least 8 characters are required.';
    } else {
        $stmt = db()->prepare('INSERT INTO users (full_name, username, password_hash, role) VALUES (?, ?, ?, "admin")');
        $stmt->execute([$name, $username, password_hash($password, PASSWORD_DEFAULT)]);
        header('Location: login.php'); exit;
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
<label>Password</label><input type="password" name="password" minlength="8" required>
<button class="btn btn-glow">Create Admin</button>
</form></div></body></html>
