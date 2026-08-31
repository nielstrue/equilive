<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_admin();

/**
 * Admin-side til håndkørt bulk-backfill af DRF-klassedetaljer (hest/pony,
 * sværhedsgrad) for flere stævner ad gangen - samme funktion som knappen på
 * show.php, men i batches. Til den fulde backfill af mange tusind stævner er
 * cli/import_show_details.php (kørt via cron) bedre egnet, men på hosting
 * uden shell-/cron-adgang kan man i stedet klikke sig igennem batches her.
 *
 * Et enkelt HTTP-kald bør ikke løbe i timevis (delt hosting sætter typisk et
 * max_execution_time på 30-60s), så batch-størrelsen er bevidst lille - klik
 * "Kør batch" gentagne gange indtil "Mangler stadig" rammer 0.
 */
$years = [2023, 2024, 2025, 2026];

$selectedYears = array_values(array_intersect(
    array_map('intval', $_POST['years'] ?? $_GET['years'] ?? $years),
    $years
));
if (!$selectedYears) {
    $selectedYears = $years;
}
$limit = max(1, min(50, (int)($_POST['limit'] ?? $_GET['limit'] ?? 15)));
$force = !empty($_POST['force']);

$result = null;
$error  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'harvest_batch') {
    @set_time_limit(0);
    try {
        $result = (new ShowDetailImporter(db()))->harvestBatch($selectedYears, $limit, $force);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$importer = new ShowDetailImporter(db());
$pendingByYear = [];
foreach ($years as $y) {
    $pendingByYear[$y] = $importer->pendingCount([$y]);
}
$pendingSelected = $importer->pendingCount($selectedYears);

render_header('DRF-klassedetaljer (backfill)', 'import');
?>
<p><a href="<?= h(url('import.php')) ?>">← Import</a></p>
<h1>DRF-klassedetaljer: bulk-backfill</h1>
<p class="muted">Henter hest/pony og sværhedsgrad fra DRF for stævner der endnu ikke er høstet
    (samme som knappen "Hent klassedetaljer fra DRF" på et enkelt stævnes side, kørt i batches).
    Kør batchen gentagne gange indtil "Mangler stadig" er 0.</p>

<table class="kv" style="max-width:28rem">
    <?php foreach ($years as $y): ?>
        <tr><th><?= $y ?></th><td class="r"><?= number_format($pendingByYear[$y], 0, ',', '.') ?> mangler</td></tr>
    <?php endforeach; ?>
</table>

<?php if ($error): ?><div class="notice error"><strong>Fejl:</strong> <?= h($error) ?></div><?php endif; ?>

<?php if ($result): ?>
    <div class="notice <?= $result['failed'] > 0 ? 'error' : 'ok' ?>">
        <strong>Batch kørt.</strong>
        <ul>
            <li>Stævner behandlet: <?= (int)$result['total'] ?></li>
            <li>OK: <?= (int)$result['ok'] ?></li>
            <li>Fejlet: <?= (int)$result['failed'] ?></li>
            <li>Klasser opdateret i alt: <?= (int)$result['classes_matched'] ?></li>
            <li>Mangler stadig (valgte år): <?= number_format($pendingSelected, 0, ',', '.') ?></li>
        </ul>
    </div>
    <?php if ($result['rows']): ?>
    <table class="data">
        <thead><tr><th>Stævne</th><th>Prop</th><th>År</th><th>Resultat</th></tr></thead>
        <tbody>
        <?php foreach ($result['rows'] as $row): ?>
            <tr>
                <td><a href="<?= h(url('show.php?id=' . $row['show_id'])) ?>">#<?= (int)$row['show_id'] ?></a></td>
                <td><?= h($row['prop']) ?></td>
                <td><?= (int)$row['aar'] ?></td>
                <td>
                    <?php if ($row['ok']): ?>
                        <span class="ja">OK</span> <?= (int)$row['classes_matched'] ?>/<?= (int)$row['classes_total'] ?> klasser
                    <?php else: ?>
                        <span class="nej">Fejl</span> <span class="muted"><?= h($row['message']) ?></span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
<?php endif; ?>

<form method="post" style="margin:.8rem 0;display:flex;gap:1rem;align-items:flex-start;flex-wrap:wrap"><?= csrf_field() ?>
    <input type="hidden" name="action" value="harvest_batch">
    <fieldset style="border:0;padding:0;margin:0">
        <legend class="muted" style="font-size:.85rem">År</legend>
        <?php foreach ($years as $y): ?>
            <label style="margin-right:.6rem">
                <input type="checkbox" name="years[]" value="<?= $y ?>" <?= in_array($y, $selectedYears, true) ? 'checked' : '' ?>>
                <?= $y ?>
            </label>
        <?php endforeach; ?>
    </fieldset>
    <label class="muted" style="font-size:.85rem">Antal stævner pr. batch:
        <input type="number" name="limit" value="<?= (int)$limit ?>" min="1" max="50" style="width:5rem">
    </label>
    <label class="muted" style="font-size:.85rem">
        <input type="checkbox" name="force" value="1" <?= $force ? 'checked' : '' ?>>
        Genindlæs allerede høstede stævner
    </label>
    <button class="btn" type="submit">Kør batch</button>
</form>
<p class="muted">Max 50 pr. batch for at undgå timeout på hosting med kort <code>max_execution_time</code>.</p>
<?php
render_footer();
