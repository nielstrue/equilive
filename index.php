<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_login();

$stats = new Stats(db());
$d = $stats->dashboard();
$perYear = $stats->showsPerYear();

render_header('Forside', '');
$periodStart = $d['period_start'] ? (int)substr($d['period_start'], 0, 4) : null;
$periodEnd   = $d['period_end']   ? (int)substr($d['period_end'], 0, 4) : null;
?>
<h1>Overblik</h1>
<?php if ($periodStart && $periodEnd): ?>
    <p class="muted">
        <?= $periodStart === $periodEnd ? h((string)$periodStart) : h($periodStart . ' – ' . $periodEnd) ?>
    </p>
<?php endif; ?>

<div class="cards">
    <div class="card"><span class="num"><?= number_format($d['shows'], 0, ',', '.') ?></span><span class="lbl">Stævner</span></div>
    <div class="card"><span class="num"><?= number_format($d['classes'], 0, ',', '.') ?></span><span class="lbl">Klasser</span></div>
    <div class="card"><span class="num"><?= number_format($d['officials'], 0, ',', '.') ?></span><span class="lbl">Officials</span></div>
    <div class="card"><span class="num"><?= number_format($d['assign'], 0, ',', '.') ?></span><span class="lbl">Tildelinger</span></div>
    <div class="card"><span class="num"><?= number_format($d['clubs'], 0, ',', '.') ?></span><span class="lbl">Klubber</span></div>
    <div class="card"><span class="num"><?= number_format($d['clubs_with_shows'], 0, ',', '.') ?></span><span class="lbl">Klubber med stævne</span></div>
    <div class="card"><span class="num"><?= number_format($d['clubs_without_shows'], 0, ',', '.') ?></span><span class="lbl">Klubber uden stævne</span></div>
    <div class="card"><span class="num"><?= number_format($d['ryttere'], 0, ',', '.') ?></span><span class="lbl">Starter i alt</span></div>
</div>

<?php if ($perYear): ?>
<h2>Stævner pr. år</h2>
<table class="data" style="max-width:420px">
    <thead><tr><th>År</th><th class="r">Stævner</th><th class="r">Klasser</th></tr></thead>
    <tbody>
    <?php foreach ($perYear as $py): ?>
        <tr>
            <td><a href="<?= h(url('shows.php?aar=' . (int)$py['aar'])) ?>"><?= (int)$py['aar'] ?></a></td>
            <td class="r"><?= number_format((int)$py['staevner'], 0, ',', '.') ?></td>
            <td class="r"><?= number_format((int)$py['klasser'], 0, ',', '.') ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<?php if ($d['shows'] === 0): ?>
    <div class="notice">
        Databasen er tom. Gå til <a href="<?= h(url('import.php')) ?>">Import</a> og indlæs din CSV-fil for at komme i gang.
    </div>
<?php endif; ?>

<section style="margin-top:1.6rem">
    <h2>Seneste import</h2>
    <?php if ($d['last_import']): $li = $d['last_import']; ?>
        <table class="kv">
            <tr><th>Tidspunkt</th><td><?= h($li['imported_at']) ?></td></tr>
            <tr><th>Fil</th><td><?= h($li['filename'] ?? '–') ?></td></tr>
            <tr><th>Rækker læst</th><td><?= number_format((int)$li['rows_total'], 0, ',', '.') ?></td></tr>
            <tr><th>Sprunget over</th><td><?= (int)$li['rows_skipped'] ?></td></tr>
            <tr><th>Nye tildelinger</th><td><?= number_format((int)$li['assign_new'], 0, ',', '.') ?></td></tr>
            <tr><th>Allerede kendt</th><td><?= number_format((int)$li['assign_seen'], 0, ',', '.') ?></td></tr>
        </table>
    <?php else: ?>
        <p class="muted">Ingen import endnu.</p>
    <?php endif; ?>
</section>
<?php
render_footer();
