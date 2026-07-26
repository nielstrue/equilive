<?php
/**
 * CLI-importer til planlagt/ugentlig kørsel og backfill af flere år.
 *
 * Brug:
 *   php cli/import.php                          (bruger default_csv fra config.php)
 *   php cli/import.php sti/til/officials.csv    (én fil)
 *   php cli/import.php sti/til/data             (MAPPE: indlæser alle officials_*.csv)
 *   php cli/import.php officials_2020.csv 2020  (tving årstal som 2. argument)
 *
 * Årstallet udledes normalt automatisk af filnavnet (officials_YYYY.csv).
 *
 * Windows Task Scheduler-eksempel:
 *   C:\wamp64\bin\php\php8.x\php.exe C:\Users\niels\dev\equilive\cli\import.php
 */
require __DIR__ . '/../inc/bootstrap.php';

$path    = $argv[1] ?? ($GLOBALS['config']['default_csv'] ?? '');
$yearArg = isset($argv[2]) ? (int)$argv[2] : null;

if ($path === '') {
    fwrite(STDERR, "Angiv en CSV-fil eller en mappe.\n");
    exit(1);
}

// Byg liste af filer der skal importeres.
if (is_dir($path)) {
    $files = glob(rtrim($path, "/\\") . DIRECTORY_SEPARATOR . 'officials_*.csv');
    sort($files); // ældste år først
    if (!$files) {
        fwrite(STDERR, "Ingen officials_*.csv filer fundet i mappen: $path\n");
        exit(1);
    }
} elseif (is_readable($path)) {
    $files = [$path];
} else {
    fwrite(STDERR, "Kan ikke læse: '$path'\n");
    exit(1);
}

$importer = new Importer(db());
$grand = ['rows_total' => 0, 'rows_skipped' => 0, 'assign_new' => 0, 'assign_seen' => 0];
$start = microtime(true);

foreach ($files as $file) {
    $name = basename($file);
    try {
        $r = $importer->import($file, $name, $yearArg);
    } catch (Throwable $e) {
        fwrite(STDERR, "FEJL i $name: " . $e->getMessage() . "\n");
        exit(1);
    }
    printf(
        "%-28s rækker=%-6d nye=%-6d kendt=%-6d skippet=%d\n",
        $name, $r['rows_total'], $r['assign_new'], $r['assign_seen'], $r['rows_skipped']
    );
    foreach ($grand as $k => $_) { $grand[$k] += $r[$k]; }
}

$sek = round(microtime(true) - $start, 1);
echo str_repeat('-', 60) . "\n";
printf(
    "I alt (%d fil(er), %ss): rækker=%d nye=%d kendt=%d skippet=%d\n",
    count($files), $sek, $grand['rows_total'], $grand['assign_new'], $grand['assign_seen'], $grand['rows_skipped']
);
exit(0);
