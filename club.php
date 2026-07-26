<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

$id          = (int)($_GET['id'] ?? 0);
$selectedAar = array_map('strval', (array)($_GET['aar'] ?? []));

$stats = new Stats(db());
$club  = $stats->club($id, $selectedAar);

if (!$club) {
    render_header('Klub', 'clubs');
    echo '<div class="notice error">Klub ikke fundet.</div>';
    render_footer();
    exit;
}

$officials = $stats->clubOfficials($id, $selectedAar);

render_header($club['navn'], 'clubs');
?>
<p><a href="<?= h(url('clubs.php')) ?>">← Alle klubber</a></p>
<h1><?= h($club['navn']) ?><?= $club['forkort'] ? ' <span class="muted">(' . h($club['forkort']) . ')</span>' : '' ?></h1>

<?php if ($selectedAar): ?>
    <p class="muted">Filtreret på år: <strong><?= h(implode(', ', $selectedAar)) ?></strong>
        · <a href="<?= h(url('club.php?id=' . $id)) ?>">vis alle år</a></p>
<?php endif; ?>

<table class="kv">
    <tr><th>Distrikt</th><td><?= h($club['distrikt'] ?? '–') ?></td></tr>
</table>

<div class="cards">
    <div class="card"><span class="num"><?= (int)$club['antal_staevner'] ?></span><span class="lbl">Stævner</span></div>
    <div class="card"><span class="num"><?= count($officials) ?></span><span class="lbl">Officials brugt</span></div>
</div>

<h2>Officials brugt ved klubbens stævner</h2>
<table class="data">
    <thead>
        <tr>
            <th>Official</th>
            <th class="r">Stævner</th>
            <th class="r">Klasser</th>
            <th class="r">Roller</th>
            <th>Rollefordeling</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($officials as $o): ?>
        <tr>
            <td><a href="<?= h(url('official.php?id=' . (int)$o['id'])) ?>"><?= h($o['navn']) ?></a></td>
            <td class="r"><?= (int)$o['antal_staevner'] ?></td>
            <td class="r"><?= (int)$o['antal_klasser'] ?></td>
            <td class="r"><?= (int)$o['antal_roller'] ?></td>
            <td class="small"><?= h($o['roller']) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$officials): ?>
        <tr><td colspan="5" class="muted">Ingen officials registreret for denne klub endnu.</td></tr>
    <?php endif; ?>
    </tbody>
</table>
<?php
render_footer();
