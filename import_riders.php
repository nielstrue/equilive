<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_admin();

/**
 * Admin-side til håndkørt bulk-backfill af ryttere - to uafhængige batches:
 *  1) Ryttere pr. klasse (RiderResultImporter) - kun stævner med
 *     resultat_status = "Resultatbehandling færdig" (se knappen på show.php).
 *  2) Rytterdetaljer: licens/kategori (RiderDetailImporter) - den langsomme
 *     del, normalt kørt som natligt cron-job via cli/import_rider_details.php,
 *     men kan også køres herfra i småbatches.
 *
 * Samme begrundelse som import_show_details.php: et enkelt HTTP-kald bør ikke
 * løbe i timevis paa delt hosting, saa batch-størrelsen er bevidst lille.
 */
$years = [2023, 2024, 2025, 2026];

$selectedYears = array_values(array_intersect(
    array_map('intval', $_POST['years'] ?? $_GET['years'] ?? $years),
    $years
));
if (!$selectedYears) {
    $selectedYears = $years;
}
$classLimit = max(1, min(20, (int)($_POST['class_limit'] ?? $_GET['class_limit'] ?? 5)));
$classForce = !empty($_POST['class_force']);

$riderLimit = max(1, min(50, (int)($_POST['rider_limit'] ?? $_GET['rider_limit'] ?? 15)));
$riderForce = !empty($_POST['rider_force']);

$classResult  = null;
$riderResult  = null;
$error        = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'harvest_class_riders') {
    @set_time_limit(0);
    try {
        $classResult = (new RiderResultImporter(db()))->harvestBatch($selectedYears, $classLimit, $classForce);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'harvest_rider_details') {
    @set_time_limit(0);
    try {
        $riderResult = (new RiderDetailImporter(db()))->harvestBatch($riderLimit, $riderForce);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$classImporter  = new RiderResultImporter(db());
$pendingClassByYear = [];
foreach ($years as $y) {
    $pendingClassByYear[$y] = $classImporter->pendingCount([$y]);
}
$pendingClassSelected = $classImporter->pendingCount($selectedYears);

$riderImporter  = new RiderDetailImporter(db());
$pendingRiders  = $riderImporter->pendingCount();

render_header('Ryttere (backfill)', 'import');
?>
<p><a href="<?= h(url('import.php')) ?>">← Import</a></p>
<h1>Ryttere: bulk-backfill</h1>

<?php if ($error): ?><div class="notice error"><strong>Fejl:</strong> <?= h($error) ?></div><?php endif; ?>

<h2>1. Ryttere pr. klasse</h2>
<p class="muted">Henter navn+RiderId pr. klasse fra DRF (samme som knappen "Hent ryttere fra DRF" på et
    enkelt stævnes side, kørt i batches). Kun stævner med status "Resultatbehandling færdig" -
    kør "Hent klassedetaljer fra DRF" for stævnet først, hvis det mangler.</p>

<table class="kv" style="max-width:28rem">
    <?php foreach ($years as $y): ?>
        <tr><th><?= $y ?></th><td class="r"><?= number_format($pendingClassByYear[$y], 0, ',', '.') ?> mangler</td></tr>
    <?php endforeach; ?>
</table>

<?php if ($classResult): ?>
    <div class="notice <?= $classResult['failed'] > 0 ? 'error' : 'ok' ?>">
        <strong>Batch kørt.</strong>
        <ul>
            <li>Stævner behandlet: <?= (int)$classResult['total'] ?></li>
            <li>OK: <?= (int)$classResult['ok'] ?></li>
            <li>Fejlet: <?= (int)$classResult['failed'] ?></li>
            <li>Ryttere matchet i alt: <?= (int)$classResult['riders_matched'] ?></li>
            <li>Mangler stadig (valgte år): <?= number_format($pendingClassSelected, 0, ',', '.') ?></li>
        </ul>
    </div>
    <?php if ($classResult['rows']): ?>
    <table class="data">
        <thead><tr><th>Stævne</th><th>Prop</th><th>År</th><th>Resultat</th></tr></thead>
        <tbody>
        <?php foreach ($classResult['rows'] as $row): ?>
            <tr>
                <td><a href="<?= h(url('show.php?id=' . $row['show_id'])) ?>">#<?= (int)$row['show_id'] ?></a></td>
                <td><?= h($row['prop']) ?></td>
                <td><?= (int)$row['aar'] ?></td>
                <td>
                    <?php if ($row['ok']): ?>
                        <span class="ja">OK</span> <?= (int)$row['riders_matched'] ?> ryttere / <?= (int)$row['classes_harvested'] ?> klasser
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
    <input type="hidden" name="action" value="harvest_class_riders">
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
        <input type="number" name="class_limit" value="<?= (int)$classLimit ?>" min="1" max="20" style="width:5rem">
    </label>
    <label class="muted" style="font-size:.85rem">
        <input type="checkbox" name="class_force" value="1" <?= $classForce ? 'checked' : '' ?>>
        Genindlæs allerede høstede stævner
    </label>
    <button class="btn" type="submit">Kør batch</button>
</form>
<p class="muted">Max 20 stævner pr. batch (koster ét DRF-kald pr. klasse - langsommere end klassedetaljer).</p>

<h2>2. Rytterdetaljer (licens/kategori)</h2>
<p class="muted">Henter rytterlicens og rytterkategorier fra hver rytters DRF-profil. Denne side kan
    være langsom - normalt køres dette som et natligt job via
    <code>cli/import_rider_details.php --limit=N</code>, men kan også køres i småbatches herfra.</p>

<p class="muted"><?= number_format($pendingRiders, 0, ',', '.') ?> ryttere mangler rytterdetaljer.</p>

<?php if ($riderResult): ?>
    <div class="notice <?= $riderResult['failed'] > 0 ? 'error' : 'ok' ?>">
        <strong>Batch kørt.</strong>
        <ul>
            <li>Ryttere behandlet: <?= (int)$riderResult['total'] ?></li>
            <li>OK: <?= (int)$riderResult['ok'] ?></li>
            <li>Fejlet: <?= (int)$riderResult['failed'] ?></li>
            <li>Mangler stadig: <?= number_format($riderImporter->pendingCount(), 0, ',', '.') ?></li>
        </ul>
    </div>
    <?php if ($riderResult['rows']): ?>
    <table class="data">
        <thead><tr><th>Rytter</th><th>RiderId</th><th>Resultat</th></tr></thead>
        <tbody>
        <?php foreach ($riderResult['rows'] as $row): ?>
            <tr>
                <td><a href="<?= h(url('rider.php?id=' . $row['rider_id'])) ?>"><?= h($row['navn']) ?></a></td>
                <td><?= h($row['drf_rider_id']) ?></td>
                <td>
                    <?php if ($row['ok']): ?>
                        <span class="ja">OK</span> <?= (int)$row['licenses'] ?> licensrækker / <?= (int)$row['categories'] ?> kategorier
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
    <input type="hidden" name="action" value="harvest_rider_details">
    <label class="muted" style="font-size:.85rem">Antal ryttere pr. batch:
        <input type="number" name="rider_limit" value="<?= (int)$riderLimit ?>" min="1" max="50" style="width:5rem">
    </label>
    <label class="muted" style="font-size:.85rem">
        <input type="checkbox" name="rider_force" value="1" <?= $riderForce ? 'checked' : '' ?>>
        Genindlæs allerede høstede ryttere
    </label>
    <button class="btn" type="submit">Kør batch</button>
</form>
<p class="muted">Max 50 pr. batch for at undgå timeout på hosting med kort <code>max_execution_time</code>.</p>
<?php
render_footer();
