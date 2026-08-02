<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_login();

$selectedAar = array_map('strval', (array)($_GET['aar'] ?? []));
$type        = trim($_GET['type'] ?? '');
$rolle       = trim($_GET['rolle'] ?? '');

$stats = new Stats(db());
$rows  = $stats->activeOfficialsWithoutRole($type, $rolle, $selectedAar);
$years = $stats->years();
$typer = $stats->drfTyper();
$roles = $stats->roles();

render_header('Officials uden rolle', 'officials_uden_rolle');
?>
<h1>Aktive officials uden rolle</h1>
<p class="muted">Aktive officials fra DRF-listen der ikke har haft <?= $rolle !== '' ? 'rollen "' . h($rolle) . '"' : 'nogen tildeling' ?>
    <?= $selectedAar ? 'i ' . h(implode(', ', $selectedAar)) : 'i stævnedata' ?><?= $type !== '' ? ' (type: ' . h($type) . ')' : '' ?>.
    Brug den til at følge officials med lav eller ingen aktivitet.</p>

<form class="filters" method="get">
    <?php checkbox_dropdown('aar', 'Alle år', array_combine($years, $years), $selectedAar); ?>
    <select name="type">
        <option value="">Alle typer</option>
        <?php foreach ($typer as $t): ?>
            <option value="<?= h($t['type']) ?>" <?= $type===$t['type']?'selected':'' ?>><?= h($t['type']) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="rolle">
        <option value="">Enhver rolle (dvs. slet ingen tildeling)</option>
        <?php foreach ($roles as $r): ?>
            <option value="<?= h($r['rolle']) ?>" <?= $rolle===$r['rolle']?'selected':'' ?>><?= h($r['rolle']) ?></option>
        <?php endforeach; ?>
    </select>
    <button class="btn" type="submit">Filtrér</button>
</form>

<p class="muted"><?= count($rows) ?> officials</p>

<table class="data">
    <thead>
        <tr><th>Official</th><th>Type(r)</th><th>Sidste opgave</th><th class="r">Opgaver i alt</th></tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td><a href="<?= h(url('official.php?id=' . (int)$r['id'])) ?>"><?= h($r['navn']) ?></a></td>
            <td class="small"><?= h($r['typer'] ?? '') ?></td>
            <td><?= $r['sidste_opgave'] ? dk_date($r['sidste_opgave']) : '–' ?></td>
            <td class="r"><?= (int)$r['antal_opgaver'] ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
        <tr><td colspan="4" class="muted">Ingen officials matcher filteret.</td></tr>
    <?php endif; ?>
    </tbody>
</table>
<?php
render_footer();
