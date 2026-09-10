<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

$next = trim($_POST['next'] ?? $_GET['next'] ?? '');
// Kun tilladt som lokal, relativ sti - undgaa at "next" bruges til open redirect.
if ($next !== '' && (str_starts_with($next, '//') || preg_match('#^[a-z][a-z0-9+.-]*://#i', $next) || !str_starts_with($next, '/'))) {
    $next = '';
}
$error  = null;
$notice = null;

if (current_user() !== null) {
    header('Location: ' . ($next !== '' ? $next : url('')));
    exit;
}

if (($_GET['timeout'] ?? '') === '1') {
    $notice = 'Din session er udløbet af sikkerhedshensyn. Log venligst ind igen.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    // trim() beskytter mod et kopieret kodeord der har fået et ekstra mellemrum
    // med (fx ved manuel markering fra en "Kopiér"-visning) - et rigtigt
    // kodeord her har aldrig indledende/afsluttende mellemrum i forvejen.
    $password = trim((string)($_POST['password'] ?? ''));
    $ip       = client_ip();

    if (login_ip_rate_limited($ip)) {
        $error = 'For mange loginforsøg fra din forbindelse. Prøv igen om lidt.';
    } else {
        $user        = db()->one('SELECT * FROM users WHERE email = ?', [$email]);
        $lockedUntil = $user ? account_locked_until((int)$user['id']) : null;

        if ($lockedUntil !== null) {
            log_login_attempt($ip, $email, false);
            $error = 'Kontoen er midlertidigt låst pga. for mange forkerte loginforsøg. Prøv igen efter '
                . date('H:i', strtotime($lockedUntil)) . '.';
        } elseif ($user && $user['is_active'] && $user['password_hash'] && password_verify($password, $user['password_hash'])) {
            log_login_attempt($ip, $email, true);
            reset_failed_login((int)$user['id']);

            if (Mfa::isEnabled((int)$user['id'])) {
                // Login er ikke fuldført endnu - kodeordet er rigtigt, men
                // to-faktor-koden mangler. Ingen $_SESSION['user'] sættes før
                // mfa_verify.php har godkendt en kode.
                session_regenerate_id(true);
                $_SESSION['mfa_pending'] = [
                    'user_id'    => (int)$user['id'],
                    'started_at' => time(),
                    'next'       => $next,
                ];
                header('Location: ' . url('mfa_verify.php'));
                exit;
            }

            complete_login($user);
            header('Location: ' . ($next !== '' ? $next : url('')));
            exit;
        } else {
            log_login_attempt($ip, $email, false);
            if ($user) {
                register_failed_login((int)$user['id']);
            }
            $error = 'Forkert email eller adgangskode.';
        }
    }
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

        <?php if ($notice && !$error): ?><div class="notice"><?= h($notice) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>

        <form method="post" class="login-form"><?= csrf_field() ?>
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
