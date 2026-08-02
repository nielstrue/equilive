<?php
defined('APP') or die('Direkte adgang ikke tilladt');

/**
 * Sammenlægning af to klub-poster der reelt er samme klub (typisk pga. en
 * stavefejl eller manglende forkortelse i det importerede navn). Flytter
 * fremmednøgler (shows.club_id, drf_clubs.club_id) til den bevarede post og
 * sletter kilden. Ingen historik/alias bevares - hvis en fremtidig CSV-import
 * stadig bruger den flettede klubs club_key, opretter Importer den bare igen.
 */
class ClubMerger
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Flet $mergeId ind i $keepId og slet $mergeId.
     *
     * @return array{moved_shows:int, moved_drf:int}
     */
    public function merge(int $keepId, int $mergeId): array
    {
        if ($keepId === $mergeId) {
            throw new InvalidArgumentException('Kan ikke flette en klub med sig selv.');
        }

        $keep  = $this->db->one('SELECT id FROM clubs WHERE id = ?', [$keepId]);
        $merge = $this->db->one('SELECT id FROM clubs WHERE id = ?', [$mergeId]);
        if (!$keep || !$merge) {
            throw new InvalidArgumentException('Ukendt klub-id.');
        }

        $this->db->begin();
        try {
            $movedShows = $this->db->run('UPDATE shows SET club_id = ? WHERE club_id = ?', [$keepId, $mergeId])->rowCount();
            $movedDrf   = $this->db->run('UPDATE drf_clubs SET club_id = ? WHERE club_id = ?', [$keepId, $mergeId])->rowCount();
            $this->db->run('DELETE FROM clubs WHERE id = ?', [$mergeId]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return ['moved_shows' => $movedShows, 'moved_drf' => $movedDrf];
    }
}
