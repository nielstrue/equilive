<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_admin();

/**
 * Ikke-blokerende advarsler sat under CSV-import (se
 * Importer::checkAssignmentWarnings()): en rolle der ikke passer til klassens
 * disciplin, eller en official der mangler den DRF-type rollen kræver (se
 * rollekataloget på roles.php). Tabellen assignment_warnings afspejler altid
 * den AKTUELLE tilstand - en advarsel forsvinder af sig selv ved næste import,
 * hvis problemet er rettet (rolle, klassificering eller DRF-data).
 */
$stats    = new Stats(db());
$warnings = $stats->assignmentWarnings();

$typeLabel = [
    'rolle_disciplin' => 'Rolle/disciplin',
    'rolle_drf_type'  => 'Rolle/DRF-type',
];

render_header('Advarsler', 'warnings');
?>
<h1>Advarsler fra import</h1>
<p class="muted">Sat automatisk under CSV-import - stopper ikke importen, men er værd at tjekke.
    Ret enten rollen på klassens side, eller klassificér rollen/tilføj krævet DRF-type på
    <a href="<?= h(url('roles.php')) ?>">Rollekataloget</a>. Advarslen forsvinder selv ved næste
    import, når problemet er rettet.</p>

<p class="muted"><?= count($warnings) ?> advarsler</p>

<table class="data">
    <thead>
        <tr><th>Type</th><th>Advarsel</th><th>Official</th><th>Rolle</th><th>Klasse</th><th>Stævne</th><th class="nowrap">Dato</th><th>Sat</th></tr>
    </thead>
    <tbody>
    <?php foreach ($warnings as $w): ?>
        <tr>
            <td class="small"><?= h($typeLabel[$w['type']] ?? $w['type']) ?></td>
            <td class="small"><?= h($w['besked']) ?></td>
            <td><a href="<?= h(url('official.php?id=' . (int)$w['official_id']) . '&' . from_params('Advarsler')) ?>"><?= h($w['official']) ?></a></td>
            <td><?= h($w['rolle']) ?></td>
            <td><a href="<?= h(url('class.php?id=' . (int)$w['class_id']) . '&' . from_params('Advarsler')) ?>"><?= h($w['klassenavn']) ?></a></td>
            <td><a href="<?= h(url('show.php?id=' . (int)$w['show_id']) . '&' . from_params('Advarsler')) ?>"><?= h($w['prop']) ?></a></td>
            <td class="nowrap"><?= dk_date($w['dato']) ?></td>
            <td class="nowrap"><?= h($w['created_at']) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$warnings): ?>
        <tr><td colspan="8" class="muted">Ingen aktive advarsler.</td></tr>
    <?php endif; ?>
    </tbody>
</table>
<?php
render_footer();
