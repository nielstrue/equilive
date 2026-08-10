<?php
defined('APP') or die('Direkte adgang ikke tilladt');

/**
 * Engangsmigrering: retter shows.prop_unknown for stævner der blev
 * importeret før Importer::hasUsableProp() blev strammet op til at kræve
 * "PropNNNN"-formatet. Sådanne rækker har et ikke-tomt prop (fx et bart
 * tal, et UUID eller "EQ_ID_...") som ikke er en gyldig DRF-nøgle, men stod
 * med prop_unknown=0 - så ShowDetailImporter::import() fejlede på dem igen
 * og igen ved hvert nyt batch i stedet for at blive sprunget over som de
 * øvrige prop_unknown-stævner.
 */
class InvalidPropShowMigrator
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /** @return array{found:int,rows:array} */
    public function run(bool $dryRun): array
    {
        $rows = $this->db->all(
            "SELECT id, prop, aar FROM shows
             WHERE prop_unknown = 0 AND prop NOT REGEXP '^Prop[0-9]+$'
             ORDER BY id"
        );

        if (!$dryRun && $rows) {
            $upd = $this->db->pdo()->prepare('UPDATE shows SET prop_unknown = 1 WHERE id = ?');
            foreach ($rows as $r) {
                $upd->execute([$r['id']]);
            }
        }

        return ['found' => count($rows), 'rows' => $rows];
    }
}
