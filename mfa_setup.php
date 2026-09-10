<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_login();

$user   = current_user();
$userId = (int)$user['id'];

$error         = null;
$notice        = null;
$recoveryCodes = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'start_enroll') {
        $enroll = Mfa::beginEnrollment($user['email']);
        $_SESSION['mfa_enroll_secret'] = $enroll['secret'];
    } elseif ($action === 'cancel_enroll') {
        unset($_SESSION['mfa_enroll_secret']);
    } elseif ($action === 'confirm_enroll') {
        $secret = $_SESSION['mfa_enroll_secret'] ?? null;
        $code   = trim($_POST['code'] ?? '');
        if ($secret && Mfa::confirmEnrollment($userId, $secret, $code)) {
            unset($_SESSION['mfa_enroll_secret']);
            $recoveryCodes = Mfa::generateRecoveryCodes($userId);
            $notice = 'To-faktor login er aktiveret. Gem genoprettelseskoderne herunder et sikkert sted.';
        } else {
            $error = 'Forkert kode - prøv igen (koden skifter hvert 30. sekund).';
        }
    } elseif ($action === 'disable' || $action === 'regenerate_codes') {
        $password = (string)($_POST['password'] ?? '');
        $bd = db()->one('SELECT password_hash FROM users WHERE id = ?', [$userId]);
        if (!$bd || !$bd['password_hash'] || !password_verify($password, (string)$bd['password_hash'])) {
            $error = 'Forkert kodeord.';
        } elseif ($action === 'disable') {
            Mfa::disable($userId);
            $notice = 'To-faktor login er deaktiveret.';
        } else {
            $recoveryCodes = Mfa::generateRecoveryCodes($userId);
            $notice = 'Nye genoprettelseskoder er genereret - de gamle virker ikke længere.';
        }
    }
}

$state   = Mfa::state($userId);
$enabled = (bool)($state['mfa_enabled'] ?? false);

$pendingSecret = !$enabled ? ($_SESSION['mfa_enroll_secret'] ?? null) : null;
$pendingUri    = $pendingSecret ? Totp::provisioningUri($pendingSecret, $user['email'], 'Equilive') : null;

render_header('To-faktor login', '');
?>
<h1>To-faktor login</h1>

<?php if ($notice): ?><div class="notice ok"><?= h($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>

<?php if ($recoveryCodes): ?>
    <div class="notice">
        <strong>Dine genoprettelseskoder</strong> - brug én af dem til at logge ind, hvis du ikke har adgang
        til din authenticator-app. Hver kode kan kun bruges én gang. De vises kun her og nu - gem dem et sikkert sted
        (fx en adgangskodemanager), print dem, eller skriv dem ned.
        <ul style="font-family:monospace;font-size:1.05rem;columns:2;margin:.8rem 0 0">
            <?php foreach ($recoveryCodes as $code): ?>
                <li><?= h($code) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($enabled): ?>
    <section style="max-width:480px">
        <p class="notice ok" style="display:inline-block">To-faktor login er aktiveret
            <?php if ($state['mfa_enrolled_at']): ?>(siden <?= h($state['mfa_enrolled_at']) ?>)<?php endif; ?>.</p>
        <p class="muted">Genoprettelseskoder tilbage: <?= (int)Mfa::recoveryCodesRemaining($userId) ?> af <?= Mfa::RECOVERY_CODE_COUNT ?>.</p>

        <h2>Generér nye genoprettelseskoder</h2>
        <p class="muted">Ugyldiggør alle nuværende koder og laver et nyt sæt - brug det hvis du er ved at løbe tør, eller har mistanke om at nogen har set dem.</p>
        <form method="post" style="display:flex;gap:.5rem;align-items:end;flex-wrap:wrap"><?= csrf_field() ?>
            <input type="hidden" name="action" value="regenerate_codes">
            <label class="muted" style="font-size:.85rem;display:flex;flex-direction:column;gap:.3rem">
                Bekræft med dit kodeord
                <input type="password" name="password" required autocomplete="current-password">
            </label>
            <button class="btn" type="submit">Generér nye koder</button>
        </form>

        <h2>Deaktivér to-faktor login</h2>
        <p class="muted">Du logger herefter kun ind med email og kodeord.</p>
        <form method="post" style="display:flex;gap:.5rem;align-items:end;flex-wrap:wrap"
              onsubmit="return confirm('Sikker på du vil deaktivere to-faktor login?');"><?= csrf_field() ?>
            <input type="hidden" name="action" value="disable">
            <label class="muted" style="font-size:.85rem;display:flex;flex-direction:column;gap:.3rem">
                Bekræft med dit kodeord
                <input type="password" name="password" required autocomplete="current-password">
            </label>
            <button class="btn" type="submit">Deaktivér</button>
        </form>
    </section>
<?php elseif ($pendingSecret): ?>
    <section style="max-width:480px">
        <p class="muted">Scan QR-koden med Microsoft Authenticator (eller en anden authenticator-app) - vælg
            "Tilføj konto" → "Andet". Kan du ikke scanne, så indtast koden manuelt i stedet.</p>

        <div id="mfa-qr" data-uri="<?= h($pendingUri) ?>" style="margin:1rem 0"></div>
        <p class="muted">Kan ikke scanne? Indtast denne kode manuelt:</p>
        <p style="font-family:monospace;font-size:1.1rem;letter-spacing:.05em"><?= h(chunk_split($pendingSecret, 4, ' ')) ?></p>

        <form method="post" style="display:flex;gap:.5rem;align-items:end;flex-wrap:wrap;margin-top:1rem"><?= csrf_field() ?>
            <input type="hidden" name="action" value="confirm_enroll">
            <label class="muted" style="font-size:.85rem;display:flex;flex-direction:column;gap:.3rem">
                Kode fra appen
                <input type="text" name="code" inputmode="numeric" pattern="[0-9]*" maxlength="6" required autofocus>
            </label>
            <button class="btn" type="submit">Bekræft og aktivér</button>
        </form>
        <form method="post" style="margin-top:.5rem"><?= csrf_field() ?>
            <input type="hidden" name="action" value="cancel_enroll">
            <button type="submit" class="muted"
                    style="background:none;border:none;padding:0;text-decoration:underline;cursor:pointer;font-size:.85rem">
                Annullér
            </button>
        </form>
    </section>

    <script src="<?= h(asset_version('assets/vendor/qrcodejs/qrcode.min.js')) ?>" defer></script>
    <script defer>
    document.addEventListener('DOMContentLoaded', function () {
        var el = document.getElementById('mfa-qr');
        if (el && window.QRCode) {
            new QRCode(el, { text: el.dataset.uri, width: 200, height: 200 });
        }
    });
    </script>
<?php else: ?>
    <section style="max-width:480px">
        <p class="muted">Beskyt din konto med et ekstra login-trin via en authenticator-app (fx Microsoft
            Authenticator eller Google Authenticator). Du får samtidig et sæt genoprettelseskoder, og kan bruge en
            engangskode sendt til din email, hvis appen ikke er tilgængelig.</p>
        <form method="post"><?= csrf_field() ?>
            <input type="hidden" name="action" value="start_enroll">
            <button class="btn" type="submit">Aktivér to-faktor login</button>
        </form>
    </section>
<?php endif; ?>
<?php
render_footer();
