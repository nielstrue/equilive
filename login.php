<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

$next = trim($_POST['next'] ?? $_GET['next'] ?? '');
// Kun tilladt som lokal, relativ sti - undgaa at "next" bruges til open redirect.
if ($next !== '' && (str_starts_with($next, '//') || preg_match('#^[a-z][a-z0-9+.-]*://#i', $next) || !str_starts_with($next, '/'))) {
    $next = '';
}
$error = null;

if (current_user() !== null) {
    header('Location: ' . ($next !== '' ? $next : url('')));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    $user = db()->one('SELECT * FROM users WHERE email = ?', [$email]);
    if ($user && $user['is_active'] && $user['password_hash'] && password_verify($password, $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id'    => (int)$user['id'],
            'name'  => $user['name'],
            'email' => $user['email'],
            'role'  => $user['role'],
        ];
        header('Location: ' . ($next !== '' ? $next : url('')));
        exit;
    }
    $error = 'Forkert email eller adgangskode.';
}
?><!DOCTYPE html>
<html lang="da">
<head>
    <?php html_head('Log ind'); ?>
</head>
<body>
<main class="auth-page">
    <div class="auth-card">
        <div class="auth-logo">
            <?= render_logo(56) ?>
            <span class="word">Equilive</span>
            <span class="tagline">Statistik for danske ridestævner</span>
        </div>

        <?php if ($error): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>

        <form method="post" class="login-form">
            <input type="hidden" name="next" value="<?= h($next) ?>">
            <label>Email
                <input type="email" name="email" required autofocus value="<?= h($_POST['email'] ?? '') ?>">
            </label>
            <label>Adgangskode
                <input type="password" name="password" required>
            </label>
            <button class="btn" type="submit">Log ind</button>
        </form>
    </div>
</main>
</body>
</html>
