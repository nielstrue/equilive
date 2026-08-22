<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_admin();

/**
 * Bevidst slettede tildelinger (se class.php's "Slet"-knap) - de springes
 * over ved fremtidige imports af samme kildedata (Importer::isTombstoned()),
 * saa en fejlagtig/uønsket tildeling ikke bare bliver genskabt uge efter uge.
 * Denne side lader en admin se listen og evt. fortryde en sletning.
 */
$ok = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'undo') {
    $tombId = (int)($_POST['id'] ?? 0);
    if (!$tombId) {
        $error = 'Ukendt række.';
    } else {
        db()->run('DELETE FROM deleted_assignments WHERE id = ?', [$tombId]);
        $ok = 'Fortrudt - tildelingen kan igen blive oprettet ved næste import.';
    }
}

$stats = new Stats(db());
$rows  = $stats->deletedAssignments();

render_header('Slettede tildelinger', 'deleted_assignments');
?>
<p><a href="<?= h(url('import.php')) ?>">← Import</a></p>
<h1>Slettede tildelinger</h1>
<p class="muted">Når en tildeling slettes på en klasses side ("Slet"), huskes den her, saa den ikke
    genskabes af en senere import af samme kildedata. Fortryd for at tillade den igen fremover.</p>

<?php if ($error): ?><div class="notice error"><strong>Fejl:</strong> <?= h($error) ?></div><?php endif; ?>
<?php if ($ok): ?><div class="notice ok"><?= h($ok) ?></div><?php endif; ?>

<p class="muted"><?= count($rows) ?> slettede tildelinger</p>

<table class="data">
    <thead>
        <tr><th>Official</th><th>Rolle (rå)</th><th>Klasse</th><th>Stævne</th><th class="nowrap">Dato</th>
            <th class="nowrap">Slettet</th><th>Af</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td><a href="<?= h(url('official.php?id=' . (int)$r['official_id']) . '&' . from_params('Slettede tildelinger')) ?>"><?= h($r['official']) ?></a></td>
            <td><?= h($r['orig_rolle']) ?></td>
            <td><a href="<?= h(url('class.php?id=' . (int)$r['class_id']) . '&' . from_params('Slettede tildelinger')) ?>"><?= h($r['klassenavn']) ?></a></td>
            <td><a href="<?= h(url('show.php?id=' . (int)$r['show_id']) . '&' . from_params('Slettede tildelinger')) ?>"><?= h($r['prop']) ?></a></td>
            <td class="nowrap"><?= dk_date($r['dato']) ?></td>
            <td class="nowrap"><?= h($r['deleted_at']) ?></td>
            <td><?= h($r['deleted_by_navn'] ?? '–') ?></td>
            <td>
                <form method="post" onsubmit="return confirm('Fortryd sletningen af <?= h($r['official']) ?> - <?= h($r['orig_rolle']) ?>? Tildelingen kan blive genskabt ved næste import.');">
                    <input type="hidden" name="action" value="undo">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button class="btn" type="submit">Fortryd</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
        <tr><td colspan="8" class="muted">Ingen slettede tildelinger.</td></tr>
    <?php endif; ?>
    </tbody>
</table>
<?php
render_footer();
