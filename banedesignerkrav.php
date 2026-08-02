<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_login();

$stats  = new Stats(db());
$result = $stats->banedesignerKravStatus();
$years  = $result['years'];
$rows   = $result['rows'];

render_header('Banedesignerkrav', 'status_krav');
?>
<p class="muted"><a href="<?= h(url('status_krav.php')) ?>">← Opretholdelse af status</a> ·
    <a href="<?= h(url('dommerkrav.php')) ?>">Springdommer</a> · <strong>Banedesigner</strong></p>
<h1>Banedesignerkrav - springning</h1>
<p class="muted">Viser om banedesignere (niveau D/C/B/A) opfylder DRF's aktivitetskrav for at
    opretholde niveauet, ud fra registrerede stævner i Equilive
    <?php if ($years): ?>(<?= h(implode('–', [$years[0], end($years)])) ?>)<?php endif; ?>.</p>

<div class="notice">
    <strong>OBS:</strong>
    <ul>
        <li>"Ansvarlig banedesigner" er rollen <code>show_jumping_course_designer</code> (den rolle
            <code>course_designer</code> vaskes til på springklasser ved import) - ikke den rå
            <code>course_designer</code>-rolle, som kun forekommer på andre discipliner.</li>
        <li>Et stævnes niveau her er den højeste springklasse i stævnet, ikke stævnets samlede/blandede niveau.</li>
        <li>For C/B/A skal <strong>både</strong> "ansvarlig"-tallet <strong>og</strong> assist-betingelsen være
            opfyldt (ikke to alternative veje).</li>
        <li>Sværhedsgrad (svh) er kun registreret for et fåtal klasser i Equilive. Assist-kravene for C/B
            kræver specifikt svh ≥ 4 (B: mindst ét svh ≥ 5) og er implementeret efter kravteksten uden
            lempelse - det betyder de i praksis sjældent kan vise sig opfyldt med den nuværende datadækning,
            selv for meget aktive banedesignere. Dette er en bevidst, aftalt afvejning - ikke en fejl.</li>
        <li>En banedesigners kvalifikationstype (fx "Springning - Banedesigner - A") er et øjebliksbillede
            fra seneste DRF-høstning, ikke en historik - der tjekkes om den ansvarlige designer PT. har
            typen A/B, ikke om vedkommende havde den på assist-tidspunktet.</li>
        <li>Totalkravene for C/B/A vurderes først for vinduet [indeværende år - 2 .. indeværende år]. Er
            kravet ikke opfyldt dér, vises <span class="badge" style="background:#e67e22;color:#fff">Opmærksom</span>,
            og der tjekkes samtidig om kravet var opfyldt i de 3 hele foregående år alene - er det tilfældet,
            vises også <span class="badge badge-muted">Opfyldt tidligere</span>.</li>
        <li>D-niveau (og "D (bygger kun i egen klub)") har intet beskrevet aktivitetskrav og vises uden
            opfylder-vurdering (<span class="badge badge-muted">–</span>).</li>
        <li>Deltagelse i DRF's refleksionsdage indgår ikke - ingen data i Equilive, skal tjekkes manuelt.</li>
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
                <?php if ($r['opfylder'] === null): ?>
                    <span class="badge badge-muted">–</span>
                <?php elseif ($r['opfylder']): ?>
                    <span class="badge badge-drf">Ja</span>
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
        <tr><td colspan="5" class="muted">Ingen banedesignere (niveau D/C/B/A) fundet i DRF-listen.</td></tr>
    <?php endif; ?>
    </tbody>
</table>
<?php
render_footer();
