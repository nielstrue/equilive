<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

$search      = trim($_GET['q'] ?? '');
$sort        = $_GET['sort'] ?? 'staevner';
$selectedAar = array_map('strval', (array)($_GET['aar'] ?? []));

$stats = new Stats(db());
$rows  = $stats->clubsOverview($search, $selectedAar, $sort);
$years = $stats->years();

// Bevar årsfilteret ned til klub-detaljesiden, saa officials-listen der matcher.
$yearQuery = $selectedAar ? ('&' . http_build_query(['aar' => $selectedAar])) : '';

render_header('Klubber', 'clubs');
?>
<h1>Klubber</h1>

<form class="filters" method="get">
    <input type="text" name="q" placeholder="Søg klub…" value="<?= h($search) ?>">
    <?php checkbox_dropdown('aar', 'Alle år', array_combine($years, $years), $selectedAar); ?>
    <select name="sort" onchange="this.form.submit()">
        <option value="staevner" <?= $sort==='staevner'?'selected':'' ?>>Flest stævner</option>
        <option value="navn"     <?= $sort==='navn'    ?'selected':'' ?>>Navn (A-Å)</option>
    </select>
    <button class="btn" type="submit">Filtrér</button>
</form>

<p class="muted"><?= count($rows) ?> klubber</p>

<table class="data">
    <thead>
        <tr>
            <th>Klub</th>
            <th>Distrikt</th>
            <th class="r">Stævner</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td>
                <a href="<?= h(url('club.php?id=' . (int)$r['id'] . $yearQuery)) ?>"><?= h($r['navn']) ?></a>
                <?php if ($r['forkort']): ?> <span class="muted">(<?= h($r['forkort']) ?>)</span><?php endif; ?>
            </td>
            <td><?= h($r['distrikt'] ?? '') ?></td>
            <td class="r"><?= (int)$r['antal_staevner'] ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
        <tr><td colspan="3" class="muted">Ingen klubber fundet.</td></tr>
    <?php endif; ?>
    </tbody>
</table>
<?php
render_footer();
