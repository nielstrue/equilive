<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

$id = (int)($_GET['id'] ?? 0);
$stats = new Stats(db());
$s = $stats->show($id);

if (!$s) {
    render_header('Stævne', 'shows');
    echo '<div class="notice error">Stævne ikke fundet.</div>';
    render_footer();
    exit;
}
$classes = $stats->showClasses($id);
$ryttere = array_sum(array_map(fn($c) => (int)$c['starter'], $classes));

render_header($s['prop'], 'shows');
?>
<p><a href="<?= h(url('shows.php')) ?>">← Alle stævner</a></p>
<h1><?= h($s['prop']) ?> <?= level_badge($s['top_code'], $s['has_lower']) ?></h1>

<table class="kv">
    <tr><th>Klub</th><td><?= h($s['klub'] ?? '–') ?><?= $s['forkort'] ? ' (' . h($s['forkort']) . ')' : '' ?></td></tr>
    <tr><th>Dato</th><td><?= dk_date($s['dato']) ?></td></tr>
    <tr><th>Disciplin</th><td><?= h($s['disciplin'] ?? '–') ?></td></tr>
    <tr><th>Stævneniveau</th><td><?= h(Levels::label($s['top_slug'])) ?><?= $s['has_lower'] ? ' – har også klasser på lavere niveau' : '' ?></td></tr>
    <tr><th>Klasser</th><td><?= count($classes) ?></td></tr>
    <tr><th>Startende ryttere i alt</th><td><?= number_format($ryttere, 0, ',', '.') ?></td></tr>
    <?php if ($s['prop_unknown']): ?><tr><th>Bemærk</th><td class="muted">Prop manglede i kilden – stævnet er identificeret ud fra klub + dato.</td></tr><?php endif; ?>
</table>

<h2>Klasser</h2>
<table class="data">
    <thead>
        <tr><th>Nr.</th><th>Klassenavn</th><th>Niveau</th><th>Stilspringning</th>
            <th class="r">Ryttere</th><th>Officials</th></tr>
    </thead>
    <tbody>
    <?php foreach ($classes as $c): ?>
        <tr>
            <td><?= h($c['klassenr']) ?></td>
            <td><a href="<?= h(url('class.php?id=' . (int)$c['id'])) ?>"><?= h($c['klassenavn']) ?></a></td>
            <td><?= level_badge($c['niveau_code']) ?></td>
            <td><?= ja_nej($c['stilspringning']) ?></td>
            <td class="r"><?= $c['starter'] === null ? '–' : (int)$c['starter'] ?></td>
            <td class="small"><?= h($c['officials']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php
render_footer();
