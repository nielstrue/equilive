<?php
/**
 * CLI-høstning af DRF's klubliste ("find-klubber") - udfylder clubs.distrikt.
 *
 * Brug:
 *   php cli/import_drf_clubs.php            (live fra rideforbund.dk)
 *   php cli/import_drf_clubs.php --file      (fra gemt HTML, se drf_clubs_file i config.php)
 *
 * Windows Task Scheduler-eksempel:
 *   C:\wamp64\bin\php\php8.x\php.exe C:\Users\niels\dev\equilive\cli\import_drf_clubs.php
 */
require __DIR__ . '/../inc/bootstrap.php';

$useFile = in_array('--file', $argv, true);

$importer = new DrfClubImporter(db());
try {
    if ($useFile) {
        $r = $importer->import($GLOBALS['config']['drf_clubs_file'] ?? null, true);
    } else {
        $r = $importer->import(null, false);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'FEJL: ' . $e->getMessage() . "\n");
    exit(1);
}

printf(
    "Klubber på DRF-listen: %d, matchet til dine klubber: %d, uden match: %d\n",
    $r['rows'], $r['matched_clubs'], $r['unmatched_names']
);
exit(0);
