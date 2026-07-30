<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_login();

$stats  = new Stats(db());
$result = $stats->springdommerKravStatus();
$years  = $result['years'];
$rows   = $result['rows'];

render_header('Dommerkrav', 'dommerkrav');
?>
<h1>Dommerkrav - springning</h1>
<p class="muted">Viser om springdommere (niveau D/C/B/A) opfylder DRF's aktivitetskrav for at
    opretholde niveauet, ud fra dømte stævner registreret i Equilive
    <?php if ($years): ?>(<?= h(implode('–', [$years[0], end($years)])) ?>)<?php endif; ?>.</p>

<div class="notice">
    <strong>OBS:</strong>
    <ul>
        <li>Kun rollen <code>show_jumping_judge</code> tælles med. Springdommer-D's alternative vej
            ("assistent til C-stævner") indgår derfor ikke.</li>
        <li>Et stævnes niveau er stævnets samlede (højeste) niveau, ikke nødvendigvis niveauet på den
            konkrete klasse der blev dømt.</li>
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

<table class="data">
    <thead>
        <tr><th>Official</th><th>Niveau</th><th>Official-status</th><th>Opfylder krav?</th><th>Detaljer</th></tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td><a href="<?= h(url('official.php?id=' . (int)$r['official_id'])) ?>"><?= h($r['navn']) ?></a></td>
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
