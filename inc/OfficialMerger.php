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

        $keep  = $this->db->one('SELECT id, navn FROM officials WHERE id = ?', [$keepId]);
        $merge = $this->db->one('SELECT id, navn FROM officials WHERE id = ?', [$mergeId]);
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

            // Bevar det flettede navn - og dets evt. egne tidligere aliaser - som
            // alias paa den bevarede official, saa fremtidig CSV-import og
            // DRF-matchning stadig genkender personen under det gamle navn.
            $this->addAlias($keepId, $merge['navn']);
            foreach ($this->db->all('SELECT navn FROM official_aliases WHERE official_id = ?', [$mergeId]) as $a) {
                $this->addAlias($keepId, $a['navn']);
            }

            $this->db->run('DELETE FROM officials WHERE id = ?', [$mergeId]);

            if ($finalNavn !== null && $finalNavn !== '') {
                // Det hidtidige navn paa den bevarede official skal ogsaa kunne
                // genkendes fremover, hvis det rettes samtidig med fletningen.
                $this->renameKeepingAlias($keepId, $finalNavn);
            }

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return ['moved' => $moved, 'merged_duplicates' => $dropped];
    }

    /**
     * Omdøber en official og bevarer det hidtidige navn som alias, saa
     * fremtidig CSV-import og DRF-matchning stadig genkender personen under
     * det gamle navn. Bruges bl.a. naar et DRF-navn knyttes til en
     * eksisterende official der har skiftet navn (se DrfOfficialLinker).
     */
    public function renameKeepingAlias(int $officialId, string $newNavn): void
    {
        $newNavn = trim($newNavn);
        if ($newNavn === '') {
            throw new InvalidArgumentException('Nyt navn må ikke være tomt.');
        }
        $official = $this->db->one('SELECT id, navn FROM officials WHERE id = ?', [$officialId]);
        if (!$official) {
            throw new InvalidArgumentException('Ukendt official-id.');
        }
        if ($newNavn === $official['navn']) {
            return;
        }

        // Kan kaldes selvstændigt eller som del af en større transaktion (fx
        // DrfOfficialLinker) - start kun en ny transaktion hvis der ikke
        // allerede er én i gang, ellers fejler PDO paa en nested begin().
        $ownTransaction = !$this->db->pdo()->inTransaction();
        if ($ownTransaction) {
            $this->db->begin();
        }
        try {
            // Raekkefoelgen betyder noget: UPDATE foerst, saa addAlias() (som
            // selv slaar det NUVAERENDE navn op for at undgaa at gemme et
            // alias identisk med navnet) rent faktisk sammenligner mod det
            // gamle navn og ikke det navn vi er ved at saette.
            $this->db->run('UPDATE officials SET navn = ? WHERE id = ?', [$newNavn, $officialId]);
            $this->addAlias($officialId, $official['navn']);
            if ($ownTransaction) {
                $this->db->commit();
            }
        } catch (Throwable $e) {
            if ($ownTransaction) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Gemmer $navn som alias for $officialId, hvis det ikke allerede er
     * personens nuværende navn. Findes samme navn allerede som alias for en
     * anden official (fx efter en tidligere fejlagtig fletning), rettes det
     * til at pege på denne official i stedet.
     */
    private function addAlias(int $officialId, string $navn): void
    {
        $navn = trim($navn);
        if ($navn === '') {
            return;
        }
        $current = $this->db->scalar('SELECT navn FROM officials WHERE id = ?', [$officialId]);
        if ($navn === $current) {
            return;
        }
        $this->db->run(
            'INSERT INTO official_aliases (official_id, navn, created_at) VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE official_id = VALUES(official_id)',
            [$officialId, $navn]
        );
    }
}
