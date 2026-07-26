<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

$search       = trim($_GET['q'] ?? '');
$sort         = $_GET['sort'] ?? 'staevner';
$selectedAar  = array_map('strval', (array)($_GET['aar'] ?? []));
$selectedDist = array_map('strval', (array)($_GET['distrikt'] ?? []));

$stats      = new Stats(db());
$rows       = $stats->officialsOverview($search, $sort, $selectedAar, '', '', $selectedDist);
$years      = $stats->years();
$distrikter = $stats->drfDistrikter();

render_header('Officials', 'officials');
?>
<h1>Officials-statistik</h1>

<form class="filters" method="get">
    <input type="text" name="q" placeholder="Søg navn…" value="<?= h($search) ?>">
    <?php checkbox_dropdown('aar', 'Alle år', array_combine($years, $years), $selectedAar); ?>
    <?php checkbox_dropdown('distrikt', 'Alle distrikter', array_column($distrikter, 'distrikt', 'distrikt'), $selectedDist); ?>
    <select name="sort" onchange="this.form.submit()">
        <option value="staevner" <?= $sort==='staevner'?'selected':'' ?>>Flest stævner</option>
        <option value="klasser"  <?= $sort==='klasser' ?'selected':'' ?>>Flest klasser</option>
        <option value="ryttere"  <?= $sort==='ryttere' ?'selected':'' ?>>Flest ryttere</option>
        <option value="navn"     <?= $sort==='navn'    ?'selected':'' ?>>Navn (A-Å)</option>
    </select>
    <button class="btn" type="submit">Filtrér</button>
</form>

<p class="muted"><?= count($rows) ?> officials</p>

<table class="data">
    <thead>
        <tr>
            <th>Official</th>
            <th class="r">Stævner</th>
            <th class="r">Klasser</th>
            <th class="r">Roller</th>
            <th class="r">Ryttere</th>
            <th>Niveauer</th>
            <th>Distrikt</th>
            <th>DRF</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td><a href="<?= h(url('official.php?id=' . (int)$r['id'])) ?>"><?= h($r['navn']) ?></a></td>
            <td class="r"><?= (int)$r['antal_staevner'] ?></td>
            <td class="r"><?= (int)$r['antal_klasser'] ?></td>
            <td class="r"><?= (int)$r['antal_roller'] ?></td>
            <td class="r"><?= number_format((int)$r['antal_ryttere'], 0, ',', '.') ?></td>
            <td><?= h($r['niveauer'] ?? '') ?></td>
            <td><?= h($r['distrikter'] ?? '') ?></td>
            <td><?= $r['drf_listed'] ? '<span class="badge badge-drf">✓</span>' : '<span class="muted">–</span>' ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
        <tr><td colspan="8" class="muted">Ingen officials fundet.</td></tr>
    <?php endif; ?>
    </tbody>
</table>
<?php
render_footer();
