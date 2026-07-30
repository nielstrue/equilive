<?php
defined('APP') or die('Direkte adgang ikke tilladt');

/**
 * CSV-importer.
 *
 * Indlæser officials_2026.csv (semikolon-separeret, uden header) og
 * gemmer normaliseret i databasen. Kan køres igen og igen med samme
 * eller udvidet fil: eksisterende rækker opdateres via naturlige nøgler,
 * så der ikke opstår dubletter.
 *
 * Fast kolonnerækkefølge (12 felter):
 *   0 Prop  1 Klub  2 Dato  3 Forkort  4 Disciplin  5 Klassenr
 *   6 Klassenavn  7 Niveau  8 Official  9 Rolle  10 Starter  11 Nummer
 *
 * Nogle klassenavne indeholder selv semikolon(er). Da de første 6 og de
 * sidste 5 felter altid er faste, samles alt "i midten" til Klassenavn.
 */
class Importer
{
    private Database $db;

    // In-memory caches (naturlig nøgle => id) for hastighed under import.
    private array $clubCache = [];
    private array $showCache = [];
    private array $classCache = [];
    private array $officialCache = [];

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Importér en CSV-fil.
     * @return array summary med tælleværdier
     */
    public function import(string $filePath, ?string $filename = null, ?int $year = null): array
    {
        if (!is_readable($filePath)) {
            throw new RuntimeException('Kan ikke læse filen: ' . $filePath);
        }

        // Udled årstal af filnavnet (fx officials_2025.csv => 2025), hvis ikke angivet.
        if ($year === null && $filename !== null && preg_match('/officials_(\d{4})/i', $filename, $mm)) {
            $year = (int)$mm[1];
        }

        $summary = [
            'rows_total'   => 0,
            'rows_skipped' => 0,
            'assign_new'   => 0,
            'assign_seen'  => 0,
            'shows'        => 0,
            'classes'      => 0,
            'officials'    => 0,
        ];

        $fh = fopen($filePath, 'r');
        if ($fh === false) {
            throw new RuntimeException('Kunne ikke åbne filen.');
        }

        $this->db->begin();
        try {
            $first = true;
            while (($line = fgets($fh)) !== false) {
                // Fjern evt. UTF-8 BOM på første linje.
                if ($first) {
                    $line = preg_replace('/^\xEF\xBB\xBF/', '', $line);
                    $first = false;
                }
                $line = rtrim($line, "\r\n");
                if ($line === '') {
                    continue;
                }

                $row = $this->parseLine($line);
                if ($row === null) {
                    $summary['rows_skipped']++;
                    continue;
                }
                $summary['rows_total']++;

                $clubId     = $this->getClub($row['forkort'], $row['klub']);
                $showId     = $this->getShow($row, $clubId, $year);
                $classId    = $this->getClass($showId, $row);
                $officialId = $this->getOfficial($row['official']);

                $isNew = $this->upsertAssignment($classId, $officialId, $row['rolle'], $row['nummer']);
                if ($isNew) {
                    $summary['assign_new']++;
                } else {
                    $summary['assign_seen']++;
                }
            }
            fclose($fh);

            // Genberegn stævnernes samlede niveau + primær disciplin.
            $this->recomputeShowLevels();

            $summary['shows']     = count($this->showCache);
            $summary['classes']   = count($this->classCache);
            $summary['officials'] = count($this->officialCache);

            // Log importen.
            $this->db->run(
                'INSERT INTO imports (filename, imported_at, rows_total, rows_skipped, assign_new, assign_seen, note)
                 VALUES (?, NOW(), ?, ?, ?, ?, ?)',
                [
                    $filename,
                    $summary['rows_total'],
                    $summary['rows_skipped'],
                    $summary['assign_new'],
                    $summary['assign_seen'],
                    'OK',
                ]
            );

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            if (is_resource($fh)) fclose($fh);
            throw $e;
        }

        return $summary;
    }

    /**
     * Del en CSV-linje op i de 12 logiske felter.
     * Returnerer null hvis linjen ikke kan tolkes (for få felter).
     */
    public function parseLine(string $line): ?array
    {
        $p = explode(';', $line);
        if (count($p) < 12) {
            return null;
        }

        // Klassenavn = alt mellem de 6 første og de 5 sidste felter.
        $klassenavn = implode(';', array_slice($p, 6, count($p) - 11));

        return [
            'prop'       => trim($p[0]),
            'klub'       => trim($p[1]),
            'dato'       => trim($p[2]),
            'forkort'    => trim($p[3]),
            'disciplin'  => trim($p[4]),
            'klassenr'   => trim($p[5]),
            'klassenavn' => trim($klassenavn),
            'niveau'     => strtolower(trim($p[count($p) - 5])),
            'official'   => trim($p[count($p) - 4]),
            'rolle'      => trim($p[count($p) - 3]),
            'starter'    => trim($p[count($p) - 2]),
            'nummer'     => trim($p[count($p) - 1]),
        ];
    }

    /** dd/mm/yyyy => Y-m-d, eller null hvis ugyldig. */
    private function parseDate(string $s): ?string
    {
        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $s, $m)) {
            $d = sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
            if (checkdate((int)$m[2], (int)$m[1], (int)$m[3])) {
                return $d;
            }
        }
        return null;
    }

    private function getClub(string $forkort, string $navn): int
    {
        // Nøgle: Forkort hvis udfyldt, ellers klubnavn.
        $key = $forkort !== '' ? $forkort : ('navn:' . $navn);
        if (isset($this->clubCache[$key])) {
            return $this->clubCache[$key];
        }

        $id = $this->db->scalar('SELECT id FROM clubs WHERE club_key = ?', [$key]);
        if ($id === false) {
            $this->db->run(
                'INSERT INTO clubs (club_key, forkort, navn) VALUES (?, ?, ?)',
                [$key, $forkort !== '' ? $forkort : null, $navn]
            );
            $id = $this->db->lastId();
        } else {
            // Hold navn/forkort opdateret.
            $this->db->run(
                'UPDATE clubs SET navn = ?, forkort = COALESCE(NULLIF(?, ""), forkort) WHERE id = ?',
                [$navn, $forkort, $id]
            );
        }
        return $this->clubCache[$key] = (int)$id;
    }

    private function getShow(array $row, int $clubId, ?int $year = null): int
    {
        // Naturlig nøgle: prop|forkort|dato|klub (så UNKNOWN-prop ikke smelter sammen).
        $natKey = sha1($row['prop'] . '|' . $row['forkort'] . '|' . $row['dato'] . '|' . $row['klub']);
        $iso = $this->parseDate($row['dato']);
        // Årstal: fra filnavn hvis kendt, ellers fra datoen.
        $aar = $year ?? ($iso !== null ? (int)substr($iso, 0, 4) : null);
        if (isset($this->showCache[$natKey])) {
            return $this->showCache[$natKey];
        }

        $id = $this->db->scalar('SELECT id FROM shows WHERE natural_key = ?', [$natKey]);
        if ($id === false) {
            $this->db->run(
                'INSERT INTO shows (natural_key, prop, club_id, dato, aar, prop_unknown)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [
                    $natKey,
                    $row['prop'],
                    $clubId,
                    $iso,
                    $aar,
                    ($row['prop'] === '' || strtoupper($row['prop']) === 'UNKNOWN') ? 1 : 0,
                ]
            );
            $id = $this->db->lastId();
        } else {
            $this->db->run(
                'UPDATE shows SET club_id = ?, dato = ?, aar = COALESCE(?, aar) WHERE id = ?',
                [$clubId, $iso, $aar, $id]
            );
        }
        return $this->showCache[$natKey] = (int)$id;
    }

    private function getClass(int $showId, array $row): int
    {
        $natKey = sha1($showId . '|' . $row['klassenr'] . '|' . $row['klassenavn']);
        if (isset($this->classCache[$natKey])) {
            return $this->classCache[$natKey];
        }

        // Kendt niveau? Ellers gem NULL (rå værdi kan være tom/sponsortekst ved fejl).
        $niveau = isset(Levels::MAP[$row['niveau']]) ? $row['niveau'] : null;
        $starter = is_numeric($row['starter']) ? (int)$row['starter'] : null;
        $stil = (strpos($row['klassenavn'], 'S2') !== false) ? 1 : 0;

        $id = $this->db->scalar('SELECT id FROM classes WHERE natural_key = ?', [$natKey]);
        if ($id === false) {
            $this->db->run(
                'INSERT INTO classes (natural_key, show_id, klassenr, klassenavn, disciplin, niveau_slug, starter, stilspringning)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$natKey, $showId, $row['klassenr'], $row['klassenavn'], $row['disciplin'], $niveau, $starter, $stil]
            );
            $id = $this->db->lastId();
        } else {
            // Opdatér tal der kan ændre sig mellem eksporter (fx Starter).
            $this->db->run(
                'UPDATE classes SET disciplin = ?, niveau_slug = ?, starter = ?, stilspringning = ? WHERE id = ?',
                [$row['disciplin'], $niveau, $starter, $stil, $id]
            );
        }
        return $this->classCache[$natKey] = (int)$id;
    }

    private function getOfficial(string $navn): int
    {
        $navn = $this->normalizeNavn($navn);
        $navn = $navn === '' ? '(ukendt)' : $navn;
        if (isset($this->officialCache[$navn])) {
            return $this->officialCache[$navn];
        }
        $id = $this->db->scalar('SELECT id FROM officials WHERE navn = ?', [$navn]);
        if ($id === false) {
            // Er navnet et kendt alias for en eksisterende official (fx efter et
            // navneskift der tidligere er flettet)? Saa genbruges samme official
            // i stedet for at oprette en ny.
            $id = $this->db->scalar('SELECT official_id FROM official_aliases WHERE navn = ?', [$navn]);
        }
        if ($id === false) {
            $this->db->run('INSERT INTO officials (navn) VALUES (?)', [$navn]);
            $id = $this->db->lastId();
        } else {
            // Optræder personen i årets Equipe-eksport, er vedkommende aktiv -
            // ogsaa selvom status tidligere er sat til ikke_aktiv (fx efter en pause).
            $this->db->run(
                "UPDATE officials SET status = 'aktiv' WHERE id = ? AND status = 'ikke_aktiv'",
                [$id]
            );
        }
        return $this->officialCache[$navn] = (int)$id;
    }

    /**
     * Fjerner usynlige tegn (non-breaking space m.fl.) som trim() ikke rammer,
     * og kollapser flere mellemrum til ét - ellers kan samme person ende som
     * to forskellige officials-rækker der ser identiske ud på skærmen.
     */
    private function normalizeNavn(string $navn): string
    {
        $navn = preg_replace('/[\x{00A0}\x{2000}-\x{200B}\x{202F}\x{FEFF}]/u', ' ', $navn) ?? $navn;
        $navn = preg_replace('/\s+/u', ' ', $navn) ?? $navn;
        return trim($navn);
    }

    /** @return bool true hvis en ny tildeling blev oprettet. */
    private function upsertAssignment(int $classId, int $officialId, string $rolle, string $nummer): bool
    {
        $rolle = $rolle === '' ? '(ukendt)' : $rolle;
        $exists = $this->db->scalar(
            'SELECT id FROM assignments WHERE class_id = ? AND official_id = ? AND rolle = ?',
            [$classId, $officialId, $rolle]
        );
        if ($exists === false) {
            $this->db->run(
                'INSERT INTO assignments (class_id, official_id, rolle, nummer) VALUES (?, ?, ?, ?)',
                [$classId, $officialId, $rolle, $nummer !== '' ? $nummer : null]
            );
            return true;
        }
        $this->db->run('UPDATE assignments SET nummer = ? WHERE id = ?', [$nummer !== '' ? $nummer : null, $exists]);
        return false;
    }

    /**
     * Genberegn hvert stævnes samlede niveau ud fra dets klasser:
     *  - top_rank/top_slug/top_code = højeste klasseniveau
     *  - has_lower = 1 hvis mindst én klasse er på et lavere niveau
     *  - disciplin = hyppigste disciplin blandt klasserne
     */
    private function recomputeShowLevels(): void
    {
        // Niveauer (højeste + om der findes lavere).
        $rows = $this->db->all(
            'SELECT c.show_id,
                    MAX(l.`rank`) AS mx,
                    MIN(l.`rank`) AS mn
             FROM classes c
             JOIN levels l ON l.slug = c.niveau_slug
             GROUP BY c.show_id'
        );
        $upd = $this->db->pdo()->prepare(
            'UPDATE shows SET top_rank = ?, top_slug = ?, top_code = ?, has_lower = ? WHERE id = ?'
        );
        foreach ($rows as $r) {
            $mx = (int)$r['mx'];
            $upd->execute([
                $mx,
                Levels::slugForRank($mx),
                Levels::codeForRank($mx),
                ((int)$r['mn'] < $mx) ? 1 : 0,
                (int)$r['show_id'],
            ]);
        }

        // Primær disciplin = hyppigste blandt stævnets klasser.
        $disc = $this->db->all(
            'SELECT show_id, disciplin, COUNT(*) AS n
             FROM classes
             WHERE disciplin IS NOT NULL AND disciplin <> ""
             GROUP BY show_id, disciplin'
        );
        $best = [];
        foreach ($disc as $d) {
            $sid = (int)$d['show_id'];
            if (!isset($best[$sid]) || $d['n'] > $best[$sid]['n']) {
                $best[$sid] = ['disciplin' => $d['disciplin'], 'n' => $d['n']];
            }
        }
        $updD = $this->db->pdo()->prepare('UPDATE shows SET disciplin = ? WHERE id = ?');
        foreach ($best as $sid => $b) {
            $updD->execute([$b['disciplin'], $sid]);
        }
    }

    /**
     * Henter CSV'en fra en URL (fx api.equilive.dk) og importerer den.
     * Gemmes til $targetPath, så filen også ligger klar til fx planlagt
     * kørsel via cli/import.php (typisk config['default_csv']).
     *
     * @return array summary med tælleværdier, se import()
     */
    public function importFromUrl(string $url, string $targetPath): array
    {
        $body = $this->fetchUrl($url);
        if ($body === '') {
            throw new RuntimeException('Hentede en tom fil fra ' . $url);
        }

        $dir = dirname($targetPath);
        if (!is_dir($dir)) {
            throw new RuntimeException('Målmappen findes ikke: ' . $dir);
        }
        if (file_put_contents($targetPath, $body) === false) {
            throw new RuntimeException('Kunne ikke skrive filen: ' . $targetPath);
        }

        return $this->import($targetPath, basename($targetPath));
    }

    private function fetchUrl(string $url): string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => 60,
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; EquiliveBot/1.0)',
            ]);
            $body = curl_exec($ch);
            $err  = curl_error($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($body === false) {
                throw new RuntimeException('curl-fejl: ' . $err);
            }
            if ($code >= 400) {
                throw new RuntimeException('HTTP ' . $code . ' fra ' . $url);
            }
            return (string)$body;
        }
        $ctx = stream_context_create(['http' => [
            'timeout' => 60,
            'header'  => "User-Agent: Mozilla/5.0 (compatible; EquiliveBot/1.0)\r\n",
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            throw new RuntimeException('Kunne ikke hente URL (aktivér curl eller allow_url_fopen): ' . $url);
        }
        return $body;
    }
}
