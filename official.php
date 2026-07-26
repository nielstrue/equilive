<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

$id = (int)($_GET['id'] ?? 0);
$stats = new Stats(db());
$off = $stats->official($id);

if (!$off) {
    render_header('Official', 'officials');
    echo '<div class="notice error">Official ikke fundet.</div>';
    render_footer();
    exit;
}

$roles  = $stats->officialRoles($id);
$levels = $stats->officialLevels($id);
$shows  = $stats->officialShows($id);
$drfRoles = $stats->officialDrfRoles($id);

$totRyttere = 0;
foreach ($shows as $s) { $totRyttere += (int)$s['ryttere']; }

render_header($off['navn'], 'officials');
?>
<p><a href="<?= h(url('officials.php')) ?>">← Alle officials</a></p>
<h1><?= h($off['navn']) ?></h1>

<div class="cards">
    <div class="card"><span class="num"><?= count($shows) ?></span><span class="lbl">Stævner</span></div>
    <div class="card"><span class="num"><?= array_sum(array_column($shows, 'klasser')) ?></span><span class="lbl">Klasser</span></div>
    <div class="card"><span class="num"><?= number_format($totRyttere, 0, ',', '.') ?></span><span class="lbl">Ryttere i alt</span></div>
</div>

<section class="drf-box">
    <h2>DRF officials-liste
        <?= $off['drf_listed'] ? '<span class="badge badge-drf">✓ på listen</span>' : '<span class="badge badge-muted">ikke fundet</span>' ?>
    </h2>
    <?php if ($drfRoles): ?>
        <table class="data">
            <thead><tr><th>Kategori</th><th>Type</th><th>Distrikt</th></tr></thead>
            <tbody>
            <?php foreach ($drfRoles as $dr): ?>
                <tr><td><?= h($dr['kategori']) ?></td><td><?= h($dr['type']) ?></td><td><?= h($dr['distrikt']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p class="muted">Denne official er ikke matchet på den høstede DRF-liste.
            Det kan skyldes stavning/navneforskelle – se <a href="<?= h(url('drf.php')) ?>">DRF-afstemningen</a>.</p>
    <?php endif; ?>
</section>

<div class="grid2">
    <section>
        <h2>Roller</h2>
        <table class="data">
            <thead><tr><th>Rolle</th><th class="r">Antal</th></tr></thead>
            <tbody>
            <?php foreach ($roles as $r): ?>
                <tr><td><?= h($r['rolle']) ?></td><td class="r"><?= (int)$r['antal'] ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
    <section>
        <h2>Niveauer</h2>
        <table class="data">
            <thead><tr><th>Niveau</th><th class="r">Klasser</th></tr></thead>
            <tbody>
            <?php foreach ($levels as $l): ?>
                <tr><td><?= level_badge($l['code']) ?> <?= h($l['label']) ?></td><td class="r"><?= (int)$l['klasser'] ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$levels): ?><tr><td colspan="2" class="muted">–</td></tr><?php endif; ?>
            </tbody>
        </table>
    </section>
</div>

<h2>Stævner</h2>
<table class="data">
    <thead>
        <tr><th>Dato</th><th>Stævne</th><th>Klub</th><th>Disciplin</th><th>Niveau</th>
            <th class="r">Klasser</th><th class="r">Ryttere</th><th>Roller</th></tr>
    </thead>
    <tbody>
    <?php foreach ($shows as $s): ?>
        <tr>
            <td><?= dk_date($s['dato']) ?></td>
            <td><a href="<?= h(url('show.php?id=' . (int)$s['id'])) ?>"><?= h($s['prop']) ?></a></td>
            <td><?= h($s['klub'] ?? '–') ?></td>
            <td><?= h($s['disciplin'] ?? '') ?></td>
            <td><?= level_badge($s['top_code'], $s['has_lower']) ?></td>
            <td class="r"><?= (int)$s['klasser'] ?></td>
            <td class="r"><?= number_format((int)$s['ryttere'], 0, ',', '.') ?></td>
            <td class="small"><?= h($s['roller']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php
render_footer();
