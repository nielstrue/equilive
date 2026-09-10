<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

if (current_user() !== null) {
    header('Location: ' . url(''));
    exit;
}

$pending = $_SESSION['mfa_pending'] ?? null;
// Login-forsøget må ikke stå åbent for evigt - 5 minutter til at indtaste koden,
// ellers skal man taste email+kodeord igen.
if (!$pending || (time() - $pending['started_at']) > 300) {
    unset($_SESSION['mfa_pending']);
    header('Location: ' . url('login.php'));
    exit;
}

$userId = (int)$pending['user_id'];
$user   = db()->one('SELECT * FROM users WHERE id = ? AND is_active = 1', [$userId]);
if (!$user) {
    unset($_SESSION['mfa_pending']);
    header('Location: ' . url('login.php'));
    exit;
}

$error  = null;
$notice = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $ip     = client_ip();

    if ($action === 'cancel') {
        unset($_SESSION['mfa_pending']);
        header('Location: ' . url('login.php'));
        exit;
    }

    if ($action === 'send_email_code') {
        $sendError = Mfa::sendEmailCode($userId, $user['email'], $user['name']);
        if ($sendError !== null) {
            $error = $sendError;
        } else {
            $notice = 'Kode sendt til ' . mask_email($user['email']) . '.';
        }
    } elseif (in_array($action, ['verify_totp', 'verify_recovery', 'verify_email'], true)) {
        $lockedUntil = account_locked_until($userId);
        if (login_ip_rate_limited($ip)) {
            $error = 'For mange forsøg fra din forbindelse. Prøv igen om lidt.';
        } elseif ($lockedUntil !== null) {
            $error = 'Kontoen er midlertidigt låst pga. for mange forkerte forsøg. Prøv igen efter '
                . date('H:i', strtotime($lockedUntil)) . '.';
        } else {
            $code = trim($_POST['code'] ?? '');
            $ok   = match ($action) {
                'verify_totp'     => Mfa::verifyTotp($userId, $code),
                'verify_recovery' => Mfa::verifyRecoveryCode($userId, $code),
                'verify_email'    => Mfa::verifyEmailCode($userId, $code),
            };

            if ($ok) {
                log_login_attempt($ip, $user['email'], true);
                complete_login($user);
                header('Location: ' . ($pending['next'] !== '' ? $pending['next'] : url('')));
                exit;
            }

            log_login_attempt($ip, $user['email'], false);
            register_failed_login($userId);
            $error = $action === 'verify_recovery' ? 'Forkert eller allerede brugt genoprettelseskode.' : 'Forkert kode.';
        }
    }
}
?><!DOCTYPE html>
<html lang="da">
<head>
    <?php html_head('Bekræft login'); ?>
</head>
<body>
<main class="auth-page">
    <div class="auth-card">
        <div class="auth-logo">
            <?= render_logo(56) ?>
            <span class="word">Equilive</span>
            <span class="tagline">To-faktor login</span>
        </div>

        <?php if ($notice && !$error): ?><div class="notice"><?= h($notice) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>

        <form method="post" class="login-form"><?= csrf_field() ?>
            <input type="hidden" name="action" value="verify_totp">
            <label>Kode fra din authenticator-app
                <input type="text" name="code" inputmode="numeric" pattern="[0-9]*" maxlength="6"
                       autocomplete="one-time-code" required autofocus>
            </label>
            <button class="btn" type="submit">Bekræft</button>
        </form>

        <details style="margin-top:1.2rem">
            <summary class="muted" style="cursor:pointer">Har du ikke adgang til appen?</summary>
            <div style="display:flex;flex-direction:column;gap:1rem;margin-top:.8rem">
                <form method="post" style="display:flex;flex-direction:column;gap:.5rem"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="verify_recovery">
                    <label class="muted" style="font-size:.85rem;display:flex;flex-direction:column;gap:.3rem">
                        Genoprettelseskode
                        <input type="text" name="code" placeholder="XXXXX-XXXXX" required>
                    </label>
                    <button class="btn" type="submit">Log ind med genoprettelseskode</button>
                </form>

                <form method="post" style="display:flex;flex-direction:column;gap:.5rem"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="verify_email">
                    <label class="muted" style="font-size:.85rem;display:flex;flex-direction:column;gap:.3rem">
                        Engangskode fra email
                        <input type="text" name="code" inputmode="numeric" pattern="[0-9]*" maxlength="6" placeholder="6 cifre">
                    </label>
                    <button class="btn" type="submit">Bekræft email-kode</button>
                </form>
                <form method="post"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="send_email_code">
                    <button class="btn" type="submit">Send engangskode til <?= h(mask_email($user['email'])) ?></button>
                </form>
            </div>
        </details>

        <form method="post" style="margin-top:1rem"><?= csrf_field() ?>
            <input type="hidden" name="action" value="cancel">
            <button type="submit" class="muted"
                    style="background:none;border:none;padding:0;text-decoration:underline;cursor:pointer;font-size:.85rem">
                Annullér og log ind igen
            </button>
        </form>
    </div>
</main>
</body>
</html>
