<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_login();

$error = null;
$ok    = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save_role') {
            $roleId      = (int)($_POST['role_id'] ?? 0);
            $alle        = !empty($_POST['alle_discipliner']) ? 1 : 0;
            $disciplines = array_values(array_filter(array_map('strval', (array)($_POST['disciplines'] ?? [])), fn($v) => $v !== ''));

            if (!$roleId) {
                throw new InvalidArgumentException('Ukendt rolle.');
            }

            db()->begin();
            try {
                db()->run('UPDATE roles SET alle_discipliner = ? WHERE id = ?', [$alle, $roleId]);
                db()->run('DELETE FROM role_disciplines WHERE role_id = ?', [$roleId]);
                if (!$alle) {
                    foreach ($disciplines as $d) {
                        db()->run('INSERT INTO role_disciplines (role_id, disciplin) VALUES (?, ?)', [$roleId, $d]);
                    }
                }
                db()->commit();
            } catch (Throwable $e) {
                db()->rollBack();
                throw $e;
            }
            $ok = 'Rolle opdateret.';
        } elseif ($action === 'add_role') {
            $navn = trim($_POST['navn'] ?? '');
            if ($navn === '') {
                throw new InvalidArgumentException('Mangler rollenavn.');
            }
            db()->run(
                'INSERT INTO roles (navn, alle_discipliner) VALUES (?, 1) ON DUPLICATE KEY UPDATE navn = navn',
                [$navn]
            );
            $ok = 'Rolle tilføjet til kataloget - sæt evt. discipliner nedenfor.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$stats       = new Stats(db());
$roles       = $stats->rolesCatalog();
$uncataloged = $stats->uncatalogedRoles();
$discipliner = $stats->roleDisciplineOptions();

render_header('Roller', 'roles');
?>
<h1>Rollekatalog</h1>
<p class="muted">Knyt hver rolle til én eller flere discipliner, eller markér at rollen gælder
    alle discipliner (fx en steward der bruges på tværs). Bruges til at filtrere på disciplin
    i <a href="<?= h(url('officials.php')) ?>">Officials-statistik</a>.</p>

<?php if ($error): ?><div class="notice error"><strong>Fejl:</strong> <?= h($error) ?></div><?php endif; ?>
<?php if ($ok): ?><div class="notice ok"><?= h($ok) ?></div><?php endif; ?>

<table class="data">
    <thead>
        <tr>
            <th>Rolle</th>
            <th class="r">Tildelinger</th>
            <th>Alle discipliner</th>
            <?php foreach ($discipliner as $d): ?>
                <th><?= h($d['disciplin']) ?></th>
            <?php endforeach; ?>
            <th></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($roles as $r): ?>
        <?php
        $current = $r['discipliner'] ? explode(', ', $r['discipliner']) : [];
        $formId  = 'role-form-' . (int)$r['id'];
        ?>
        <tr>
            <td><?= h($r['navn']) ?></td>
            <td class="r"><?= number_format((int)$r['antal_tildelinger'], 0, ',', '.') ?></td>
            <td class="r">
                <input type="checkbox" name="alle_discipliner" value="1" form="<?= h($formId) ?>"
                    class="js-alle-toggle" <?= $r['alle_discipliner'] ? 'checked' : '' ?>>
            </td>
            <?php foreach ($discipliner as $d): ?>
                <td class="r">
                    <input type="checkbox" name="disciplines[]" value="<?= h($d['disciplin']) ?>"
                        form="<?= h($formId) ?>"
                        <?= in_array($d['disciplin'], $current, true) ? 'checked' : '' ?>
                        <?= $r['alle_discipliner'] ? 'disabled' : '' ?>>
                </td>
            <?php endforeach; ?>
            <td><button class="btn" type="submit" form="<?= h($formId) ?>">Gem</button></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$roles): ?>
        <tr><td colspan="<?= 4 + count($discipliner) ?>" class="muted">Ingen roller i kataloget endnu.</td></tr>
    <?php endif; ?>
    </tbody>
</table>

<?php foreach ($roles as $r): ?>
    <form id="role-form-<?= (int)$r['id'] ?>" method="post">
        <input type="hidden" name="action" value="save_role">
        <input type="hidden" name="role_id" value="<?= (int)$r['id'] ?>">
    </form>
<?php endforeach; ?>

<?php if ($uncataloged): ?>
    <h2>Roller uden klassifikation</h2>
    <p class="muted">Disse rollenavne bruges i tildelinger, men er endnu ikke i kataloget ovenfor
        (fx efter en manuel rette i klasse-visningen). Tilføj dem for at kunne filtrere på dem.</p>
    <table class="data">
        <thead><tr><th>Rolle</th><th class="r">Tildelinger</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($uncataloged as $u): ?>
            <tr>
                <td><?= h($u['rolle']) ?></td>
                <td class="r"><?= number_format((int)$u['antal'], 0, ',', '.') ?></td>
                <td>
                    <form method="post">
                        <input type="hidden" name="action" value="add_role">
                        <input type="hidden" name="navn" value="<?= h($u['rolle']) ?>">
                        <button class="btn" type="submit">Tilføj til katalog</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
<?php
render_footer();
