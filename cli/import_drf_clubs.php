<?php
/**
 * CLI-høstning af DRF's klubliste ("find-klubber") - udfylder clubs.distrikt.
 *
 * Brug:
 *   php cli/import_drf_clubs.php                          (live fra rideforbund.dk)
 *   php cli/import_drf_clubs.php --file sti\til\gemt.html  (fra gemt HTML)
 *
 * (Fra browseren kan en gemt HTML-fil i stedet uploades under Import.)
 *
 * Windows Task Scheduler-eksempel:
 *   C:\wamp64\bin\php\php8.x\php.exe C:\Users\niels\dev\equilive\cli\import_drf_clubs.php
 */
require __DIR__ . '/../inc/bootstrap.php';

$fileIdx  = array_search('--file', $argv, true);
$filePath = $fileIdx !== false ? ($argv[$fileIdx + 1] ?? null) : null;
if ($fileIdx !== false && !$filePath) {
    fwrite(STDERR, "Brug: php cli/import_drf_clubs.php --file <sti-til-gemt-html>\n");
    exit(1);
}

$importer = new DrfClubImporter(db());
try {
    if ($filePath !== null) {
        $r = $importer->import($filePath, true);
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
