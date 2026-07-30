<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_login();

$stats   = new Stats(db());
$isAdmin = (current_user()['role'] ?? '') === 'admin';

$linkError = null;
$linkOk    = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'link_drf') {
    require_admin();
    $drfNavn      = trim($_POST['drf_navn'] ?? '');
    $officialNavn = trim($_POST['official_navn'] ?? '');
    try {
        $linker = new DrfOfficialLinker(db());
        if ($officialNavn !== '') {
            $officialId = (int)(db()->scalar('SELECT id FROM officials WHERE navn = ?', [$officialNavn]) ?: 0);
            if (!$officialId) {
                throw new InvalidArgumentException('Fandt ingen official med navnet "' . $officialNavn . '".');
            }
            $linker->linkToExisting($drfNavn, $officialId);
            $linkOk = '"' . $drfNavn . '" knyttet til den eksisterende official "' . $officialNavn . '".';
        } else {
            $linker->createNew($drfNavn);
            $linkOk = '"' . $drfNavn . '" oprettet som ny official.';
        }
    } catch (Throwable $e) {
        $linkError = $e->getMessage();
    }
}

$sum = $stats->drfSummary();

$search   = trim($_GET['q'] ?? '');
$kategori = trim($_GET['kategori'] ?? '');
$distrikt = trim($_GET['distrikt'] ?? '');

render_header('DRF-liste', 'drf');
?>
<h1>DRF officials-liste</h1>

<?php if ($linkError): ?><div class="notice error"><strong>Fejl:</strong> <?= h($linkError) ?></div><?php endif; ?>
<?php if ($linkOk): ?><div class="notice ok"><?= h($linkOk) ?></div><?php endif; ?>

<?php if ($sum['rows'] === 0): ?>
    <div class="notice">
        Der er endnu ikke høstet nogen DRF-liste. Gå til <a href="<?= h(url('import.php')) ?>">Import</a>
        og hent listen (live eller fra gemt fil).
    </div>
<?php else: ?>

<div class="cards">
    <div class="card"><span class="num"><?= number_format($sum['persons'], 0, ',', '.') ?></span><span class="lbl">Personer på DRF-listen</span></div>
    <div class="card"><span class="num"><?= number_format($sum['rows'], 0, ',', '.') ?></span><span class="lbl">Roller (type×distrikt)</span></div>
    <div class="card"><span class="num"><?= number_format($sum['matched'], 0, ',', '.') ?></span><span class="lbl">Matchede officials</span></div>
    <div class="card"><span class="num"><?= number_format($sum['drf_unmatched'], 0, ',', '.') ?></span><span class="lbl">DRF-navne uden match</span></div>
</div>
<p class="muted">Sidst høstet: <?= h($sum['harvested_at'] ?? '–') ?></p>

<h2>Afstemning (datakvalitet)</h2>
<div class="grid2">
    <section>
        <h3>DRF-navne uden match i dine data</h3>
        <p class="muted">Personer på DRF-listen der ikke kunne kobles til en official – ofte stavning/navneforskelle
            eller personer der endnu ikke har virket ved et stævne.
            <?php if ($isAdmin): ?>Lad feltet stå tomt for at oprette som ny official, eller vælg en eksisterende
                for at knytte navnet til den (fx ved navneskift – det gamle navn bevares som alias).<?php endif; ?></p>
        <?php $u = $stats->drfUnmatched(); ?>
        <?php if ($isAdmin): ?>
            <datalist id="official-datalist">
                <?php foreach ($stats->officialsOverview('', 'navn') as $o): ?>
                    <option value="<?= h($o['navn']) ?>">
                <?php endforeach; ?>
            </datalist>
        <?php endif; ?>
        <table class="data">
            <thead><tr><th>Navn</th><th>By</th><th>Typer</th><?php if ($isAdmin): ?><th>Knyt til official</th><?php endif; ?></tr></thead>
            <tbody>
            <?php foreach ($u as $r): ?>
                <tr>
                    <td><?= h($r['navn']) ?></td>
                    <td class="small"><?= h(trim(($r['postnr'] ?? '') . ' ' . ($r['postdistrikt'] ?? ''))) ?></td>
                    <td class="small"><?= h($r['typer']) ?></td>
                    <?php if ($isAdmin): ?>
                        <td>
                            <form method="post" style="display:flex;gap:.3rem"
                                  onsubmit="return this.official_navn.value ? confirm('Omdøb den valgte official til dette DRF-navn (det gamle navn bevares som alias)?') : true;">
                                <input type="hidden" name="action" value="link_drf">
                                <input type="hidden" name="drf_navn" value="<?= h($r['navn']) ?>">
                                <input type="text" name="official_navn" list="official-datalist" size="26"
                                       placeholder="Tom = ny official">
                                <button class="btn" type="submit">Knyt</button>
                            </form>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            <?php if (!$u): ?><tr><td colspan="<?= $isAdmin ? 4 : 3 ?>" class="muted">Alle DRF-navne er matchet 🎉</td></tr><?php endif; ?>
            </tbody>
        </table>
    </section>
    <section>
        <h3>Dine officials uden DRF-match</h3>
        <p class="muted">Personer der har virket ved stævner, men ikke findes på DRF-listen – tjek for stavefejl i stævnedata, eller ophørte officials.</p>
        <?php $n = $stats->officialsNotInDrf(); ?>
        <table class="data">
            <thead><tr><th>Official</th><th class="r">Stævner</th><th class="r">Roller</th></tr></thead>
            <tbody>
            <?php foreach ($n as $r): ?>
                <tr>
                    <td><a href="<?= h(url('official.php?id=' . (int)$r['id'])) ?>"><?= h($r['navn']) ?></a></td>
                    <td class="r"><?= (int)$r['staevner'] ?></td>
                    <td class="r"><?= (int)$r['roller'] ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$n): ?><tr><td colspan="3" class="muted">Alle dine officials er på DRF-listen 🎉</td></tr><?php endif; ?>
            </tbody>
        </table>
    </section>
</div>

<h2>Gennemse DRF-listen</h2>
<form class="filters" method="get">
    <input type="text" name="q" placeholder="Søg navn…" value="<?= h($search) ?>">
    <select name="kategori">
        <option value="">Alle kategorier</option>
        <?php foreach ($stats->drfKategorier() as $k): ?>
            <option value="<?= h($k['kategori']) ?>" <?= $kategori===$k['kategori']?'selected':'' ?>><?= h($k['kategori']) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="distrikt">
        <option value="">Alle distrikter</option>
        <?php foreach ($stats->drfDistrikter() as $d): ?>
            <option value="<?= h($d['distrikt']) ?>" <?= $distrikt===$d['distrikt']?'selected':'' ?>><?= h($d['distrikt']) ?></option>
        <?php endforeach; ?>
    </select>
    <button class="btn" type="submit">Filtrér</button>
</form>

<?php $list = $stats->drfList($search, $kategori, $distrikt); ?>
<p class="muted"><?= count($list) ?> rækker</p>
<table class="data">
    <thead><tr><th>Navn</th><th>Kategori</th><th>Type</th><th>Distrikt</th><th>By</th><th>Match</th></tr></thead>
    <tbody>
    <?php foreach ($list as $r): ?>
        <tr>
            <td><?php if ($r['official_id']): ?><a href="<?= h(url('official.php?id=' . (int)$r['official_id'])) ?>"><?= h($r['navn']) ?></a><?php else: ?><?= h($r['navn']) ?><?php endif; ?></td>
            <td><?= h($r['kategori']) ?></td>
            <td class="small"><?= h($r['type']) ?></td>
            <td><?= h($r['distrikt']) ?></td>
            <td class="small"><?= h(trim(($r['postnr'] ?? '') . ' ' . ($r['postdistrikt'] ?? ''))) ?></td>
            <td><?= $r['official_id'] ? '<span class="badge badge-drf">✓</span>' : '<span class="muted">–</span>' ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php endif; ?>
<?php
render_footer();
