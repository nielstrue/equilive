<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_login();

$search       = trim($_GET['q'] ?? '');
$sort         = $_GET['sort'] ?? 'navn';
$selectedAar  = array_map('strval', (array)($_GET['aar'] ?? []));
$selectedDist = array_map('strval', (array)($_GET['distrikt'] ?? []));
$selectedDisc = array_map('strval', (array)($_GET['disciplin'] ?? []));
$selectedType = array_map('strval', (array)($_GET['type'] ?? []));
// Ingen "status"-param i URL'en endnu (foerste besoeg) => default til kun aktive.
$selectedStatus = isset($_GET['status']) ? array_map('strval', (array)$_GET['status']) : ['aktiv'];

$stats       = new Stats(db());
$rows        = $stats->officialsOverview($search, $sort, $selectedAar, $selectedDisc, '', $selectedDist, $selectedType, $selectedStatus);
$years       = $stats->years();
$distrikter  = $stats->drfDistrikter();
$discipliner = $stats->roleDisciplineOptions();
$typer       = $stats->drfTyper();

// Bevar årsfilteret ned til official-detaljesiden, saa dens tal matcher.
$yearQuery = $selectedAar ? ('&' . http_build_query(['aar' => $selectedAar])) : '';

render_header('Officials', 'officials');
?>
<h1>Officials-statistik</h1>

<form class="filters" method="get">
    <input type="text" name="q" placeholder="Søg navn…" value="<?= h($search) ?>">
    <?php checkbox_dropdown('aar', 'Alle år', array_combine($years, $years), $selectedAar); ?>
    <?php checkbox_dropdown('distrikt', 'Alle distrikter', array_column($distrikter, 'distrikt', 'distrikt'), $selectedDist); ?>
    <?php checkbox_dropdown('disciplin', 'Alle discipliner', array_column($discipliner, 'disciplin', 'disciplin'), $selectedDisc); ?>
    <?php checkbox_dropdown('type', 'Alle typer', array_column($typer, 'type', 'type'), $selectedType); ?>
    <?php checkbox_dropdown('status', 'Status', [
        'aktiv'        => 'Aktiv',
        'kun_e_niveau' => 'Kun E-niveau',
        'fei_official' => 'FEI official',
        'ikke_aktiv'   => 'Ikke aktiv',
    ], $selectedStatus); ?>
    <select name="sort" onchange="this.form.submit()">
        <option value="staevner" <?= $sort==='staevner'?'selected':'' ?>>Flest stævner</option>
        <option value="klasser"  <?= $sort==='klasser' ?'selected':'' ?>>Flest klasser</option>
        <option value="ryttere"  <?= $sort==='ryttere' ?'selected':'' ?>>Flest ryttere</option>
        <option value="navn"     <?= $sort==='navn'    ?'selected':'' ?>>Navn (A-Å)</option>
        <option value="distrikt" <?= $sort==='distrikt'?'selected':'' ?>>Distrikt</option>
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
            <th>Status</th>
            <th>Type</th>
            <th>Distrikt</th>
            <th>DRF</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td><a href="<?= h(url('official.php?id=' . (int)$r['id'] . $yearQuery)) ?>"><?= h($r['navn']) ?></a></td>
            <td class="r"><?= (int)$r['antal_staevner'] ?></td>
            <td class="r"><?= (int)$r['antal_klasser'] ?></td>
            <td class="r"><?= (int)$r['antal_roller'] ?></td>
            <td class="r"><?= number_format((int)$r['antal_ryttere'], 0, ',', '.') ?></td>
            <td><?= h($r['niveauer'] ?? '') ?></td>
            <td><?= official_status_badge($r['status']) ?></td>
            <td class="small"><?= h($r['typer'] ?? '') ?></td>
            <td><?= h($r['distrikter'] ?? '') ?></td>
            <td><?= $r['drf_listed'] ? '<span class="badge badge-drf">✓</span>' : '<span class="muted">–</span>' ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
        <tr><td colspan="10" class="muted">Ingen officials fundet.</td></tr>
    <?php endif; ?>
    </tbody>
</table>
<?php
render_footer();
