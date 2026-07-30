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
    $navn    = trim($_POST['navn'] ?? '');

    try {
        if (!$keepId || !$mergeId) {
            throw new InvalidArgumentException('Vælg begge officials.');
        }
        $merger = new OfficialMerger(db());
        $result = $merger->merge($keepId, $mergeId, $navn !== '' ? $navn : null);
        $result['keep_id'] = $keepId;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$all      = $stats->officialsOverview('', 'navn');
$preKeep  = (int)($_GET['keep'] ?? 0);
$preMerge = (int)($_GET['merge'] ?? 0);

render_header('Flet officials', 'officials_merge');
?>
<p><a href="<?= h(url('officials.php')) ?>">← Alle officials</a> ·
   <a href="<?= h(url('officials_duplicates.php')) ?>">Find mulige dubletter →</a></p>
<h1>Flet to officials sammen</h1>
<p class="muted">Brug denne funktion når samme person ved en fejl (fx en stavefejl i
    det importerede navn) optræder som to forskellige officials. Alle stævner,
    klasser og roller flyttes til den bevarede post, og dubletten slettes. Kan
    ikke fortrydes.</p>

<?php if ($error): ?>
    <div class="notice error"><strong>Fejl:</strong> <?= h($error) ?></div>
<?php endif; ?>

<?php if ($result): ?>
    <div class="notice ok">
        <strong>Flettet.</strong>
        <ul>
            <li>Tildelinger flyttet: <?= (int)$result['moved'] ?></li>
            <li>Dubletter droppet (fandtes allerede på begge poster): <?= (int)$result['merged_duplicates'] ?></li>
        </ul>
        <a class="btn" href="<?= h(url('official.php?id=' . (int)$result['keep_id'])) ?>">Se den flettede official →</a>
    </div>
<?php else: ?>
<form method="post" onsubmit="return confirm('Sikker på at du vil flette disse to officials? Handlingen kan ikke fortrydes.');">
    <p>
        <label>Behold (den korrekte post):<br>
        <select name="keep_id" required>
            <option value="">– vælg official –</option>
            <?php foreach ($all as $o): ?>
                <option value="<?= (int)$o['id'] ?>" <?= $preKeep === (int)$o['id'] ? 'selected' : '' ?>><?= h($o['navn']) ?> (#<?= (int)$o['id'] ?> · <?= (int)$o['antal_staevner'] ?> stævner)</option>
            <?php endforeach; ?>
        </select>
        </label>
    </p>
    <p>
        <label>Flet ind i ovenstående og slet (dubletten):<br>
        <select name="merge_id" required>
            <option value="">– vælg official –</option>
            <?php foreach ($all as $o): ?>
                <option value="<?= (int)$o['id'] ?>" <?= $preMerge === (int)$o['id'] ? 'selected' : '' ?>><?= h($o['navn']) ?> (#<?= (int)$o['id'] ?> · <?= (int)$o['antal_staevner'] ?> stævner)</option>
            <?php endforeach; ?>
        </select>
        </label>
    </p>
    <p>
        <label>Navn på den bevarede post (valgfrit - ret hvis ingen af de to er stavet korrekt):<br>
        <input type="text" name="navn" size="40">
        </label>
    </p>
    <p><button class="btn" type="submit">Flet officials</button></p>
</form>
<?php endif; ?>
<?php
render_footer();
