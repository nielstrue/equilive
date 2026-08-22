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
 *
 * Roller i import_excluded_roles (admin-styret, se import_role_exclusions.php)
 * springes helt over - hele CSV-rækken ignoreres, saa der hverken oprettes
 * show/klasse/official eller tildeling for den. Paavirker kun fremtidige
 * imports, ikke allerede importerede tildelinger.
 *
 * Klasser med disciplin "score_summary" er IKKE rigtige klasser (en syntetisk
 * opsummeringsraekke fra kilden) og springes derfor altid over - fast regel,
 * ikke admin-styret som import_excluded_roles. Allerede importerede
 * score_summary-klasser skal ryddes manuelt, se
 * sql/find_and_delete_score_summary_classes.sql.
 *
 * Hver tildeling tjekkes desuden for to ikke-blokerende advarsler (se
 * checkAssignmentWarnings()): om rollen passer til klassens disciplin
 * (rollekataloget, roles.php), og om officialen har den DRF-type rollen
 * ev. kræver (role_drf_types, redigeres ogsaa paa roles.php). Fejlede tjek
 * stopper IKKE importen - de gemmes i assignment_warnings til gennemsyn paa
 * warnings.php.
 */
class Importer
{
    private Database $db;

    // In-memory caches (naturlig nøgle => id) for hastighed under import.
    private array $clubCache = [];
    private array $showCache = [];
    private array $classCache = [];
    private array $officialCache = [];

    // Roller (raa CSV-værdi) der springes helt over ved import - se
    // import_role_exclusions.php. Indlæses én gang pr. import() i excludedRoles().
    private array $excludedRoles = [];

    // Caches til checkAssignmentWarnings(), indlæst én gang pr. import().
    private array $rolesCatalog = [];         // rolle-navn => ['alle' => bool, 'discs' => string[]]
    private array $roleDrfTypes = [];         // rolle-navn => string[] (kraevede DRF-type-delstrenge)
    private array $officialDrfTypesCache = []; // official_id => string[] (drf_officials.type), lazy pr. official

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
            'rows_total'             => 0,
            'rows_skipped'           => 0,
            'assign_new'             => 0,
            'assign_seen'            => 0,
            'assign_excluded'        => 0,
            'score_summary_skipped'  => 0,
            'warnings_flagged'       => 0,
            'assign_deleted_skipped' => 0,
            'shows'                  => 0,
            'classes'                => 0,
            'officials'              => 0,
        ];

        $this->excludedRoles = array_flip(array_column(
            $this->db->all('SELECT rolle FROM import_excluded_roles'), 'rolle'
        ));

        $this->rolesCatalog = [];
        foreach ($this->db->all(
            "SELECT r.navn, r.alle_discipliner, GROUP_CONCAT(DISTINCT rd.disciplin) AS discs
             FROM roles r
             LEFT JOIN role_disciplines rd ON rd.role_id = r.id
             GROUP BY r.id, r.navn, r.alle_discipliner"
        ) as $r) {
            $this->rolesCatalog[$r['navn']] = [
                'alle'  => (bool)$r['alle_discipliner'],
                'discs' => $r['discs'] !== null ? explode(',', $r['discs']) : [],
            ];
        }

        $this->roleDrfTypes = [];
        foreach ($this->db->all(
            'SELECT r.navn, rdt.drf_type FROM role_drf_types rdt JOIN roles r ON r.id = rdt.role_id'
        ) as $r) {
            $this->roleDrfTypes[$r['navn']][] = $r['drf_type'];
        }
        $this->officialDrfTypesCache = [];

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

                if ($row['disciplin'] === 'score_summary') {
                    $summary['score_summary_skipped']++;
                    continue;
                }

                if (isset($this->excludedRoles[$row['rolle']])) {
                    $summary['assign_excluded']++;
                    continue;
                }

                $clubId     = $this->getClub($row['forkort'], $row['klub']);
                $showId     = $this->getShow($row, $clubId, $year);
                $classId    = $this->getClass($showId, $row);
                $officialId = $this->getOfficial($row['official']);

                $origRolle = $row['rolle'] === '' ? '(ukendt)' : $row['rolle'];
                if ($this->isTombstoned($classId, $officialId, $origRolle)) {
                    $summary['assign_deleted_skipped']++;
                    continue;
                }

                $result = $this->upsertAssignment(
                    $classId, $officialId, $row['rolle'], $row['nummer'],
                    $row['disciplin'], $this->isStilspringning($row['klassenavn'])
                );
                if ($result['isNew']) {
                    $summary['assign_new']++;
                } else {
                    $summary['assign_seen']++;
                }

                $summary['warnings_flagged'] += $this->checkAssignmentWarnings(
                    $result['id'], $officialId, $result['rolle'], $row['disciplin']
                );
            }
            fclose($fh);

            // Genberegn stævnernes samlede niveau + primær disciplin.
            $this->recomputeShowLevels();

            $summary['shows']     = count($this->showCache);
            $summary['classes']   = count($this->classCache);
            $summary['officials'] = count($this->officialCache);

            // Log importen.
            $this->db->run(
                'INSERT INTO imports (filename, imported_at, rows_total, rows_skipped, assign_new, assign_seen, assign_excluded, warnings_flagged, assign_deleted_skipped, note)
                 VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $filename,
                    $summary['rows_total'],
                    $summary['rows_skipped'],
                    $summary['assign_new'],
                    $summary['assign_seen'],
                    $summary['assign_excluded'],
                    $summary['warnings_flagged'],
                    $summary['assign_deleted_skipped'],
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
            // navn overskrives IKKE her - en senere import kan have en rodet/forkert
            // klub-tekst i kilden (fx en sammenblanding af stævne- og klubnavn), som
            // ellers ville overskrive et allerede korrekt klubnavn. Ret navnet manuelt
            // på klubbens side i stedet (se club.php).
            $this->db->run(
                'UPDATE clubs SET forkort = COALESCE(NULLIF(?, ""), forkort) WHERE id = ?',
                [$forkort, $id]
            );
        }
        return $this->clubCache[$key] = (int)$id;
    }

    /**
     * Kun props på formen "PropNNNNN" kan slås op på DRF (se
     * ShowDetailImporter) - ældre/andre kilder har af og til leveret et bart
     * tal, et UUID eller "EQ_ID_..." i stedet, som ikke er en gyldig DRF-nøgle
     * selvom feltet ikke er tomt.
     */
    private function hasUsableProp(string $prop): bool
    {
        return (bool)preg_match('/^Prop\d+$/i', $prop);
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
                    $this->hasUsableProp($row['prop']) ? 0 : 1,
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
        $stil = $this->isStilspringning($row['klassenavn']) ? 1 : 0;

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

    /**
     * Er denne (klasse, official, raa CSV-rolle) bevidst slettet af en bruger
     * (class.php's "Slet", se deleted_assignments.php)? Saa maa den ikke
     * genskabes her. $origRolle skal vaere normaliseret ligesom
     * upsertAssignment() gør det ('' => '(ukendt)'), saa nøglen matcher den
     * der blev gemt paa sletningstidspunktet.
     */
    private function isTombstoned(int $classId, int $officialId, string $origRolle): bool
    {
        return $this->db->scalar(
            'SELECT id FROM deleted_assignments WHERE class_id = ? AND official_id = ? AND orig_rolle = ?',
            [$classId, $officialId, $origRolle]
        ) !== false;
    }

    /**
     * Opretter/opdaterer en tildeling. Matcher på orig_rolle (den rolle CSV'en
     * oprindelig satte) i stedet for rolle (den viste rolle), saa en manuel
     * rolleret­telse i class.php genkendes ved næste import og ikke bliver
     * duplikeret med den oprindelige (uredigerede) rolle fra kilden - se
     * sql/migrate_add_orig_rolle.sql. rolle røres derfor aldrig her efter
     * første oprettelse.
     *
     * @return array{id:int,isNew:bool,rolle:string} id/isNew til tælling, rolle (den
     *         viste/normaliserede rolle) til checkAssignmentWarnings().
     */
    private function upsertAssignment(int $classId, int $officialId, string $rolle, string $nummer, string $disciplin, bool $isStilspringning): array
    {
        $rolle = $rolle === '' ? '(ukendt)' : $rolle;
        $displayRolle = $this->normalizeRolle($disciplin, $rolle, $isStilspringning);
        $exists = $this->db->scalar(
            'SELECT id FROM assignments WHERE class_id = ? AND official_id = ? AND orig_rolle = ?',
            [$classId, $officialId, $rolle]
        );
        if ($exists === false) {
            // Samme official kan optræde med to forskellige CSV-roller i samme
            // klasse, der normaliseres til samme viste rolle (fx 'judge' og
            // 'chief_judge' => 'show_jumping_judge'). Uden dette tjek ville
            // begge rammes af INSERT og krænke assignments.uq_assign
            // (class_id, official_id, rolle).
            $exists = $this->db->scalar(
                'SELECT id FROM assignments WHERE class_id = ? AND official_id = ? AND rolle = ?',
                [$classId, $officialId, $displayRolle]
            );
        }
        if ($exists === false) {
            $this->db->run(
                'INSERT INTO assignments (class_id, official_id, rolle, orig_rolle, nummer) VALUES (?, ?, ?, ?, ?)',
                [$classId, $officialId, $displayRolle, $rolle, $nummer !== '' ? $nummer : null]
            );
            return ['id' => (int)$this->db->lastId(), 'isNew' => true, 'rolle' => $displayRolle];
        }
        $this->db->run('UPDATE assignments SET nummer = ? WHERE id = ?', [$nummer !== '' ? $nummer : null, $exists]);
        return ['id' => (int)$exists, 'isNew' => false, 'rolle' => $displayRolle];
    }

    /**
     * Ikke-blokerende tjek af én tildeling, kaldt for hver CSV-række (ikke kun
     * nye) - saa en tidligere fejlfri tildeling ogsaa fanges, hvis fx
     * rollekataloget eller DRF-typerne ændrer sig efterfølgende. Gemmer
     * fundne problemer i assignment_warnings og RYDDER dem igen hvis
     * problemet ikke længere er der (tabellen afspejler altid nutiden).
     *
     * @return int antal NYE advarsler (ikke tidligere set) for denne tildeling.
     */
    private function checkAssignmentWarnings(int $assignmentId, int $officialId, string $rolle, string $disciplin): int
    {
        $problems = [];

        // A) Passer rollen til klassens disciplin? Kun for roller der allerede
        // er klassificeret i rollekataloget (roles.php) - en uklassificeret
        // rolle siger vi intet om (se roles.php's "Roller uden klassifikation").
        if ($disciplin !== '' && isset($this->rolesCatalog[$rolle])) {
            $cat = $this->rolesCatalog[$rolle];
            if (!$cat['alle'] && $cat['discs'] && !in_array($disciplin, $cat['discs'], true)) {
                $problems['rolle_disciplin'] = sprintf(
                    'Rollen "%s" er ikke klassificeret til disciplinen "%s" i rollekataloget (se Roller).',
                    $rolle, $disciplin
                );
            }
        }

        // B) Har officialen den DRF-type rollen ev. kræver (role_drf_types)?
        // Matcher som delstreng (case-insensitive), ligesom DrfImporter::kategori().
        if (!empty($this->roleDrfTypes[$rolle])) {
            if (!array_key_exists($officialId, $this->officialDrfTypesCache)) {
                $this->officialDrfTypesCache[$officialId] = array_column(
                    $this->db->all('SELECT DISTINCT type FROM drf_officials WHERE official_id = ?', [$officialId]),
                    'type'
                );
            }
            $matched = false;
            foreach ($this->roleDrfTypes[$rolle] as $required) {
                foreach ($this->officialDrfTypesCache[$officialId] as $actual) {
                    if (stripos($actual, $required) !== false) {
                        $matched = true;
                        break 2;
                    }
                }
            }
            if (!$matched) {
                $problems['rolle_drf_type'] = sprintf(
                    'Officialen er ikke registreret med DRF-typen "%s" som rollen "%s" kræver.',
                    implode('/', $this->roleDrfTypes[$rolle]), $rolle
                );
            }
        }

        $newCount = 0;
        foreach (['rolle_disciplin', 'rolle_drf_type'] as $type) {
            if (isset($problems[$type])) {
                $existing = $this->db->scalar(
                    'SELECT id FROM assignment_warnings WHERE assignment_id = ? AND type = ?',
                    [$assignmentId, $type]
                );
                $this->db->run(
                    'INSERT INTO assignment_warnings (assignment_id, type, besked) VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE besked = VALUES(besked), created_at = NOW()',
                    [$assignmentId, $type, $problems[$type]]
                );
                if ($existing === false) {
                    $newCount++;
                }
            } else {
                $this->db->run(
                    'DELETE FROM assignment_warnings WHERE assignment_id = ? AND type = ?',
                    [$assignmentId, $type]
                );
            }
        }
        return $newCount;
    }

    /**
     * Datavask ved import: på en springklasse (disciplin = show_jumping)
     * dækker CSV-rollerne 'judge' og 'chief_judge' reelt springdommeren -
     * gemmes derfor som 'show_jumping_judge', den rolle DRF's aktivitetskrav
     * (Stats::springdommerKravStatus) og øvrig statistik kigger efter.
     * På en stilspringningsklasse (stilspringning = 1) dækker 'dressage_judge'
     * tilsvarende reelt stildommeren og gemmes som 'style_judge' - uden for
     * stilspringning er en dressage_judge-rolle mere tvetydig og rettes IKKE
     * automatisk. 'course_designer' er en generisk rolle på tværs af
     * discipliner (bruges også til eventing/dressur) - på en springklasse
     * dækker den reelt banebyggeren og gemmes som 'show_jumping_course_designer'.
     * Den oprindelige CSV-rolle bevares uændret i orig_rolle.
     */
    private function normalizeRolle(string $disciplin, string $rolle, bool $isStilspringning): string
    {
        if ($disciplin === 'show_jumping' && in_array($rolle, ['judge', 'chief_judge'], true)) {
            return 'show_jumping_judge';
        }
        if ($disciplin === 'show_jumping' && $isStilspringning && $rolle === 'dressage_judge') {
            return 'style_judge';
        }
        if ($disciplin === 'show_jumping' && $rolle === 'course_designer') {
            return 'show_jumping_course_designer';
        }
        return $rolle;
    }

    /** "S2", "S3", "S4" eller "S5" i klassenavnet markerer en stilspringningsklasse. */
    private function isStilspringning(string $klassenavn): bool
    {
        return (bool)preg_match('/S[2-5]/', $klassenavn);
    }

    /**
     * Genberegn hvert stævnes samlede niveau ud fra dets klasser:
     *  - top_rank/top_slug/top_code = højeste klasseniveau
     *  - has_lower = 1 hvis mindst én klasse er på et lavere niveau
     *  - disciplin = hyppigste disciplin blandt klasserne
     *
     * Kaldes altid til sidst i import(), men er offentlig saa den ogsaa kan
     * køres separat efter en manuel oprydning i classes (fx
     * sql/find_and_delete_score_summary_classes.sql), se
     * cli/recompute_show_levels.php.
     */
    public function recomputeShowLevels(): void
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
