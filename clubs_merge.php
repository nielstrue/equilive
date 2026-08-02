<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_admin();

$stats  = new Stats(db());
$result = null;
$error  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $keepId  = (int)($_POST['keep_id'] ?? 0);
    $mergeId = (int)($_POST['merge_id'] ?? 0);

    try {
        if (!$keepId || !$mergeId) {
            throw new InvalidArgumentException('Vælg begge klubber.');
        }
        $merger = new ClubMerger(db());
        $result = $merger->merge($keepId, $mergeId);
        $result['keep_id'] = $keepId;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$all      = $stats->clubsOverview('', '', 'navn');
$preKeep  = (int)($_GET['keep'] ?? 0);
$preMerge = (int)($_GET['merge'] ?? 0);

render_header('Flet klubber', 'clubs_merge');
?>
<p><a href="<?= h(url('clubs.php')) ?>">← Alle klubber</a></p>
<h1>Flet to klubber sammen</h1>
<p class="muted">Brug denne funktion når samme klub ved en fejl (fx en stavefejl eller manglende
    forkortelse i det importerede navn) optræder som to forskellige klubber. Alle stævner og
    DRF-klub-match flyttes til den bevarede post, og dubletten slettes. Der gemmes ingen historik -
    kan ikke fortrydes.</p>

<?php if ($error): ?>
    <div class="notice error"><strong>Fejl:</strong> <?= h($error) ?></div>
<?php endif; ?>

<?php if ($result): ?>
    <div class="notice ok">
        <strong>Flettet.</strong>
        <ul>
            <li>Stævner flyttet: <?= (int)$result['moved_shows'] ?></li>
            <li>DRF-klub-match flyttet: <?= (int)$result['moved_drf'] ?></li>
        </ul>
        <a class="btn" href="<?= h(url('club.php?id=' . (int)$result['keep_id'])) ?>">Se den flettede klub →</a>
    </div>
<?php else: ?>
<form method="post" onsubmit="return confirm('Sikker på at du vil flette disse to klubber? Handlingen kan ikke fortrydes.');">
    <p>
        <label>Behold (den korrekte post):<br>
        <select name="keep_id" required>
            <option value="">– vælg klub –</option>
            <?php foreach ($all as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= $preKeep === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['navn']) ?><?= $c['forkort'] ? ' (' . h($c['forkort']) . ')' : '' ?> (#<?= (int)$c['id'] ?> · <?= (int)$c['antal_staevner'] ?> stævner)</option>
            <?php endforeach; ?>
        </select>
        </label>
    </p>
    <p>
        <label>Flet ind i ovenstående og slet (dubletten):<br>
        <select name="merge_id" required>
            <option value="">– vælg klub –</option>
            <?php foreach ($all as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= $preMerge === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['navn']) ?><?= $c['forkort'] ? ' (' . h($c['forkort']) . ')' : '' ?> (#<?= (int)$c['id'] ?> · <?= (int)$c['antal_staevner'] ?> stævner)</option>
            <?php endforeach; ?>
        </select>
        </label>
    </p>
    <p><button class="btn" type="submit">Flet klubber</button></p>
</form>
<?php endif; ?>
<?php
render_footer();
