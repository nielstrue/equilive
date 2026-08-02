<?php
defined('APP') or die('Direkte adgang ikke tilladt');

/**
 * Knytter en umatchet person fra FEI-listen (fei_officials) til en official -
 * enten ved at oprette en helt ny official, eller ved at knytte til en
 * eksisterende. I modsætning til DrfOfficialLinker omdøbes officialen IKKE
 * til FEI-navnet (FEI-listen skriver efternavnet med versaler, og det er
 * ikke det navn stævnedata/DRF-listen bruger) - kun selve koblingen sættes.
 */
class FeiOfficialLinker
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Opretter en ny official ud fra FEI-personens navn og knytter
     * fei_officials-rækken til den.
     *
     * @return int Den nye officials id.
     */
    public function createNew(int $feiId): int
    {
        $person = $this->db->one('SELECT fei_id, first_name, last_name FROM fei_officials WHERE fei_id = ?', [$feiId]);
        if (!$person) {
            throw new InvalidArgumentException('Ukendt FEI-person (#' . $feiId . ').');
        }
        $navn = trim($person['first_name'] . ' ' . $this->titleCase($person['last_name']));

        $this->db->begin();
        try {
            $existing = $this->db->scalar('SELECT id FROM officials WHERE navn = ?', [$navn]);
            if ($existing !== false) {
                throw new InvalidArgumentException(
                    'Der findes allerede en official med navnet "' . $navn . '" (#' . $existing . ') - '
                    . 'brug "knyt til eksisterende" i stedet.'
                );
            }

            $this->db->run('INSERT INTO officials (navn) VALUES (?)', [$navn]);
            $officialId = (int)$this->db->lastId();

            $this->linkFeiRow($feiId, $officialId);

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $officialId;
    }

    /** Knytter fei_officials-rækken $feiId til en eksisterende official $officialId. */
    public function linkToExisting(int $feiId, int $officialId): void
    {
        $official = $this->db->scalar('SELECT id FROM officials WHERE id = ?', [$officialId]);
        if ($official === false) {
            throw new InvalidArgumentException('Ukendt official-id.');
        }

        $this->db->begin();
        try {
            $this->linkFeiRow($feiId, $officialId);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function linkFeiRow(int $feiId, int $officialId): void
    {
        $this->db->run('UPDATE fei_officials SET official_id = ? WHERE fei_id = ?', [$officialId, $feiId]);
        $this->db->run('UPDATE officials SET fei_listed = 1 WHERE id = ?', [$officialId]);
    }

    private function titleCase(string $s): string
    {
        return mb_convert_case(mb_strtolower($s, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    }
}
