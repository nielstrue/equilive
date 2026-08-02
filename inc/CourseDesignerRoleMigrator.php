<?php
defined('APP') or die('Direkte adgang ikke tilladt');

/**
 * Engangsmigrering: retter eksisterende tildelinger til samme datavask som
 * Importer::normalizeRolle() nu laver løbende ved import - rollen
 * 'course_designer' er generisk på tværs af discipliner (bruges også til
 * eventing/dressur), men på en springklasse (classes.disciplin =
 * show_jumping) dækker den reelt banebyggeren og rettes til
 * 'show_jumping_course_designer'. Den oprindelige rolle bevares i orig_rolle.
 *
 * Hvis officialen allerede har en separat 'show_jumping_course_designer'-
 * tildeling på samme klasse (en reel dublet, fx fra en fejl i kilden), kan
 * rækken ikke bare omdøbes uden at bryde assignments' unikke nøgle (class_id,
 * official_id, rolle) - i stedet overføres "nummer" til den bevarede række
 * (hvis den mangler det), og dublet-rækken slettes.
 *
 * Deles af cli/migrate_normalize_course_designer_role.php (terminal) og
 * migrate_course_designer_role.php (webside, til hosting uden terminal/cron).
 */
class CourseDesignerRoleMigrator
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /** @return array{found:int, renamed:int, dupes_removed:int, nummer_transferred:int, dupes:array} */
    public function run(bool $dryRun): array
    {
        $rows = $this->db->all(
            "SELECT a.id, a.class_id, a.official_id, a.rolle, a.nummer
             FROM assignments a
             JOIN classes c ON c.id = a.class_id
             WHERE c.disciplin = 'show_jumping' AND a.rolle = 'course_designer'
             ORDER BY a.id"
        );

        $renamed = 0;
        $dupesRemoved = 0;
        $nummerTransferred = 0;
        $dupes = [];

        if (!$rows) {
            return [
                'found' => 0, 'renamed' => 0, 'dupes_removed' => 0,
                'nummer_transferred' => 0, 'dupes' => [],
            ];
        }

        if (!$dryRun) {
            $this->db->begin();
        }

        try {
            foreach ($rows as $row) {
                $existing = $this->db->one(
                    "SELECT id, nummer FROM assignments
                     WHERE class_id = ? AND official_id = ? AND rolle = 'show_jumping_course_designer' AND id <> ?",
                    [$row['class_id'], $row['official_id'], $row['id']]
                );

                if ($existing) {
                    // Dublet: officialen har allerede en show_jumping_course_designer-
                    // tildeling paa denne klasse. Overfoer "nummer" hvis den bevarede
                    // raekke mangler det, og slet course_designer-raekken i stedet for
                    // at omdoebe den (ville ellers bryde den unikke noegle).
                    if (($existing['nummer'] === null || $existing['nummer'] === '')
                        && $row['nummer'] !== null && $row['nummer'] !== '') {
                        if (!$dryRun) {
                            $this->db->run('UPDATE assignments SET nummer = ? WHERE id = ?', [$row['nummer'], $existing['id']]);
                        }
                        $nummerTransferred++;
                    }
                    if (!$dryRun) {
                        $this->db->run('DELETE FROM assignments WHERE id = ?', [$row['id']]);
                    }
                    $dupesRemoved++;
                    $dupes[] = $row;
                    continue;
                }

                if (!$dryRun) {
                    $this->db->run(
                        'UPDATE assignments SET orig_rolle = ?, rolle = ? WHERE id = ?',
                        [$row['rolle'], 'show_jumping_course_designer', $row['id']]
                    );
                }
                $renamed++;
            }

            if (!$dryRun) {
                $this->db->commit();
            }
        } catch (Throwable $e) {
            if (!$dryRun) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return [
            'found' => count($rows),
            'renamed' => $renamed,
            'dupes_removed' => $dupesRemoved,
            'nummer_transferred' => $nummerTransferred,
            'dupes' => $dupes,
        ];
    }
}
