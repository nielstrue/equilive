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

    /**
     * Nøgletal til forsiden. Stævner med status = 'udelukket' (se
     * showsOverview()/show.php) tælles ikke med - hverken selve stævnet,
     * dets klasser, tildelinger eller ryttere.
     */
    public function dashboard(): array
    {
        return [
            'shows'     => (int)$this->db->scalar("SELECT COUNT(*) FROM shows WHERE status = 'aktiv'"),
            'classes'   => (int)$this->db->scalar(
                "SELECT COUNT(*) FROM classes c JOIN shows s ON s.id = c.show_id WHERE s.status = 'aktiv'"
            ),
            'officials' => (int)$this->db->scalar('SELECT COUNT(*) FROM officials'),
            'assign'    => (int)$this->db->scalar(
                "SELECT COUNT(*) FROM assignments a
                 JOIN classes c ON c.id = a.class_id
                 JOIN shows   s ON s.id = c.show_id
                 WHERE s.status = 'aktiv'"
            ),
            'clubs'     => (int)$this->db->scalar('SELECT COUNT(*) FROM clubs'),
            'clubs_with_shows' => (int)$this->db->scalar(
                'SELECT COUNT(*) FROM clubs c WHERE EXISTS (SELECT 1 FROM shows s WHERE s.club_id = c.id)'
            ),
            'clubs_without_shows' => (int)$this->db->scalar(
                'SELECT COUNT(*) FROM clubs c WHERE NOT EXISTS (SELECT 1 FROM shows s WHERE s.club_id = c.id)'
            ),
            'ryttere'   => (int)$this->db->scalar(
                "SELECT COALESCE(SUM(c.starter),0) FROM classes c
                 JOIN shows s ON s.id = c.show_id WHERE s.status = 'aktiv'"
            ),
            'period_start' => $this->db->scalar("SELECT MIN(dato) FROM shows WHERE status = 'aktiv'"),
            'period_end'   => $this->db->scalar("SELECT MAX(dato) FROM shows WHERE status = 'aktiv'"),
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
    public function officialsOverview(string $search = '', string $sort = 'navn', $year = '', $disciplin = '', string $rolle = '', $distrikt = '', $type = '', $status = ''): array
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
            // Officials uden distrikt (NULL) sorteres til sidst i stedet for foerst.
            'distrikt' => '(dst.distrikter IS NULL) ASC, dst.distrikter ASC, o.navn ASC',
        ][$sort] ?? 'o.navn ASC';

        // Aarsfilter joines ind i hver deltal-forespoergsel for sig, saa hver af dem kan
        // bruge sine egne indekser (assign_official/assign_class/classes_show/shows_aar)
        // i stedet for én stor fan-out-JOIN med en korreleret subquery pr. official.
        // Flere år vælges som OR via IN(...). shows joines altid ind (uanset aarsfilter)
        // for at udelukke stævner med status = 'udelukket' fra alle nøgletal.
        $yearJoin = "JOIN shows s ON s.id = c.show_id AND s.status = 'aktiv'";
        $yearParams = [];
        if ($years) {
            $placeholders = implode(',', array_fill(0, count($years), '?'));
            $yearJoin .= " AND s.aar IN ($placeholders)";
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

    /**
     * Aktive officials der IKKE har haft en tildeling (evt. en bestemt rolle)
     * i de valgte år - til at følge officials med lav eller ingen aktivitet.
     * Populationen er DRF-listen (drf_officials, matchet til officials), da
     * den repræsenterer alle registrerede officials - ikke kun dem der har
     * haft en tildeling i stævnedata (officialsOverview() med et årsfilter
     * skifter til INNER JOIN og udelader netop dem, hvilket er det modsatte
     * af hvad denne bruges til).
     *
     * @param string $type   Ét DRF-type, fx "Springning - Banedesigner - D". Tom = alle typer.
     * @param string $rolle  Én rolle, fx "show_jumping_judge". Tom = enhver tildeling tæller.
     * @param array<int,string>|string $year Ét årstal eller flere (OR). Tom = alle år.
     */
    public function activeOfficialsWithoutRole(string $type = '', string $rolle = '', $year = ''): array
    {
        $years = array_values(array_filter((array)$year, fn($v) => $v !== ''));

        $typeCond = '';
        $typeParams = [];
        if ($type !== '') {
            $typeCond = 'AND d.type = ?';
            $typeParams[] = $type;
        }

        // Distinkte aktive officials paa DRF-listen (evt. filtreret på type).
        $officials = $this->db->all(
            "SELECT o.id, o.navn, o.status,
                    GROUP_CONCAT(DISTINCT d.type ORDER BY d.type SEPARATOR ', ') AS typer
             FROM drf_officials d
             JOIN officials o ON o.id = d.official_id
             WHERE o.status = 'aktiv' $typeCond
             GROUP BY o.id, o.navn, o.status
             ORDER BY o.navn",
            $typeParams
        );
        if (!$officials) {
            return [];
        }

        $ids = array_column($officials, 'id');
        $idPh = implode(',', array_fill(0, count($ids), '?'));

        $roleCond = '';
        $roleParams = [];
        if ($rolle !== '') {
            $roleCond = 'AND a.rolle = ?';
            $roleParams[] = $rolle;
        }
        $yearCond = '';
        $yearParams = [];
        if ($years) {
            $placeholders = implode(',', array_fill(0, count($years), '?'));
            $yearCond = "AND s.aar IN ($placeholders)";
            $yearParams = $years;
        }

        // Officials der HAR mindst én tildeling der matcher rolle/år-filteret - resten mangler den.
        $withActivity = $this->db->all(
            "SELECT DISTINCT a.official_id
             FROM assignments a
             JOIN classes c ON c.id = a.class_id
             JOIN shows s   ON s.id = c.show_id AND s.status = 'aktiv' $yearCond
             WHERE a.official_id IN ($idPh) $roleCond",
            array_merge($yearParams, $ids, $roleParams)
        );
        $hasActivity = array_flip(array_map('intval', array_column($withActivity, 'official_id')));

        // Sidste opgave + antal opgaver i alt (uafhængigt af filteret), til kontekst.
        $lastActivityRows = $this->db->all(
            "SELECT a.official_id, MAX(s.dato) AS sidste_opgave, COUNT(*) AS antal_opgaver
             FROM assignments a
             JOIN classes c ON c.id = a.class_id
             JOIN shows s   ON s.id = c.show_id AND s.status = 'aktiv'
             WHERE a.official_id IN ($idPh)
             GROUP BY a.official_id",
            $ids
        );
        $lastByOfficial = [];
        foreach ($lastActivityRows as $r) {
            $lastByOfficial[(int)$r['official_id']] = $r;
        }

        $rows = [];
        foreach ($officials as $o) {
            if (isset($hasActivity[(int)$o['id']])) {
                continue;
            }
            $last = $lastByOfficial[(int)$o['id']] ?? null;
            $rows[] = [
                'id'            => $o['id'],
                'navn'          => $o['navn'],
                'status'        => $o['status'],
                'typer'         => $o['typer'],
                'sidste_opgave' => $last['sidste_opgave'] ?? null,
                'antal_opgaver' => $last ? (int)$last['antal_opgaver'] : 0,
            ];
        }
        return $rows;
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
     * vurderet ud fra dømmerollen 'show_jumping_judge' og stævnets HØJESTE
     * springklasse-niveau (ikke stævnets samlede/blandede niveau - et blandet
     * stævne med både dressur- og springklasser tæller altså korrekt med som
     * et springstævne på sit eget niveau, se show.php#579 for et eksempel).
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
        // Et stævnes niveau her er det HØJESTE springklasse-niveau i stævnet
        // (c.disciplin = 'show_jumping') - ikke stævnets samlede/blandede
        // niveau (s.top_code) - saa et blandet stævne (fx dressur + spring,
        // se show.php#579) bedømmes ud fra sin egen springklasse og tæller
        // korrekt med, selvom dressurklasserne skulle have et andet niveau.
        // Én raekke pr. (official, stævne); niveauet slaas op i PHP bagefter.
        $activityRows = $this->db->all(
            "SELECT a.official_id, s.id AS show_id, s.aar, MAX(l.`rank`) AS lvl_rank
             FROM assignments a
             JOIN classes c ON c.id = a.class_id AND c.disciplin = 'show_jumping'
             JOIN shows s ON s.id = c.show_id AND s.status = 'aktiv'
             JOIN levels l ON l.slug = c.niveau_slug
             WHERE a.rolle = 'show_jumping_judge' AND a.official_id IN ($idPh) AND s.aar IN ($yearPh)
             GROUP BY a.official_id, s.id, s.aar",
            array_merge($ids, $years)
        );

        // $activity[official_id][aar][code] = antal distinkte stævner.
        $activity = [];
        foreach ($activityRows as $r) {
            $code = Levels::codeForRank((int)$r['lvl_rank']);
            $oid  = (int)$r['official_id'];
            $yr   = (int)$r['aar'];
            $activity[$oid][$yr][$code] = ($activity[$oid][$yr][$code] ?? 0) + 1;
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

    /**
     * Banedesigneres (niveau D/C/B/A, springning) aktivitetskrav for at
     * opretholde niveauet.
     *
     * OBS - fortolkning og datamæssige forudsætninger (aftalt eksplicit):
     * - Rollen 'course_designer' er efter datavasken (Importer::normalizeRolle
     *   / CourseDesignerRoleMigrator) omdøbt til 'show_jumping_course_designer'
     *   for springklasser - det er den rolle der bruges her som "ansvarlig
     *   banedesigner", IKKE 'course_designer' (som nu kun forekommer på andre
     *   discipliner).
     * - Et stævnes niveau er den højeste springklasse i stævnet (samme
     *   tilgang som springdommerKravStatus), ikke stævnets samlede/blandede
     *   niveau.
     * - For C/B/A skal BÅDE "ansvarlig"-tallet OG assist-betingelsen være
     *   opfyldt (AND, ikke to alternative veje).
     * - "Stævnedag" i C-niveauets assist-krav tælles som "distinkt stævne"
     *   (datamodellen har kun én dato pr. stævne).
     * - Sværhedsgrad (classes.svaerhedsgrad) er kun udfyldt for et fåtal
     *   klasser i praksis - assist-kravene for C/B er implementeret efter
     *   kravteksten uden lempelse, hvilket betyder de i praksis sjældent kan
     *   opfyldes med den nuværende datadækning. Det er en bevidst afvejning.
     * - Rullende 3-års-vindue, samme "opmærksom/opfyldt tidligere"-mekanik
     *   som springdommerKravStatus, blot skaleret til 3 år.
     * - En banedesigners kvalifikationstype (drf_officials.type) er et
     *   øjebliksbillede fra sidste DRF-høstning, ikke en historik - der
     *   tjekkes derfor om den ansvarlige designer PT. har typen A/B, ikke om
     *   vedkommende havde den på assist-tidspunktet.
     * - D-niveau (og "D (bygger kun i egen klub)") har intet beskrevet
     *   aktivitetskrav og vises uden opfylder-vurdering.
     *
     * @return array{years: array<int,int>, rows: array<int,array>}
     */
    public function banedesignerKravStatus(): array
    {
        $currentYear = (int)date('Y');
        $windowNow  = [$currentYear - 2, $currentYear - 1, $currentYear];
        $windowPrev = [$currentYear - 3, $currentYear - 2, $currentYear - 1];
        $allYears   = array_values(array_unique(array_merge($windowPrev, $windowNow)));
        sort($allYears);

        $typer = [
            'Springning - Banedesigner - D' => 'D',
            'Springning - Banedesigner - D (bygger kun i egen klub)' => 'D',
            'Springning - Banedesigner - C' => 'C',
            'Springning - Banedesigner - B' => 'B',
            'Springning - Banedesigner - A' => 'A',
        ];
        $officials = $this->db->all(
            "SELECT DISTINCT o.id AS official_id, o.navn, o.status, d.type
             FROM drf_officials d
             JOIN officials o ON o.id = d.official_id
             WHERE d.type IN ('" . implode("','", array_keys($typer)) . "')
             ORDER BY o.navn"
        );
        if (!$officials) {
            return ['years' => $allYears, 'rows' => []];
        }

        $ids    = array_values(array_unique(array_map(fn($o) => (int)$o['official_id'], $officials)));
        $idPh   = implode(',', array_fill(0, count($ids), '?'));
        $yearPh = implode(',', array_fill(0, count($allYears), '?'));

        // Ansvarlig banedesigner-aktivitet (rolle = show_jumping_course_designer).
        $designerRows = $this->db->all(
            "SELECT a.official_id, s.id AS show_id, s.aar, MAX(l.`rank`) AS lvl_rank
             FROM assignments a
             JOIN classes c ON c.id = a.class_id AND c.disciplin = 'show_jumping'
             JOIN shows s   ON s.id = c.show_id AND s.status = 'aktiv'
             JOIN levels l  ON l.slug = c.niveau_slug
             WHERE a.rolle = 'show_jumping_course_designer' AND a.official_id IN ($idPh) AND s.aar IN ($yearPh)
             GROUP BY a.official_id, s.id, s.aar",
            array_merge($ids, $allYears)
        );
        // designerActivity[official_id][aar][] = niveaukode, én pr. stævne.
        $designerActivity = [];
        foreach ($designerRows as $r) {
            $designerActivity[(int)$r['official_id']][(int)$r['aar']][] = Levels::codeForRank((int)$r['lvl_rank']);
        }

        // Assist-aktivitet (rolle = course_designer_assistant), beriget med den
        // ansvarlige designers DRF-type(r) på samme klasse og stævnets springniveau.
        // Datamængden er lille (svaerhedsgrad er sjældent udfyldt), saa et opslag
        // pr. raekke er uden praktisk performance-betydning.
        $assistRows = $this->db->all(
            "SELECT a.official_id AS assistant_id, a.class_id, c.svaerhedsgrad, s.id AS show_id, s.aar
             FROM assignments a
             JOIN classes c ON c.id = a.class_id AND c.disciplin = 'show_jumping'
             JOIN shows s   ON s.id = c.show_id AND s.status = 'aktiv'
             WHERE a.rolle = 'course_designer_assistant' AND a.official_id IN ($idPh) AND s.aar IN ($yearPh)",
            array_merge($ids, $allYears)
        );
        $assistActivity = [];
        $showCodeCache = [];
        foreach ($assistRows as $r) {
            $showId = (int)$r['show_id'];
            if (!array_key_exists($showId, $showCodeCache)) {
                $lvl = $this->db->scalar(
                    "SELECT MAX(l.`rank`) FROM classes c JOIN levels l ON l.slug = c.niveau_slug
                     WHERE c.show_id = ? AND c.disciplin = 'show_jumping'",
                    [$showId]
                );
                $showCodeCache[$showId] = ($lvl !== false && $lvl !== null) ? Levels::codeForRank((int)$lvl) : null;
            }

            $designerTypes = [];
            foreach ($this->db->all(
                "SELECT DISTINCT official_id FROM assignments WHERE class_id = ? AND rolle = 'show_jumping_course_designer'",
                [$r['class_id']]
            ) as $d) {
                $designerTypes[(int)$d['official_id']] = array_column(
                    $this->db->all('SELECT DISTINCT type FROM drf_officials WHERE official_id = ?', [$d['official_id']]),
                    'type'
                );
            }

            $assistActivity[(int)$r['assistant_id']][(int)$r['aar']][] = [
                'show_id'        => $showId,
                'svh'            => $r['svaerhedsgrad'] !== null ? (int)$r['svaerhedsgrad'] : null,
                'show_code'      => $showCodeCache[$showId],
                'designer_types' => $designerTypes, // [designer_official_id => [type, ...]]
            ];
        }

        // ---- Evalueringshjælpere (arbejder paa de foraf hentede aktivitetsdata). ----
        $designerShowCount = function (int $oid, array $years, array $codes) use ($designerActivity): int {
            $n = 0;
            foreach ($years as $y) {
                foreach (($designerActivity[$oid][$y] ?? []) as $code) {
                    if (in_array($code, $codes, true)) { $n++; }
                }
            }
            return $n;
        };
        $designerHasCodeInYears = function (int $oid, array $years, string $code) use ($designerActivity): bool {
            foreach ($years as $y) {
                if (in_array($code, $designerActivity[$oid][$y] ?? [], true)) { return true; }
            }
            return false;
        };
        // Assist-raekker for $oid/$years hvor mindst én ansvarlig designer paa klassen
        // har en af $designerTypesWanted, og (hvis $minSvh angivet) svh >= $minSvh.
        $assistMatches = function (int $oid, array $years, array $designerTypesWanted, ?int $minSvh) use ($assistActivity): array {
            $matches = [];
            foreach ($years as $y) {
                foreach (($assistActivity[$oid][$y] ?? []) as $row) {
                    if ($minSvh !== null && ($row['svh'] === null || $row['svh'] < $minSvh)) { continue; }
                    foreach ($row['designer_types'] as $designerId => $types) {
                        if (array_intersect($types, $designerTypesWanted)) {
                            $matches[] = ['show_id' => $row['show_id'], 'svh' => $row['svh'], 'show_code' => $row['show_code'], 'designer_id' => $designerId];
                        }
                    }
                }
            }
            return $matches;
        };
        $assistShowCount = function (int $oid, array $years, array $codes) use ($assistActivity): int {
            $shows = [];
            foreach ($years as $y) {
                foreach (($assistActivity[$oid][$y] ?? []) as $row) {
                    if ($row['show_code'] !== null && in_array($row['show_code'], $codes, true)) {
                        $shows[$row['show_id']] = true;
                    }
                }
            }
            return count($shows);
        };

        $evalWindow = function (string $level, int $oid, array $years) use (
            $designerShowCount, $designerHasCodeInYears, $assistMatches, $assistShowCount
        ): array {
            sort($years);
            if ($level === 'C') {
                $ansvarlig = $designerShowCount($oid, $years, ['C']);
                $matches = $assistMatches($oid, $years, ['Springning - Banedesigner - A', 'Springning - Banedesigner - B'], 4);
                $assistShows = count(array_unique(array_column($matches, 'show_id')));
                $assistDesigners = count(array_unique(array_column($matches, 'designer_id')));
                $pass = $ansvarlig >= 6 && $assistShows >= 4 && $assistDesigners >= 2;
                $text = "$ansvarlig C-stævner som ansvarlig (krav ≥6) OG assisteret $assistDesigners forsk. A/B-banedesignere ved $assistShows stævner med svh≥4 (krav ≥2 designere, ≥4 stævner)";
                return [$pass, $text];
            }
            if ($level === 'B') {
                $ansvarlig = $designerShowCount($oid, $years, ['C', 'B']);
                $matches = $assistMatches($oid, $years, ['Springning - Banedesigner - A'], 4);
                $assistShows = count(array_unique(array_column($matches, 'show_id')));
                $assistDesigners = count(array_unique(array_column($matches, 'designer_id')));
                $hasB5 = false;
                foreach ($matches as $m) {
                    if ($m['svh'] !== null && $m['svh'] >= 5 && $m['show_code'] === 'B') { $hasB5 = true; break; }
                }
                $pass = $ansvarlig >= 10 && $assistDesigners >= 2 && $assistShows >= 2 && $hasB5;
                $text = "$ansvarlig C/B-stævner som ansvarlig (krav ≥10) OG assisteret $assistDesigners forsk. A-banedesignere ved $assistShows stævner svh≥4 (krav ≥2/≥2), "
                    . ($hasB5 ? 'heraf mindst ét B-stævne svh≥5 ✓' : 'intet B-stævne svh≥5 endnu ✗');
                return [$pass, $text];
            }
            // A
            $ansvarlig = $designerShowCount($oid, $years, ['C', 'B']);
            $pairs = [[$years[0], $years[1]], [$years[1], $years[2]]];
            $b1 = true;
            foreach ($pairs as $pair) {
                if (!$designerHasCodeInYears($oid, $pair, 'B')) { $b1 = false; break; }
            }
            $b2 = true;
            foreach ($years as $y) {
                if ($assistShowCount($oid, [$y], ['B', 'A', 'FEI']) < 2) { $b2 = false; break; }
            }
            $pass = $ansvarlig >= 12 && ($b1 || $b2);
            $text = "$ansvarlig C/B-stævner som ansvarlig (krav ≥12) OG "
                . ($b1 ? 'mindst ét B-stævne som ansvarlig hvert 2. år ✓' : ($b2 ? 'assist-alternativet (≥2 B+/år) ✓' : 'hverken "B hvert 2. år" eller assist-alternativet opfyldt ✗'));
            return [$pass, $text];
        };

        $rows = [];
        foreach ($officials as $o) {
            $oid   = (int)$o['official_id'];
            $level = $typer[$o['type']];
            $krav = '';
            $opfylder = null;
            $opfyldtTidligere = null;

            if ($level === 'D') {
                $krav = 'Intet formelt aktivitetskrav registreret for D-niveau.';
            } else {
                [$pass1, $text1] = $evalWindow($level, $oid, $windowNow);
                $opfylder = $pass1;
                $krav = "$text1 (vindue " . $windowNow[0] . '–' . end($windowNow) . ')';

                if (!$opfylder) {
                    [$pass2, $text2] = $evalWindow($level, $oid, $windowPrev);
                    $opfyldtTidligere = $pass2;
                    $krav .= ' | Tidligere (fuldt ' . $windowPrev[0] . '–' . end($windowPrev) . '): ' . $text2;
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

        return ['years' => $allYears, 'rows' => $rows];
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

        $yearCond = '';
        $yearParams = [];
        if ($years) {
            $placeholders = implode(',', array_fill(0, count($years), '?'));
            $yearCond = "AND s.aar IN ($placeholders)";
            $yearParams = $years;
        }

        // shows joines altid ind for at udelukke stævner med status = 'udelukket'.
        return $this->db->all(
            "SELECT a.rolle, COUNT(*) AS antal
             FROM assignments a
             JOIN classes c ON c.id = a.class_id
             JOIN shows   s ON s.id = c.show_id AND s.status = 'aktiv' $yearCond
             WHERE a.official_id = ?
             GROUP BY a.rolle ORDER BY antal DESC, a.rolle",
            array_merge($yearParams, [$id])
        );
    }

    /**
     * Stævner en official har virket ved, med roller og nøgletal (kan filtreres på år, OR).
     * @param array<int,string>|string $year Ét årstal eller flere (OR).
     */
    public function officialShows(int $id, $year = ''): array
    {
        $years = array_values(array_filter((array)$year, fn($v) => $v !== ''));

        // shows-joinet har altid status = 'aktiv', saa udelukkede stævner aldrig vises
        // paa en officials side, uanset aarsfilter.
        $yearCond = "AND s.status = 'aktiv'";
        $yearParams = [];
        if ($years) {
            $placeholders = implode(',', array_fill(0, count($years), '?'));
            $yearCond .= " AND s.aar IN ($placeholders)";
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
                    COALESCE(rid.ryttere, 0) AS ryttere,
                    (SELECT GROUP_CONCAT(DISTINCT c3.disciplin ORDER BY c3.disciplin SEPARATOR ' / ')
                        FROM classes c3 WHERE c3.show_id = s.id AND c3.disciplin IS NOT NULL AND c3.disciplin <> '') AS discipliner
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

        $yearJoin = "JOIN shows s ON s.id = c.show_id AND s.status = 'aktiv'";
        $yearParams = [];
        if ($years) {
            $placeholders = implode(',', array_fill(0, count($years), '?'));
            $yearJoin .= " AND s.aar IN ($placeholders)";
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

    /**
     * Oversigt over stævner. $status styrer livscyklus-filteret: 'aktiv'
     * (standard) viser kun aktive stævner, 'udelukket' viser kun udelukkede,
     * 'alle' viser begge - se shows.php.
     * @param array<int,string>|string $year Ét årstal eller flere (OR).
     */
    public function showsOverview(string $search = '', string $disciplin = '', string $niveau = '', $year = '', string $status = 'aktiv'): array
    {
        $years = array_values(array_filter((array)$year, fn($v) => $v !== ''));

        $where = [];
        $params = [];
        if ($status !== 'alle') { $where[] = 's.status = ?'; $params[] = $status; }
        if ($search !== '') {
            $where[] = '(s.prop LIKE ? OR cl.navn LIKE ? OR cl.forkort LIKE ?)';
            $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
        }
        // Discipline filtreres via klasserne (ikke kun s.disciplin, som blot er
        // stævnets HYPPIGSTE disciplin) - saa et blandet stævne (fx dressur +
        // spring) ogsaa findes naar der filtreres på springning, se show.php#579.
        if ($disciplin !== '') {
            $where[] = 'EXISTS (SELECT 1 FROM classes c WHERE c.show_id = s.id AND c.disciplin = ?)';
            $params[] = $disciplin;
        }
        if ($niveau !== '') { $where[] = 's.top_slug = ?'; $params[] = $niveau; }
        if ($years) {
            $placeholders = implode(',', array_fill(0, count($years), '?'));
            $where[] = "s.aar IN ($placeholders)";
            array_push($params, ...$years);
        }
        $w = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        return $this->db->all(
            "SELECT s.id, s.prop, s.dato, s.aar, s.disciplin, s.top_code, s.has_lower, s.prop_unknown,
                    s.status, s.status_note,
                    cl.navn AS klub, cl.forkort,
                    (SELECT COUNT(*) FROM classes c WHERE c.show_id = s.id) AS klasser,
                    (SELECT COALESCE(SUM(starter),0) FROM classes c WHERE c.show_id = s.id) AS ryttere,
                    (SELECT COUNT(DISTINCT a.official_id)
                        FROM assignments a JOIN classes c ON c.id = a.class_id
                        WHERE c.show_id = s.id) AS officials,
                    (SELECT GROUP_CONCAT(DISTINCT c.disciplin ORDER BY c.disciplin SEPARATOR ' / ')
                        FROM classes c WHERE c.show_id = s.id AND c.disciplin IS NOT NULL AND c.disciplin <> '') AS discipliner
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

        $showsJoin = "LEFT JOIN shows s ON s.club_id = c.id AND s.status = 'aktiv'";
        $yearParams = [];
        if ($years) {
            // Med aarsfilter skal klubber uden staevne det/de aar udelades, saa joinet er INNER.
            $placeholders = implode(',', array_fill(0, count($years), '?'));
            $showsJoin = "JOIN shows s ON s.club_id = c.id AND s.status = 'aktiv' AND s.aar IN ($placeholders)";
            $yearParams = $years;
        }

        $where = '';
        $searchParams = [];
        if ($search !== '') {
            $where = 'WHERE c.navn LIKE ?';
            $searchParams[] = '%' . $search . '%';
        }

        return $this->db->all(
            "SELECT c.id, c.navn, c.forkort, c.distrikt, c.postnr, c.status,
                    COUNT(DISTINCT s.id) AS antal_staevner
             FROM clubs c
             $showsJoin
             $where
             GROUP BY c.id, c.navn, c.forkort, c.distrikt, c.postnr, c.status
             ORDER BY $order",
            array_merge($yearParams, $searchParams)
        );
    }

    /**
     * Klubber i clubs-tabellen der ikke optræder på nogen stævner overhovedet
     * - hverken aktive eller udelukkede. Kandidater til oprydning/fletning,
     * fx en klub oprettet ud fra en importlinje der senere blev rettet.
     */
    public function clubsWithoutShows(): array
    {
        return $this->db->all(
            "SELECT c.id, c.navn, c.forkort, c.distrikt, c.postnr, c.status
             FROM clubs c
             WHERE NOT EXISTS (SELECT 1 FROM shows s WHERE s.club_id = c.id)
             ORDER BY c.navn"
        );
    }

    /**
     * Én klubs stamdata + antal stævner i alt (kan filtreres på år, OR).
     * @param array<int,string>|string $year Ét årstal eller flere (OR).
     */
    public function club(int $id, $year = ''): ?array
    {
        $years = array_values(array_filter((array)$year, fn($v) => $v !== ''));

        $yearWhere = "AND s.status = 'aktiv'";
        $yearParams = [];
        if ($years) {
            $placeholders = implode(',', array_fill(0, count($years), '?'));
            $yearWhere .= " AND s.aar IN ($placeholders)";
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

        $yearWhere = "AND s.status = 'aktiv'";
        $yearParams = [];
        if ($years) {
            $placeholders = implode(',', array_fill(0, count($years), '?'));
            $yearWhere .= " AND s.aar IN ($placeholders)";
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

    /**
     * Stævner en klub har holdt, med nøgletal (kan filtreres på år, OR).
     * Udelukkede stævner (status = 'udelukket') tælles ikke med, ligesom
     * club()/clubOfficials().
     * @param array<int,string>|string $year Ét årstal eller flere (OR).
     */
    public function clubShows(int $id, $year = ''): array
    {
        $years = array_values(array_filter((array)$year, fn($v) => $v !== ''));

        $yearWhere = "AND s.status = 'aktiv'";
        $yearParams = [];
        if ($years) {
            $placeholders = implode(',', array_fill(0, count($years), '?'));
            $yearWhere .= " AND s.aar IN ($placeholders)";
            $yearParams = $years;
        }

        return $this->db->all(
            "SELECT s.id, s.prop, s.dato, s.aar, s.disciplin, s.top_code, s.has_lower, s.prop_unknown,
                    (SELECT COUNT(*) FROM classes c WHERE c.show_id = s.id) AS klasser,
                    (SELECT COALESCE(SUM(starter),0) FROM classes c WHERE c.show_id = s.id) AS ryttere,
                    (SELECT COUNT(DISTINCT a.official_id)
                        FROM assignments a JOIN classes c ON c.id = a.class_id
                        WHERE c.show_id = s.id) AS officials
             FROM shows s
             WHERE s.club_id = ? $yearWhere
             ORDER BY s.dato DESC, s.prop",
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

    /** Aktive officials (til dropdown, fx ny tildeling på en klasse). */
    public function activeOfficials(): array
    {
        return $this->db->all("SELECT id, navn FROM officials WHERE status = 'aktiv' ORDER BY navn");
    }

    /** Distinkte aar (til filter), nyeste foerst. Udelukkede stævner tæller ikke med. */
    public function years(): array
    {
        $rows = $this->db->all("SELECT DISTINCT aar FROM shows WHERE aar IS NOT NULL AND status = 'aktiv' ORDER BY aar DESC");
        return array_map(fn($r) => (int)$r['aar'], $rows);
    }

    /** Antal staevner pr. aar (til forsiden). Udelukkede stævner tæller ikke med. */
    public function showsPerYear(): array
    {
        return $this->db->all(
            "SELECT aar, COUNT(*) AS staevner,
                    (SELECT COUNT(*) FROM classes c JOIN shows s2 ON s2.id = c.show_id
                        WHERE s2.aar = s.aar AND s2.status = 'aktiv') AS klasser
             FROM shows s
             WHERE aar IS NOT NULL AND s.status = 'aktiv'
             GROUP BY aar ORDER BY aar DESC"
        );
    }

    /**
     * Distinkte discipliner (til filter). Hentes fra klasserne, ikke fra
     * shows.disciplin (som blot er stævnets hyppigste disciplin) - saa en
     * disciplin der kun optræder som sekundær i blandede stævner (fx spring
     * ved et overvejende dressurstævne) stadig er valgbar. Udelukkede
     * stævner tæller ikke med.
     */
    public function disciplines(): array
    {
        return $this->db->all(
            "SELECT DISTINCT c.disciplin FROM classes c
             JOIN shows s ON s.id = c.show_id AND s.status = 'aktiv'
             WHERE c.disciplin IS NOT NULL AND c.disciplin <> '' ORDER BY c.disciplin"
        );
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
            "SELECT o.id, o.navn,
                    COUNT(DISTINCT CASE WHEN s.status = 'aktiv' THEN c.show_id END) AS antal_staevner
             FROM officials o
             LEFT JOIN assignments a ON a.official_id = o.id
             LEFT JOIN classes c ON c.id = a.class_id
             LEFT JOIN shows s ON s.id = c.show_id
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
             JOIN shows s       ON s.id = c.show_id AND s.status = 'aktiv'
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

    // ---------------- FEI officials-liste ----------------
    // FeiOfficialMatcher matcher HELE fei_officials-listen (alle lande) mod
    // officials, fordi nogle af dine officials er udenlandske FEI-officials
    // der har virket ved danske (internationale) stævner. Nøgletal og
    // "uden match"-listen her holder fokus på de danske (nf = 'Denmark'),
    // da resten af listen er udenlandske officials der aldrig optræder i
    // dine stævnedata - men matched-tallet dækker alle lande.

    public function feiSummary(): array
    {
        return [
            'persons'        => (int)$this->db->scalar("SELECT COUNT(*) FROM fei_officials WHERE nf = 'Denmark'"),
            'functions'      => (int)$this->db->scalar(
                "SELECT COUNT(*) FROM fei_official_functions f
                 JOIN fei_officials o ON o.fei_id = f.fei_id
                 WHERE o.nf = 'Denmark'"
            ),
            'matched'        => (int)$this->db->scalar('SELECT COUNT(*) FROM officials WHERE fei_listed = 1'),
            'matched_foreign'=> (int)$this->db->scalar(
                "SELECT COUNT(*) FROM fei_officials WHERE official_id IS NOT NULL AND nf <> 'Denmark'"
            ),
            'unmatched'      => (int)$this->db->scalar("SELECT COUNT(*) FROM fei_officials WHERE nf = 'Denmark' AND official_id IS NULL"),
            'updated_at'     => $this->db->scalar('SELECT MAX(updated_at) FROM fei_officials'),
        ];
    }

    /** FEI-funktioner (discipline/rolle/niveau/status) for én official. */
    public function officialFeiFunctions(int $id): array
    {
        return $this->db->all(
            'SELECT f.discipline, f.function_name, f.level, f.status
             FROM fei_officials o
             JOIN fei_official_functions f ON f.fei_id = o.fei_id
             WHERE o.official_id = ?
             ORDER BY f.discipline, f.function_name',
            [$id]
        );
    }

    /** Danske FEI-personer der IKKE kunne matches til en official. */
    public function feiUnmatched(): array
    {
        return $this->db->all(
            "SELECT o.fei_id, o.first_name, o.last_name,
                    GROUP_CONCAT(DISTINCT CONCAT(f.discipline, ' - ', f.function_name, ' (', f.level, ')')
                        ORDER BY f.discipline, f.function_name SEPARATOR ', ') AS funktioner
             FROM fei_officials o
             LEFT JOIN fei_official_functions f ON f.fei_id = o.fei_id
             WHERE o.nf = 'Denmark' AND o.official_id IS NULL
             GROUP BY o.fei_id, o.first_name, o.last_name
             ORDER BY o.last_name, o.first_name"
        );
    }

    /**
     * Officials der er matchet til en udenlandsk (nf <> Denmark) FEI-official -
     * typisk internationale officials der har virket ved danske CSI/CDI-stævner.
     */
    public function feiMatchedForeign(): array
    {
        return $this->db->all(
            "SELECT o.fei_id, o.first_name, o.last_name, o.nf, off.id AS official_id, off.navn
             FROM fei_officials o
             JOIN officials off ON off.id = o.official_id
             WHERE o.nf <> 'Denmark'
             ORDER BY o.nf, o.last_name, o.first_name"
        );
    }

    /**
     * Gennemse den høstede FEI-liste med filtre. Som udgangspunkt kun danske
     * personer (nf = Denmark) - sæt $allCountries for også at kunne slå
     * udenlandske FEI-officials op (fx for manuelt at knytte en der har
     * virket ved et internationalt stævne i Danmark).
     */
    public function feiList(string $search = '', string $discipline = '', bool $allCountries = false): array
    {
        $where = [];
        $params = [];
        if (!$allCountries)      { $where[] = "o.nf = 'Denmark'"; }
        if ($search !== '')     { $where[] = '(o.first_name LIKE ? OR o.last_name LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }
        if ($discipline !== '') { $where[] = 'f.discipline = ?'; $params[] = $discipline; }
        $w = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        return $this->db->all(
            "SELECT o.fei_id, o.first_name, o.last_name, o.nf, o.official_id,
                    f.discipline, f.function_name, f.level, f.status
             FROM fei_officials o
             JOIN fei_official_functions f ON f.fei_id = o.fei_id
             $w
             ORDER BY o.last_name, o.first_name, f.discipline, f.function_name",
            $params
        );
    }

    public function feiDisciplines(): array
    {
        return $this->db->all(
            "SELECT DISTINCT f.discipline
             FROM fei_official_functions f
             JOIN fei_officials o ON o.fei_id = f.fei_id
             WHERE o.nf = 'Denmark'
             ORDER BY f.discipline"
        );
    }

}
