<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_admin();

/**
 * Admin-side til at styre hvilke roller der springes helt over ved CSV-import
 * (se Importer::import() - matcher raa CSV-rollen, orig_rolle). Kun admins
 * kan tilgå denne side (require_admin ovenfor).
 */
$error = null;
$ok    = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'add') {
            $rolle = trim($_POST['rolle'] ?? '');
            if ($rolle === '') {
                throw new InvalidArgumentException('Mangler et rollenavn.');
            }
            db()->run(
                'INSERT INTO import_excluded_roles (rolle) VALUES (?) ON DUPLICATE KEY UPDATE rolle = rolle',
                [$rolle]
            );
            $ok = 'Rollen "' . $rolle . '" springes nu over ved fremtidige imports.';
        } elseif ($action === 'remove') {
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) {
                throw new InvalidArgumentException('Ukendt række.');
            }
            db()->run('DELETE FROM import_excluded_roles WHERE id = ?', [$id]);
            $ok = 'Rollen importeres igen fremover.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$stats     = new Stats(db());
$excluded  = $stats->importExcludedRoles();
$candidates = $stats->importableRoleCandidates();

render_header('Ekskluderede import-roller', 'import');
?>
<p><a href="<?= h(url('import.php')) ?>">← Import</a></p>
<h1>Roller der springes over ved import</h1>
<p class="muted">Rækker i CSV-filen med en af rollerne herunder importeres slet ikke - der oprettes
    hverken show, klasse, official eller tildeling for den række. Matcher den rå rolle fra
    kildefilen (før evt. omdøbning, se Importer::normalizeRolle). Dette påvirker kun
    <strong>fremtidige</strong> imports - allerede importerede tildelinger fjernes ikke automatisk.</p>

<?php if ($error): ?><div class="notice error"><strong>Fejl:</strong> <?= h($error) ?></div><?php endif; ?>
<?php if ($ok): ?><div class="notice ok"><?= h($ok) ?></div><?php endif; ?>

<h2>Ekskluderede roller</h2>
<table class="data">
    <thead><tr><th>Rolle</th><th>Tilføjet</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($excluded as $e): ?>
        <tr>
            <td><?= h($e['rolle']) ?></td>
            <td><?= h($e['created_at']) ?></td>
            <td>
                <form method="post" onsubmit="return confirm('Fjern \'<?= h($e['rolle']) ?>\' fra eksklusionslisten? Rollen importeres igen fremover.');">
                    <input type="hidden" name="action" value="remove">
                    <input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
                    <button class="btn" type="submit" style="background:#c0392b">Fjern</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$excluded): ?>
        <tr><td colspan="3" class="muted">Ingen roller ekskluderet - alle roller importeres.</td></tr>
    <?php endif; ?>
    </tbody>
</table>

<h2>Tilføj rolle til eksklusionslisten</h2>
<form method="post" style="display:flex;gap:.4rem;align-items:center;flex-wrap:wrap;margin:.6rem 0">
    <input type="hidden" name="action" value="add">
    <input type="text" name="rolle" list="role-candidates" placeholder="Rollenavn, fx steward" required>
    <datalist id="role-candidates">
        <?php foreach ($candidates as $c): ?>
            <option value="<?= h($c['rolle']) ?>"><?= h($c['rolle']) ?> (<?= (int)$c['antal'] ?> tildelinger hidtil)</option>
        <?php endforeach; ?>
    </datalist>
    <button class="btn" type="submit">Tilføj</button>
</form>
<p class="muted">Skriv rollenavnet præcis som det står i kildefilen (fx <code>steward</code>), eller
    vælg fra listen af roller der tidligere er set i imports.</p>
<?php
render_footer();
