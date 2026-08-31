<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$isAdmin = (current_user()['role'] ?? '') === 'admin';
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
$riderResult = null;
$statusError = null;
$deleteResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_official_role') {
    require_admin();
    $officialId = (int)($_POST['official_id'] ?? 0);
    $rolle      = trim($_POST['rolle'] ?? '');
    if (!$officialId || $rolle === '') {
        $error = 'Ukendt official eller rolle.';
    } else {
        try {
            $navn = db()->scalar('SELECT navn FROM officials WHERE id = ?', [$officialId]);
            // Hent (klasse, raa rolle) for hver ramt tildeling FØR sletning - skal
            // bruges til at oprette en tombstone pr. klasse bagefter (se
            // Importer::isTombstoned() og deleted_assignments.php), saa ingen af
            // dem genskabes ved en senere import af samme kildedata.
            $rows = db()->all(
                'SELECT c.id AS class_id, a.orig_rolle
                 FROM assignments a JOIN classes c ON c.id = a.class_id
                 WHERE c.show_id = ? AND a.official_id = ? AND a.rolle = ?',
                [$id, $officialId, $rolle]
            );
            db()->run(
                'DELETE a FROM assignments a JOIN classes c ON c.id = a.class_id
                 WHERE c.show_id = ? AND a.official_id = ? AND a.rolle = ?',
                [$id, $officialId, $rolle]
            );
            foreach ($rows as $r) {
                db()->run(
                    'INSERT INTO deleted_assignments (class_id, official_id, orig_rolle, deleted_by)
                     VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE deleted_by = VALUES(deleted_by), deleted_at = NOW()',
                    [(int)$r['class_id'], $officialId, $r['orig_rolle'], current_user()['id'] ?? null]
                );
            }
            $deleteResult = ['navn' => $navn !== false ? $navn : ('#' . $officialId), 'rolle' => $rolle, 'antal' => count($rows)];
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'harvest_details') {
    try {
        $detailResult = (new ShowDetailImporter(db()))->import($id);
        $s = $stats->show($id); // genindlæs detail_harvested_at + resultat_status
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'harvest_riders') {
    try {
        $riderResult = (new RiderResultImporter(db()))->import($id);
        $s = $stats->show($id); // genindlæs riders_harvested_at
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
$showOfficials = $stats->showOfficials($id);
$ryttere = array_sum(array_map(fn($c) => (int)$c['starter'], $classes));
$discipliner = array_values(array_unique(array_filter(array_column($classes, 'disciplin'))));
sort($discipliner);

// Bevar det filter shows.php blev tilgaaet med, saa "← Alle stævner" foerer tilbage
// til den samme filtrerede/sorterede liste i stedet for at nulstille den (kun
// naar man reelt kom derfra - se back_link()/from_params() i inc/layout.php).
$backQuery = http_build_query(array_diff_key($_GET, ['id' => true, 'from' => true, 'from_label' => true]));
$backUrl = url('shows.php') . ($backQuery !== '' ? '?' . $backQuery : '');

render_header($s['prop'], 'shows');
back_link($backUrl, 'Alle stævner');
?>
<h1><?= h($s['prop']) ?> <?= level_badge($s['top_code'], $s['has_lower']) ?> <?= show_status_badge($s['status']) ?></h1>

<?php if ($s['status'] === 'udelukket'): ?>
    <div class="notice">Dette stævne er udelukket fra alle statistikker, opgørelser og officials-visninger.
        <?php if ($s['status_note']): ?>Begrundelse: <?= h($s['status_note']) ?><?php endif; ?></div>
<?php endif; ?>

<?php if ($statusError): ?><div class="notice error"><?= h($statusError) ?></div><?php endif; ?>
<form method="post" style="display:flex;gap:.4rem;align-items:center;margin:.6rem 0;flex-wrap:wrap"><?= csrf_field() ?>
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
        <?php if ($s['resultat_status']): ?>Status: <strong><?= h($s['resultat_status']) ?></strong>.<?php endif; ?>
    </div>
<?php endif; ?>
<?php if ($riderResult): ?>
    <div class="notice ok">
        Ryttere hentet fra DRF: <?= (int)$riderResult['riders_matched'] ?> ryttere fundet på tværs af
        <?= (int)$riderResult['classes_harvested'] ?> klasser.
    </div>
<?php endif; ?>
<?php if ($deleteResult): ?>
    <div class="notice ok">
        Slettede <?= (int)$deleteResult['antal'] ?> tildeling(er) af rollen "<?= h($deleteResult['rolle']) ?>"
        for <?= h($deleteResult['navn']) ?> på tværs af stævnets klasser.
    </div>
<?php endif; ?>

<form method="post" style="margin:.6rem 0;display:flex;gap:.6rem;align-items:center;flex-wrap:wrap"><?= csrf_field() ?>
    <input type="hidden" name="action" value="harvest_details">
    <button class="btn" type="submit"<?= $s['prop_unknown'] ? ' disabled' : '' ?>>
        <?= $s['detail_harvested_at'] ? 'Genindlæs klassedetaljer fra DRF' : 'Hent klassedetaljer fra DRF' ?>
    </button>
    <?php if ($s['detail_harvested_at']): ?>
        <span class="muted">Sidst hentet: <?= h($s['detail_harvested_at']) ?><?php if ($s['resultat_status']): ?> · Status: <?= h($s['resultat_status']) ?><?php endif; ?></span>
    <?php elseif ($s['prop_unknown']): ?>
        <span class="muted">Stævnet mangler et gyldigt Prop-id, så der kan ikke hentes detaljer.</span>
    <?php endif; ?>
</form>

<form method="post" style="margin:.6rem 0"><?= csrf_field() ?>
    <input type="hidden" name="action" value="harvest_riders">
    <button class="btn" type="submit"<?= $s['resultat_status'] !== 'Resultatbehandling færdig' ? ' disabled' : '' ?>>
        <?= $s['riders_harvested_at'] ? 'Genindlæs ryttere fra DRF' : 'Hent ryttere fra DRF' ?>
    </button>
    <?php if ($s['riders_harvested_at']): ?>
        <span class="muted">Sidst hentet: <?= h($s['riders_harvested_at']) ?></span>
    <?php elseif ($s['resultat_status'] !== 'Resultatbehandling færdig'): ?>
        <span class="muted">Kræver status "Resultatbehandling færdig" fra DRF (hent klassedetaljer først).</span>
    <?php endif; ?>
</form>

<h2>Officials på stævnet</h2>
<p class="muted">Alle officials og roller brugt på tværs af stævnets klasser - til hurtigt at få øje på en rolle der ser forkert ud.</p>
<table class="data">
    <thead><tr><th>Official</th><th>Rolle</th><th class="r">Klasser</th><?php if ($isAdmin): ?><th></th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($showOfficials as $o): ?>
        <tr>
            <td><a href="<?= h(url('official.php?id=' . (int)$o['official_id']) . '&' . from_params($s['prop'])) ?>"><?= h($o['navn']) ?></a></td>
            <td><?= h($o['rolle']) ?></td>
            <td class="r"><?= (int)$o['klasser'] ?></td>
            <?php if ($isAdmin): ?>
            <td>
                <form method="post" onsubmit="return confirm('Slet rollen \'<?= h($o['rolle']) ?>\' for <?= h($o['navn']) ?> på alle <?= (int)$o['klasser'] ?> klasse(r) i dette stævne? Kan ikke fortrydes.');"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete_official_role">
                    <input type="hidden" name="official_id" value="<?= (int)$o['official_id'] ?>">
                    <input type="hidden" name="rolle" value="<?= h($o['rolle']) ?>">
                    <button class="btn" type="submit" style="background:#c0392b;padding:.3rem .6rem;font-size:.8rem">Slet rolle</button>
                </form>
            </td>
            <?php endif; ?>
        </tr>
    <?php endforeach; ?>
    <?php if (!$showOfficials): ?>
        <tr><td colspan="<?= $isAdmin ? 4 : 3 ?>" class="muted">Ingen officials registreret på dette stævne.</td></tr>
    <?php endif; ?>
    </tbody>
</table>

<h2>Klasser</h2>
<table class="data">
    <thead>
        <tr><th>Nr.</th><th>Klassenavn</th><th>Niveau</th><th>Disciplin</th><th>Hest/Pony</th><th class="r">Svh</th>
            <th>Stilspringning</th><th class="r">Ryttere</th><th>Officials</th></tr>
    </thead>
    <tbody>
    <?php foreach ($classes as $c): ?>
        <tr>
            <td><?= h($c['klassenr']) ?></td>
            <td><a href="<?= h(url('class.php?id=' . (int)$c['id'])) ?>"><?= h($c['klassenavn']) ?></a></td>
            <td><?= level_badge($c['niveau_code']) ?></td>
            <td><?= h($c['disciplin'] ?? '–') ?></td>
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
