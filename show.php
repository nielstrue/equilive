<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$stats = new Stats(db());
$s = $stats->show($id);

if (!$s) {
    render_header('Stævne', 'shows');
    echo '<div class="notice error">Stævne ikke fundet.</div>';
    render_footer();
    exit;
}

$error = null;
$detailResult = null;
$statusError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'harvest_details') {
    try {
        $detailResult = (new ShowDetailImporter(db()))->import($id);
        $s = $stats->show($id); // genindlæs detail_harvested_at
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_status') {
    $nyStatus = $_POST['status'] ?? '';
    $note     = trim($_POST['status_note'] ?? '');
    if (!in_array($nyStatus, ['aktiv', 'udelukket'], true)) {
        $statusError = 'Ukendt status.';
    } else {
        db()->run('UPDATE shows SET status = ?, status_note = ? WHERE id = ?', [$nyStatus, $note !== '' ? $note : null, $id]);
        $s['status'] = $nyStatus;
        $s['status_note'] = $note !== '' ? $note : null;
    }
}

$classes = $stats->showClasses($id);
$ryttere = array_sum(array_map(fn($c) => (int)$c['starter'], $classes));
$discipliner = array_values(array_unique(array_filter(array_column($classes, 'disciplin'))));
sort($discipliner);

// Bevar det filter shows.php blev tilgaaet med, saa "← Alle stævner" foerer tilbage
// til den samme filtrerede/sorterede liste i stedet for at nulstille den.
$backQuery = http_build_query(array_diff_key($_GET, ['id' => true]));
$backUrl = url('shows.php') . ($backQuery !== '' ? '?' . $backQuery : '');

render_header($s['prop'], 'shows');
?>
<p><a href="<?= h($backUrl) ?>">← Alle stævner</a></p>
<h1><?= h($s['prop']) ?> <?= level_badge($s['top_code'], $s['has_lower']) ?> <?= show_status_badge($s['status']) ?></h1>

<?php if ($s['status'] === 'udelukket'): ?>
    <div class="notice">Dette stævne er udelukket fra alle statistikker, opgørelser og officials-visninger.
        <?php if ($s['status_note']): ?>Begrundelse: <?= h($s['status_note']) ?><?php endif; ?></div>
<?php endif; ?>

<?php if ($statusError): ?><div class="notice error"><?= h($statusError) ?></div><?php endif; ?>
<form method="post" style="display:flex;gap:.4rem;align-items:center;margin:.6rem 0;flex-wrap:wrap">
    <input type="hidden" name="action" value="set_status">
    <label class="muted" style="font-size:.85rem">Status:
        <select name="status">
            <option value="aktiv"     <?= $s['status']==='aktiv'     ? 'selected' : '' ?>>Aktiv</option>
            <option value="udelukket" <?= $s['status']==='udelukket' ? 'selected' : '' ?>>Udelukket (tæller ikke med nogen steder)</option>
        </select>
    </label>
    <input type="text" name="status_note" placeholder="Begrundelse (valgfri)" size="30" value="<?= h($s['status_note'] ?? '') ?>">
    <button class="btn" type="submit">Gem</button>
</form>

<table class="kv">
    <tr><th>Klub</th><td><?= h($s['klub'] ?? '–') ?><?= $s['forkort'] ? ' (' . h($s['forkort']) . ')' : '' ?></td></tr>
    <tr><th>Dato</th><td><?= dk_date($s['dato']) ?></td></tr>
    <tr><th>Disciplin</th><td><?= h($discipliner ? implode(' / ', $discipliner) : ($s['disciplin'] ?? '–')) ?></td></tr>
    <tr><th>Stævneniveau</th><td><?= h(Levels::label($s['top_slug'])) ?><?= $s['has_lower'] ? ' – har også klasser på lavere niveau' : '' ?></td></tr>
    <tr><th>Klasser</th><td><?= count($classes) ?></td></tr>
    <tr><th>Startende ryttere i alt</th><td><?= number_format($ryttere, 0, ',', '.') ?></td></tr>
    <?php if ($s['prop_unknown']): ?><tr><th>Bemærk</th><td class="muted">Prop manglede i kilden – stævnet er identificeret ud fra klub + dato.</td></tr><?php endif; ?>
</table>

<?php if ($error): ?><div class="notice error"><strong>Fejl:</strong> <?= h($error) ?></div><?php endif; ?>
<?php if ($detailResult): ?>
    <div class="notice ok">
        Klassedetaljer hentet fra DRF: <?= (int)$detailResult['classes_matched'] ?> af
        <?= (int)$detailResult['classes_total'] ?> klasser opdateret (hest/pony, sværhedsgrad).
    </div>
<?php endif; ?>

<form method="post" style="margin:.6rem 0">
    <input type="hidden" name="action" value="harvest_details">
    <button class="btn" type="submit"<?= $s['prop_unknown'] ? ' disabled' : '' ?>>
        <?= $s['detail_harvested_at'] ? 'Genindlæs klassedetaljer fra DRF' : 'Hent klassedetaljer fra DRF' ?>
    </button>
    <?php if ($s['detail_harvested_at']): ?>
        <span class="muted">Sidst hentet: <?= h($s['detail_harvested_at']) ?></span>
    <?php elseif ($s['prop_unknown']): ?>
        <span class="muted">Stævnet mangler et gyldigt Prop-id, så der kan ikke hentes detaljer.</span>
    <?php endif; ?>
</form>

<h2>Klasser</h2>
<table class="data">
    <thead>
        <tr><th>Nr.</th><th>Klassenavn</th><th>Niveau</th><th>Hest/Pony</th><th class="r">Svh</th>
            <th>Stilspringning</th><th class="r">Ryttere</th><th>Officials</th></tr>
    </thead>
    <tbody>
    <?php foreach ($classes as $c): ?>
        <tr>
            <td><?= h($c['klassenr']) ?></td>
            <td><a href="<?= h(url('class.php?id=' . (int)$c['id'])) ?>"><?= h($c['klassenavn']) ?></a></td>
            <td><?= level_badge($c['niveau_code']) ?></td>
            <td><?= h($c['hest_pony'] ?? '–') ?></td>
            <td class="r"><?= $c['svaerhedsgrad'] === null ? '–' : (int)$c['svaerhedsgrad'] ?></td>
            <td><?= ja_nej($c['stilspringning']) ?></td>
            <td class="r"><?= $c['starter'] === null ? '–' : (int)$c['starter'] ?></td>
            <td class="small"><?= h($c['officials']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php
render_footer();
