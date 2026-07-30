<?php
defined('APP') or die('Direkte adgang ikke tilladt');

/**
 * Knytter et umatchet navn fra DRF-listen (drf_officials) til en official -
 * enten ved at oprette en helt ny official, eller ved at "flytte" navnet
 * over på en eksisterende official der reelt er samme person (fx efter et
 * navneskift, hvor DRF-listen allerede har det nye navn, men personen endnu
 * ikke har haft en rolle registreret under det).
 */
class DrfOfficialLinker
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Opretter en ny official med $drfNavn og knytter alle drf_officials-
     * rækker med dette navn til den.
     *
     * @return int Den nye officials id.
     */
    public function createNew(string $drfNavn): int
    {
        $drfNavn = trim($drfNavn);
        if ($drfNavn === '') {
            throw new InvalidArgumentException('DRF-navn må ikke være tomt.');
        }

        $this->db->begin();
        try {
            $existing = $this->db->scalar('SELECT id FROM officials WHERE navn = ?', [$drfNavn]);
            if ($existing !== false) {
                throw new InvalidArgumentException(
                    'Der findes allerede en official med navnet "' . $drfNavn . '" (#' . $existing . ') - '
                    . 'brug "knyt til eksisterende" i stedet.'
                );
            }

            $this->db->run('INSERT INTO officials (navn) VALUES (?)', [$drfNavn]);
            $officialId = (int)$this->db->lastId();

            $this->linkDrfRows($drfNavn, $officialId);

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $officialId;
    }

    /**
     * Knytter $drfNavn til en eksisterende official: officialen omdøbes til
     * $drfNavn (det hidtidige navn bevares som alias via OfficialMerger), og
     * alle drf_officials-rækker med $drfNavn peges på officialen.
     */
    public function linkToExisting(string $drfNavn, int $officialId): void
    {
        $drfNavn = trim($drfNavn);
        if ($drfNavn === '') {
            throw new InvalidArgumentException('DRF-navn må ikke være tomt.');
        }

        $this->db->begin();
        try {
            (new OfficialMerger($this->db))->renameKeepingAlias($officialId, $drfNavn);
            $this->linkDrfRows($drfNavn, $officialId);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** Peger alle drf_officials-rækker med $navn på $officialId og markerer officialen som DRF-listed. */
    private function linkDrfRows(string $navn, int $officialId): void
    {
        $this->db->run('UPDATE drf_officials SET official_id = ? WHERE navn = ?', [$officialId, $navn]);
        $this->db->run('UPDATE officials SET drf_listed = 1 WHERE id = ?', [$officialId]);
    }
}
