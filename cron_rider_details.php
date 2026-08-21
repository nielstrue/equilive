<?php
/**
 * URL-trigget udgave af cli/import_rider_details.php - til hosting uden
 * shell/crontab-adgang (fx truerne.dk), hvor man i stedet kan sætte en
 * "cron via URL"-opgave op i hostingens kontrolpanel, eller bruge en ekstern
 * cron-tjeneste (cron-job.org e.l.) til at kalde denne URL periodisk.
 *
 * Beskyttet af en delt hemmelighed (config['cron_secret']) i stedet for login,
 * da et cron-kald ikke har en session. Alle med denne værdi kan udløse
 * batch-jobbet, saa hold URL'en/hemmeligheden hemmelig.
 *
 * Brug (eksempel med hemmelighed og 100 ryttere pr. kørsel):
 *   https://truerne.dk/equilive/cron_rider_details.php?key=DIN_HEMMELIGHED&limit=100
 *
 * Valgfrie parametre (samme betydning som CLI-scriptet):
 *   limit  - antal ryttere pr. kørsel (default 100, hvis udeladt)
 *   delay  - ms mellem hvert DRF-opslag (default 1500)
 *   force  - "1" for at genindlæse allerede høstede ryttere
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

// Skal ikke afbrydes selvom cron-kaldet (curl/wget) selv giver op før svaret -
// og PHP's udførelsestid maa ikke stoppe et batch paa fx 100 ryttere midtvejs.
ignore_user_abort(true);
@set_time_limit(0);

$limit   = max(1, min(500, (int)($_GET['limit'] ?? 100)));
$force   = ($_GET['force'] ?? '') === '1';
$delayMs = max(0, (int)($_GET['delay'] ?? 1500));

$importer = new RiderDetailImporter(db());
$start    = microtime(true);
$result   = $importer->harvestBatch($limit, $force, $delayMs);

if ($result['total'] === 0) {
    echo "Ingen ryttere at behandle" . ($force ? '' : ' (mangler detail_harvested_at)') . ".\n";
    exit;
}

printf("Behandler %d rytter(e) (limit=%d%s, delay=%dms)...\n", $result['total'], $limit, $force ? ', force=1' : '', $delayMs);
foreach ($result['rows'] as $i => $row) {
    $n = $i + 1;
    if ($row['ok']) {
        printf(
            "[%d/%d] #%d %-30s RiderId=%-10s licens=%d kategorier=%d\n",
            $n, $result['total'], $row['rider_id'], $row['navn'], $row['drf_rider_id'], $row['licenses'], $row['categories']
        );
    } else {
        printf(
            "[%d/%d] #%d %-30s RiderId=%-10s FEJL: %s\n",
            $n, $result['total'], $row['rider_id'], $row['navn'], $row['drf_rider_id'], $row['message']
        );
    }
}

$sek = round(microtime(true) - $start, 1);
echo str_repeat('-', 60) . "\n";
printf("Færdig på %ss: %d ok, %d fejlet.\n", $sek, $result['ok'], $result['failed']);
