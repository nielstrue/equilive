<?php
/**
 * CLI natlig batch (se inc/RiderDetailImporter.php): henter supplerende
 * rytterdata (rytterlicens, rytterkategorier) fra DRF's rytterprofil-side.
 * Siden kan være langsom, saa antallet pr. kørsel styres via --limit, saa
 * jobbet kan planlægges (fx via Windows Task Scheduler/cron) til at tage en
 * portion ryttere ad gangen om natten.
 *
 * Brug:
 *   php cli/import_rider_details.php --limit=200
 *   php cli/import_rider_details.php --limit=5                    (lokal test)
 *   php cli/import_rider_details.php --limit=200 --force
 *   php cli/import_rider_details.php --limit=200 --delay=2000      (ms mellem opslag, default 1500)
 */
require __DIR__ . '/../inc/bootstrap.php';

function arg(string $name, ?string $default = null): ?string {
    foreach ($GLOBALS['argv'] as $a) {
        if (str_starts_with($a, "--$name=")) {
            return substr($a, strlen($name) + 3);
        }
    }
    return $default;
}

$limitArg = arg('limit');
if ($limitArg === null) {
    fwrite(STDERR, "Mangler --limit (antal ryttere pr. kørsel, fx --limit=200)\n");
    exit(1);
}
$limit   = (int)$limitArg;
$force   = in_array('--force', $argv, true);
$delayMs = (int)arg('delay', '1500');

$importer = new RiderDetailImporter(db());
$start    = microtime(true);
$result   = $importer->harvestBatch($limit, $force, $delayMs);

if ($result['total'] === 0) {
    echo "Ingen ryttere at behandle" . ($force ? '' : ' (mangler detail_harvested_at)') . ".\n";
    exit(0);
}

printf("Behandler %d rytter(e) (limit=%d%s, delay=%dms)...\n", $result['total'], $limit, $force ? ', --force' : '', $delayMs);
foreach ($result['rows'] as $i => $row) {
    $n = $i + 1;
    if ($row['ok']) {
        printf(
            "[%d/%d] #%d %-30s RiderId=%-10s licens=%d kategorier=%d\n",
            $n, $result['total'], $row['rider_id'], $row['navn'], $row['drf_rider_id'], $row['licenses'], $row['categories']
        );
    } else {
        fwrite(STDERR, sprintf(
            "[%d/%d] #%d %-30s RiderId=%-10s FEJL: %s\n",
            $n, $result['total'], $row['rider_id'], $row['navn'], $row['drf_rider_id'], $row['message']
        ));
    }
}

$sek = round(microtime(true) - $start, 1);
echo str_repeat('-', 60) . "\n";
printf("Færdig på %ss: %d ok, %d fejlet.\n", $sek, $result['ok'], $result['failed']);
exit($result['failed'] > 0 && $result['ok'] === 0 ? 1 : 0);
