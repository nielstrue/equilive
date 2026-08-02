<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_admin();

$result        = null;
$drfResult     = null;
$drfClubResult = null;
$error         = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'drf') {
    try {
        $drf = new DrfImporter(db());
        if (($_POST['drf_source'] ?? '') === 'file') {
            if (empty($_FILES['drf_html']['tmp_name']) || !is_uploaded_file($_FILES['drf_html']['tmp_name'])) {
                throw new RuntimeException('Vælg en HTML-fil at uploade.');
            }
            $drfResult = $drf->import($_FILES['drf_html']['tmp_name'], true);
        } else {
            $drfResult = $drf->import(null, false); // live fra config drf_url
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'drf_clubs') {
    try {
        $drfClubs = new DrfClubImporter(db());
        if (($_POST['drf_source'] ?? '') === 'file') {
            if (empty($_FILES['drf_clubs_html']['tmp_name']) || !is_uploaded_file($_FILES['drf_clubs_html']['tmp_name'])) {
                throw new RuntimeException('Vælg en HTML-fil at uploade.');
            }
            $drfClubResult = $drfClubs->import($_FILES['drf_clubs_html']['tmp_name'], true);
        } else {
            $drfClubResult = $drfClubs->import(null, false); // live fra config drf_clubs_url
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'csv_url') {
    try {
        $url = $GLOBALS['config']['csv_url'] ?? '';
        if ($url === '') {
            throw new RuntimeException('Ingen csv_url sat i config.php.');
        }
        $target   = $GLOBALS['config']['default_csv'] ?? (__DIR__ . '/data/officials_2026.csv');
        $importer = new Importer(db());
        $result   = $importer->importFromUrl($url, $target);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $importer = new Importer(db());
        if (!empty($_FILES['csv']['tmp_name']) && is_uploaded_file($_FILES['csv']['tmp_name'])) {
            $result = $importer->import($_FILES['csv']['tmp_name'], $_FILES['csv']['name'] ?? 'upload.csv');
        } else {
            $error = 'Vælg en CSV-fil.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$defaultPath = $GLOBALS['config']['default_csv'] ?? '';

render_header('Import', 'import');
?>
<h1>Equipe official liste</h1>

<?php if ($error): ?>
    <div class="notice error"><strong>Fejl:</strong> <?= h($error) ?></div>
<?php endif; ?>

<?php if ($result): ?>
    <div class="notice ok">
        <strong>Import gennemført.</strong>
        <ul>
            <li>Rækker læst: <?= number_format($result['rows_total'], 0, ',', '.') ?></li>
            <li>Sprunget over (ugyldige): <?= (int)$result['rows_skipped'] ?></li>
            <li>Nye tildelinger: <?= number_format($result['assign_new'], 0, ',', '.') ?></li>
            <li>Allerede kendt (opdateret): <?= number_format($result['assign_seen'], 0, ',', '.') ?></li>
        </ul>
        <a class="btn" href="<?= h(url('officials.php')) ?>">Se officials-statistik →</a>
    </div>
<?php endif; ?>

<?php if ($drfResult): ?>
    <div class="notice ok">
        <strong>DRF-liste opdateret.</strong>
        <ul>
            <li>Personer på listen: <?= number_format($drfResult['unique_persons'], 0, ',', '.') ?></li>
            <li>Roller i alt (type×distrikt): <?= number_format($drfResult['rows'], 0, ',', '.') ?></li>
            <li>Matchet til dine officials: <?= number_format($drfResult['matched_persons'], 0, ',', '.') ?></li>
            <li>DRF-navne uden match: <?= number_format($drfResult['unmatched_names'], 0, ',', '.') ?></li>
        </ul>
        <a class="btn" href="<?= h(url('drf.php')) ?>">Se DRF-afstemning →</a>
    </div>
<?php endif; ?>

<?php if ($drfClubResult): ?>
    <div class="notice ok">
        <strong>DRF-klubliste opdateret.</strong>
        <ul>
            <li>Klubber på listen: <?= number_format($drfClubResult['rows'], 0, ',', '.') ?></li>
            <li>Matchet til eksisterende klubber (distrikt udfyldt): <?= number_format($drfClubResult['matched_clubs'], 0, ',', '.') ?></li>
            <li>Nye klubber oprettet: <?= number_format($drfClubResult['created_clubs'], 0, ',', '.') ?></li>
        </ul>
        <a class="btn" href="<?= h(url('clubs.php')) ?>">Se klubber →</a>
    </div>
<?php endif; ?>

<div class="grid2">
    <section>
        <h2>Hent live fra equilive.dk</h2>
        <form method="post">
            <input type="hidden" name="action" value="csv_url">
            <p><button class="btn" type="submit">Hent og indlæs nyeste CSV</button></p>
        </form>
        <p class="muted">Henter <code><?= h($GLOBALS['config']['csv_url'] ?? '') ?></code> og gemmer den som
            <code><?= h($defaultPath) ?></code>, klar til både denne knap og planlagt kørsel via
            <code>cli/import.php</code>. Kræver at serveren har adgang til internettet (curl).</p>
    </section>
    <section>
        <h2>Upload fil</h2>
        <form method="post" enctype="multipart/form-data">
            <p><input type="file" name="csv" accept=".csv,text/csv"></p>
            <p><button class="btn" type="submit">Indlæs CSV</button></p>
        </form>
        <p class="muted">Samme fil kan indlæses igen hver uge – eksisterende rækker
            opdateres, og der oprettes ingen dubletter.</p>
    </section>
</div>

<h2>DRF officials-liste (find-dommer)</h2>
<div class="grid2">
    <section>
        <h3>Hent live fra rideforbund.dk</h3>
        <form method="post">
            <input type="hidden" name="action" value="drf">
            <input type="hidden" name="drf_source" value="live">
            <p><button class="btn" type="submit">Hent DRF-liste live</button></p>
        </form>
        <p class="muted">Henter fra <code><?= h($GLOBALS['config']['drf_url'] ?? '') ?></code>.
            Kræver at serveren har adgang til internettet (curl).</p>
    </section>
    <section>
        <h3>Upload fil</h3>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="drf">
            <input type="hidden" name="drf_source" value="file">
            <p><input type="file" name="drf_html" accept=".html,.htm,text/html"></p>
            <p><button class="btn" type="submit">Indlæs HTML</button></p>
        </form>
        <p class="muted">Gem find-dommer-siden som HTML og upload den her, hvis live-hentning ikke er mulig.</p>
    </section>
</div>

<h2>DRF klubliste (find-klubber)</h2>
<div class="grid2">
    <section>
        <h3>Hent live fra rideforbund.dk</h3>
        <form method="post">
            <input type="hidden" name="action" value="drf_clubs">
            <input type="hidden" name="drf_source" value="live">
            <p><button class="btn" type="submit">Hent DRF-klubliste live</button></p>
        </form>
        <p class="muted">Henter fra <code><?= h($GLOBALS['config']['drf_clubs_url'] ?? '') ?></code>
            og udfylder <code>clubs.distrikt</code> for dine klubber. Kræver at serveren har
            adgang til internettet (curl).</p>
    </section>
    <section>
        <h3>Upload fil</h3>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="drf_clubs">
            <input type="hidden" name="drf_source" value="file">
            <p><input type="file" name="drf_clubs_html" accept=".html,.htm,text/html"></p>
            <p><button class="btn" type="submit">Indlæs HTML</button></p>
        </form>
        <p class="muted">Gem find-klubber-siden som HTML og upload den her, hvis live-hentning ikke er mulig.</p>
    </section>
</div>

<h2>Importhistorik</h2>
<?php $imports = (new Stats(db()))->imports(15); ?>
<?php if ($imports): ?>
<table class="data">
    <thead><tr><th>Tid</th><th>Fil</th><th>Rækker</th><th>Skippet</th><th>Nye</th><th>Kendt</th></tr></thead>
    <tbody>
    <?php foreach ($imports as $im): ?>
        <tr>
            <td><?= h($im['imported_at']) ?></td>
            <td><?= h($im['filename'] ?? '–') ?></td>
            <td class="r"><?= number_format((int)$im['rows_total'], 0, ',', '.') ?></td>
            <td class="r"><?= (int)$im['rows_skipped'] ?></td>
            <td class="r"><?= number_format((int)$im['assign_new'], 0, ',', '.') ?></td>
            <td class="r"><?= number_format((int)$im['assign_seen'], 0, ',', '.') ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php else: ?>
    <p class="muted">Ingen importer endnu.</p>
<?php endif; ?>
<?php
render_footer();
