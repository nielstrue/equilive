<?php
defined('APP') or die('Direkte adgang ikke tilladt');

/**
 * Høster ryttere (navn + RiderId) pr. klasse for ét stævne fra DRF's
 * klasseresultat-sider og gemmer dem i riders/class_riders.
 *
 * Kræver at ShowDetailImporter allerede har kørt for stævnet: den udfylder
 * baade shows.resultat_status (høstet fra samme side som klasserne) og
 * classes.drf_class_id (DRF's SectionId, som skal bruges til at bygge
 * klasseresultat-URL'en herunder). Kun stævner med resultat_status =
 * "Resultatbehandling færdig" må høstes - før den status er resultatlisten
 * ikke nødvendigvis færdig/pålidelig.
 *
 * Modsat ShowDetailImporter (ét HTTP-kald pr. stævne) koster dette ét
 * HTTP-kald PR. KLASSE, saa dette er bevidst en separat handling (egen knap
 * på show.php / eget batch+CLI), adskilt fra "Hent klassedetaljer fra DRF".
 *
 * HTML-struktur (pr. 2026), klasseresultat-siden:
 *   <table id="entries">
 *     <tbody>
 *       <tr>
 *         <td><a href="/go/heste-og-ryttere/find-ryttere/vis-rytter?RiderId=87477">Navn</a></td>
 *         ...
 *
 * RiderId er ikke altid numerisk (fx "K091730" er set i data).
 */
class RiderResultImporter
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Stævner der er kandidater til rytter-høstning (til bulk-backfill, se
     * harvestBatch() og cli/import_class_riders.php). E-stævner
     * ("Rideskolestævne (E)", shows.top_code = 'E') og endurance-stævner
     * (shows.disciplin = 'endurance') udelades - der er ikke behov for at
     * høste ryttere fra rideskolestævner eller distanceridt.
     * @return array<int,array{id:int,prop:string,aar:?int}>
     */
    private function pending(array $years, bool $force, ?int $limit): array
    {
        $placeholders = implode(',', array_fill(0, count($years), '?'));
        $sql = "SELECT id, prop, aar FROM shows
                WHERE aar IN ($placeholders)
                  AND status = 'aktiv'
                  AND resultat_status = 'Resultatbehandling færdig'
                  AND (top_code IS NULL OR top_code != 'E')
                  AND (disciplin IS NULL OR disciplin != 'endurance')"
             . ($force ? '' : ' AND riders_harvested_at IS NULL')
             . ' ORDER BY aar, id';
        $rows = $this->db->all($sql, $years);
        return $limit !== null ? array_slice($rows, 0, $limit) : $rows;
    }

    /** Antal stævner der (endnu) mangler rytter-høstning for hvert år i $years. */
    public function pendingCount(array $years): int
    {
        return count($this->pending($years, false, null));
    }

    /**
     * Kør rytter-høstning for op til $limit stævner i $years (ældste år/id
     * først). $delayMs er pausen mellem hvert klasse-opslag (der kan være
     * mange klasser pr. stævne, saa dette er langsommere end
     * ShowDetailImporter::harvestBatch()).
     *
     * @return array{total:int,ok:int,failed:int,riders_matched:int,rows:array}
     */
    public function harvestBatch(array $years, ?int $limit, bool $force, int $delayMs = 300): array
    {
        $shows = $this->pending($years, $force, $limit);

        $result = ['total' => count($shows), 'ok' => 0, 'failed' => 0, 'riders_matched' => 0, 'rows' => []];
        foreach ($shows as $show) {
            $row = ['show_id' => (int)$show['id'], 'prop' => $show['prop'], 'aar' => $show['aar']];
            try {
                $r = $this->import((int)$show['id'], $delayMs);
                $row['ok'] = true;
                $row['classes_harvested'] = $r['classes_harvested'];
                $row['riders_matched']    = $r['riders_matched'];
                $result['ok']++;
                $result['riders_matched'] += $r['riders_matched'];
            } catch (Throwable $e) {
                $row['ok'] = false;
                $row['message'] = $e->getMessage();
                $result['failed']++;
            }
            $result['rows'][] = $row;
        }
        return $result;
    }

    /** @return array{classes_harvested:int,riders_matched:int} */
    public function import(int $showId, int $delayMs = 300): array
    {
        $show = $this->db->one('SELECT id, prop, resultat_status FROM shows WHERE id = ?', [$showId]);
        if (!$show) {
            throw new RuntimeException('Stævne ikke fundet.');
        }
        if ($show['resultat_status'] !== 'Resultatbehandling færdig') {
            throw new RuntimeException(
                'Stævnet har ikke status "Resultatbehandling færdig" endnu (er: '
                . ($show['resultat_status'] ?? 'ukendt - hent klassedetaljer først') . ').'
            );
        }

        $classes = $this->db->all(
            'SELECT id, drf_class_id FROM classes WHERE show_id = ? AND drf_class_id IS NOT NULL',
            [$showId]
        );
        if (!$classes) {
            throw new RuntimeException('Ingen klasser med DRF-SectionId - hent klassedetaljer fra DRF først.');
        }

        $base = $GLOBALS['config']['drf_class_result_url']
            ?? 'https://rideforbund.dk/go/resultater-ranglister/staevneresultat/klasseresultat';

        $ridersMatched = 0;
        foreach ($classes as $i => $class) {
            $url = $base . '?EventId=' . rawurlencode($show['prop']) . '&SectionId=' . rawurlencode($class['drf_class_id']);
            $html = $this->fetchUrl($url);
            $riders = $this->parse($html);

            foreach ($riders as $r) {
                $riderId = $this->upsertRider($r['drf_rider_id'], $r['navn']);
                $this->db->run(
                    'INSERT IGNORE INTO class_riders (class_id, rider_id) VALUES (?, ?)',
                    [$class['id'], $riderId]
                );
                $ridersMatched++;
            }

            if ($i < count($classes) - 1 && $delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

        $this->db->run('UPDATE shows SET riders_harvested_at = NOW() WHERE id = ?', [$showId]);

        return ['classes_harvested' => count($classes), 'riders_matched' => $ridersMatched];
    }

    private function upsertRider(string $drfRiderId, string $navn): int
    {
        $this->db->run(
            'INSERT INTO riders (drf_rider_id, navn) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE navn = VALUES(navn)',
            [$drfRiderId, $navn]
        );
        return (int)$this->db->scalar('SELECT id FROM riders WHERE drf_rider_id = ?', [$drfRiderId]);
    }

    // ---------------- Hentning ----------------

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

    /** @return array<int,array{drf_rider_id:string,navn:string}> */
    public function parse(string $html): array
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        $xp = new DOMXPath($doc);

        $links = $xp->query("//table[@id='entries']//tr/td[1]//a[contains(@href, 'RiderId=')]");

        $out = [];
        foreach ($links as $a) {
            if (!$a instanceof DOMElement) {
                continue;
            }
            $href = $a->getAttribute('href');
            if (!preg_match('/RiderId=([^&"]+)/', $href, $m)) {
                continue;
            }
            $navn = $this->clean($a->textContent);
            if ($navn === '') {
                continue;
            }
            $out[] = ['drf_rider_id' => $m[1], 'navn' => $navn];
        }
        return $out;
    }

    private function clean(string $s): string
    {
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim($s);
    }
}
