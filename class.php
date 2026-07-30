<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_login();

$id    = (int)($_GET['id'] ?? 0);
$stats = new Stats(db());
$class = $stats->classInfo($id);

if (!$class) {
    render_header('Klasse', 'shows');
    echo '<div class="notice error">Klasse ikke fundet.</div>';
    render_footer();
    exit;
}

$error = null;
$ok    = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $assignId = (int)($_POST['assignment_id'] ?? 0);
    $nyRolle  = trim($_POST['rolle'] ?? '');

    try {
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
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$assignments = $stats->classAssignments($id);
$roles       = $stats->rolesForDiscipline($class['disciplin'] ?? '');
$roleNames   = array_column($roles, 'navn');

render_header($class['klassenavn'], 'shows');
?>
<p><a href="<?= h(url('show.php?id=' . (int)$class['show_id'])) ?>">← <?= h($class['prop']) ?></a></p>
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
<p class="muted">Ret rollen for en official hvis der er valgt forkert i den importerede fil.</p>
<?php if (!$roleNames): ?>
    <p class="muted">Ingen roller i <a href="<?= h(url('roles.php')) ?>">rollekataloget</a> matcher denne
        disciplin endnu - tilføj/klassificér en rolle under Roller.</p>
<?php endif; ?>

<table class="data">
    <thead><tr><th>Official</th><th>Rolle</th><th>Nummer</th></tr></thead>
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
            <td><a href="<?= h(url('official.php?id=' . (int)$a['official_id'])) ?>"><?= h($a['navn']) ?></a></td>
            <td>
                <form method="post" style="display:flex;gap:.4rem">
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
        </tr>
    <?php endforeach; ?>
    <?php if (!$assignments): ?>
        <tr><td colspan="3" class="muted">Ingen officials registreret på denne klasse.</td></tr>
    <?php endif; ?>
    </tbody>
</table>
<?php
render_footer();
