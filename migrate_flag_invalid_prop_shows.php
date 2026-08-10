<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_admin();

/**
 * Admin-side til engangsmigreringen i inc/InvalidPropShowMigrator.php - til
 * hosting uden shell-/CLI-adgang (se cli/migrate_flag_invalid_prop_shows.php
 * for CLI-udgaven, som gør det samme). Viser altid et dry-run-preview først;
 * migreringen udføres kun ved eksplicit POST med bekræftelse. Idempotent -
 * kan trygt genkøres, den finder 0 rækker anden gang.
 */
$applied = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'apply' && ($_POST['confirm'] ?? '') === '1') {
    $applied = (new InvalidPropShowMigrator(db()))->run(false);
}

$preview = (new InvalidPropShowMigrator(db()))->run(true);

render_header('Migrering: ugyldige prop-id\'er', 'import');
?>
<p><a href="<?= h(url('import.php')) ?>">← Import</a></p>
<h1>Engangsmigrering: stævner med ugyldigt prop-id</h1>
<p class="muted">Retter <code>shows.prop_unknown</code> for stævner der har et ikke-tomt <code>prop</code> som
    alligevel ikke kan slås op på DRF (fx et bart tal, et UUID eller <code>EQ_ID_...</code> i stedet for
    <code>PropNNNNN</code>). Uden dette bliver de ved med at fejle igen og igen i hvert batch under
    <a href="<?= h(url('import_show_details.php')) ?>">DRF-klassedetaljer</a> i stedet for at blive sprunget
    permanent over.</p>

<?php if ($applied): ?>
    <div class="notice ok">
        <strong>Migrering udført.</strong>
        <?= (int)$applied['found'] ?> stævne(r) markeret som <code>prop_unknown = 1</code>.
    </div>
<?php endif; ?>

<?php if ($preview['found'] === 0): ?>
    <div class="notice ok">Ingen stævner mangler denne rettelse - migreringen er allerede kørt (eller unødvendig).</div>
<?php else: ?>
    <div class="notice">
        <?= (int)$preview['found'] ?> stævne(r) vil blive markeret som <code>prop_unknown = 1</code>:
    </div>
    <table class="data">
        <thead><tr><th>Stævne</th><th>År</th><th>Prop</th></tr></thead>
        <tbody>
        <?php foreach (array_slice($preview['rows'], 0, 200) as $r): ?>
            <tr>
                <td><a href="<?= h(url('show.php?id=' . $r['id'])) ?>">#<?= (int)$r['id'] ?></a></td>
                <td><?= h($r['aar'] ?? '–') ?></td>
                <td><?= h($r['prop']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php if (count($preview['rows']) > 200): ?>
        <p class="muted">... og <?= count($preview['rows']) - 200 ?> flere.</p>
    <?php endif; ?>

    <form method="post" style="margin:.8rem 0">
        <input type="hidden" name="action" value="apply">
        <label class="muted" style="font-size:.85rem">
            <input type="checkbox" name="confirm" value="1" required>
            Ja, markér disse <?= (int)$preview['found'] ?> stævner som prop_unknown
        </label>
        <p><button class="btn" type="submit">Udfør migrering</button></p>
    </form>
<?php endif; ?>
<?php
render_footer();
