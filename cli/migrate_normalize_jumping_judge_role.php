<?php
/**
 * Engangsmigrering (CLI): se inc/JumpingJudgeRoleMigrator.php for detaljer.
 *
 * Brug:
 *   php cli/migrate_normalize_jumping_judge_role.php            (udfør ændringer)
 *   php cli/migrate_normalize_jumping_judge_role.php --dry-run  (vis kun hvad der ville ske)
 */
require __DIR__ . '/../inc/bootstrap.php';

$dryRun = in_array('--dry-run', $argv, true);

$result = (new JumpingJudgeRoleMigrator(db()))->run($dryRun);

printf("Fundet %d tildeling(er) med rolle judge/chief_judge på springklasser.\n", $result['found']);
foreach ($result['dupes'] as $row) {
    printf("  dublet fjernet: assignment #%d (%s) - klasse %d, official %d havde allerede show_jumping_judge\n",
        $row['id'], $row['rolle'], $row['class_id'], $row['official_id']);
}

echo str_repeat('-', 60) . "\n";
printf(
    "%sOmdøbt til show_jumping_judge: %d\nDubletter fjernet: %d (heraf %d med nummer overført)\n",
    $dryRun ? '[DRY RUN] ' : '', $result['renamed'], $result['dupes_removed'], $result['nummer_transferred']
);
exit(0);
