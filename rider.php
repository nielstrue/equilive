<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_login();

$id    = (int)($_GET['id'] ?? 0);
$stats = new Stats(db());
$rider = $stats->riderInfo($id);

if (!$rider) {
    render_header('Rytter', 'riders');
    echo '<div class="notice error">Rytter ikke fundet.</div>';
    render_footer();
    exit;
}

$licenses   = $stats->riderLicenses($id);
$categories = $stats->riderCategories($id);
$classes    = $stats->riderClasses($id);

render_header($rider['navn'], 'riders');
back_link(url('riders.php'), 'Alle ryttere');
?>
<h1><?= h($rider['navn']) ?></h1>
<p class="muted">DRF-nummer: <?= h($rider['drf_rider_id']) ?></p>

<div class="grid2">
    <section>
        <h2>Rytterlicens</h2>
        <?php if ($licenses): ?>
            <table class="data">
                <thead><tr><th>Type</th><th>Værdi</th></tr></thead>
                <tbody>
                <?php foreach ($licenses as $l): ?>
                    <tr><td><?= h($l['type']) ?></td><td><?= h($l['vaerdi'] ?? '–') ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="muted">Ikke hentet endnu - køres via det natlige batch-job
                (<code>cli/import_rider_details.php</code>) eller admin-siden "Ryttere-import".</p>
        <?php endif; ?>
    </section>
    <section>
        <h2>Rytterkategorier</h2>
        <?php if ($categories): ?>
            <table class="data">
                <thead><tr><th>Kategori</th><th>Værdi</th></tr></thead>
                <tbody>
                <?php foreach ($categories as $c): ?>
                    <tr><td><?= h($c['kategori']) ?></td><td><?= h($c['vaerdi'] ?? '–') ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="muted">Ikke hentet endnu - køres via det natlige batch-job
                (<code>cli/import_rider_details.php</code>) eller admin-siden "Ryttere-import".</p>
        <?php endif; ?>
    </section>
</div>

<h2>Klasser</h2>
<table class="data">
    <thead>
        <tr><th class="nowrap">Dato</th><th>Stævne</th><th>Klub</th><th>Klasse</th><th>Disciplin</th><th class="tight">Niveau</th></tr>
    </thead>
    <tbody>
    <?php foreach ($classes as $c): ?>
        <tr>
            <td class="nowrap"><?= dk_date($c['dato']) ?></td>
            <td><a href="<?= h(url('show.php?id=' . (int)$c['show_id']) . '&' . from_params($rider['navn'])) ?>"><?= h($c['prop']) ?></a></td>
            <td><?= h($c['klub'] ?? '–') ?></td>
            <td><a href="<?= h(url('class.php?id=' . (int)$c['class_id']) . '&' . from_params($rider['navn'])) ?>"><?= h($c['klassenavn']) ?></a></td>
            <td><?= h($c['disciplin'] ?? '–') ?></td>
            <td class="tight"><?= level_badge($c['niveau_code']) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$classes): ?>
        <tr><td colspan="6" class="muted">Ingen klasser registreret for denne rytter.</td></tr>
    <?php endif; ?>
    </tbody>
</table>
<?php
render_footer();
