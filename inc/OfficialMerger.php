<?php
defined('APP') or die('Direkte adgang ikke tilladt');

/**
 * Sammenlægning af to officials-poster der reelt er samme person
 * (typisk pga. en stavefejl i det importerede navn). Flytter alle
 * assignments (fremmednøgler) til den bevarede post og sletter kilden.
 */
class OfficialMerger
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Flet $mergeId ind i $keepId og slet $mergeId.
     *
     * assignments har en unik nøgle (class_id, official_id, rolle), så hvis
     * begge officials har en tildeling i samme klasse+rolle (en reel dublet
     * fra fx to importerede rækker for samme person), kan den ikke bare
     * flyttes - den droppes i stedet (og "nummer" overføres, hvis den
     * bevarede rækkes mangler det). Alt andet flyttes uændret.
     *
     * @return array{moved:int, merged_duplicates:int}
     */
    public function merge(int $keepId, int $mergeId, ?string $finalNavn = null): array
    {
        if ($keepId === $mergeId) {
            throw new InvalidArgumentException('Kan ikke flette en official med sig selv.');
        }

        $keep  = $this->db->one('SELECT id FROM officials WHERE id = ?', [$keepId]);
        $merge = $this->db->one('SELECT id FROM officials WHERE id = ?', [$mergeId]);
        if (!$keep || !$merge) {
            throw new InvalidArgumentException('Ukendt official-id.');
        }

        $moved   = 0;
        $dropped = 0;

        $this->db->begin();
        try {
            $rows = $this->db->all(
                'SELECT id, class_id, rolle, nummer FROM assignments WHERE official_id = ?',
                [$mergeId]
            );

            foreach ($rows as $row) {
                $existing = $this->db->one(
                    'SELECT id, nummer FROM assignments WHERE official_id = ? AND class_id = ? AND rolle = ?',
                    [$keepId, $row['class_id'], $row['rolle']]
                );

                if ($existing) {
                    // Dublet: keepId har allerede denne (klasse, rolle). Overfoer
                    // "nummer" hvis den bevarede raekke mangler det. Selve
                    // merge-raekken slettes automatisk naar officials-posten
                    // slettes nedenfor (ON DELETE CASCADE).
                    if (($existing['nummer'] === null || $existing['nummer'] === '') && $row['nummer'] !== null && $row['nummer'] !== '') {
                        $this->db->run('UPDATE assignments SET nummer = ? WHERE id = ?', [$row['nummer'], $existing['id']]);
                    }
                    $dropped++;
                } else {
                    $this->db->run('UPDATE assignments SET official_id = ? WHERE id = ?', [$keepId, $row['id']]);
                    $moved++;
                }
            }

            $this->db->run('DELETE FROM officials WHERE id = ?', [$mergeId]);

            if ($finalNavn !== null && $finalNavn !== '') {
                $this->db->run('UPDATE officials SET navn = ? WHERE id = ?', [$finalNavn, $keepId]);
            }

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return ['moved' => $moved, 'merged_duplicates' => $dropped];
    }
}
