<?php
defined('APP') or die('Direkte adgang ikke tilladt');

/**
 * Høster Dansk Ride Forbunds klubliste ("find-klubber") og bruger den til at
 * udfylde clubs.distrikt for dine eksisterende klubber.
 *
 * Kilden kan enten hentes live (curl) eller læses fra en gemt HTML-fil.
 * Hver kørsel er et fuldt snapshot: drf_clubs tømmes og genindlæses, og hver
 * DRF-klub forsøges matchet til en eksisterende klub på forkortelse
 * (clubs.club_key/forkort, som er det samme "ClubId" DRF bruger) og ellers på
 * normaliseret navn. clubs.distrikt sættes for match.
 *
 * HTML-struktur (pr. 2026):
 *   <table> ... <thead><tr><th>Distrikt NN</th></tr></thead>
 *     <tbody><tr><td>
 *        <div class="row">
 *           <div class="col-4 club-info"><div class="vcard">
 *              <div class="header"><a href=".../vis-klub?ClubId=ARK">
 *                 <span class="org"><b>NAVN</b></span>(ARK)</a></div>
 *              <div class="adr">
 *                 <span class="street-address">Vej 1</span>
 *                 <span class="postal-code">1234</span><span class="locality">By</span>
 *              </div>
 *              <a class="url external" href="http://...">http://...</a>
 *           </div></div>
 *        </div>
 *     </td></tr> ...
 */
class DrfClubImporter
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * @param string|null $source URL eller sti til HTML-fil. Null => config['drf_clubs_url'].
     * @param bool        $isFile true hvis $source er en lokal fil.
     */
    public function import(?string $source = null, bool $isFile = false): array
    {
        if ($source === null) {
            $source = $GLOBALS['config']['drf_clubs_url'] ?? '';
        }
        if ($source === '') {
            throw new RuntimeException('Ingen DRF-klub-kilde angivet (sæt drf_clubs_url i config.php).');
        }

        $html = $isFile ? $this->readFile($source) : $this->fetchUrl($source);
        if ($html === '' || stripos($html, 'vcard') === false) {
            throw new RuntimeException('Kilden gav ingen genkendelige klub-data (mangler "vcard"). '
                . 'Er URL/fil korrekt? Hentede ' . strlen($html) . ' tegn.');
        }

        $records = $this->parse($html);
        if (!$records) {
            throw new RuntimeException('Kunne ikke udtrække nogen klubber fra HTML.');
        }

        $this->db->begin();
        try {
            // Fuldt snapshot: ryd tidligere liste.
            $this->db->run('DELETE FROM drf_clubs');

            // Byg opslag: forkortelse -> klub-id (fra eksisterende data).
            $byKey  = [];
            $byNorm = [];
            foreach ($this->db->all('SELECT id, club_key, navn FROM clubs') as $c) {
                $byKey[mb_strtoupper($c['club_key'], 'UTF-8')] = (int)$c['id'];
                $byNorm[$this->norm($c['navn'])] = (int)$c['id'];
            }

            $ins = $this->db->pdo()->prepare(
                'INSERT INTO drf_clubs
                    (navn, navn_norm, forkort, distrikt, adresse, postnr, postdistrikt, url, club_id, harvested_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                    navn_norm=VALUES(navn_norm), distrikt=VALUES(distrikt), adresse=VALUES(adresse),
                    postnr=VALUES(postnr), postdistrikt=VALUES(postdistrikt), url=VALUES(url),
                    club_id=VALUES(club_id), harvested_at=NOW()'
            );

            $matchedClubs   = [];
            $unmatchedNames = [];
            foreach ($records as $r) {
                $norm    = $this->norm($r['navn']);
                $clubId  = null;
                if ($r['forkort'] !== null && isset($byKey[mb_strtoupper($r['forkort'], 'UTF-8')])) {
                    $clubId = $byKey[mb_strtoupper($r['forkort'], 'UTF-8')];
                } elseif (isset($byNorm[$norm])) {
                    $clubId = $byNorm[$norm];
                }
                if ($clubId !== null) {
                    $matchedClubs[$clubId] = true;
                } else {
                    $unmatchedNames[$norm] = true;
                }
                $ins->execute([
                    $r['navn'], $norm, $r['forkort'], $r['distrikt'],
                    $r['adresse'], $r['postnr'], $r['postdistrikt'], $r['url'], $clubId,
                ]);
            }

            // Udfyld distrikt for matchede klubber.
            $this->db->run(
                'UPDATE clubs c JOIN drf_clubs d ON d.club_id = c.id SET c.distrikt = d.distrikt'
            );

            // Log kørslen.
            $this->db->run(
                'INSERT INTO imports (filename, imported_at, rows_total, note)
                 VALUES (?, NOW(), ?, ?)',
                [$isFile ? basename($source) : $source, count($records), 'DRF-klubliste']
            );

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return [
            'rows'            => count($records),
            'matched_clubs'   => count($matchedClubs),
            'unmatched_names' => count($unmatchedNames),
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

    /** @return array<int,array{navn:string,forkort:?string,distrikt:?string,adresse:?string,postnr:?string,postdistrikt:?string,url:?string}> */
    public function parse(string $html): array
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        $xp = new DOMXPath($doc);

        $tables = $xp->query("//table[.//div[contains(concat(' ', normalize-space(@class), ' '), ' vcard ')]]");

        $out = [];
        foreach ($tables as $table) {
            $thNode  = $xp->query(".//thead//th", $table)->item(0);
            $distrikt = $thNode ? $this->clean($thNode->textContent) : null;

            $vcards = $xp->query(".//div[contains(concat(' ', normalize-space(@class), ' '), ' vcard ')]", $table);
            foreach ($vcards as $vc) {
                $navn = $this->nodeText($xp, ".//span[contains(concat(' ', normalize-space(@class), ' '), ' org ')]", $vc);
                if ($navn === '') {
                    continue;
                }

                $href = '';
                $link = $xp->query(".//div[contains(concat(' ', normalize-space(@class), ' '), ' header ')]//a[@href]", $vc)->item(0);
                if ($link instanceof DOMElement) {
                    $href = (string)$link->getAttribute('href');
                }
                $forkort = $this->clubIdFromHref($href);

                $urlNode = $xp->query(".//a[contains(concat(' ', normalize-space(@class), ' '), ' url ')]", $vc)->item(0);
                $url = $urlNode instanceof DOMElement ? $this->clean($urlNode->getAttribute('href')) : '';

                $out[] = [
                    'navn'         => $navn,
                    'forkort'      => $forkort,
                    'distrikt'     => $distrikt !== '' ? $distrikt : null,
                    'adresse'      => $this->nodeText($xp, ".//span[contains(concat(' ', normalize-space(@class), ' '), ' street-address ')]", $vc) ?: null,
                    'postnr'       => $this->nodeText($xp, ".//span[contains(concat(' ', normalize-space(@class), ' '), ' postal-code ')]", $vc) ?: null,
                    'postdistrikt' => $this->nodeText($xp, ".//span[contains(concat(' ', normalize-space(@class), ' '), ' locality ')]", $vc) ?: null,
                    'url'          => $url !== '' ? $url : null,
                ];
            }
        }
        return $out;
    }

    /** Udtrækker ?ClubId=XXX fra en find-klubber-URL (DRF's egen klub-forkortelse). */
    private function clubIdFromHref(string $href): ?string
    {
        $query = (string)parse_url($href, PHP_URL_QUERY);
        if ($query === '') {
            return null;
        }
        parse_str($query, $params);
        $id = trim((string)($params['ClubId'] ?? ''));
        return $id !== '' ? $id : null;
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
}
