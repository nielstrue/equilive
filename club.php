<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_login();

$id          = (int)($_GET['id'] ?? 0);
$selectedAar = array_map('strval', (array)($_GET['aar'] ?? []));
$isAdmin     = (current_user()['role'] ?? '') === 'admin';

$stats = new Stats(db());
$club  = $stats->club($id, $selectedAar);

if (!$club) {
    render_header('Klub', 'clubs');
    echo '<div class="notice error">Klub ikke fundet.</div>';
    render_footer();
    exit;
}

$statusError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_club') {
    require_admin();
    $nyNavn   = trim($_POST['navn'] ?? '');
    $nyStatus = $_POST['status'] ?? '';
    if ($nyNavn === '') {
        $statusError = 'Navn må ikke være tomt.';
    } elseif (!in_array($nyStatus, ['aktiv', 'ophoert'], true)) {
        $statusError = 'Ukendt status.';
    } else {
        db()->run('UPDATE clubs SET navn = ?, status = ? WHERE id = ?', [$nyNavn, $nyStatus, $id]);
        $club['navn'] = $nyNavn;
        $club['status'] = $nyStatus;
    }
}

$officials = $stats->clubOfficials($id, $selectedAar);
$shows     = $stats->clubShows($id, $selectedAar);

render_header($club['navn'], 'clubs');
?>
<p><a href="<?= h(url('clubs.php')) ?>">← Alle klubber</a></p>
<h1><?= h($club['navn']) ?><?= $club['forkort'] ? ' <span class="muted">(' . h($club['forkort']) . ')</span>' : '' ?> <?= club_status_badge($club['status']) ?></h1>

<?php if ($selectedAar): ?>
    <p class="muted">Filtreret på år: <strong><?= h(implode(', ', $selectedAar)) ?></strong>
        · <a href="<?= h(url('club.php?id=' . $id)) ?>">vis alle år</a></p>
<?php endif; ?>

<table class="kv">
    <tr><th>Distrikt</th><td><?= h($club['distrikt'] ?? '–') ?></td></tr>
    <tr><th>Postnummer</th><td><?= h($club['postnr'] ?? '–') ?></td></tr>
</table>

<?php if ($isAdmin): ?>
    <?php if ($statusError): ?><div class="notice error"><?= h($statusError) ?></div><?php endif; ?>
    <form method="post" style="display:flex;gap:.4rem;align-items:center;margin:.6rem 0;flex-wrap:wrap">
        <input type="hidden" name="action" value="update_club">
        <label class="muted" style="font-size:.85rem">Navn:
            <input type="text" name="navn" value="<?= h($club['navn']) ?>" size="30" required>
        </label>
        <label class="muted" style="font-size:.85rem">Status:
            <select name="status">
                <option value="aktiv"   <?= $club['status']==='aktiv'   ? 'selected' : '' ?>>Aktiv</option>
                <option value="ophoert" <?= $club['status']==='ophoert' ? 'selected' : '' ?>>Ophørt</option>
            </select>
        </label>
        <button class="btn" type="submit">Gem</button>
    </form>
<?php endif; ?>

<div class="cards">
    <div class="card"><span class="num"><?= (int)$club['antal_staevner'] ?></span><span class="lbl">Stævner</span></div>
    <div class="card"><span class="num"><?= count($officials) ?></span><span class="lbl">Officials brugt</span></div>
</div>

<h2>Officials brugt ved klubbens stævner</h2>
<table class="data">
    <thead>
        <tr>
            <th>Official</th>
            <th class="r">Stævner</th>
            <th class="r">Klasser</th>
            <th class="r">Roller</th>
            <th>Rollefordeling</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($officials as $o): ?>
        <tr>
            <td><a href="<?= h(url('official.php?id=' . (int)$o['id'])) ?>"><?= h($o['navn']) ?></a></td>
            <td class="r"><?= (int)$o['antal_staevner'] ?></td>
            <td class="r"><?= (int)$o['antal_klasser'] ?></td>
            <td class="r"><?= (int)$o['antal_roller'] ?></td>
            <td class="small"><?= h($o['roller']) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$officials): ?>
        <tr><td colspan="5" class="muted">Ingen officials registreret for denne klub endnu.</td></tr>
    <?php endif; ?>
    </tbody>
</table>

<h2>Stævner holdt af klubben</h2>
<table class="data">
    <thead>
        <tr><th>Dato</th><th>Prop</th><th>Disciplin</th><th>Niveau</th>
            <th class="r">Klasser</th><th class="r">Officials</th><th class="r">Ryttere</th></tr>
    </thead>
    <tbody>
    <?php foreach ($shows as $sh): ?>
        <tr>
            <td><?= dk_date($sh['dato']) ?></td>
            <td>
                <a href="<?= h(url('show.php?id=' . (int)$sh['id'])) ?>"><?= h($sh['prop']) ?></a>
                <?php if ($sh['prop_unknown']): ?><span class="badge badge-warn" title="Prop manglede i kilden">?</span><?php endif; ?>
            </td>
            <td><?= h($sh['disciplin'] ?? '') ?></td>
            <td><?= level_badge($sh['top_code'], $sh['has_lower']) ?></td>
            <td class="r"><?= (int)$sh['klasser'] ?></td>
            <td class="r"><?= (int)$sh['officials'] ?></td>
            <td class="r"><?= number_format((int)$sh['ryttere'], 0, ',', '.') ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$shows): ?>
        <tr><td colspan="7" class="muted">Ingen stævner registreret for denne klub endnu.</td></tr>
    <?php endif; ?>
    </tbody>
</table>
<?php
render_footer();
