<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_login();

$search = trim($_GET['q'] ?? '');
$sort   = $_GET['sort'] ?? 'navn';

$stats = new Stats(db());
$rows  = $stats->ridersOverview($search, $sort);

render_header('Ryttere', 'riders');
?>
<h1>Ryttere</h1>
<p class="muted">Ryttere høstet fra DRF's klasseresultat-sider (navn + RiderId), med supplerende
    licens/kategori-data hentet via det natlige batch-job. Se knappen "Hent ryttere fra DRF" på et
    stævnes side, eller admin-siden "Ryttere-import" for bulk-backfill.</p>

<form class="filters" method="get">
    <input type="text" name="q" placeholder="Søg navn eller DRF-nummer…" value="<?= h($search) ?>">
    <select name="sort" onchange="this.form.submit()">
        <option value="navn"    <?= $sort==='navn'   ?'selected':'' ?>>Navn (A-Å)</option>
        <option value="klasser" <?= $sort==='klasser'?'selected':'' ?>>Flest klasser</option>
    </select>
    <button class="btn" type="submit">Filtrér</button>
</form>

<p class="muted"><?= count($rows) ?> ryttere</p>

<table class="data">
    <thead>
        <tr><th>Rytter</th><th>DRF-nummer</th><th class="r">Klasser</th><th>Rytterdetaljer</th></tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td><a href="<?= h(url('rider.php?id=' . (int)$r['id'])) ?>"><?= h($r['navn']) ?></a></td>
            <td><?= h($r['drf_rider_id']) ?></td>
            <td class="r"><?= (int)$r['klasser'] ?></td>
            <td><?= $r['detail_harvested_at'] ? '<span class="ja">Hentet</span>' : '<span class="muted">Mangler</span>' ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
        <tr><td colspan="4" class="muted">Ingen ryttere fundet.</td></tr>
    <?php endif; ?>
    </tbody>
</table>
<?php
render_footer();
