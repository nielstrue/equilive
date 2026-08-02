<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_admin();

/**
 * Engangsmigrering til hosting uden terminal/cron-adgang - kør denne side én
 * gang som admin, og slet den derefter via FTP hvis du vil rydde op (den er
 * admin-only og ufarlig at lade blive liggende: at køre den igen finder 0
 * rækker, da migreringen er idempotent). Se inc/CourseDesignerRoleMigrator.php
 * for selve logikken - samme klasse bruges af cli/migrate_normalize_course_designer_role.php
 * lokalt.
 */

$error  = null;
$result = null;
$preview = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run') {
    try {
        $result = (new CourseDesignerRoleMigrator(db()))->run(false);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
} else {
    try {
        $preview = (new CourseDesignerRoleMigrator(db()))->run(true);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

render_header('Migrering: banebygger-rolle', '');
?>
<h1>Engangsmigrering: course_designer → show_jumping_course_designer</h1>
<p class="muted">Retter eksisterende tildelinger på springklasser med rolle <code>course_designer</code>
    (en generisk rolle brugt på tværs af discipliner) til <code>show_jumping_course_designer</code>.
    Den oprindelige rolle bevares i <code>orig_rolle</code>. Klasser i andre discipliner (eventing,
    dressur mm.) rettes ikke.</p>

<?php if ($error): ?><div class="notice error"><strong>Fejl:</strong> <?= h($error) ?></div><?php endif; ?>

<?php if ($result): ?>
    <div class="notice ok">
        <strong>Migrering gennemført.</strong>
        <ul>
            <li>Fundet: <?= (int)$result['found'] ?></li>
            <li>Omdøbt til show_jumping_course_designer: <?= (int)$result['renamed'] ?></li>
            <li>Dubletter fjernet: <?= (int)$result['dupes_removed'] ?> (heraf <?= (int)$result['nummer_transferred'] ?> med nummer overført)</li>
        </ul>
        <p class="muted">Du kan nu slette denne fil (<code>migrate_course_designer_role.php</code>) via FTP, eller
            lade den blive liggende - den finder 0 rækker og gør intet, hvis den køres igen.</p>
    </div>
<?php elseif ($preview): ?>
    <div class="notice">
        <strong>Dry run - intet er ændret endnu.</strong>
        <ul>
            <li>Rækker der vil blive omdøbt til show_jumping_course_designer: <?= (int)$preview['renamed'] ?></li>
            <li>Dubletter der vil blive fjernet: <?= (int)$preview['dupes_removed'] ?> (heraf <?= (int)$preview['nummer_transferred'] ?> med nummer overført)</li>
        </ul>
    </div>
    <?php if ($preview['found'] > 0): ?>
        <form method="post" onsubmit="return confirm('Kør migreringen for <?= (int)$preview['found'] ?> rækker? Kan ikke fortrydes.');">
            <input type="hidden" name="action" value="run">
            <button class="btn" type="submit">Kør migrering nu</button>
        </form>
    <?php else: ?>
        <p class="muted">Ingen rækker at migrere - kørt allerede, eller ingen data matcher.</p>
    <?php endif; ?>
<?php endif; ?>
<?php
render_footer();
