<?php
/**
 * URL-trigget udgave af cli/import_class_riders.php - til hosting uden
 * shell/crontab-adgang (fx truerne.dk), se cron_rider_details.php for
 * samme mønster og begrundelse.
 *
 * Henter ryttere (navn+RiderId) pr. klasse fra DRF for stævner der allerede
 * har status "Resultatbehandling færdig" (se inc/RiderResultImporter -
 * kræver at cron_show_details.php/"Hent klassedetaljer fra DRF" er kørt for
 * stævnet først, da det er den der udfylder statussen og classes.drf_class_id).
 *
 * Beskyttet af samme delte hemmelighed som cron_rider_details.php
 * (config['cron_secret']).
 *
 * Brug (eksempel):
 *   https://truerne.dk/equilive/cron_class_riders.php?key=DIN_HEMMELIGHED&limit=10
 *
 * Valgfrie parametre:
 *   years  - kommasepareret årstal, fx "2025,2026" (default: samme som CLI'et)
 *   limit  - antal stævner pr. kørsel (default 10 - koster ét DRF-kald PR.
 *            KLASSE i stævnet, saa langsommere pr. stævne end de to øvrige
 *            cron-endpoints)
 *   delay  - ms mellem hvert klasse-opslag (default 300)
 *   force  - "1" for at genindlæse allerede høstede stævner
 */
require __DIR__ . '/inc/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

$secret = $GLOBALS['config']['cron_secret'] ?? '';
$key    = $_GET['key'] ?? '';
if ($secret === '' || !hash_equals($secret, (string)$key)) {
    http_response_code(403);
    echo "Forbidden\n";
    exit;
}

ignore_user_abort(true);
@set_time_limit(0);

$yearsArg = $_GET['years'] ?? '2023,2024,2025,2026';
$years    = array_values(array_filter(array_map('intval', explode(',', (string)$yearsArg))));
$limit    = max(1, min(50, (int)($_GET['limit'] ?? 10)));
$force    = ($_GET['force'] ?? '') === '1';
$delayMs  = max(0, (int)($_GET['delay'] ?? 300));

if (!$years) {
    http_response_code(400);
    echo "Ugyldig years (fx years=2023,2024,2025,2026)\n";
    exit;
}

$importer = new RiderResultImporter(db());
$start    = microtime(true);
$result   = $importer->harvestBatch($years, $limit, $force, $delayMs);

if ($result['total'] === 0) {
    echo "Ingen stævner at behandle (år: " . implode(',', $years)
        . ($force ? '' : ', mangler resultat_status="Resultatbehandling færdig" eller allerede høstet') . ").\n";
    exit;
}

printf(
    "Behandler %d stævne(r) (år: %s%s, delay=%dms)...\n",
    $result['total'], implode(',', $years), $force ? ', force=1' : '', $delayMs
);
foreach ($result['rows'] as $i => $row) {
    $n = $i + 1;
    if ($row['ok']) {
        printf(
            "[%d/%d] #%d %-16s aar=%d  klasser=%d  ryttere=%d\n",
            $n, $result['total'], $row['show_id'], $row['prop'], $row['aar'], $row['classes_harvested'], $row['riders_matched']
        );
    } else {
        printf(
            "[%d/%d] #%d %-16s aar=%d  FEJL: %s\n",
            $n, $result['total'], $row['show_id'], $row['prop'], $row['aar'], $row['message']
        );
    }
}

$sek = round(microtime(true) - $start, 1);
echo str_repeat('-', 60) . "\n";
printf(
    "Færdig på %ss: %d ok, %d fejlet, %d ryttere matchet i alt.\n",
    $sek, $result['ok'], $result['failed'], $result['riders_matched']
);
