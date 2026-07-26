<?php
defined('APP') or die('Direkte adgang ikke tilladt');

/**
 * Statistik- og opslags-queries. Al forretningslogik til visning
 * samles her, så siderne (index.php osv.) holdes tynde.
 */
class Stats
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /** Nøgletal til forsiden. */
    public function dashboard(): array
    {
        return [
            'shows'     => (int)$this->db->scalar('SELECT COUNT(*) FROM shows'),
            'classes'   => (int)$this->db->scalar('SELECT COUNT(*) FROM classes'),
            'officials' => (int)$this->db->scalar('SELECT COUNT(*) FROM officials'),
            'assign'    => (int)$this->db->scalar('SELECT COUNT(*) FROM assignments'),
            'clubs'     => (int)$this->db->scalar('SELECT COUNT(*) FROM clubs'),
            'ryttere'   => (int)$this->db->scalar('SELECT COALESCE(SUM(starter),0) FROM classes'),
            'last_import' => $this->db->one('SELECT * FROM imports ORDER BY id DESC LIMIT 1'),
        ];
    }

    /**
     * Oversigt over alle officials med nøgletal.
     * Ryttere tælles pr. DISTINKT klasse, så flere roller i samme klasse
     * ikke dobbelttæller de startende ryttere.
     */
    /**
     * @param array<int,string>|string $year     Ét årstal eller flere (OR).
     * @param array<int,string>|string $distrikt Ét distrikt eller flere (OR).
     */
    public function officialsOverview(string $search = '', string $sort = 'staevner', $year = '', string $disciplin = '', string $rolle = '', $distrikt = ''): array
    {
        $years      = array_values(array_filter((array)$year, fn($v) => $v !== ''));
        $distrikter = array_values(array_filter((array)$distrikt, fn($v) => $v !== ''));

        $order = [
            'navn'     => 'o.navn ASC',
            'staevner' => 'antal_staevner DESC, o.navn ASC',
            'klasser'  => 'antal_klasser DESC, o.navn ASC',
            'ryttere'  => 'antal_ryttere DESC, o.navn ASC',
        ][$sort] ?? 'antal_staevner DESC, o.navn ASC';

        // Aarsfilter joines ind i hver deltal-forespoergsel for sig, saa hver af dem kan
        // bruge sine egne indekser (assign_official/assign_class/classes_show/shows_aar)
        // i stedet for én stor fan-out-JOIN med en korreleret subquery pr. official.
        // Flere år vælges som OR via IN(...).
        $yearJoin = '';
        $yearParams = [];
        if ($years) {
            $placeholders = implode(',', array_fill(0, count($years), '?'));
            $yearJoin = "JOIN shows s ON s.id = c.show_id AND s.aar IN ($placeholders)";
            $yearParams = $years;
        }

        // Disciplin filtreres pr. klasse (klassens egen disciplin, ikke stævnets
        // "hyppigste"), rolle filtreres pr. tildeling. Begge dele deles af alle tre
        // deltal-forespoergsler saa tallene stemmer overens.
        $filterConds = [];
        $filterParams = [];
        if ($disciplin !== '') { $filterConds[] = 'c.disciplin = ?'; $filterParams[] = $disciplin; }
        if ($rolle !== '')     { $filterConds[] = 'a.rolle = ?';     $filterParams[] = $rolle; }
        $filterWhere = $filterConds ? ('WHERE ' . implode(' AND ', $filterConds)) : '';

        // Stævner/klasser/roller pr. official - ét pass over assignments+classes(+shows).
        $countsSql = "
            SELECT a.official_id,
                   COUNT(DISTINCT c.show_id)  AS antal_staevner,
                   COUNT(DISTINCT a.class_id) AS antal_klasser,
                   COUNT(*)                   AS antal_roller
            FROM assignments a
            JOIN classes c ON c.id = a.class_id
            $yearJoin
            $filterWhere
            GROUP BY a.official_id
        ";

        // Ryttere pr. official, dedupliceret pr. klasse (flere roller i samme klasse
        // maa ikke dobbelttaelle de startende ryttere).
        $ridersSql = "
            SELECT dc.official_id, COALESCE(SUM(dc.starter), 0) AS antal_ryttere
            FROM (
                SELECT DISTINCT a.official_id, a.class_id, c.starter
                FROM assignments a
                JOIN classes c ON c.id = a.class_id
                $yearJoin
                $filterWhere
            ) dc
            GROUP BY dc.official_id
        ";

        // Niveauer pr. official.
        $levelsSql = "
            SELECT a.official_id,
                   GROUP_CONCAT(DISTINCT l.code ORDER BY l.`rank` DESC SEPARATOR '/') AS niveauer
            FROM assignments a
            JOIN classes c ON c.id = a.class_id
            JOIN levels  l ON l.slug = c.niveau_slug
            $yearJoin
            $filterWhere
            GROUP BY a.official_id
        ";

        // Ved aars-, disciplin- eller rollefilter skal officials uden matchende
        // aktivitet udelades, saa cnt-joinet er INNER; ellers LEFT (0-taeller for alle).
        $countsJoinType = ($years || $disciplin !== '' || $rolle !== '') ? 'JOIN' : 'LEFT JOIN';

        // Distrikt(er) pr. official fra DRF-listen (en person kan optraede i flere distrikter).
        $districtsSql = "
            SELECT official_id, GROUP_CONCAT(DISTINCT distrikt ORDER BY distrikt SEPARATOR '/') AS distrikter
            FROM drf_officials
            WHERE official_id IS NOT NULL AND distrikt IS NOT NULL AND distrikt <> ''
            GROUP BY official_id
        ";

        $whereConds = [];
        $searchParams = [];
        if ($search !== '') {
            $whereConds[] = 'o.navn LIKE ?';
            $searchParams[] = '%' . $search . '%';
        }
        if ($distrikter) {
            $placeholders = implode(',', array_fill(0, count($distrikter), '?'));
            $whereConds[] = "o.id IN (SELECT official_id FROM drf_officials WHERE distrikt IN ($placeholders))";
            array_push($searchParams, ...$distrikter);
        }
        $where = $whereConds ? ('WHERE ' . implode(' AND ', $whereConds)) : '';

        $sql = "
            SELECT o.id, o.navn, o.drf_listed,
                   COALESCE(cnt.antal_staevner, 0) AS antal_staevner,
                   COALESCE(cnt.antal_klasser, 0)  AS antal_klasser,
                   COALESCE(cnt.antal_roller, 0)   AS antal_roller,
                   COALESCE(rid.antal_ryttere, 0)  AS antal_ryttere,
                   lvl.niveauer,
                   dst.distrikter
            FROM officials o
            $countsJoinType ($countsSql) cnt ON cnt.official_id = o.id
            LEFT JOIN ($ridersSql) rid ON rid.official_id = o.id
            LEFT JOIN ($levelsSql) lvl ON lvl.official_id = o.id
            LEFT JOIN ($districtsSql) dst ON dst.official_id = o.id
            $where
            ORDER BY $order
        ";

        $subParams = array_merge($yearParams, $filterParams);
        $params = array_merge($subParams, $subParams, $subParams, $searchParams);
        return $this->db->all($sql, $params);
    }

    /** Distinkte discipliner paa klasseniveau (til filter). */
    public function classDisciplines(): array
    {
        return $this->db->all("SELECT DISTINCT disciplin FROM classes WHERE disciplin IS NOT NULL AND disciplin <> '' ORDER BY disciplin");
    }

    /** Distinkte official-roller (til filter). */
    public function roles(): array
    {
        return $this->db->all("SELECT DISTINCT rolle FROM assignments WHERE rolle IS NOT NULL AND rolle <> '' ORDER BY rolle");
    }

    /** Én officials stamdata + nøgletal. */
    public function official(int $id): ?array
    {
        return $this->db->one('SELECT * FROM officials WHERE id = ?', [$id]);
    }

    /** Fordeling af roller for én official. */
    public function officialRoles(int $id): array
    {
        return $this->db->all(
            'SELECT rolle, COUNT(*) AS antal
             FROM assignments WHERE official_id = ?
             GROUP BY rolle ORDER BY antal DESC, rolle',
            [$id]
        );
    }

    /** Stævner en official har virket ved, med roller og nøgletal. */
    public function officialShows(int $id): array
    {
        return $this->db->all(
            "SELECT s.id, s.prop, s.dato, s.disciplin, s.top_code, s.has_lower,
                    cl.navn AS klub, cl.forkort,
                    COUNT(DISTINCT a.class_id) AS klasser,
                    GROUP_CONCAT(DISTINCT a.rolle ORDER BY a.rolle SEPARATOR ', ') AS roller,
                    (SELECT COALESCE(SUM(y.starter),0) FROM (
                         SELECT DISTINCT a2.class_id, c2.starter
                         FROM assignments a2
                         JOIN classes c2 ON c2.id = a2.class_id
                         WHERE a2.official_id = ? AND c2.show_id = s.id
                    ) y) AS ryttere
             FROM assignments a
             JOIN classes c ON c.id = a.class_id
             JOIN shows   s ON s.id = c.show_id
             LEFT JOIN clubs cl ON cl.id = s.club_id
             WHERE a.official_id = ?
             GROUP BY s.id, s.prop, s.dato, s.disciplin, s.top_code, s.has_lower, cl.navn, cl.forkort
             ORDER BY s.dato DESC, s.prop",
            [$id, $id]
        );
    }

    /** Niveaufordeling (antal klasser pr. niveau) for én official. */
    public function officialLevels(int $id): array
    {
        return $this->db->all(
            "SELECT l.code, l.label, l.`rank`, COUNT(DISTINCT a.class_id) AS klasser
             FROM assignments a
             JOIN classes c ON c.id = a.class_id
             JOIN levels  l ON l.slug = c.niveau_slug
             WHERE a.official_id = ?
             GROUP BY l.code, l.label, l.`rank`
             ORDER BY l.`rank` DESC",
            [$id]
        );
    }

    /** Oversigt over stævner. */
    public function showsOverview(string $search = '', string $disciplin = '', string $niveau = '', string $year = ''): array
    {
        $where = [];
        $params = [];
        if ($search !== '') {
            $where[] = '(s.prop LIKE ? OR cl.navn LIKE ? OR cl.forkort LIKE ?)';
            $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
        }
        if ($disciplin !== '') { $where[] = 's.disciplin = ?'; $params[] = $disciplin; }
        if ($niveau !== '')    { $where[] = 's.top_slug = ?';  $params[] = $niveau; }
        if ($year !== '')      { $where[] = 's.aar = ?';       $params[] = $year; }
        $w = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        return $this->db->all(
            "SELECT s.id, s.prop, s.dato, s.aar, s.disciplin, s.top_code, s.has_lower, s.prop_unknown,
                    cl.navn AS klub, cl.forkort,
                    (SELECT COUNT(*) FROM classes c WHERE c.show_id = s.id) AS klasser,
                    (SELECT COALESCE(SUM(starter),0) FROM classes c WHERE c.show_id = s.id) AS ryttere,
                    (SELECT COUNT(DISTINCT a.official_id)
                        FROM assignments a JOIN classes c ON c.id = a.class_id
                        WHERE c.show_id = s.id) AS officials
             FROM shows s
             LEFT JOIN clubs cl ON cl.id = s.club_id
             $w
             ORDER BY s.dato DESC, s.prop",
            $params
        );
    }

    public function show(int $id): ?array
    {
        return $this->db->one(
            'SELECT s.*, cl.navn AS klub, cl.forkort
             FROM shows s LEFT JOIN clubs cl ON cl.id = s.club_id
             WHERE s.id = ?',
            [$id]
        );
    }

    /** Klasser i et stævne med niveau, ryttere og officials. */
    public function showClasses(int $id): array
    {
        return $this->db->all(
            "SELECT c.id, c.klassenr, c.klassenavn, c.disciplin, c.starter, c.stilspringning,
                    l.code AS niveau_code, l.label AS niveau_label,
                    GROUP_CONCAT(DISTINCT CONCAT(o.navn, ' (', a.rolle, ')') ORDER BY o.navn SEPARATOR '; ') AS officials
             FROM classes c
             LEFT JOIN levels l ON l.slug = c.niveau_slug
             LEFT JOIN assignments a ON a.class_id = c.id
             LEFT JOIN officials o ON o.id = a.official_id
             WHERE c.show_id = ?
             GROUP BY c.id, c.klassenr, c.klassenavn, c.disciplin, c.starter, c.stilspringning, l.code, l.label
             ORDER BY c.klassenr + 0, c.klassenr",
            [$id]
        );
    }

    /**
     * Oversigt over klubber: distrikt og antal stævner (kan filtreres på år, OR).
     * @param array<int,string>|string $year Ét årstal eller flere (OR).
     */
    public function clubsOverview(string $search = '', $year = '', string $sort = 'staevner'): array
    {
        $years = array_values(array_filter((array)$year, fn($v) => $v !== ''));

        $order = [
            'navn'     => 'c.navn ASC',
            'staevner' => 'antal_staevner DESC, c.navn ASC',
        ][$sort] ?? 'antal_staevner DESC, c.navn ASC';

        $showsJoin = 'LEFT JOIN shows s ON s.club_id = c.id';
        $yearParams = [];
        if ($years) {
            // Med aarsfilter skal klubber uden staevne det/de aar udelades, saa joinet er INNER.
            $placeholders = implode(',', array_fill(0, count($years), '?'));
            $showsJoin = "JOIN shows s ON s.club_id = c.id AND s.aar IN ($placeholders)";
            $yearParams = $years;
        }

        $where = '';
        $searchParams = [];
        if ($search !== '') {
            $where = 'WHERE c.navn LIKE ?';
            $searchParams[] = '%' . $search . '%';
        }

        return $this->db->all(
            "SELECT c.id, c.navn, c.forkort, c.distrikt,
                    COUNT(DISTINCT s.id) AS antal_staevner
             FROM clubs c
             $showsJoin
             $where
             GROUP BY c.id, c.navn, c.forkort, c.distrikt
             ORDER BY $order",
            array_merge($yearParams, $searchParams)
        );
    }

    /**
     * Én klubs stamdata + antal stævner i alt (kan filtreres på år, OR).
     * @param array<int,string>|string $year Ét årstal eller flere (OR).
     */
    public function club(int $id, $year = ''): ?array
    {
        $years = array_values(array_filter((array)$year, fn($v) => $v !== ''));

        $yearWhere = '';
        $yearParams = [];
        if ($years) {
            $placeholders = implode(',', array_fill(0, count($years), '?'));
            $yearWhere = "AND s.aar IN ($placeholders)";
            $yearParams = $years;
        }

        return $this->db->one(
            "SELECT c.*,
                    (SELECT COUNT(*) FROM shows s WHERE s.club_id = c.id $yearWhere) AS antal_staevner
             FROM clubs c WHERE c.id = ?",
            array_merge($yearParams, [$id])
        );
    }

    /**
     * Officials brugt ved denne klubs stævner, med antal stævner/klasser/roller
     * (kan filtreres på år, OR).
     * @param array<int,string>|string $year Ét årstal eller flere (OR).
     */
    public function clubOfficials(int $id, $year = ''): array
    {
        $years = array_values(array_filter((array)$year, fn($v) => $v !== ''));

        $yearWhere = '';
        $yearParams = [];
        if ($years) {
            $placeholders = implode(',', array_fill(0, count($years), '?'));
            $yearWhere = "AND s.aar IN ($placeholders)";
            $yearParams = $years;
        }

        return $this->db->all(
            "SELECT o.id, o.navn,
                    COUNT(DISTINCT c.show_id)  AS antal_staevner,
                    COUNT(DISTINCT a.class_id) AS antal_klasser,
                    COUNT(*)                   AS antal_roller,
                    GROUP_CONCAT(DISTINCT a.rolle ORDER BY a.rolle SEPARATOR ', ') AS roller
             FROM assignments a
             JOIN classes c    ON c.id = a.class_id
             JOIN shows s      ON s.id = c.show_id
             JOIN officials o  ON o.id = a.official_id
             WHERE s.club_id = ? $yearWhere
             GROUP BY o.id, o.navn
             ORDER BY antal_staevner DESC, o.navn",
            array_merge([$id], $yearParams)
        );
    }

    /** Én klasses stamdata + stævne den hører til. */
    public function classInfo(int $id): ?array
    {
        return $this->db->one(
            "SELECT c.*, l.code AS niveau_code, l.label AS niveau_label,
                    s.id AS show_id, s.prop, s.dato
             FROM classes c
             LEFT JOIN levels l ON l.slug = c.niveau_slug
             JOIN shows s ON s.id = c.show_id
             WHERE c.id = ?",
            [$id]
        );
    }

    /** Alle tildelinger (official + rolle) for én klasse, til visning/redigering. */
    public function classAssignments(int $id): array
    {
        return $this->db->all(
            "SELECT a.id, a.official_id, o.navn, a.rolle, a.nummer
             FROM assignments a
             JOIN officials o ON o.id = a.official_id
             WHERE a.class_id = ?
             ORDER BY o.navn",
            [$id]
        );
    }

    /** Distinkte aar (til filter), nyeste foerst. */
    public function years(): array
    {
        $rows = $this->db->all('SELECT DISTINCT aar FROM shows WHERE aar IS NOT NULL ORDER BY aar DESC');
        return array_map(fn($r) => (int)$r['aar'], $rows);
    }

    /** Antal staevner pr. aar (til forsiden). */
    public function showsPerYear(): array
    {
        return $this->db->all(
            'SELECT aar, COUNT(*) AS staevner,
                    (SELECT COUNT(*) FROM classes c JOIN shows s2 ON s2.id = c.show_id WHERE s2.aar = s.aar) AS klasser
             FROM shows s
             WHERE aar IS NOT NULL
             GROUP BY aar ORDER BY aar DESC'
        );
    }

    /** Distinkte discipliner (til filter). */
    public function disciplines(): array
    {
        return $this->db->all("SELECT DISTINCT disciplin FROM shows WHERE disciplin IS NOT NULL AND disciplin <> '' ORDER BY disciplin");
    }

    public function imports(int $limit = 20): array
    {
        return $this->db->all('SELECT * FROM imports ORDER BY id DESC LIMIT ' . (int)$limit);
    }

    /**
     * Finder kandidatpar til fletning udelukkende ud fra navnelighed - til at
     * fange stavefejl (fx "Bjørnkjær" vs. "Bjørnkær") og usynlige tegn/mellemrum
     * der har givet samme person to officials-poster. Sorteret efter mest
     * sandsynlige match først. Den med flest stævner i parret foreslås som
     * "keep" (den der bevares), da det typisk er den korrekt stavede post.
     *
     * @return array<int, array{keep: array, merge: array, distance: int, reason: string}>
     */
    public function possibleDuplicates(int $maxDistance = 2): array
    {
        $officials = $this->db->all(
            "SELECT o.id, o.navn, COUNT(DISTINCT c.show_id) AS antal_staevner
             FROM officials o
             LEFT JOIN assignments a ON a.official_id = o.id
             LEFT JOIN classes c ON c.id = a.class_id
             GROUP BY o.id, o.navn
             ORDER BY o.navn"
        );
        $n = count($officials);
        $pairs = [];

        for ($i = 0; $i < $n; $i++) {
            $a = $officials[$i];
            $normA = self::normalizeForCompare($a['navn']);

            for ($j = $i + 1; $j < $n; $j++) {
                $b = $officials[$j];

                $dist = null;
                $reason = null;
                $normB = self::normalizeForCompare($b['navn']);

                if ($normA === $normB) {
                    $dist = 0;
                    $reason = 'Identisk efter normalisering (mellemrum/store-/små bogstaver)';
                } else {
                    // Redigeringsafstanden kan aldrig blive mindre end laengdeforskellen -
                    // spring straks videre hvis den alene overstiger graensen.
                    if (abs(mb_strlen($normA) - mb_strlen($normB)) > $maxDistance) {
                        continue;
                    }
                    // Kun sammenlign navne der starter med samme bogstav - holder antallet
                    // af sammenligninger nede og undgaar stoerstedelen af tilfaeldige traeff.
                    if (mb_substr($normA, 0, 1) !== mb_substr($normB, 0, 1)) {
                        continue;
                    }

                    $d = self::mbLevenshtein($normA, $normB);
                    if ($d > 0 && $d <= $maxDistance) {
                        $dist = $d;
                        $reason = "Ligner hinanden (redigeringsafstand $d)";
                    }
                }

                if ($dist === null) {
                    continue;
                }

                // Flest stævner foreslås som "behold" - typisk den korrekt stavede post.
                [$keep, $merge] = ((int)$a['antal_staevner'] >= (int)$b['antal_staevner']) ? [$a, $b] : [$b, $a];

                $pairs[] = ['keep' => $keep, 'merge' => $merge, 'distance' => $dist, 'reason' => $reason];
            }
        }

        usort($pairs, fn($x, $y) => $x['distance'] <=> $y['distance']);
        return $pairs;
    }

    /** Fjerner usynlige mellemrumstegn og normaliserer til smaa bogstaver, til navnesammenligning. */
    private static function normalizeForCompare(string $navn): string
    {
        $navn = preg_replace('/[\x{00A0}\x{2000}-\x{200B}\x{202F}\x{FEFF}]/u', ' ', $navn) ?? $navn;
        $navn = preg_replace('/\s+/u', ' ', $navn) ?? $navn;
        return mb_strtolower(trim($navn), 'UTF-8');
    }

    /** Multibyte-sikker Levenshtein-afstand (PHP's indbyggede levenshtein() regner paa bytes, ikke tegn). */
    private static function mbLevenshtein(string $a, string $b): int
    {
        $ca = preg_split('//u', $a, -1, PREG_SPLIT_NO_EMPTY);
        $cb = preg_split('//u', $b, -1, PREG_SPLIT_NO_EMPTY);
        $la = count($ca);
        $lb = count($cb);
        $prev = range(0, $lb);

        for ($i = 1; $i <= $la; $i++) {
            $cur = [$i];
            for ($j = 1; $j <= $lb; $j++) {
                $cost = $ca[$i - 1] === $cb[$j - 1] ? 0 : 1;
                $cur[$j] = min($prev[$j] + 1, $cur[$j - 1] + 1, $prev[$j - 1] + $cost);
            }
            $prev = $cur;
        }
        return $prev[$lb];
    }

    // ---------------- DRF officials-liste ----------------

    public function drfSummary(): array
    {
        return [
            'rows'       => (int)$this->db->scalar('SELECT COUNT(*) FROM drf_officials'),
            'persons'    => (int)$this->db->scalar('SELECT COUNT(DISTINCT navn_norm) FROM drf_officials'),
            'matched'    => (int)$this->db->scalar('SELECT COUNT(*) FROM officials WHERE drf_listed = 1'),
            'not_listed' => (int)$this->db->scalar('SELECT COUNT(*) FROM officials WHERE drf_listed = 0'),
            'drf_unmatched' => (int)$this->db->scalar('SELECT COUNT(DISTINCT navn_norm) FROM drf_officials WHERE official_id IS NULL'),
            'harvested_at'  => $this->db->scalar('SELECT MAX(harvested_at) FROM drf_officials'),
        ];
    }

    /** DRF-roller (kategori/type/distrikt) for én official. */
    public function officialDrfRoles(int $id): array
    {
        return $this->db->all(
            'SELECT kategori, type, distrikt FROM drf_officials
             WHERE official_id = ? ORDER BY kategori, type, distrikt',
            [$id]
        );
    }

    /** DRF-personer der IKKE kunne matches til en official (navneforskelle mm.). */
    public function drfUnmatched(): array
    {
        return $this->db->all(
            "SELECT navn, postnr, postdistrikt,
                    GROUP_CONCAT(DISTINCT type ORDER BY type SEPARATOR ', ')     AS typer,
                    GROUP_CONCAT(DISTINCT distrikt ORDER BY distrikt SEPARATOR ', ') AS distrikter
             FROM drf_officials
             WHERE official_id IS NULL
             GROUP BY navn_norm, navn, postnr, postdistrikt
             ORDER BY navn"
        );
    }

    /** Officials fra stævnedata der IKKE er på DRF-listen (kandidater til datakvalitet). */
    public function officialsNotInDrf(): array
    {
        return $this->db->all(
            "SELECT o.id, o.navn,
                    COUNT(DISTINCT c.show_id) AS staevner,
                    COUNT(a.id)               AS roller
             FROM officials o
             JOIN assignments a ON a.official_id = o.id
             JOIN classes c     ON c.id = a.class_id
             WHERE o.drf_listed = 0
             GROUP BY o.id, o.navn
             ORDER BY staevner DESC, o.navn"
        );
    }

    /** Gennemse den høstede DRF-liste med filtre. */
    public function drfList(string $search = '', string $kategori = '', string $distrikt = ''): array
    {
        $where = []; $params = [];
        if ($search !== '')   { $where[] = 'navn LIKE ?';     $params[] = "%$search%"; }
        if ($kategori !== '') { $where[] = 'kategori = ?';    $params[] = $kategori; }
        if ($distrikt !== '') { $where[] = 'distrikt = ?';    $params[] = $distrikt; }
        $w = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        return $this->db->all(
            "SELECT navn, kategori, type, distrikt, postnr, postdistrikt, official_id
             FROM drf_officials $w
             ORDER BY navn, type",
            $params
        );
    }

    public function drfKategorier(): array
    {
        return $this->db->all("SELECT DISTINCT kategori FROM drf_officials WHERE kategori IS NOT NULL AND kategori <> '' ORDER BY kategori");
    }

    public function drfDistrikter(): array
    {
        return $this->db->all("SELECT DISTINCT distrikt FROM drf_officials WHERE distrikt IS NOT NULL AND distrikt <> '' ORDER BY distrikt");
    }

}
