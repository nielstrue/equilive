<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

$search    = trim($_GET['q'] ?? '');
$disciplin = trim($_GET['disciplin'] ?? '');
$niveau    = trim($_GET['niveau'] ?? '');
$year      = trim($_GET['aar'] ?? '');

$stats = new Stats(db());
$rows  = $stats->showsOverview($search, $disciplin, $niveau, $year);
$discs = $stats->disciplines();
$years = $stats->years();

render_header('Stævner', 'shows');
?>
<h1>Stævner</h1>

<form class="filters" method="get">
    <input type="text" name="q" placeholder="Søg prop/klub…" value="<?= h($search) ?>">
    <select name="disciplin">
        <option value="">Alle discipliner</option>
        <?php foreach ($discs as $d): ?>
            <option value="<?= h($d['disciplin']) ?>" <?= $disciplin===$d['disciplin']?'selected':'' ?>><?= h($d['disciplin']) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="niveau">
        <option value="">Alle niveauer</option>
        <?php foreach (Levels::MAP as $slug => $m): ?>
            <option value="<?= h($slug) ?>" <?= $niveau===$slug?'selected':'' ?>><?= h($m[1]) ?> – <?= h($m[2]) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="aar">
        <option value="">Alle år</option>
        <?php foreach ($years as $y): ?>
            <option value="<?= $y ?>" <?= (string)$year===(string)$y?'selected':'' ?>><?= $y ?></option>
        <?php endforeach; ?>
    </select>
    <button class="btn" type="submit">Filtrér</button>
</form>

<p class="muted"><?= count($rows) ?> stævner</p>

<table class="data">
    <thead>
        <tr><th>Dato</th><th>År</th><th>Prop</th><th>Klub</th><th>Disciplin</th><th>Niveau</th>
            <th class="r">Klasser</th><th class="r">Officials</th><th class="r">Ryttere</th></tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td><?= dk_date($r['dato']) ?></td>
            <td><?= h($r['aar'] ?? '–') ?></td>
            <td>
                <a href="<?= h(url('show.php?id=' . (int)$r['id'])) ?>"><?= h($r['prop']) ?></a>
                <?php if ($r['prop_unknown']): ?><span class="badge badge-warn" title="Prop manglede i kilden">?</span><?php endif; ?>
            </td>
            <td><?= h($r['klub'] ?? '–') ?><?php if ($r['forkort']): ?> <span class="muted">(<?= h($r['forkort']) ?>)</span><?php endif; ?></td>
            <td><?= h($r['disciplin'] ?? '') ?></td>
            <td><?= level_badge($r['top_code'], $r['has_lower']) ?></td>
            <td class="r"><?= (int)$r['klasser'] ?></td>
            <td class="r"><?= (int)$r['officials'] ?></td>
            <td class="r"><?= number_format((int)$r['ryttere'], 0, ',', '.') ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="9" class="muted">Ingen stævner.</td></tr><?php endif; ?>
    </tbody>
</table>
<?php
render_footer();
