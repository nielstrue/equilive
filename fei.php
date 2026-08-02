<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_login();

$stats   = new Stats(db());
$isAdmin = (current_user()['role'] ?? '') === 'admin';

$linkError  = null;
$linkOk     = null;
$matchResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'match_fei') {
    require_admin();
    try {
        $matchResult = (new FeiOfficialMatcher(db()))->match();
    } catch (Throwable $e) {
        $linkError = $e->getMessage();
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'link_fei') {
    require_admin();
    $feiId        = (int)($_POST['fei_id'] ?? 0);
    $officialNavn = trim($_POST['official_navn'] ?? '');
    try {
        $linker = new FeiOfficialLinker(db());
        if ($officialNavn !== '') {
            $officialId = (int)(db()->scalar('SELECT id FROM officials WHERE navn = ?', [$officialNavn]) ?: 0);
            if (!$officialId) {
                throw new InvalidArgumentException('Fandt ingen official med navnet "' . $officialNavn . '".');
            }
            $linker->linkToExisting($feiId, $officialId);
            $linkOk = 'FEI-personen knyttet til "' . $officialNavn . '".';
        } else {
            $linker->createNew($feiId);
            $linkOk = 'FEI-personen oprettet som ny official.';
        }
    } catch (Throwable $e) {
        $linkError = $e->getMessage();
    }
}

$sum = $stats->feiSummary();

$search       = trim($_GET['q'] ?? '');
$discipline   = trim($_GET['discipline'] ?? '');
$allCountries = !empty($_GET['alle_lande']);

render_header('FEI-liste', 'fei');
?>
<h1>FEI officials-liste</h1>
<p class="muted">FEI's internationale officials-liste matchet mod dine officials. Nøgletallene herunder er for de
    danske personer (NF = Denmark), men matchningen dækker hele listen - nogle af dine officials er udenlandske
    FEI-officials der har virket ved internationale stævner i Danmark, se "<?= h('Udenlandske FEI-officials i dine data') ?>" nedenfor.</p>

<?php if ($linkError): ?><div class="notice error"><strong>Fejl:</strong> <?= h($linkError) ?></div><?php endif; ?>
<?php if ($linkOk): ?><div class="notice ok"><?= h($linkOk) ?></div><?php endif; ?>
<?php if ($matchResult): ?>
    <div class="notice ok">
        Matchning gennemført: <?= (int)$matchResult['matched'] ?> matchet, <?= (int)$matchResult['unmatched'] ?> uden match.
    </div>
<?php endif; ?>

<?php if ($sum['persons'] === 0): ?>
    <div class="notice">Der er endnu ikke indlæst nogen FEI-liste i <code>fei_officials</code>/<code>fei_official_functions</code>.</div>
<?php else: ?>

<div class="cards">
    <div class="card"><span class="num"><?= number_format($sum['persons'], 0, ',', '.') ?></span><span class="lbl">Danske personer på FEI-listen</span></div>
    <div class="card"><span class="num"><?= number_format($sum['functions'], 0, ',', '.') ?></span><span class="lbl">Funktioner i alt</span></div>
    <div class="card"><span class="num"><?= number_format($sum['matched'], 0, ',', '.') ?></span><span class="lbl">Matchede officials (alle lande)</span></div>
    <div class="card"><span class="num"><?= number_format($sum['matched_foreign'], 0, ',', '.') ?></span><span class="lbl">Heraf udenlandske FEI-officials</span></div>
    <div class="card"><span class="num"><?= number_format($sum['unmatched'], 0, ',', '.') ?></span><span class="lbl">Danske FEI-personer uden match</span></div>
</div>
<p class="muted">Sidst opdateret: <?= h($sum['updated_at'] ?? '–') ?></p>

<?php if ($isAdmin): ?>
    <form method="post" style="margin:.6rem 0">
        <input type="hidden" name="action" value="match_fei">
        <button class="btn" type="submit">Genkør matching nu</button>
    </form>
    <p class="muted">Kør denne, når fei_officials/fei_official_functions er blevet genindlæst udefra.</p>
<?php endif; ?>

<h2>FEI-personer uden match i dine data</h2>
<p class="muted">Danske personer på FEI-listen der ikke kunne kobles til en official – ofte stavning/navneforskelle,
    eller personer der endnu ikke har virket ved et stævne i dine data.
    <?php if ($isAdmin): ?>Lad feltet stå tomt for at oprette som ny official, eller vælg en eksisterende
        for at knytte FEI-personen til den.<?php endif; ?></p>
<?php $u = $stats->feiUnmatched(); ?>
<?php if ($isAdmin): ?>
    <datalist id="official-datalist">
        <?php foreach ($stats->officialsOverview('', 'navn') as $o): ?>
            <option value="<?= h($o['navn']) ?>">
        <?php endforeach; ?>
    </datalist>
<?php endif; ?>
<table class="data">
    <thead><tr><th>Navn</th><th>Funktioner</th><?php if ($isAdmin): ?><th>Knyt til official</th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($u as $r): ?>
        <tr>
            <td><?= h(trim($r['first_name'] . ' ' . $r['last_name'])) ?></td>
            <td class="small"><?= h($r['funktioner'] ?? '–') ?></td>
            <?php if ($isAdmin): ?>
                <td>
                    <form method="post" style="display:flex;gap:.3rem"
                          onsubmit="return this.official_navn.value ? confirm('Knyt denne FEI-person til den valgte official?') : true;">
                        <input type="hidden" name="action" value="link_fei">
                        <input type="hidden" name="fei_id" value="<?= (int)$r['fei_id'] ?>">
                        <input type="text" name="official_navn" list="official-datalist" size="26"
                               placeholder="Tom = ny official">
                        <button class="btn" type="submit">Knyt</button>
                    </form>
                </td>
            <?php endif; ?>
        </tr>
    <?php endforeach; ?>
    <?php if (!$u): ?><tr><td colspan="<?= $isAdmin ? 3 : 2 ?>" class="muted">Alle danske FEI-personer er matchet 🎉</td></tr><?php endif; ?>
    </tbody>
</table>

<h2>Udenlandske FEI-officials i dine data</h2>
<p class="muted">Officials i dit system der er matchet til en udenlandsk FEI-official (nf ≠ Denmark) - typisk
    internationale officials der har virket ved CSI/CDI-stævner i Danmark.</p>
<?php $foreign = $stats->feiMatchedForeign(); ?>
<table class="data">
    <thead><tr><th>Official</th><th>FEI-navn</th><th>Land</th></tr></thead>
    <tbody>
    <?php foreach ($foreign as $r): ?>
        <tr>
            <td><a href="<?= h(url('official.php?id=' . (int)$r['official_id'])) ?>"><?= h($r['navn']) ?></a></td>
            <td class="small"><?= h(trim($r['first_name'] . ' ' . $r['last_name'])) ?></td>
            <td><?= h($r['nf']) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$foreign): ?><tr><td colspan="3" class="muted">Ingen udenlandske FEI-matches endnu.</td></tr><?php endif; ?>
    </tbody>
</table>

<h2>Gennemse FEI-listen</h2>
<form class="filters" method="get">
    <input type="text" name="q" placeholder="Søg navn…" value="<?= h($search) ?>">
    <select name="discipline">
        <option value="">Alle discipliner</option>
        <?php foreach ($stats->feiDisciplines() as $d): ?>
            <option value="<?= h($d['discipline']) ?>" <?= $discipline===$d['discipline']?'selected':'' ?>><?= h($d['discipline']) ?></option>
        <?php endforeach; ?>
    </select>
    <label class="muted" style="font-size:.85rem">
        <input type="checkbox" name="alle_lande" value="1" <?= $allCountries ? 'checked' : '' ?>> Vis alle lande
    </label>
    <button class="btn" type="submit">Filtrér</button>
</form>

<?php $list = $stats->feiList($search, $discipline, $allCountries); ?>
<p class="muted"><?= count($list) ?> rækker</p>
<table class="data">
    <thead><tr><th>Navn</th><?php if ($allCountries): ?><th>Land</th><?php endif; ?><th>Disciplin</th><th>Funktion</th><th>Niveau</th><th>Status</th><th>Match</th></tr></thead>
    <tbody>
    <?php foreach ($list as $r): ?>
        <tr>
            <td><?php if ($r['official_id']): ?><a href="<?= h(url('official.php?id=' . (int)$r['official_id'])) ?>"><?= h(trim($r['first_name'] . ' ' . $r['last_name'])) ?></a><?php else: ?><?= h(trim($r['first_name'] . ' ' . $r['last_name'])) ?><?php endif; ?></td>
            <?php if ($allCountries): ?><td class="small"><?= h($r['nf']) ?></td><?php endif; ?>
            <td><?= h($r['discipline']) ?></td>
            <td class="small"><?= h($r['function_name']) ?></td>
            <td><?= h($r['level']) ?></td>
            <td class="small"><?= h($r['status']) ?></td>
            <td><?= $r['official_id'] ? '<span class="badge badge-drf">✓</span>' : '<span class="muted">–</span>' ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php endif; ?>
<?php
render_footer();
