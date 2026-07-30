<?php
defined('APP') or die('Direkte adgang ikke tilladt');

/**
 * Høster den officielle officials-liste fra Dansk Ride Forbunds
 * "find-dommer"-side og gemmer den i tabellen drf_officials.
 *
 * Kilden kan enten hentes live (curl) eller læses fra en gemt HTML-fil.
 * Hver kørsel er et fuldt snapshot: drf_officials tømmes og genindlæses,
 * og hver DRF-person forsøges matchet til en eksisterende official på
 * normaliseret navn. officials.drf_listed sættes = 1 for match.
 *
 * HTML-struktur (pr. 2026):
 *   <table> ... <thead><tr><th>TYPE</th></tr></thead>
 *     <tbody><tr><td class="officials">
 *        <h5><a ...>Distrikt NN</a></h5>
 *        <div class="row">
 *           <div class="col-md-3"><div class="vcard">
 *              <span class="fn"><b>NAVN</b></span>
 *              <span class="postal-code">1234</span><span class="locality">By</span>
 *              <div class="tel">tlf</div>
 *              <a class="email">email</a>
 *           </div></div> ...
 *        </div>
 *        <h5>Distrikt NN+1</h5> ...
 */
class DrfImporter
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * @param string|null $source URL eller sti til HTML-fil. Null => config['drf_url'].
     * @param bool        $isFile true hvis $source er en lokal fil.
     */
    public function import(?string $source = null, bool $isFile = false): array
    {
        if ($source === null) {
            $source = $GLOBALS['config']['drf_url'] ?? '';
        }
        if ($source === '') {
            throw new RuntimeException('Ingen DRF-kilde angivet (sæt drf_url i config.php).');
        }

        $html = $isFile ? $this->readFile($source) : $this->fetchUrl($source);
        if ($html === '' || stripos($html, 'vcard') === false) {
            throw new RuntimeException('Kilden gav ingen genkendelige official-data (mangler "vcard"). '
                . 'Er URL/fil korrekt? Hentede ' . strlen($html) . ' tegn.');
        }

        $records = $this->parse($html);
        if (!$records) {
            throw new RuntimeException('Kunne ikke udtrække nogen personer fra HTML.');
        }

        $this->db->begin();
        try {
            // Fuldt snapshot: ryd tidligere liste og nulstil markeringer.
            $this->db->run('DELETE FROM drf_officials');
            $this->db->run('UPDATE officials SET drf_listed = 0');

            // Byg opslag: normaliseret navn -> official_id (fra eksisterende data).
            // Tidligere navne (aliaser fra flettede officials, fx efter et
            // navneskift) inkluderes ogsaa, saa DRF-listen stadig matcher personen
            // under det gamle navn - men et nuvaerende navn har altid forrang.
            $map = [];
            foreach ($this->db->all('SELECT official_id, navn FROM official_aliases') as $a) {
                $map[$this->norm($a['navn'])] = (int)$a['official_id'];
            }
            foreach ($this->db->all('SELECT id, navn FROM officials') as $o) {
                $map[$this->norm($o['navn'])] = (int)$o['id'];
            }

            $ins = $this->db->pdo()->prepare(
                'INSERT INTO drf_officials
                    (navn, navn_norm, kategori, type, distrikt, postnr, postdistrikt, tlf, email, official_id, harvested_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                    navn_norm=VALUES(navn_norm), kategori=VALUES(kategori),
                    postnr=VALUES(postnr), postdistrikt=VALUES(postdistrikt), tlf=VALUES(tlf), email=VALUES(email),
                    official_id=VALUES(official_id), harvested_at=NOW()'
            );

            $matchedOfficials = [];
            $unmatchedNames   = [];
            foreach ($records as $r) {
                $norm = $this->norm($r['navn']);
                $officialId = $map[$norm] ?? null;
                if ($officialId !== null) {
                    $matchedOfficials[$officialId] = true;
                } else {
                    $unmatchedNames[$norm] = true;
                }
                $ins->execute([
                    $r['navn'], $norm, $this->kategori($r['type']), $r['type'], $r['distrikt'],
                    $r['postnr'], $r['postdistrikt'], $r['tlf'], $r['email'], $officialId,
                ]);
            }

            // Sæt drf_listed for matchede officials.
            $this->db->run(
                'UPDATE officials o JOIN drf_officials d ON d.official_id = o.id SET o.drf_listed = 1'
            );

            // Log kørslen.
            $this->db->run(
                'INSERT INTO imports (filename, imported_at, rows_total, note)
                 VALUES (?, NOW(), ?, ?)',
                [$isFile ? basename($source) : $source, count($records), 'DRF-liste']
            );

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $uniqueNames = [];
        foreach ($records as $r) { $uniqueNames[$this->norm($r['navn'])] = true; }

        return [
            'rows'             => count($records),
            'unique_persons'   => count($uniqueNames),
            'matched_persons'  => count($matchedOfficials),
            'unmatched_names'  => count($unmatchedNames),
            'officials_flagged'=> count($matchedOfficials),
        ];
    }

    // ---------------- Hentning ----------------

    private function readFile(string $path): string
    {
        if (!is_readable($path)) {
            throw new RuntimeException('Kan ikke læse HTML-fil: ' . $path);
        }
        return (string)file_get_contents($path);
    }

    private function fetchUrl(string $url): string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => 45,
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; EquiliveBot/1.0)',
                CURLOPT_HTTPHEADER     => ['Accept-Language: da,en;q=0.8'],
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
        // Fald tilbage til file_get_contents.
        $ctx = stream_context_create(['http' => [
            'timeout' => 45,
            'header'  => "User-Agent: Mozilla/5.0 (compatible; EquiliveBot/1.0)\r\n",
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            throw new RuntimeException('Kunne ikke hente URL (aktivér curl eller allow_url_fopen): ' . $url);
        }
        return $body;
    }

    // ---------------- Parsing ----------------

    /** @return array<int,array{navn:string,type:string,distrikt:?string,postnr:?string,postdistrikt:?string,tlf:?string,email:?string}> */
    public function parse(string $html): array
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        $xp = new DOMXPath($doc);

        $vcards = $xp->query(
            "//td[contains(concat(' ', normalize-space(@class), ' '), ' officials ')]"
            . "//div[contains(concat(' ', normalize-space(@class), ' '), ' vcard ')]"
        );

        $out = [];
        foreach ($vcards as $vc) {
            $navn = $this->nodeText($xp, ".//span[contains(concat(' ', normalize-space(@class), ' '), ' fn ')]", $vc);
            if ($navn === '') {
                continue;
            }
            $typeNode = $xp->query("ancestor::table[1]//th[1]", $vc)->item(0);
            $type = $typeNode ? $this->clean($typeNode->textContent) : '';

            $distNode = $xp->query(
                "ancestor::div[contains(concat(' ', normalize-space(@class), ' '), ' row ')][1]"
                . "/preceding-sibling::h5[1]", $vc
            )->item(0);
            $distrikt = $distNode ? $this->clean($distNode->textContent) : null;

            $out[] = [
                'navn'     => $navn,
                'type'     => $type,
                'distrikt' => $distrikt !== '' ? $distrikt : null,
                'postnr'   => $this->nodeText($xp, ".//span[contains(concat(' ', normalize-space(@class), ' '), ' postal-code ')]", $vc) ?: null,
                'postdistrikt' => $this->nodeText($xp, ".//span[contains(concat(' ', normalize-space(@class), ' '), ' locality ')]", $vc) ?: null,
                'tlf'      => $this->nodeText($xp, ".//*[contains(concat(' ', normalize-space(@class), ' '), ' tel ')]", $vc) ?: null,
                'email'    => $this->nodeText($xp, ".//a[contains(concat(' ', normalize-space(@class), ' '), ' email ')]", $vc) ?: null,
            ];
        }
        return $out;
    }

    private function nodeText(DOMXPath $xp, string $q, DOMNode $ctx): string
    {
        $n = $xp->query($q, $ctx)->item(0);
        return $n ? $this->clean($n->textContent) : '';
    }

    private function clean(string $s): string
    {
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim($s);
    }

    /** Normalisér navn til matching: små bogstaver, ét mellemrum, trimmet. */
    private function norm(string $s): string
    {
        $s = preg_replace('/\s+/u', ' ', $s);
        return mb_strtolower(trim($s), 'UTF-8');
    }

    /** Afled en grov kategori/disciplin af typenavnet. */
    private function kategori(string $type): string
    {
        $t = mb_strtolower($type, 'UTF-8');
        $has = fn(string $needle) => mb_strpos($t, $needle) !== false;
        if ($has('teknisk delegeret'))                 return 'Teknisk delegeret';
        if ($has('steward'))                           return 'Steward';
        if ($has('military') || $has('terræn'))        return 'Military';
        if ($has('distance'))                          return 'Distance';
        if ($has('volti'))                             return 'Voltigering';
        if ($has('spring') || $has('banedesign') || $has('banebygger') || $has('hunter')) return 'Springning';
        if ($has('dressur') || $has('berider'))        return 'Dressur';
        if ($has('veterinær') || $has('dyrlæge'))      return 'Veterinær';
        if ($has('udd') || $has('rideinstruktør') || $has('ridelære')
            || $has('rideskole') || $has('læremester')) return 'Uddannelse';
        return 'Andet';
    }
}
