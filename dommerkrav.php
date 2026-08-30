<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_login();

$stats  = new Stats(db());
$result = $stats->springdommerKravStatus();
$years  = $result['years'];
$rows   = $result['rows'];

// Matrix: antal officials pr. niveau (D/C/B/A, nedad) der opfylder/ikke opfylder
// kravet (Ja/Nej/Opmærksom, hen ad). Samme klassificering som selve listen
// herunder: D har et haardt Ja/Nej-krav, C/B/A viser Opmærksom (aldrig et
// haardt Nej) naar totalkravet ikke er opfyldt i det aktuelle 2-aars-vindue.
$niveauer = ['D', 'C', 'B', 'A'];
$matrix   = array_fill_keys($niveauer, ['ja' => 0, 'nej' => 0, 'opmaerksom' => 0]);
foreach ($rows as $r) {
    if (!isset($matrix[$r['niveau']])) {
        continue;
    }
    if ($r['opfylder']) {
        $matrix[$r['niveau']]['ja']++;
    } elseif ($r['niveau'] === 'D') {
        $matrix[$r['niveau']]['nej']++;
    } else {
        $matrix[$r['niveau']]['opmaerksom']++;
    }
}

render_header('Dommerkrav', 'status_krav');
?>
<p class="muted"><a href="<?= h(url('status_krav.php')) ?>">← Opretholdelse af status</a> ·
    <strong>Springdommer</strong> · <a href="<?= h(url('banedesignerkrav.php')) ?>">Banedesigner</a></p>
<h1>Dommerkrav - springning</h1>
<p class="muted">Viser om springdommere (niveau D/C/B/A) opfylder DRF's aktivitetskrav for at
    opretholde niveauet, ud fra dømte stævner registreret i Equilive
    <?php if ($years): ?>(<?= h(implode('–', [$years[0], end($years)])) ?>)<?php endif; ?>.</p>

<div class="notice">
    <strong>OBS:</strong>
    <ul>
        <li>Kun rollen <code>show_jumping_judge</code> tælles med. Springdommer-D's alternative vej
            ("assistent til C-stævner") indgår derfor ikke.</li>
        <li>Et stævnes niveau her er den højeste springklasse i stævnet - ved et blandet stævne (fx
            dressur + spring samme dag) tæller stævnet altså med på sit eget springniveau, uafhængig af
            niveauet på en evt. dressurklasse i samme stævne.</li>
        <li>Totalkravene for C/B/A vurderes først for vinduet forrige år + indeværende år. Er kravet
            ikke opfyldt dér, vises <span class="badge" style="background:#e67e22;color:#fff">Opmærksom</span>
            (det kan stadig nås inden årets udgang), og der tjekkes samtidig om kravet var opfyldt i de
            2 hele foregående år alene - er det tilfældet, vises også
            <span class="badge badge-muted">Opfyldt tidligere</span> for at niveauet senest var
            dokumenteret opretholdt. Uden det ville stort set alle vise "Opmærksom" i starten af et nyt
            kalenderår, før sæsonen er i gang.</li>
        <li>Springdommer-D's årskrav vurderes ud fra indeværende år og forrige hele kalenderår - opfyldt
            hvis mindst ét af de to år lever op til kravet.</li>
        <li>Deltagelse i DRF's refleksionsdage (krav: mindst hvert 3. år) indgår ikke - der findes
            ingen data om dette i Equilive. Tjek manuelt.</li>
    </ul>
</div>

<table class="data" style="max-width:28rem;margin-bottom:1.2rem">
    <thead>
        <tr><th>Niveau</th><th class="r">Ja</th><th class="r">Nej</th><th class="r">Opmærksom</th></tr>
    </thead>
    <tbody>
    <?php foreach ($niveauer as $niv): ?>
        <tr>
            <td><span class="badge badge-lvl badge-<?= h($niv) ?>"><?= h($niv) ?></span></td>
            <td class="r"><?= (int)$matrix[$niv]['ja'] ?></td>
            <td class="r"><?= (int)$matrix[$niv]['nej'] ?></td>
            <td class="r"><?= (int)$matrix[$niv]['opmaerksom'] ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<table class="data">
    <thead>
        <tr><th>Official</th><th>Niveau</th><th>Official-status</th><th>Opfylder krav?</th><th>Detaljer</th></tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td><a href="<?= h(url('official.php?id=' . (int)$r['official_id']) . '&' . from_params('Dommerkrav')) ?>"><?= h($r['navn']) ?></a></td>
            <td><span class="badge badge-lvl badge-<?= h($r['niveau']) ?>"><?= h($r['niveau']) ?></span></td>
            <td><?= official_status_badge($r['status']) ?></td>
            <td>
                <?php if ($r['opfylder']): ?>
                    <span class="badge badge-drf">Ja</span>
                <?php elseif ($r['niveau'] === 'D'): ?>
                    <span class="badge" style="background:#c0392b;color:#fff">Nej</span>
                <?php else: ?>
                    <span class="badge" style="background:#e67e22;color:#fff">Opmærksom</span>
                    <?php if ($r['opfyldt_tidligere']): ?>
                        <br><span class="badge badge-muted">Opfyldt tidligere</span>
                    <?php endif; ?>
                <?php endif; ?>
            </td>
            <td class="small"><?= h($r['krav']) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
        <tr><td colspan="5" class="muted">Ingen springdommere (niveau D/C/B/A) fundet i DRF-listen.</td></tr>
    <?php endif; ?>
    </tbody>
</table>
<?php
render_footer();
