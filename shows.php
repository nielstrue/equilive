<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_login();

$search      = trim($_GET['q'] ?? '');
$disciplin   = trim($_GET['disciplin'] ?? '');
$niveau      = trim($_GET['niveau'] ?? '');
$selectedAar = array_map('strval', (array)($_GET['aar'] ?? []));
$status      = trim($_GET['status'] ?? 'aktiv');

$stats = new Stats(db());
$rows  = $stats->showsOverview($search, $disciplin, $niveau, $selectedAar, $status);
$discs = $stats->disciplines();
$years = $stats->years();

// Bevar det aktive filter ned til stævnedetaljesiden, saa "← Alle stævner" derfra
// foerer tilbage til den samme filtrerede liste i stedet for at nulstille den.
$filterQuery = http_build_query($_GET);

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
    <?php checkbox_dropdown('aar', 'Alle år', array_combine($years, $years), $selectedAar); ?>
    <select name="status">
        <option value="aktiv"     <?= $status==='aktiv'     ? 'selected' : '' ?>>Aktive stævner</option>
        <option value="udelukket" <?= $status==='udelukket' ? 'selected' : '' ?>>Kun udelukkede</option>
        <option value="alle"      <?= $status==='alle'      ? 'selected' : '' ?>>Alle (inkl. udelukkede)</option>
    </select>
    <button class="btn" type="submit">Filtrér</button>
    <a class="btn" style="background:var(--muted)" href="<?= h(url('shows.php')) ?>">Nulstil filter</a>
</form>

<p class="muted"><?= count($rows) ?> stævner</p>

<table class="data">
    <thead>
        <tr><th>Dato</th><th>År</th><th>Prop</th><th>Klub</th><th>Disciplin</th><th>Niveau</th>
            <th class="r">Klasser</th><th class="r">Officials</th><th class="r">Ryttere</th><th>Status</th></tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td><?= dk_date($r['dato']) ?></td>
            <td><?= h($r['aar'] ?? '–') ?></td>
            <td>
                <a href="<?= h(url('show.php?id=' . (int)$r['id'] . ($filterQuery !== '' ? '&' . $filterQuery : ''))) ?>"><?= h($r['prop']) ?></a>
                <?php if ($r['prop_unknown']): ?><span class="badge badge-warn" title="Prop manglede i kilden">?</span><?php endif; ?>
            </td>
            <td><?= h($r['klub'] ?? '–') ?><?php if ($r['forkort']): ?> <span class="muted">(<?= h($r['forkort']) ?>)</span><?php endif; ?></td>
            <td><?= h($r['discipliner'] ?? $r['disciplin'] ?? '') ?></td>
            <td><?= level_badge($r['top_code'], $r['has_lower']) ?></td>
            <td class="r"><?= (int)$r['klasser'] ?></td>
            <td class="r"><?= (int)$r['officials'] ?></td>
            <td class="r"><?= number_format((int)$r['ryttere'], 0, ',', '.') ?></td>
            <td><?= show_status_badge($r['status']) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="10" class="muted">Ingen stævner.</td></tr><?php endif; ?>
    </tbody>
</table>
<?php
render_footer();
