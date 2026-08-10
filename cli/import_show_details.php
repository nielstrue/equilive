<?php
/**
 * CLI-backfill (se inc/ShowDetailImporter.php): henter klassedetaljer
 * (hest/pony, sværhedsgrad) fra DRF for alle stævner i en årrække - samme
 * funktion som knappen "Hent klassedetaljer fra DRF" på show.php (eller den
 * admin-gaterede import_show_details.php-side), bare kørt i bulk for mange
 * stævner ad gangen.
 *
 * Springer stævner uden gyldigt Prop-id over (kan ikke slås op på DRF), og
 * springer som udgangspunkt allerede høstede stævner over - brug --force for
 * at genindlæse dem alle.
 *
 * Brug:
 *   php cli/import_show_details.php --years=2023,2024,2025,2026
 *   php cli/import_show_details.php --years=2026 --limit=10        (lokal test)
 *   php cli/import_show_details.php --years=2023,2024,2025,2026 --force
 *   php cli/import_show_details.php --years=2026 --delay=2000      (ms mellem kald, default 1000)
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

$yearsArg = arg('years', '2023,2024,2025,2026');
$years    = array_values(array_filter(array_map('intval', explode(',', $yearsArg))));
$limit    = arg('limit') !== null ? (int)arg('limit') : null;
$force    = in_array('--force', $argv, true);
$delayMs  = (int)arg('delay', '1000');

if (!$years) {
    fwrite(STDERR, "Ugyldig --years (fx --years=2023,2024,2025,2026)\n");
    exit(1);
}

$importer = new ShowDetailImporter(db());
$start    = microtime(true);
$result   = $importer->harvestBatch($years, $limit, $force, $delayMs);

if ($result['total'] === 0) {
    echo "Ingen stævner at behandle (år: " . implode(',', $years) . ($force ? '' : ', mangler detail_harvested_at') . ").\n";
    exit(0);
}

printf(
    "Behandler %d stævne(r) (år: %s%s, delay=%dms)...\n",
    $result['total'], implode(',', $years), $force ? ', --force' : '', $delayMs
);
foreach ($result['rows'] as $i => $row) {
    $n = $i + 1;
    if ($row['ok']) {
        printf(
            "[%d/%d] #%d %-16s aar=%d  klasser=%d/%d\n",
            $n, $result['total'], $row['show_id'], $row['prop'], $row['aar'], $row['classes_matched'], $row['classes_total']
        );
    } else {
        fwrite(STDERR, sprintf(
            "[%d/%d] #%d %-16s aar=%d  FEJL: %s\n",
            $n, $result['total'], $row['show_id'], $row['prop'], $row['aar'], $row['message']
        ));
    }
}

$sek = round(microtime(true) - $start, 1);
echo str_repeat('-', 60) . "\n";
printf(
    "Færdig på %ss: %d ok, %d fejlet, %d klasser opdateret i alt.\n",
    $sek, $result['ok'], $result['failed'], $result['classes_matched']
);
exit($result['failed'] > 0 && $result['ok'] === 0 ? 1 : 0);
