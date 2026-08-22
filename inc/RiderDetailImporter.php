<?php
defined('APP') or die('Direkte adgang ikke tilladt');

/**
 * Høster supplerende data (rytterlicens, rytterkategorier) for én rytter fra
 * DRF's rytterprofil-side og gemmer dem i rider_licenses/rider_categories.
 *
 * Rytterprofilen kan være langsom at hente, saa dette er tænkt kørt som et
 * batch-job om natten (se cli/import_rider_details.php), et antal ryttere
 * ad gangen styret via en parameter - samme mønster som
 * ShowDetailImporter::harvestBatch(), bare pr. rytter i stedet for pr. stævne.
 *
 * HTML-struktur (pr. 2026), rytterprofilen:
 *   <h3>Rytterlicens</h3>
 *   <table class="table table-condensed">
 *     <tr><th>B-Stævner</th><td>Ikke registreret</td></tr>
 *     <tr><th>C-Stævner</th><td>30-06-2027</td></tr>
 *     ...
 *   <h3>Rytterkategorier</h3>
 *   <table class="table table-condensed">
 *     <tr><th>Spring - Hest</th><td>1</td></tr>
 *
 * Begge tabeller viser kun de rækker rytteren reelt har, saa data gemmes som
 * frie nøgle/værdi-rækker (ligesom role_disciplines) i stedet for faste
 * kolonner.
 */
class RiderDetailImporter
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /** @return array<int,array{id:int,drf_rider_id:string,navn:string}> */
    private function pending(?int $limit, bool $force): array
    {
        $sql = 'SELECT id, drf_rider_id, navn FROM riders'
             . ($force ? '' : ' WHERE detail_harvested_at IS NULL')
             . ' ORDER BY id'
             . ($limit !== null ? ' LIMIT ' . (int)$limit : '');
        return $this->db->all($sql);
    }

    /** Antal ryttere der (endnu) mangler licens/kategori-høstning. */
    public function pendingCount(): int
    {
        return count($this->pending(null, false));
    }

    /**
     * Kør licens/kategori-høstning for op til $limit ryttere, med $delayMs
     * pause mellem hvert DRF-opslag. Antallet ($limit) styres af den der
     * kalder (CLI-parameter til natlig cron, eller admin-batch-siden).
     *
     * @return array{total:int,ok:int,failed:int,rows:array}
     */
    public function harvestBatch(?int $limit, bool $force, int $delayMs = 1500): array
    {
        $riders = $this->pending($limit, $force);

        $result = ['total' => count($riders), 'ok' => 0, 'failed' => 0, 'rows' => []];
        foreach ($riders as $i => $rider) {
            $row = ['rider_id' => (int)$rider['id'], 'navn' => $rider['navn'], 'drf_rider_id' => $rider['drf_rider_id']];
            try {
                $r = $this->import((int)$rider['id']);
                $row['ok'] = true;
                $row['licenses']   = $r['licenses'];
                $row['categories'] = $r['categories'];
                $result['ok']++;
            } catch (Throwable $e) {
                $row['ok'] = false;
                $row['message'] = $e->getMessage();
                $result['failed']++;
            }
            $result['rows'][] = $row;

            if ($i < count($riders) - 1 && $delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }
        return $result;
    }

    /** @return array{licenses:int,categories:int} */
    public function import(int $riderId): array
    {
        $rider = $this->db->one('SELECT id, drf_rider_id FROM riders WHERE id = ?', [$riderId]);
        if (!$rider) {
            throw new RuntimeException('Rytter ikke fundet.');
        }

        $base = $GLOBALS['config']['drf_rider_url'] ?? 'https://rideforbund.dk/go/heste-og-ryttere/find-ryttere/vis-rytter';
        $url  = $base . '?RiderId=' . rawurlencode($rider['drf_rider_id']);

        $html = $this->fetchUrl($url);
        if ($html === '') {
            throw new RuntimeException('Kilden gav intet svar for RiderId=' . $rider['drf_rider_id']);
        }

        $parsed = $this->parse($html);

        $this->db->begin();
        try {
            $this->db->run('DELETE FROM rider_licenses WHERE rider_id = ?', [$riderId]);
            foreach ($parsed['licenses'] as $l) {
                $this->db->run(
                    'INSERT INTO rider_licenses (rider_id, type, vaerdi) VALUES (?, ?, ?)',
                    [$riderId, $l['type'], $l['vaerdi']]
                );
            }

            $this->db->run('DELETE FROM rider_categories WHERE rider_id = ?', [$riderId]);
            foreach ($parsed['categories'] as $c) {
                $this->db->run(
                    'INSERT INTO rider_categories (rider_id, kategori, vaerdi) VALUES (?, ?, ?)',
                    [$riderId, $c['kategori'], $c['vaerdi']]
                );
            }

            $this->db->run('UPDATE riders SET detail_harvested_at = NOW() WHERE id = ?', [$riderId]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return ['licenses' => count($parsed['licenses']), 'categories' => count($parsed['categories'])];
    }

    // ---------------- Hentning ----------------

    private function fetchUrl(string $url): string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => 60,
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
            'timeout' => 60,
            'header'  => "User-Agent: Mozilla/5.0 (compatible; EquiliveBot/1.0)\r\n",
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            throw new RuntimeException('Kunne ikke hente URL (aktivér curl eller allow_url_fopen): ' . $url);
        }
        return $body;
    }

    // ---------------- Parsing ----------------

    /** @return array{licenses:array<int,array{type:string,vaerdi:string}>,categories:array<int,array{kategori:string,vaerdi:string}>} */
    public function parse(string $html): array
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        $xp = new DOMXPath($doc);

        return [
            'licenses'   => $this->keyValueRows($xp, 'Rytterlicens', 'type'),
            'categories' => $this->keyValueRows($xp, 'Rytterkategorier', 'kategori'),
        ];
    }

    /** @return array<int,array<string,string>> hver række som [$keyName => ..., 'vaerdi' => ...] */
    private function keyValueRows(DOMXPath $xp, string $heading, string $keyName): array
    {
        $q = "//h3[normalize-space(text())='" . $heading . "']/following-sibling::table[1]//tr";
        $out = [];
        foreach ($xp->query($q) as $tr) {
            if (!$tr instanceof DOMElement) {
                continue;
            }
            $th = $xp->query('.//th', $tr)->item(0);
            $td = $xp->query('.//td', $tr)->item(0);
            if (!$th || !$td) {
                continue;
            }
            $key = $this->clean($th->textContent);
            $val = $this->clean($td->textContent);
            if ($key === '') {
                continue;
            }
            $out[] = [$keyName => $key, 'vaerdi' => $val];
        }
        return $out;
    }

    private function clean(string $s): string
    {
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim($s);
    }
}
