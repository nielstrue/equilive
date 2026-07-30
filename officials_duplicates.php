<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_admin();

$stats        = new Stats(db());
$batchResults = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['pairs']) && is_array($_POST['pairs'])) {
    $merger       = new OfficialMerger(db());
    $batchResults = [];

    foreach ($_POST['pairs'] as $pair) {
        $parts   = explode(':', (string)$pair, 2);
        $keepId  = (int)($parts[0] ?? 0);
        $mergeId = (int)($parts[1] ?? 0);
        if (!$keepId || !$mergeId) {
            continue;
        }

        // Navnene hentes foer selve fletningen, saa vi stadig kan vise dem i
        // resultatlisten selvom posten forsvinder (eller allerede er forsvundet
        // pga. et tidligere, overlappende par i samme batch).
        $keepRow   = $stats->official($keepId);
        $mergeRow  = $stats->official($mergeId);
        $keepNavn  = $keepRow  ? $keepRow['navn']  : '(fjernet i denne batch)';
        $mergeNavn = $mergeRow ? $mergeRow['navn'] : '(fjernet i denne batch)';

        try {
            $r = $merger->merge($keepId, $mergeId);
            $batchResults[] = [
                'ok' => true, 'keep_navn' => $keepNavn, 'merge_navn' => $mergeNavn,
                'moved' => $r['moved'], 'dropped' => $r['merged_duplicates'],
            ];
        } catch (Throwable $e) {
            $batchResults[] = [
                'ok' => false, 'keep_navn' => $keepNavn, 'merge_navn' => $mergeNavn,
                'error' => $e->getMessage(),
            ];
        }
    }
}

// Genberegnes altid - efter en batch er evt. flettede par vaek fra listen,
// saa man ikke skal genindlaese siden manuelt.
$candidates = $stats->possibleDuplicates();

render_header('Mulige dubletter', 'officials_duplicates');
?>
<p><a href="<?= h(url('officials.php')) ?>">← Alle officials</a></p>
<h1>Mulige dubletter blandt officials</h1>
<p class="muted">Kandidater fundet udelukkende ud fra navnelighed - enten identiske efter
    normalisering (mellemrum/store-/små bogstaver) eller få tegns forskel (typisk stavefejl).
    Det er ikke sikkert at det rent faktisk er samme person - tjek stævnehistorikken for
    begge, før du fletter. Marker flere og flet dem samlet, eller brug "Flet →" for én ad gangen.</p>

<?php if ($batchResults !== null): ?>
    <div class="notice <?= array_filter($batchResults, fn($r) => !$r['ok']) ? 'error' : 'ok' ?>">
        <strong>Batch-fletning gennemført.</strong>
        <ul>
        <?php foreach ($batchResults as $r): ?>
            <?php if ($r['ok']): ?>
                <li><?= h($r['merge_navn']) ?> → <?= h($r['keep_navn']) ?>: <?= (int)$r['moved'] ?> flyttet, <?= (int)$r['dropped'] ?> dubletter droppet.</li>
            <?php else: ?>
                <li><?= h($r['merge_navn']) ?> → <?= h($r['keep_navn']) ?>: <strong>fejl</strong> - <?= h($r['error']) ?></li>
            <?php endif; ?>
        <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<p class="muted"><?= count($candidates) ?> mulige par fundet.</p>

<form method="post" onsubmit="return confirm('Sikker på at du vil flette alle markerede par? Handlingen kan ikke fortrydes.');">
<table class="data">
    <thead>
        <tr>
            <th><input type="checkbox" onclick="document.querySelectorAll('.dup-check').forEach(function(c){c.checked=this.checked;}, this)"></th>
            <th>Behold (flest stævner)</th><th>Flet ind og slet</th><th>Årsag</th><th></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($candidates as $p): ?>
        <tr>
            <td><input class="dup-check" type="checkbox" name="pairs[]" value="<?= (int)$p['keep']['id'] ?>:<?= (int)$p['merge']['id'] ?>"></td>
            <td><a href="<?= h(url('official.php?id=' . (int)$p['keep']['id'])) ?>"><?= h($p['keep']['navn']) ?></a>
                <span class="muted">(<?= (int)$p['keep']['antal_staevner'] ?> stævner)</span></td>
            <td><a href="<?= h(url('official.php?id=' . (int)$p['merge']['id'])) ?>"><?= h($p['merge']['navn']) ?></a>
                <span class="muted">(<?= (int)$p['merge']['antal_staevner'] ?> stævner)</span></td>
            <td><?= h($p['reason']) ?></td>
            <td>
                <a class="btn" href="<?= h(url('officials_merge.php?keep=' . (int)$p['keep']['id'] . '&merge=' . (int)$p['merge']['id'])) ?>">Flet →</a>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$candidates): ?>
        <tr><td colspan="5" class="muted">Ingen mulige dubletter fundet.</td></tr>
    <?php endif; ?>
    </tbody>
</table>
<?php if ($candidates): ?>
    <p><button class="btn" type="submit">Flet markerede</button></p>
<?php endif; ?>
</form>
<?php
render_footer();
