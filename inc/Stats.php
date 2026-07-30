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
     * @param array<int,string>|string $year      Ét årstal eller flere (OR).
     * @param array<int,string>|string $disciplin Én disciplin eller flere (OR) - matches via
     *                                             rollekataloget (roles/role_disciplines), ikke
     *                                             klassens egen disciplin, da nogle roller (fx
     *                                             "technical_delegate") gælder alle discipliner.
     * @param array<int,string>|string $distrikt  Ét distrikt eller flere (OR).
     * @param array<int,string>|string $type      Én DRF-type eller flere (OR), fx "Dressurdommere - B".
     * @param array<int,string>|string $status    Én officials-status eller flere (OR), fx "aktiv".
     *                                             Tom = ingen statusfiltrering (viser alle).
     */
    public function officialsOverview(string $search = '', string $sort = 'staevner', $year = '', $disciplin = '', string $rolle = '', $distrikt = '', $type = '', $status = ''): array
    {
        $years      = array_values(array_filter((array)$year, fn($v) => $v !== ''));
        $disciplines = array_values(array_filter((array)$disciplin, fn($v) => $v !== ''));
        $distrikter = array_values(array_filter((array)$distrikt, fn($v) => $v !== ''));
        $typer      = array_values(array_filter((array)$type, fn($v) => $v !== ''));
        $statuses   = array_values(array_filter((array)$status, fn($v) => $v !== ''));

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

        // Disciplin filtreres via rollekataloget (a.rolle -> roles -> role_disciplines),
        // rolle filtreres pr. tildeling. Begge dele deles af alle tre deltal-forespoergsler
        // saa tallene stemmer overens.
        $filterConds = [];
        $filterParams = [];
        if ($disciplines) {
            $placeholders = implode(',', array_fill(0, count($disciplines), '?'));
            $filterConds[] = "EXISTS (
                SELECT 1 FROM roles r
                WHERE r.navn = a.rolle
                  AND (r.alle_discipliner = 1 OR EXISTS (
                      SELECT 1 FROM role_disciplines rd
                      WHERE rd.role_id = r.id AND rd.disciplin IN ($placeholders)
                  ))
            )";
            array_push($filterParams, ...$disciplines);
        }
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
        $countsJoinType = ($years || $disciplines || $rolle !== '') ? 'JOIN' : 'LEFT JOIN';

        // Distrikt(er) pr. official fra DRF-listen (en person kan optraede i flere distrikter).
        $districtsSql = "
            SELECT official_id, GROUP_CONCAT(DISTINCT distrikt ORDER BY distrikt SEPARATOR '/') AS distrikter
            FROM drf_officials
            WHERE official_id IS NOT NULL AND distrikt IS NOT NULL AND distrikt <> ''
            GROUP BY official_id
        ";

        // Type(r) pr. official fra DRF-listen (fx "Dressurdommere - B"). En person kan
        // optræde flere gange (flere typer/distrikter), derfor distinkt og adskilt med "/".
        $typesSql = "
            SELECT official_id, GROUP_CONCAT(DISTINCT type ORDER BY type SEPARATOR ' / ') AS typer
            FROM drf_officials
            WHERE official_id IS NOT NULL AND type IS NOT NULL AND type <> ''
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
        if ($typer) {
            $placeholders = implode(',', array_fill(0, count($typer), '?'));
            $whereConds[] = "o.id IN (SELECT official_id FROM drf_officials WHERE type IN ($placeholders))";
            array_push($searchParams, ...$typer);
        }
        if ($statuses) {
            $placeholders = implode(',', array_fill(0, count($statuses), '?'));
            $whereConds[] = "o.status IN ($placeholders)";
            array_push($searchParams, ...$statuses);
        }
        $where = $whereConds ? ('WHERE ' . implode(' AND ', $whereConds)) : '';

        $sql = "
            SELECT o.id, o.navn, o.status, o.drf_listed,
                   COALESCE(cnt.antal_staevner, 0) AS antal_staevner,
                   COALESCE(cnt.antal_klasser, 0)  AS antal_klasser,
                   COALESCE(cnt.antal_roller, 0)   AS antal_roller,
                   COALESCE(rid.antal_ryttere, 0)  AS antal_ryttere,
                   lvl.niveauer,
                   dst.distrikter,
                   typ.typer
            FROM officials o
            $countsJoinType ($countsSql) cnt ON cnt.official_id = o.id
            LEFT JOIN ($ridersSql) rid ON rid.official_id = o.id
            LEFT JOIN ($levelsSql) lvl ON lvl.official_id = o.id
            LEFT JOIN ($districtsSql) dst ON dst.official_id = o.id
            LEFT JOIN ($typesSql) typ ON typ.official_id = o.id
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

    /**
     * Roller fra rollekataloget der er relevante for en given disciplin (til
     * rolle-editoren paa en klasse) - dem der gaelder alle discipliner, plus
     * dem der er tilknyttet netop denne disciplin i role_disciplines. Uden
     * disciplin (ukendt/ikke sat) vises hele kataloget.
     */
    public function rolesForDiscipline(?string $disciplin): array
    {
        $disciplin = trim((string)$disciplin);
        if ($disciplin === '') {
            return $this->db->all('SELECT navn FROM roles ORDER BY navn');
        }
        return $this->db->all(
            'SELECT DISTINCT r.navn
             FROM roles r
             LEFT JOIN role_disciplines rd ON rd.role_id = r.id
             WHERE r.alle_discipliner = 1 OR rd.disciplin = ?
             ORDER BY r.navn',
            [$disciplin]
        );
    }

    /** Discipliner der reelt bruges i klasser (til rollekatalog og officials-filter). */
    public function roleDisciplineOptions(): array
    {
        $real = $this->db->all(
            "SELECT DISTINCT disciplin FROM classes
             WHERE disciplin IS NOT NULL AND disciplin <> '' AND disciplin <> 'score_summary'
             ORDER BY disciplin"
        );
        // "organizer" er ikke en rigtig stævne-/klassedisciplin, men en syntetisk
        // kategori til roller der handler om at arrangere/lede stævnet (fx
        // competition_president) frem for en bestemt sportsgren. Tilføjes altid,
        // saa den kan vaelges i rollekataloget selvom ingen klasse nogensinde
        // faar denne "disciplin".
        $real[] = ['disciplin' => 'organizer'];
        return $real;
    }

    /** Rollekataloget: hver rolle med tilknyttede discipliner og brugsantal (til Roller-siden). */
    public function rolesCatalog(): array
    {
        return $this->db->all(
            "SELECT r.id, r.navn, r.alle_discipliner,
                    GROUP_CONCAT(DISTINCT rd.disciplin ORDER BY rd.disciplin SEPARATOR ', ') AS discipliner,
                    (SELECT COUNT(*) FROM assignments a WHERE a.rolle = r.navn) AS antal_tildelinger
             FROM roles r
             LEFT JOIN role_disciplines rd ON rd.role_id = r.id
             GROUP BY r.id, r.navn, r.alle_discipliner
             ORDER BY r.navn"
        );
    }

    /** Rollenavne der bruges i tildelinger, men endnu ikke er i rollekataloget. */
    public function uncatalogedRoles(): array
    {
        return $this->db->all(
            "SELECT a.rolle, COUNT(*) AS antal
             FROM assignments a
             LEFT JOIN roles r ON r.navn = a.rolle
             WHERE r.id IS NULL
             GROUP BY a.rolle
             ORDER BY antal DESC"
        );
    }

    /**
     * Evaluerer ét springdommer-niveaus (C/B/A) totalkrav ("X stævner pr. år
     * ... over en periode på 2 år") for et konkret 2-års-vindue [$y1, $y2].
     * Kravtallene (X*2) er de oprindelige, uændrede tal for et 2-års-vindue.
     *
     * @return array{pass: bool, text: string}
     */
    private function evalSpringdommerVindue(string $level, callable $countIn, callable $totalIn, int $y1, int $y2): array
    {
        if ($level === 'C') {
            $tot = $totalIn();
            $nC  = $countIn(['C']);
            return ['pass' => $tot >= 16 && $nC >= 8, 'text' => "$tot stævner i alt {$y1}–{$y2} (krav ≥16), heraf $nC C-stævner (krav ≥8)"];
        }
        if ($level === 'B') {
            $tot  = $totalIn();
            $nC1  = $countIn(['C'], $y1);
            $nC2  = $countIn(['C'], $y2);
            $nB   = $countIn(['B']);
            return ['pass' => $tot >= 18 && $nC1 >= 3 && $nC2 >= 3 && $nB >= 3, 'text' => "$tot stævner i alt {$y1}–{$y2} (krav ≥18), C-stævner: $nC1 ($y1) / $nC2 ($y2) (krav ≥3 pr. år, begge år), $nB B-stævner i alt (krav ≥3)"];
        }
        // A
        $tot  = $totalIn();
        $nTop = $countIn(['B', 'A', 'FEI']);
        return ['pass' => $tot >= 16 && $nTop >= 8, 'text' => "$tot stævner i alt {$y1}–{$y2} (krav ≥16), heraf $nTop B/A/FEI-stævner (krav ≥8)"];
    }

    /**
     * Springdommeres (niveau D/C/B/A) aktivitetskrav for at opretholde niveauet,
     * vurderet ud fra dømmerollen 'show_jumping_judge' og stævnets samlede
     * (højeste) niveau.
     *
     * OBS - fortolkning af kravteksten (aftalt eksplicit, da originalteksten er
     * flertydig):
     * - Springdommer C/B/A's totalkrav ("X stævner pr. år ... over en periode
     *   på 2 år") vurderes FØRST i vinduet [forrige år, indeværende år]. Er
     *   det IKKE opfyldt dér, sættes et opmærksomhedsflag (kravet kan stadig
     *   nås inden årets udgang), og der tjekkes desuden om kravet var opfyldt
     *   i de 2 hele foregående år [indeværende år - 2, forrige år] alene - er
     *   det tilfældet, vises et flueben for at niveauet senest var
     *   dokumenteret opretholdt. Uden dette ville stort set alle dommere vise
     *   "opfylder ikke" i starten af et nyt kalenderår, før sæsonen er i gang.
     * - Springdommer-B's C-stævne-krav er eksplicit årligt "(pr. år)" og skal
     *   derfor opfyldes i BEGGE år i det vindue der evalueres, individuelt.
     * - Springdommer-D's årlige krav (min. 4 D-stævner) anses for opfyldt hvis
     *   enten indeværende år eller forrige hele kalenderår opfylder det,
     *   så et endnu ikke afsluttet sæsonår ikke fejlagtigt tæller som brud.
     *   Den alternative vej ("assistent til C-stævner") indgår ikke, da kun
     *   rollen show_jumping_judge tælles med.
     *
     * Deltagelse i DRF's refleksionsdage indgår IKKE - ingen data i Equilive,
     * skal tjekkes manuelt.
     *
     * @return array{years: array<int,int>, rows: array<int,array>}
     */
    public function springdommerKravStatus(): array
    {
        $currentYear = (int)date('Y');
        $prevYear    = $currentYear - 1;
        $prevYear2   = $currentYear - 2;
        $years       = [$prevYear2, $prevYear, $currentYear];

        $typer = [
            'Springdommer - D' => 'D',
            'Springdommer - C' => 'C',
            'Springdommer - B' => 'B',
            'Springdommer - A' => 'A',
        ];
        $officials = $this->db->all(
            "SELECT DISTINCT o.id AS official_id, o.navn, o.status, d.type
             FROM drf_officials d
             JOIN officials o ON o.id = d.official_id
             WHERE d.type IN ('" . implode("','", array_keys($typer)) . "')
             ORDER BY o.navn"
        );
        if (!$officials) {
            return ['years' => $years, 'rows' => []];
        }

        $ids = array_values(array_unique(array_map(fn($o) => (int)$o['official_id'], $officials)));
        $idPh   = implode(',', array_fill(0, count($ids), '?'));
        $yearPh = implode(',', array_fill(0, count($years), '?'));
        $activityRows = $this->db->all(
            "SELECT a.official_id, s.aar, s.top_code, COUNT(DISTINCT s.id) AS n
             FROM assignments a
             JOIN classes c ON c.id = a.class_id
             JOIN shows s ON s.id = c.show_id
             WHERE a.rolle = 'show_jumping_judge' AND a.official_id IN ($idPh) AND s.aar IN ($yearPh)
             GROUP BY a.official_id, s.aar, s.top_code",
            array_merge($ids, $years)
        );

        // $activity[official_id][aar][top_code] = antal distinkte stævner.
        $activity = [];
        foreach ($activityRows as $r) {
            $activity[(int)$r['official_id']][(int)$r['aar']][$r['top_code']] = (int)$r['n'];
        }

        $rows = [];
        foreach ($officials as $o) {
            $oid    = (int)$o['official_id'];
            $level  = $typer[$o['type']];
            $byYear = $activity[$oid] ?? [];

            $makeCounters = function (array $windowYears) use ($byYear) {
                $countIn = function (array $codes, ?int $year = null) use ($byYear, $windowYears): int {
                    $sum = 0;
                    $yrs = $year !== null ? [$year] : $windowYears;
                    foreach ($yrs as $y) {
                        foreach ($codes as $c) {
                            $sum += $byYear[$y][$c] ?? 0;
                        }
                    }
                    return $sum;
                };
                $totalIn = fn(?int $year = null) => $countIn(['E', 'D', 'C', 'B', 'A', 'FEI'], $year);
                return [$countIn, $totalIn];
            };

            $krav             = '';
            $opfylder         = null;
            $opfyldtTidligere = null;

            if ($level === 'D') {
                [$countIn] = $makeCounters([$prevYear, $currentYear]);
                $nCur  = $countIn(['D'], $currentYear);
                $nPrev = $countIn(['D'], $prevYear);
                $opfylder = $nCur >= 4 || $nPrev >= 4;
                $krav = "$nCur D-stævner i $currentYear, $nPrev i $prevYear (krav: min. 4 i mindst ét af de to år; assistent-alternativet er ikke medregnet)";
            } else {
                // C/B/A: vurdér først [forrige år, indeværende år]. Fejler det,
                // saet et opmaerksomhedsflag og tjek om kravet var opfyldt i de
                // 2 hele foregaaende aar alene (uden indevaerende aar).
                [$countIn1, $totalIn1] = $makeCounters([$prevYear, $currentYear]);
                $eval1    = $this->evalSpringdommerVindue($level, $countIn1, $totalIn1, $prevYear, $currentYear);
                $opfylder = $eval1['pass'];
                $krav     = $eval1['text'];

                if (!$opfylder) {
                    [$countIn2, $totalIn2] = $makeCounters([$prevYear2, $prevYear]);
                    $eval2 = $this->evalSpringdommerVindue($level, $countIn2, $totalIn2, $prevYear2, $prevYear);
                    $opfyldtTidligere = $eval2['pass'];
                    $krav .= ' | Tidligere (fuldt ' . $prevYear2 . '–' . $prevYear . '): ' . $eval2['text'];
                }
            }

            $rows[] = [
                'official_id'       => $oid,
                'navn'              => $o['navn'],
                'status'            => $o['status'],
                'niveau'            => $level,
                'opfylder'          => $opfylder,
                'opfyldt_tidligere' => $opfyldtTidligere,
                'krav'              => $krav,
            ];
        }

        return ['years' => $years, 'rows' => $rows];
    }

    /** Én officials stamdata + nøgletal. */
    public function official(int $id): ?array
    {
        return $this->db->one('SELECT * FROM officials WHERE id = ?', [$id]);
    }

    /** Tidligere navne (aliaser) for én official, fx efter en fletning. */
    public function officialAliases(int $id): array
    {
        return $this->db->all(
            'SELECT navn, created_at FROM official_aliases WHERE official_id = ? ORDER BY created_at',
            [$id]
        );
    }

    /**
     * Fordeling af roller for én official (kan filtreres på år, OR).
     * @param array<int,string>|string $year Ét årstal eller flere (OR).
     */
    public function officialRoles(int $id, $year = ''): array
    {
        $years = array_values(array_filter((array)$year, fn($v) => $v !== ''));

        if (!$years) {
            return $this->db->all(
                'SELECT rolle, COUNT(*) AS antal
                 FROM assignments WHERE official_id = ?
                 GROUP BY rolle ORDER BY antal DESC, rolle',
                [$id]
            );
        }

        $placeholders = implode(',', array_fill(0, count($years), '?'));
        return $this->db->all(
            "SELECT a.rolle, COUNT(*) AS antal
             FROM assignments a
             JOIN classes c ON c.id = a.class_id
             JOIN shows   s ON s.id = c.show_id AND s.aar IN ($placeholders)
             WHERE a.official_id = ?
             GROUP BY a.rolle ORDER BY antal DESC, a.rolle",
            array_merge($years, [$id])
        );
    }

    /**
     * Stævner en official har virket ved, med roller og nøgletal (kan filtreres på år, OR).
     * @param array<int,string>|string $year Ét årstal eller flere (OR).
     */
    public function officialShows(int $id, $year = ''): array
    {
        $years = array_values(array_filter((array)$year, fn($v) => $v !== ''));

        $yearCond = '';
        $yearParams = [];
        if ($years) {
            $placeholders = implode(',', array_fill(0, count($years), '?'));
            $yearCond = "AND s.aar IN ($placeholders)";
            $yearParams = $years;
        }

        // Ryttere pr. stævne, dedupliceret pr. klasse (flere roller i samme klasse maa
        // ikke dobbelttaelle de startende ryttere). Beregnes som et selvstændigt,
        // ikke-korreleret underforespørgsel (kun filtreret på official_id) og joines ind
        // pr. show_id bagefter - en korreleret subquery i FROM (som viste sig her) er
        // ikke standard-SQL og fejler på MariaDB, selvom MySQL 8 accepterer den.
        return $this->db->all(
            "SELECT s.id, s.prop, s.dato, s.disciplin, s.top_code, s.has_lower,
                    cl.navn AS klub, cl.forkort,
                    COUNT(DISTINCT a.class_id) AS klasser,
                    GROUP_CONCAT(DISTINCT a.rolle ORDER BY a.rolle SEPARATOR ', ') AS roller,
                    COALESCE(rid.ryttere, 0) AS ryttere
             FROM assignments a
             JOIN classes c ON c.id = a.class_id
             JOIN shows   s ON s.id = c.show_id $yearCond
             LEFT JOIN clubs cl ON cl.id = s.club_id
             LEFT JOIN (
                 SELECT dc.show_id, COALESCE(SUM(dc.starter), 0) AS ryttere
                 FROM (
                     SELECT DISTINCT a2.class_id, c2.show_id, c2.starter
                     FROM assignments a2
                     JOIN classes c2 ON c2.id = a2.class_id
                     WHERE a2.official_id = ?
                 ) dc
                 GROUP BY dc.show_id
             ) rid ON rid.show_id = s.id
             WHERE a.official_id = ?
             GROUP BY s.id, s.prop, s.dato, s.disciplin, s.top_code, s.has_lower, cl.navn, cl.forkort, rid.ryttere
             ORDER BY s.dato DESC, s.prop",
            array_merge($yearParams, [$id], [$id])
        );
    }

    /**
     * Niveaufordeling (antal klasser pr. niveau) for én official (kan filtreres på år, OR).
     * @param array<int,string>|string $year Ét årstal eller flere (OR).
     */
    public function officialLevels(int $id, $year = ''): array
    {
        $years = array_values(array_filter((array)$year, fn($v) => $v !== ''));

        $yearJoin = '';
        $yearParams = [];
        if ($years) {
            $placeholders = implode(',', array_fill(0, count($years), '?'));
            $yearJoin = "JOIN shows s ON s.id = c.show_id AND s.aar IN ($placeholders)";
            $yearParams = $years;
        }

        return $this->db->all(
            "SELECT l.code, l.label, l.`rank`, COUNT(DISTINCT a.class_id) AS klasser
             FROM assignments a
             JOIN classes c ON c.id = a.class_id
             JOIN levels  l ON l.slug = c.niveau_slug
             $yearJoin
             WHERE a.official_id = ?
             GROUP BY l.code, l.label, l.`rank`
             ORDER BY l.`rank` DESC",
            array_merge($yearParams, [$id])
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
                    c.hest_pony, c.svaerhedsgrad,
                    l.code AS niveau_code, l.label AS niveau_label,
                    GROUP_CONCAT(DISTINCT CONCAT(o.navn, ' (', a.rolle, ')') ORDER BY o.navn SEPARATOR '; ') AS officials
             FROM classes c
             LEFT JOIN levels l ON l.slug = c.niveau_slug
             LEFT JOIN assignments a ON a.class_id = c.id
             LEFT JOIN officials o ON o.id = a.official_id
             WHERE c.show_id = ?
             GROUP BY c.id, c.klassenr, c.klassenavn, c.disciplin, c.starter, c.stilspringning,
                      c.hest_pony, c.svaerhedsgrad, l.code, l.label
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

    public function drfTyper(): array
    {
        return $this->db->all("SELECT DISTINCT type FROM drf_officials WHERE type IS NOT NULL AND type <> '' ORDER BY type");
    }

}
