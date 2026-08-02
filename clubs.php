<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_login();

$search      = trim($_GET['q'] ?? '');
$sort        = $_GET['sort'] ?? 'staevner';
$selectedAar = array_map('strval', (array)($_GET['aar'] ?? []));

$stats   = new Stats(db());
$rows    = $stats->clubsOverview($search, $selectedAar, $sort);
$years   = $stats->years();
$noShows = $stats->clubsWithoutShows();
$isAdmin = (current_user()['role'] ?? '') === 'admin';

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
            <th class="nowrap">Distrikt</th>
            <th class="nowrap">Postnr.</th>
            <th class="r">Stævner</th>
            <th>Status</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td>
                <a href="<?= h(url('club.php?id=' . (int)$r['id'] . $yearQuery)) ?>"><?= h($r['navn']) ?></a>
                <?php if ($r['forkort']): ?> <span class="muted">(<?= h($r['forkort']) ?>)</span><?php endif; ?>
            </td>
            <td class="nowrap"><?= h($r['distrikt'] ?? '') ?></td>
            <td class="nowrap"><?= h($r['postnr'] ?? '') ?></td>
            <td class="r"><?= (int)$r['antal_staevner'] ?></td>
            <td><?= club_status_badge($r['status']) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
        <tr><td colspan="5" class="muted">Ingen klubber fundet.</td></tr>
    <?php endif; ?>
    </tbody>
</table>

<h2>Klubber uden stævner</h2>
<p class="muted">Klubber i klub-registret der ikke optræder på noget stævne overhovedet - hverken aktive
    eller udelukkede. Typisk en fejloprettet klub (fx efter en rettelse af klubnavn/forkortelse i
    kildedata) og kandidat til fletning med den korrekte klub.</p>
<table class="data">
    <thead>
        <tr>
            <th>Klub</th>
            <th class="nowrap">Distrikt</th>
            <th class="nowrap">Postnr.</th>
            <th>Status</th>
            <?php if ($isAdmin): ?><th></th><?php endif; ?>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($noShows as $r): ?>
        <tr>
            <td>
                <?= h($r['navn']) ?>
                <?php if ($r['forkort']): ?> <span class="muted">(<?= h($r['forkort']) ?>)</span><?php endif; ?>
            </td>
            <td class="nowrap"><?= h($r['distrikt'] ?? '') ?></td>
            <td class="nowrap"><?= h($r['postnr'] ?? '') ?></td>
            <td><?= club_status_badge($r['status']) ?></td>
            <?php if ($isAdmin): ?>
                <td><a href="<?= h(url('clubs_merge.php?merge=' . (int)$r['id'])) ?>">Flet ind i en anden klub →</a></td>
            <?php endif; ?>
        </tr>
    <?php endforeach; ?>
    <?php if (!$noShows): ?>
        <tr><td colspan="<?= $isAdmin ? 5 : 4 ?>" class="muted">Ingen - alle klubber har mindst ét stævne.</td></tr>
    <?php endif; ?>
    </tbody>
</table>
<?php
render_footer();
