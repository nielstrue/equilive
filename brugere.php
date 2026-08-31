<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_permission('USER_READ');

$bruger = current_user();
$error  = null;
$ok     = null;

/** Viser et kodeord med en "Kopiér"-knap ved siden af (til de éngangs-visninger
 *  af et nyt 1. gangs-kodeord der ikke sendes nogen andre steder hen). */
function kodeord_visning(string $kodeord): string {
    static $n = 0;
    $id = 'kw_' . (++$n);
    // Bevidst ingen mellemrum/tekst mellem </code> og <button> i selve HTML'en
    // (kun CSS margin) - ellers risikerer en manuel markering af kodeordet at
    // få et ekstra mellemrum med, som ville give "forkert kodeord" ved login.
    return '<code id="' . $id . '">' . h($kodeord) . '</code>'
        . '<button type="button" class="btn" style="margin-left:.4rem" onclick="kopierKodeord(\'' . $id . '\', this)">Kopiér</button>';
}

/** Knap der sender adgangsmailen (Mailer::sendAccessMail) for en konkret bruger.
 *  Kodeordet er kun i hukommelsen for DENNE ene request (det er hashet i DB),
 *  saa det maa lægges i et skjult felt for at kunne genbruges naar admin
 *  trykker knappen - lige saa synligt som kodeord_visning() ovenfor, ikke mere. */
function send_mail_knap(int $userId, string $kodeord): string {
    return '<form method="post" style="display:inline-block;margin-left:.6rem">' . csrf_field()
        . '<input type="hidden" name="action" value="send_access_mail">'
        . '<input type="hidden" name="id" value="' . $userId . '">'
        . '<input type="hidden" name="kodeord" value="' . h($kodeord) . '">'
        . '<button class="btn" type="submit">Send adgangsmail</button>'
        . '</form>';
}

/** Sand hvis $id er den eneste aktive admin - bruges til at forhindre at man
 *  låser sig selv (eller alle) ude af admin-adgangen ved en fejlklik. */
function er_sidste_aktive_admin(int $id, string $rolle, bool $aktiv): bool {
    if ($rolle !== 'admin' || !$aktiv) {
        return false;
    }
    $antal = (int)db()->scalar(
        "SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1 AND id != ?",
        [$id]
    );
    return $antal === 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create_user') {
            require_permission('USER_WRITE');

            $navn    = trim($_POST['navn'] ?? '');
            $adresse = trim($_POST['adresse'] ?? '');
            $email   = trim($_POST['email'] ?? '');
            $rolle   = $_POST['rolle'] ?? 'user';
            $kodeord = (string)($_POST['kodeord'] ?? '');

            if ($navn === '' || $email === '') {
                throw new InvalidArgumentException('Navn og email skal udfyldes.');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Email-adressen er ikke gyldig.');
            }
            if (!in_array($rolle, known_roles(), true)) {
                throw new InvalidArgumentException('Ukendt rolle.');
            }
            if ($fejl = validate_password($kodeord)) {
                throw new InvalidArgumentException($fejl);
            }
            if (db()->scalar('SELECT id FROM users WHERE email = ?', [$email]) !== false) {
                throw new InvalidArgumentException('Der findes allerede en bruger med den email.');
            }

            db()->run(
                'INSERT INTO users (name, address, email, password_hash, role, is_active, activated_at)
                 VALUES (?, ?, ?, ?, ?, 1, NOW())',
                [$navn, $adresse, $email, password_hash($kodeord, PASSWORD_DEFAULT), $rolle]
            );
            $nyId = db()->lastId();
            ensure_equilive_user_state((int)$nyId);
            db()->run('UPDATE equilive_user_state SET must_change_password = 1 WHERE user_id = ?', [$nyId]);
            $ok = 'Bruger oprettet: '
                . '"' . h($email) . '" / ' . kodeord_visning($kodeord) . ' - skal skiftes ved 1. login. '
                . 'Giv brugeren oplysningerne manuelt, eller' . send_mail_knap((int)$nyId, $kodeord);

        } elseif ($action === 'update_user') {
            require_permission('USER_WRITE');

            $id      = (int)($_POST['id'] ?? 0);
            $navn    = trim($_POST['navn'] ?? '');
            $adresse = trim($_POST['adresse'] ?? '');
            $email   = trim($_POST['email'] ?? '');
            $rolle   = $_POST['rolle'] ?? 'user';

            $target = db()->one('SELECT * FROM users WHERE id = ?', [$id]);
            if (!$target) {
                throw new InvalidArgumentException('Bruger ikke fundet.');
            }
            if ($navn === '' || $email === '') {
                throw new InvalidArgumentException('Navn og email skal udfyldes.');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Email-adressen er ikke gyldig.');
            }
            if (!in_array($rolle, known_roles(), true)) {
                throw new InvalidArgumentException('Ukendt rolle.');
            }
            if (db()->scalar('SELECT id FROM users WHERE email = ? AND id != ?', [$email, $id]) !== false) {
                throw new InvalidArgumentException('Der findes allerede en anden bruger med den email.');
            }
            if ($rolle !== 'admin' && $id === (int)$bruger['id'] && $target['role'] === 'admin') {
                throw new InvalidArgumentException('Du kan ikke fjerne din egen admin-rolle.');
            }
            if ($rolle !== 'admin' && er_sidste_aktive_admin($id, $target['role'], (bool)$target['is_active'])) {
                throw new InvalidArgumentException('Kan ikke fjerne admin-rollen fra den sidste aktive admin.');
            }

            db()->run('UPDATE users SET name = ?, address = ?, email = ?, role = ? WHERE id = ?',
                [$navn, $adresse, $email, $rolle, $id]);
            $ok = 'Bruger opdateret.';

        } elseif ($action === 'toggle_active') {
            require_permission('USER_WRITE');

            $id     = (int)($_POST['id'] ?? 0);
            $aktiv  = !empty($_POST['aktiv']) ? 1 : 0;
            $target = db()->one('SELECT * FROM users WHERE id = ?', [$id]);
            if (!$target) {
                throw new InvalidArgumentException('Bruger ikke fundet.');
            }
            if (!$aktiv && $id === (int)$bruger['id']) {
                throw new InvalidArgumentException('Du kan ikke deaktivere din egen bruger.');
            }
            if (!$aktiv && er_sidste_aktive_admin($id, $target['role'], (bool)$target['is_active'])) {
                throw new InvalidArgumentException('Kan ikke deaktivere den sidste aktive admin.');
            }

            db()->run('UPDATE users SET is_active = ? WHERE id = ?', [$aktiv, $id]);
            $ok = $aktiv ? 'Bruger aktiveret.' : 'Bruger deaktiveret.';

        } elseif ($action === 'reset_password') {
            require_permission('USER_WRITE');

            $id     = (int)($_POST['id'] ?? 0);
            $target = db()->one('SELECT email FROM users WHERE id = ?', [$id]);
            if (!$target) {
                throw new InvalidArgumentException('Bruger ikke fundet.');
            }
            $nyt = suggest_password();
            db()->run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($nyt, PASSWORD_DEFAULT), $id]);
            ensure_equilive_user_state($id);
            db()->run('UPDATE equilive_user_state SET must_change_password = 1 WHERE user_id = ?', [$id]);
            $ok = 'Nyt 1. gangs-kodeord sat for "' . h($target['email']) . '": ' . kodeord_visning($nyt)
                . ' - skal skiftes ved næste login.' . send_mail_knap($id, $nyt);

        } elseif ($action === 'send_access_mail') {
            require_permission('USER_WRITE');

            $id      = (int)($_POST['id'] ?? 0);
            $kodeord = (string)($_POST['kodeord'] ?? '');
            $target  = db()->one('SELECT name, email FROM users WHERE id = ?', [$id]);
            if (!$target) {
                throw new InvalidArgumentException('Bruger ikke fundet.');
            }
            if ($kodeord === '') {
                throw new InvalidArgumentException(
                    'Kodeordet er ikke længere tilgængeligt (siden er nok blevet genindlæst) - '
                    . 'sæt et nyt 1. gangs-kodeord og prøv igen.'
                );
            }
            $fejl = Mailer::sendAccessMail($target['email'], $target['name'], $kodeord);
            if ($fejl !== null) {
                throw new RuntimeException($fejl);
            }
            $ok = 'Adgangsmail sendt til "' . h($target['email']) . '".';

        } elseif ($action === 'delete_user') {
            require_permission('USER_DELETE');

            $id     = (int)($_POST['id'] ?? 0);
            $target = db()->one('SELECT * FROM users WHERE id = ?', [$id]);
            if (!$target) {
                throw new InvalidArgumentException('Bruger ikke fundet.');
            }
            if ($id === (int)$bruger['id']) {
                throw new InvalidArgumentException('Du kan ikke slette din egen bruger.');
            }
            if (er_sidste_aktive_admin($id, $target['role'], (bool)$target['is_active'])) {
                throw new InvalidArgumentException('Kan ikke slette den sidste aktive admin.');
            }

            db()->run('DELETE FROM equilive_user_state WHERE user_id = ?', [$id]);
            db()->run('DELETE FROM users WHERE id = ?', [$id]);
            $ok = 'Bruger slettet.';

        } elseif ($action === 'update_role_permissions') {
            require_permission('ADMIN_ACCESS');

            $indsendt = (array)($_POST['perms'] ?? []);
            db()->begin();
            try {
                foreach (known_roles() as $rolle) {
                    $valgt = array_values(array_intersect(
                        array_map('strval', (array)($indsendt[$rolle] ?? [])),
                        known_permissions()
                    ));
                    if ($rolle === 'admin' && !in_array('ADMIN_ACCESS', $valgt, true)) {
                        throw new InvalidArgumentException(
                            'Admin-rollen skal altid have rettigheden ADMIN_ACCESS - ellers kan ingen rette dette igen.'
                        );
                    }
                    db()->run('DELETE FROM equilive_role_permissions WHERE role = ?', [$rolle]);
                    foreach ($valgt as $perm) {
                        db()->run('INSERT INTO equilive_role_permissions (role, permission) VALUES (?, ?)', [$rolle, $perm]);
                    }
                }
                db()->commit();
            } catch (Throwable $e) {
                db()->rollBack();
                throw $e;
            }
            invalidate_role_permissions_cache();
            $ok = 'Rolle-rettigheder opdateret.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$brugere = db()->all(
    'SELECT u.*,
            COALESCE(s.must_change_password, 0) AS must_change_password,
            s.last_login_at,
            COALESCE(s.login_count, 0) AS login_count
     FROM users u
     LEFT JOIN equilive_user_state s ON s.user_id = u.id
     ORDER BY u.name'
);

render_header('Brugere', 'brugere');
?>
<h1>Brugere</h1>
<p class="muted">Roller: Admin (fuld adgang), Editor (kan se og redigere brugere, men ikke slette dem
    eller tilgå Import/fletning), Bruger og Kun læsning (kan begge se rapporter, ingen administrativ adgang).</p>

<?php if ($error): ?><div class="notice error"><strong>Fejl:</strong> <?= h($error) ?></div><?php endif; ?>
<?php if ($ok): ?><div class="notice ok"><?= $ok ?></div><?php endif; ?>

<?php if (has_permission('USER_WRITE')): ?>
<h2>Opret ny bruger</h2>
<form method="post" style="max-width:480px;display:flex;flex-direction:column;gap:.7rem"><?= csrf_field() ?>
    <input type="hidden" name="action" value="create_user">
    <label class="muted" style="font-size:.85rem;display:flex;flex-direction:column;gap:.3rem">Navn
        <input type="text" name="navn" required>
    </label>
    <label class="muted" style="font-size:.85rem;display:flex;flex-direction:column;gap:.3rem">Adresse
        <input type="text" name="adresse">
    </label>
    <label class="muted" style="font-size:.85rem;display:flex;flex-direction:column;gap:.3rem">Email
        <input type="email" name="email" required>
    </label>
    <label class="muted" style="font-size:.85rem;display:flex;flex-direction:column;gap:.3rem">Rolle
        <select name="rolle" style="width:auto">
            <?php foreach (known_roles() as $r): ?>
                <option value="<?= h($r) ?>" <?= $r === 'user' ? 'selected' : '' ?>><?= h(role_label($r)) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label class="muted" style="font-size:.85rem;display:flex;flex-direction:column;gap:.3rem">1. gangs-kodeord
        <span style="display:flex;gap:.4rem">
            <input type="text" name="kodeord" id="n_kodeord" required style="font-family:ui-monospace,Consolas,monospace;flex:1">
            <button type="button" class="btn" onclick="foreslaaKodeord()">Foreslå</button>
        </span>
    </label>
    <div class="mini muted">Kodeordet skal opfylde kravene (mindst 8 tegn, ét stort bogstav, ét tal) og skal
        skiftes af brugeren ved 1. login. Der sendes ikke mail automatisk - efter oprettelsen kan du
        enten give oplysningerne manuelt, eller sende dem via knappen "Send adgangsmail".</div>
    <button class="btn" type="submit" style="align-self:flex-start">Opret bruger</button>
</form>
<?php endif; ?>

<h2>Alle brugere <span class="mini muted">(<?= count($brugere) ?>)</span></h2>
<table class="data">
    <thead>
        <tr>
            <th>Navn</th><th>Email</th><th>Rolle</th><th>Status</th><th>Oprettet</th>
            <th class="r">Logins</th><th>Sidste login</th><th></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($brugere as $u): $formId = 'user-form-' . (int)$u['id']; ?>
        <tr>
            <td>
                <?php if (has_permission('USER_WRITE')): ?>
                    <input type="text" name="navn" form="<?= h($formId) ?>" value="<?= h($u['name']) ?>" size="16" required>
                <?php else: ?>
                    <?= h($u['name']) ?>
                <?php endif; ?>
            </td>
            <td>
                <?php if (has_permission('USER_WRITE')): ?>
                    <input type="email" name="email" form="<?= h($formId) ?>" value="<?= h($u['email']) ?>" size="20" required>
                <?php else: ?>
                    <?= h($u['email']) ?>
                <?php endif; ?>
            </td>
            <td>
                <?php if (has_permission('USER_WRITE')): ?>
                    <select name="rolle" form="<?= h($formId) ?>">
                        <?php foreach (known_roles() as $r): ?>
                            <option value="<?= h($r) ?>" <?= $r === $u['role'] ? 'selected' : '' ?>><?= h(role_label($r)) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <?= h(role_label($u['role'])) ?>
                <?php endif; ?>
            </td>
            <td class="mini">
                <?= $u['is_active'] ? '<span class="ja">Aktiv</span>' : '<span class="nej">Inaktiv</span>' ?>
                <?php if ($u['must_change_password']): ?>
                    <br><span class="badge badge-warn">Afventer 1. login</span>
                <?php endif; ?>
            </td>
            <td class="mini"><?= h(substr((string)$u['created_at'], 0, 10)) ?></td>
            <td class="r mini"><?= (int)$u['login_count'] ?></td>
            <td class="mini"><?= $u['last_login_at'] ? h(str_replace('T', ' ', substr((string)$u['last_login_at'], 0, 16))) : '–' ?></td>
            <td class="mini" style="white-space:nowrap">
                <?php if (has_permission('USER_WRITE')): ?>
                    <button class="btn" type="submit" form="<?= h($formId) ?>">Gem</button>
                    <form method="post" style="display:inline" onsubmit="return confirm('Sæt nyt 1. gangs-kodeord for <?= h(addslashes($u['email'])) ?>?')"><?= csrf_field() ?>
                        <input type="hidden" name="action" value="reset_password">
                        <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                        <button class="btn" type="submit">Nyt kodeord</button>
                    </form>
                    <form method="post" style="display:inline"><?= csrf_field() ?>
                        <input type="hidden" name="action" value="toggle_active">
                        <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                        <input type="hidden" name="aktiv" value="<?= $u['is_active'] ? 0 : 1 ?>">
                        <button class="btn" type="submit"><?= $u['is_active'] ? 'Deaktiver' : 'Aktiver' ?></button>
                    </form>
                <?php endif; ?>
                <?php if (has_permission('USER_DELETE')): ?>
                    <form method="post" style="display:inline" onsubmit="return confirm('Slet <?= h(addslashes($u['email'])) ?> permanent?')"><?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete_user">
                        <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                        <button class="btn" type="submit">Slet</button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$brugere): ?>
        <tr><td colspan="8" class="muted">Ingen brugere.</td></tr>
    <?php endif; ?>
    </tbody>
</table>

<?php if (has_permission('USER_WRITE')): ?>
    <?php foreach ($brugere as $u): ?>
        <form id="user-form-<?= (int)$u['id'] ?>" method="post">
            <input type="hidden" name="action" value="update_user">
            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <input type="hidden" name="adresse" value="<?= h($u['address']) ?>">
        </form>
    <?php endforeach; ?>
<?php endif; ?>

<?php if (has_permission('ADMIN_ACCESS')): $perms = role_permissions(); ?>
<h2 style="margin-top:2rem">Rolle-rettigheder</h2>
<p class="muted">Styrer hvad hver rolle må - fx om Editor også skal kunne slette brugere, eller om
    en anden rolle skal have fuld admin-adgang. Ændringer gælder med det samme, også for brugere
    der allerede er logget ind.</p>
<form method="post"><?= csrf_field() ?>
    <input type="hidden" name="action" value="update_role_permissions">
    <table class="data" style="max-width:640px">
        <thead>
            <tr>
                <th>Rolle</th>
                <?php foreach (known_permissions() as $p): ?>
                    <th title="<?= h(permission_label($p)) ?>"><?= h($p) ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach (known_roles() as $rolle): ?>
            <tr>
                <td><?= h(role_label($rolle)) ?></td>
                <?php foreach (known_permissions() as $p): ?>
                    <?php $laast = $rolle === 'admin' && $p === 'ADMIN_ACCESS'; ?>
                    <td class="r">
                        <input type="checkbox" name="perms[<?= h($rolle) ?>][]" value="<?= h($p) ?>"
                            title="<?= h(permission_label($p)) ?>"
                            <?= in_array($p, $perms[$rolle] ?? [], true) ? 'checked' : '' ?>
                            <?= $laast ? 'disabled' : '' ?>>
                        <?php if ($laast): ?>
                            <input type="hidden" name="perms[<?= h($rolle) ?>][]" value="<?= h($p) ?>">
                        <?php endif; ?>
                    </td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div class="mini muted" style="margin:.5rem 0">
        <?php foreach (known_permissions() as $p): ?>
            <div><strong><?= h($p) ?></strong> – <?= h(permission_label($p)) ?></div>
        <?php endforeach; ?>
    </div>
    <button class="btn" type="submit">Gem rolle-rettigheder</button>
</form>
<?php endif; ?>

<?php
render_footer();
