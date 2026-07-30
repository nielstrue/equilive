<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_login();

$id          = (int)($_GET['id'] ?? 0);
$selectedAar = array_map('strval', (array)($_GET['aar'] ?? []));

$stats = new Stats(db());
$off = $stats->official($id);

if (!$off) {
    render_header('Official', 'officials');
    echo '<div class="notice error">Official ikke fundet.</div>';
    render_footer();
    exit;
}

$statusError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_status') {
    $nyStatus = $_POST['status'] ?? '';
    if (!in_array($nyStatus, ['aktiv', 'ikke_aktiv', 'kun_e_niveau', 'fei_official'], true)) {
        $statusError = 'Ukendt status.';
    } else {
        db()->run('UPDATE officials SET status = ? WHERE id = ?', [$nyStatus, $id]);
        $off['status'] = $nyStatus;
    }
}

$roles  = $stats->officialRoles($id, $selectedAar);
$levels = $stats->officialLevels($id, $selectedAar);
$shows  = $stats->officialShows($id, $selectedAar);
$drfRoles = $stats->officialDrfRoles($id);
$aliases  = $stats->officialAliases($id);

$totRyttere = 0;
foreach ($shows as $s) { $totRyttere += (int)$s['ryttere']; }

render_header($off['navn'], 'officials');
?>
<p><a href="<?= h(url('officials.php')) ?>">← Alle officials</a></p>
<h1><?= h($off['navn']) ?> <?= official_status_badge($off['status']) ?></h1>
<?php if ($aliases): ?>
    <p class="muted">Tidligere navn(e): <?= h(implode(', ', array_column($aliases, 'navn'))) ?></p>
<?php endif; ?>

<?php if ($statusError): ?><div class="notice error"><?= h($statusError) ?></div><?php endif; ?>
<form method="post" style="display:flex;gap:.4rem;align-items:center;margin:.6rem 0">
    <input type="hidden" name="action" value="set_status">
    <label class="muted" style="font-size:.85rem">Status:
        <select name="status">
            <option value="aktiv"        <?= $off['status']==='aktiv'        ? 'selected' : '' ?>>Aktiv</option>
            <option value="kun_e_niveau" <?= $off['status']==='kun_e_niveau' ? 'selected' : '' ?>>Kun E-niveau (uuddannet klubdommer)</option>
            <option value="fei_official" <?= $off['status']==='fei_official' ? 'selected' : '' ?>>FEI official (ikke fundet paa DRF-listen)</option>
            <option value="ikke_aktiv"   <?= $off['status']==='ikke_aktiv'   ? 'selected' : '' ?>>Ikke aktiv</option>
        </select>
    </label>
    <button class="btn" type="submit">Gem</button>
</form>

<?php if ($selectedAar): ?>
    <p class="muted">Filtreret på år: <strong><?= h(implode(', ', $selectedAar)) ?></strong>
        · <a href="<?= h(url('official.php?id=' . $id)) ?>">vis alle år</a></p>
<?php endif; ?>

<div class="cards">
    <div class="card"><span class="num"><?= count($shows) ?></span><span class="lbl">Stævner</span></div>
    <div class="card"><span class="num"><?= array_sum(array_column($shows, 'klasser')) ?></span><span class="lbl">Klasser</span></div>
    <div class="card"><span class="num"><?= number_format($totRyttere, 0, ',', '.') ?></span><span class="lbl">Ryttere i alt</span></div>
</div>

<section class="drf-box">
    <h2>DRF officials-liste
        <?= $off['drf_listed'] ? '<span class="badge badge-drf">✓ på listen</span>' : '<span class="badge badge-muted">ikke fundet</span>' ?>
    </h2>
    <?php if ($drfRoles): ?>
        <table class="data">
            <thead><tr><th>Kategori</th><th>Type</th><th>Distrikt</th></tr></thead>
            <tbody>
            <?php foreach ($drfRoles as $dr): ?>
                <tr><td><?= h($dr['kategori']) ?></td><td><?= h($dr['type']) ?></td><td><?= h($dr['distrikt']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p class="muted">Denne official er ikke matchet på den høstede DRF-liste.
            Det kan skyldes stavning/navneforskelle – se <a href="<?= h(url('drf.php')) ?>">DRF-afstemningen</a>.</p>
    <?php endif; ?>
</section>

<div class="grid2">
    <section>
        <h2>Roller</h2>
        <table class="data">
            <thead><tr><th>Rolle</th><th class="r">Antal</th></tr></thead>
            <tbody>
            <?php foreach ($roles as $r): ?>
                <tr><td><?= h($r['rolle']) ?></td><td class="r"><?= (int)$r['antal'] ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
    <section>
        <h2>Niveauer</h2>
        <table class="data">
            <thead><tr><th>Niveau</th><th class="r">Klasser</th></tr></thead>
            <tbody>
            <?php foreach ($levels as $l): ?>
                <tr><td><?= level_badge($l['code']) ?> <?= h($l['label']) ?></td><td class="r"><?= (int)$l['klasser'] ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$levels): ?><tr><td colspan="2" class="muted">–</td></tr><?php endif; ?>
            </tbody>
        </table>
    </section>
</div>

<h2>Stævner</h2>
<table class="data">
    <thead>
        <tr><th>Dato</th><th>Stævne</th><th>Klub</th><th>Disciplin</th><th>Niveau</th>
            <th class="r">Klasser</th><th class="r">Ryttere</th><th>Roller</th></tr>
    </thead>
    <tbody>
    <?php foreach ($shows as $s): ?>
        <tr>
            <td><?= dk_date($s['dato']) ?></td>
            <td><a href="<?= h(url('show.php?id=' . (int)$s['id'])) ?>"><?= h($s['prop']) ?></a></td>
            <td><?= h($s['klub'] ?? '–') ?></td>
            <td><?= h($s['disciplin'] ?? '') ?></td>
            <td><?= level_badge($s['top_code'], $s['has_lower']) ?></td>
            <td class="r"><?= (int)$s['klasser'] ?></td>
            <td class="r"><?= number_format((int)$s['ryttere'], 0, ',', '.') ?></td>
            <td class="small"><?= h($s['roller']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php
render_footer();
