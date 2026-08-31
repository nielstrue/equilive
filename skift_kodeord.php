<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

// require_login() omdirigerer IKKE til sig selv herfra (se undtagelsen for
// skift_kodeord.php i funktionen), saa denne side altid kan naas, uanset om
// brugeren har et tvunget kodeordsskift staaende.
require_login();

$bruger  = current_user();
$tvunget = (bool)db()->scalar('SELECT must_change_password FROM equilive_user_state WHERE user_id = ?', [$bruger['id']]);
$fejl    = null;
$succes  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $gammelt  = (string)($_POST['gammelt'] ?? '');
    $nyt      = (string)($_POST['nyt'] ?? '');
    $gentaget = (string)($_POST['gentaget'] ?? '');

    $bd = db()->one('SELECT password_hash FROM users WHERE id = ?', [$bruger['id']]);

    if (!$tvunget) {
        // Frivilligt skift kræver nuværende kodeord, saa en kapret session
        // ikke alene kan tage kontoen fra brugeren. Tvunget 1. gangs-skift
        // kræver det ikke - man har jo lige logget ind med det.
        if (!$bd || !$bd['password_hash'] || !password_verify($gammelt, (string)$bd['password_hash'])) {
            $fejl = 'Nuværende kodeord er forkert.';
        }
    }
    if (!$fejl && $nyt !== $gentaget) {
        $fejl = 'De to nye kodeord er ikke ens.';
    }
    if (!$fejl) {
        $fejl = validate_password($nyt);
    }
    if (!$fejl) {
        db()->run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($nyt, PASSWORD_DEFAULT), $bruger['id']]);
        ensure_equilive_user_state((int)$bruger['id']);
        db()->run('UPDATE equilive_user_state SET must_change_password = 0 WHERE user_id = ?', [$bruger['id']]);
        $succes  = true;
        $tvunget = false;
    }
}

render_header('Skift kodeord', '');
?>
<h1>Skift kodeord</h1>

<?php if ($succes): ?>
    <div class="notice ok">Kodeord opdateret.</div>
    <p><a class="btn" href="<?= h(url('')) ?>">Fortsæt til appen</a></p>
<?php else: ?>
    <?php if ($tvunget): ?>
        <p class="muted">Du skal vælge et nyt kodeord, før du kan fortsætte.</p>
    <?php endif; ?>

    <?php if ($fejl): ?><div class="notice error"><?= h($fejl) ?></div><?php endif; ?>

    <form method="post" id="f_skift" style="max-width:360px;display:flex;flex-direction:column;gap:.9rem"><?= csrf_field() ?>
        <?php if (!$tvunget): ?>
            <label class="muted" style="font-size:.85rem;display:flex;flex-direction:column;gap:.3rem">
                Nuværende kodeord
                <input type="password" name="gammelt" required autofocus autocomplete="current-password">
            </label>
        <?php endif; ?>
        <label class="muted" style="font-size:.85rem;display:flex;flex-direction:column;gap:.3rem">
            Nyt kodeord
            <input type="password" name="nyt" id="nyt" required autocomplete="new-password"
                <?= $tvunget ? 'autofocus' : '' ?>>
        </label>
        <div class="styrke-bar"><div class="styrke-fill" id="styrke_fill"></div></div>
        <div class="mini" id="styrke_tekst">&nbsp;</div>
        <ul class="krav" id="krav">
            <li id="krav_laengde">Mindst 8 tegn</li>
            <li id="krav_stort">Mindst ét stort bogstav</li>
            <li id="krav_tal">Mindst ét tal</li>
        </ul>
        <label class="muted" style="font-size:.85rem;display:flex;flex-direction:column;gap:.3rem">
            Gentag nyt kodeord
            <input type="password" name="gentaget" id="gentaget" required autocomplete="new-password">
        </label>
        <div class="mini" id="match_tekst">&nbsp;</div>
        <button class="btn" type="submit">Skift kodeord</button>
    </form>
<?php endif; ?>

<?php
render_footer();
