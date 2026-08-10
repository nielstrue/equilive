<?php
/**
 * Engangsmigrering (CLI): se inc/InvalidPropShowMigrator.php for detaljer.
 *
 * Markerer stævner med et ikke-DRF-kompatibelt prop (fx et bart tal, et UUID
 * eller "EQ_ID_...") som prop_unknown=1, så DRF-detaljehøstningen
 * (import_show_details.php / cli/import_show_details.php) holder op med at
 * forsøge dem igen i hvert batch og i stedet springer dem permanent over -
 * ligesom de allerede gør for stævner med tomt/UNKNOWN prop.
 *
 * Brug:
 *   php cli/migrate_flag_invalid_prop_shows.php            (udfør ændringer)
 *   php cli/migrate_flag_invalid_prop_shows.php --dry-run  (vis kun hvad der ville ske)
 */
require __DIR__ . '/../inc/bootstrap.php';

$dryRun = in_array('--dry-run', $argv, true);
$result = (new InvalidPropShowMigrator(db()))->run($dryRun);

printf("Fundet %d stævne(r) med prop_unknown=0 men et prop der ikke matcher \"PropNNNN\"-formatet.\n", $result['found']);
foreach ($result['rows'] as $r) {
    printf("  #%-6d aar=%-4s prop=%s\n", $r['id'], $r['aar'] ?? '-', $r['prop']);
}

echo str_repeat('-', 60) . "\n";
printf("%sMarkeret som prop_unknown=1: %d\n", $dryRun ? '[DRY RUN] ' : '', $result['found']);
exit(0);
