<?php
/**
 * CLI-backfill (se inc/RiderResultImporter.php): henter ryttere (navn+RiderId)
 * pr. klasse fra DRF for stævner i en årrække, hvor stævnet allerede har
 * status "Resultatbehandling færdig" (kræver at "Hent klassedetaljer fra
 * DRF" er kørt for stævnet først - det er den der udfylder statussen og
 * classes.drf_class_id).
 *
 * Brug:
 *   php cli/import_class_riders.php --years=2023,2024,2025,2026
 *   php cli/import_class_riders.php --years=2026 --limit=5           (lokal test)
 *   php cli/import_class_riders.php --years=2023,2024,2025,2026 --force
 *   php cli/import_class_riders.php --years=2026 --delay=500         (ms mellem klasse-opslag, default 300)
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
$delayMs  = (int)arg('delay', '300');

if (!$years) {
    fwrite(STDERR, "Ugyldig --years (fx --years=2023,2024,2025,2026)\n");
    exit(1);
}

$importer = new RiderResultImporter(db());
$start    = microtime(true);
$result   = $importer->harvestBatch($years, $limit, $force, $delayMs);

if ($result['total'] === 0) {
    echo "Ingen stævner at behandle (år: " . implode(',', $years)
        . ($force ? '' : ', mangler resultat_status="Resultatbehandling færdig" eller allerede høstet') . ").\n";
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
            "[%d/%d] #%d %-16s aar=%d  klasser=%d  ryttere=%d\n",
            $n, $result['total'], $row['show_id'], $row['prop'], $row['aar'], $row['classes_harvested'], $row['riders_matched']
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
    "Færdig på %ss: %d ok, %d fejlet, %d ryttere matchet i alt.\n",
    $sek, $result['ok'], $result['failed'], $result['riders_matched']
);
exit($result['failed'] > 0 && $result['ok'] === 0 ? 1 : 0);
