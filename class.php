<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_login();

$id      = (int)($_GET['id'] ?? 0);
$isAdmin = (current_user()['role'] ?? '') === 'admin';
$stats   = new Stats(db());
$class   = $stats->classInfo($id);

if (!$class) {
    render_header('Klasse', 'shows');
    echo '<div class="notice error">Klasse ikke fundet.</div>';
    render_footer();
    exit;
}

$error = null;
$ok    = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'update_rolle';

    try {
        if ($action === 'delete_assignment') {
            require_admin();
            $assignId = (int)($_POST['assignment_id'] ?? 0);
            if (!$assignId) {
                throw new InvalidArgumentException('Ukendt tildeling.');
            }
            $row = db()->one('SELECT class_id, official_id, orig_rolle FROM assignments WHERE id = ?', [$assignId]);
            if (!$row || (int)$row['class_id'] !== $id) {
                throw new InvalidArgumentException('Tildelingen hører ikke til denne klasse.');
            }
            db()->run('DELETE FROM assignments WHERE id = ?', [$assignId]);
            // Husk sletningen, saa en senere import af samme kildedata ikke genskaber
            // netop denne (klasse, official, rolle) - se Importer::isTombstoned() og
            // deleted_assignments.php.
            db()->run(
                'INSERT INTO deleted_assignments (class_id, official_id, orig_rolle, deleted_by)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE deleted_by = VALUES(deleted_by), deleted_at = NOW()',
                [(int)$row['class_id'], (int)$row['official_id'], $row['orig_rolle'], current_user()['id'] ?? null]
            );
            $ok = 'Tildeling slettet - importeres ikke igen ved en senere import af samme kildedata.';
        } elseif ($action === 'add_assignment') {
            $officialId = (int)($_POST['official_id'] ?? 0);
            $nyRolle    = trim($_POST['rolle'] ?? '');
            $nummer     = trim($_POST['nummer'] ?? '');
            if (!$officialId) {
                throw new InvalidArgumentException('Vælg en official.');
            }
            if ($nyRolle === '') {
                throw new InvalidArgumentException('Vælg en rolle.');
            }
            $officialExists = db()->scalar('SELECT id FROM officials WHERE id = ? AND status = ?', [$officialId, 'aktiv']);
            if ($officialExists === false) {
                throw new InvalidArgumentException('Ukendt eller ikke-aktiv official.');
            }
            $conflict = db()->scalar(
                'SELECT id FROM assignments WHERE class_id = ? AND official_id = ? AND rolle = ?',
                [$id, $officialId, $nyRolle]
            );
            if ($conflict !== false) {
                throw new InvalidArgumentException('Denne official har allerede rollen "' . $nyRolle . '" på klassen.');
            }
            db()->run(
                'INSERT INTO assignments (class_id, official_id, rolle, orig_rolle, nummer) VALUES (?, ?, ?, ?, ?)',
                [$id, $officialId, $nyRolle, $nyRolle, $nummer !== '' ? $nummer : null]
            );
            $ok = 'Tildeling oprettet.';
        } else {
            $assignId = (int)($_POST['assignment_id'] ?? 0);
            $nyRolle  = trim($_POST['rolle'] ?? '');
            if (!$assignId) {
                throw new InvalidArgumentException('Ukendt tildeling.');
            }
            $nyRolle = $nyRolle === '' ? '(ukendt)' : $nyRolle;

            $row = db()->one('SELECT class_id, official_id, rolle FROM assignments WHERE id = ?', [$assignId]);
            if (!$row || (int)$row['class_id'] !== $id) {
                throw new InvalidArgumentException('Tildelingen hører ikke til denne klasse.');
            }

            if ($row['rolle'] !== $nyRolle) {
                // assignments har unik nøgle (class_id, official_id, rolle) - tjek for
                // kollision saa vi kan give en pæn fejl i stedet for en SQL-exception.
                $conflict = db()->scalar(
                    'SELECT id FROM assignments WHERE class_id = ? AND official_id = ? AND rolle = ? AND id <> ?',
                    [$id, $row['official_id'], $nyRolle, $assignId]
                );
                if ($conflict !== false) {
                    throw new InvalidArgumentException('Denne official har allerede rollen "' . $nyRolle . '" på klassen.');
                }
                db()->run('UPDATE assignments SET rolle = ? WHERE id = ?', [$nyRolle, $assignId]);
            }
            $ok = 'Rolle opdateret.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$assignments     = $stats->classAssignments($id);
$roles           = $stats->rolesForDiscipline($class['disciplin'] ?? '');
$roleNames       = array_column($roles, 'navn');
$activeOfficials = $stats->activeOfficials();
$riders          = $stats->classRiders($id);

render_header($class['klassenavn'], 'shows');
back_link(url('show.php?id=' . (int)$class['show_id']), $class['prop']);
?>
<h1><?= h($class['klassenavn']) ?> <?= level_badge($class['niveau_code']) ?></h1>

<table class="kv">
    <tr><th>Klassenr.</th><td><?= h($class['klassenr']) ?></td></tr>
    <tr><th>Disciplin</th><td><?= h($class['disciplin'] ?? '–') ?></td></tr>
    <tr><th>Startende ryttere</th><td><?= $class['starter'] === null ? '–' : (int)$class['starter'] ?></td></tr>
    <?php if ($class['hest_pony'] !== null || $class['svaerhedsgrad'] !== null): ?>
        <tr><th>Hest/Pony</th><td><?= h($class['hest_pony'] ?? '–') ?></td></tr>
        <tr><th>Sværhedsgrad</th><td><?= $class['svaerhedsgrad'] === null ? '–' : (int)$class['svaerhedsgrad'] ?></td></tr>
    <?php endif; ?>
</table>

<?php if ($error): ?><div class="notice error"><strong>Fejl:</strong> <?= h($error) ?></div><?php endif; ?>
<?php if ($ok): ?><div class="notice ok"><?= h($ok) ?></div><?php endif; ?>

<h2>Officials og roller</h2>
<p class="muted">Ret rollen for en official hvis der er valgt forkert i den importerede fil, slet en
    tildeling helt, eller tilføj en ny.</p>
<?php if (!$roleNames): ?>
    <p class="muted">Ingen roller i <a href="<?= h(url('roles.php')) ?>">rollekataloget</a> matcher denne
        disciplin endnu - tilføj/klassificér en rolle under Roller.</p>
<?php endif; ?>

<table class="data">
    <thead><tr><th>Official</th><th>Rolle</th><th>Nummer</th><?php if ($isAdmin): ?><th></th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($assignments as $a): ?>
        <?php
        // Klassens nuvaerende rolle er altid en valgmulighed, ogsaa hvis den (endnu)
        // ikke er klassificeret til denne disciplin i rollekataloget.
        $current = $a['rolle'];
        $options = $roleNames;
        if ($current !== '' && !in_array($current, $options, true)) {
            $options[] = $current;
            sort($options);
        }
        ?>
        <tr>
            <td><a href="<?= h(url('official.php?id=' . (int)$a['official_id']) . '&' . from_params($class['klassenavn'])) ?>"><?= h($a['navn']) ?></a></td>
            <td>
                <form method="post" style="display:flex;gap:.4rem">
                    <input type="hidden" name="action" value="update_rolle">
                    <input type="hidden" name="assignment_id" value="<?= (int)$a['id'] ?>">
                    <select name="rolle">
                        <?php foreach ($options as $opt): ?>
                            <option value="<?= h($opt) ?>" <?= $opt === $current ? 'selected' : '' ?>><?= h($opt) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn" type="submit">Gem</button>
                </form>
            </td>
            <td><?= h($a['nummer'] ?? '–') ?></td>
            <?php if ($isAdmin): ?>
            <td>
                <form method="post" onsubmit="return confirm('Slet denne tildeling (<?= h($a['navn']) ?> - <?= h($current) ?>) helt? Kan ikke fortrydes.');">
                    <input type="hidden" name="action" value="delete_assignment">
                    <input type="hidden" name="assignment_id" value="<?= (int)$a['id'] ?>">
                    <button class="btn" type="submit" style="background:#c0392b">Slet</button>
                </form>
            </td>
            <?php endif; ?>
        </tr>
    <?php endforeach; ?>
    <?php if (!$assignments): ?>
        <tr><td colspan="<?= $isAdmin ? 4 : 3 ?>" class="muted">Ingen officials registreret på denne klasse.</td></tr>
    <?php endif; ?>
    </tbody>
</table>

<h3>Tilføj tildeling</h3>
<form method="post" style="display:flex;gap:.4rem;align-items:center;flex-wrap:wrap;margin:.6rem 0">
    <input type="hidden" name="action" value="add_assignment">
    <select name="official_id" required>
        <option value="">– vælg official –</option>
        <?php foreach ($activeOfficials as $o): ?>
            <option value="<?= (int)$o['id'] ?>"><?= h($o['navn']) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="rolle" required>
        <option value="">– vælg rolle –</option>
        <?php foreach ($roleNames as $opt): ?>
            <option value="<?= h($opt) ?>"><?= h($opt) ?></option>
        <?php endforeach; ?>
    </select>
    <input type="text" name="nummer" placeholder="Nummer (valgfri)" size="10">
    <button class="btn" type="submit">Tilføj</button>
</form>

<h2>Ryttere</h2>
<?php if ($riders): ?>
    <table class="data">
        <thead><tr><th>Rytter</th><th>DRF-nummer</th></tr></thead>
        <tbody>
        <?php foreach ($riders as $r): ?>
            <tr>
                <td><a href="<?= h(url('rider.php?id=' . (int)$r['id']) . '&' . from_params($class['klassenavn'])) ?>"><?= h($r['navn']) ?></a></td>
                <td><?= h($r['drf_rider_id']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php else: ?>
    <p class="muted">Ingen ryttere hentet endnu - brug "Hent ryttere fra DRF" på stævnets side
        (kræver status "Resultatbehandling færdig").</p>
<?php endif; ?>
<?php
render_footer();
