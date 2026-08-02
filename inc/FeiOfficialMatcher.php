<?php
defined('APP') or die('Direkte adgang ikke tilladt');

/**
 * Matcher hele den høstede FEI officials-liste (fei_officials, alle lande)
 * mod officials - analogt til den matchning DrfImporter laver mod
 * drf_officials. Der filtreres IKKE på nf = 'Denmark': officials-listen
 * indeholder også udenlandske FEI-officials der har virket ved danske
 * (internationale) stævner, og de skal kunne matches ligesom danske.
 *
 * fei_officials/fei_official_functions fyldes af et eksternt værktøj (se
 * sql/fei_officials_schema.SQL); denne klasse laver kun selve matchningen,
 * og køres på ny hver gang FEI-listen er blevet genindlæst.
 */
class FeiOfficialMatcher
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /** @return array{matched:int, unmatched:int} */
    public function match(): array
    {
        $this->db->begin();
        try {
            // Byg opslag: normaliseret navn -> official_id, ligesom DrfImporter -
            // aliaser (tidligere navne efter fletning) inkluderes, men et
            // nuvaerende navn har altid forrang.
            $map = [];
            foreach ($this->db->all('SELECT official_id, navn FROM official_aliases') as $a) {
                $map[$this->norm($a['navn'])] = (int)$a['official_id'];
            }
            foreach ($this->db->all('SELECT id, navn FROM officials') as $o) {
                $map[$this->norm($o['navn'])] = (int)$o['id'];
            }

            $this->db->run('UPDATE officials SET fei_listed = 0');

            $upd = $this->db->pdo()->prepare('UPDATE fei_officials SET official_id = ? WHERE fei_id = ?');

            $matched = 0;
            $unmatched = 0;
            foreach ($this->db->all('SELECT fei_id, first_name, last_name FROM fei_officials') as $r) {
                $norm = $this->norm($r['first_name'] . ' ' . $r['last_name']);
                $officialId = $map[$norm] ?? null;
                $upd->execute([$officialId, $r['fei_id']]);
                if ($officialId !== null) {
                    $matched++;
                } else {
                    $unmatched++;
                }
            }

            $this->db->run(
                'UPDATE officials o JOIN fei_officials f ON f.official_id = o.id SET o.fei_listed = 1'
            );

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return ['matched' => $matched, 'unmatched' => $unmatched];
    }

    /** Normalisér navn til matching: små bogstaver, ét mellemrum, trimmet. */
    private function norm(string $s): string
    {
        $s = preg_replace('/\s+/u', ' ', $s);
        return mb_strtolower(trim($s), 'UTF-8');
    }
}
