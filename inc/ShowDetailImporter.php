<?php
defined('APP') or die('Direkte adgang ikke tilladt');

/**
 * Høster klassedetaljer (hest/pony, sværhedsgrad) for ét stævne fra Dansk Ride
 * Forbunds stævneresultat-side og gemmer dem på de matchende rækker i classes.
 *
 * Stævnet slås op via shows.prop som EventId (fx "Prop00056809" ->
 * .../staevneresultat?EventId=Prop00056809). Modsat officials/klub-listerne er
 * dette IKKE et fuldt snapshot af hele databasen - det høster ét stævne ad
 * gangen, udløst fra en knap på show.php (eller cli/import_show_details.php
 * til bulk-backfill).
 *
 * HTML-struktur (pr. 2026), pr. klasse:
 *   <tr id="KLA000740913">
 *     ...
 *     <span itemprop="name">1. LD1-LD2 Pony (D)</span>
 *     ...
 *     <p itemprop="description">Dressur: Procentklasse Pony (svh 0) | Max tilmeldinger 999</p>
 *
 * Klassenummeret ("1.") matcher lokalt classes.klassenr's numeriske præfiks -
 * en lokal klasse kan hedde "15B"/"15 C" hvor DRF kun lister "15.", saa alle
 * lokale klasser med samme numeriske præfiks opdateres.
 */
class ShowDetailImporter
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function import(int $showId): array
    {
        $show = $this->db->one('SELECT id, prop FROM shows WHERE id = ?', [$showId]);
        if (!$show) {
            throw new RuntimeException('Stævne ikke fundet.');
        }
        if (stripos($show['prop'], 'Prop') !== 0) {
            throw new RuntimeException('Stævnet har ikke et "Prop..."-id at slå op på DRF med (prop=' . $show['prop'] . ').');
        }

        $base = $GLOBALS['config']['drf_show_url'] ?? 'https://rideforbund.dk/go/resultater-ranglister/staevneresultat';
        $url  = $base . '?EventId=' . rawurlencode($show['prop']);

        $html = $this->fetchUrl($url);
        if ($html === '' || stripos($html, 'event-classes') === false) {
            throw new RuntimeException('Kilden gav ingen genkendelige klassedata (mangler "event-classes"). '
                . 'Er stævnet korrekt? Hentede ' . strlen($html) . ' tegn.');
        }

        $records = $this->parse($html);
        if (!$records) {
            throw new RuntimeException('Kunne ikke udtrække nogen klasser fra siden.');
        }

        // DRF's side kan liste samme klassenummer to gange (fx en gruppeopdelt
        // klasse "14." og "14.-61" for samme klasse) - behold kun første forekomst.
        $records = array_values(array_intersect_key($records, array_unique(array_column($records, 'klassenr'))));

        // Byg opslag: numerisk klassenr-præfiks -> lokale klasse-id'er (kan være
        // flere pr. praefiks, fx 15A/15B/15 C for samme DRF-klasse).
        $localClasses = $this->db->all('SELECT id, klassenr FROM classes WHERE show_id = ?', [$showId]);
        $byNr = [];
        foreach ($localClasses as $c) {
            if (preg_match('/^\s*(\d+)/', (string)$c['klassenr'], $m)) {
                $byNr[$m[1]][] = (int)$c['id'];
            }
        }

        $upd = $this->db->pdo()->prepare(
            'UPDATE classes SET hest_pony = ?, svaerhedsgrad = ?, drf_class_id = ? WHERE id = ?'
        );

        $matched = 0;
        $this->db->begin();
        try {
            foreach ($records as $r) {
                foreach ($byNr[$r['klassenr']] ?? [] as $classId) {
                    $upd->execute([$r['hest_pony'], $r['svaerhedsgrad'], $r['drf_class_id'], $classId]);
                    $matched++;
                }
            }
            $this->db->run('UPDATE shows SET detail_harvested_at = NOW() WHERE id = ?', [$showId]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return [
            'rows_harvested'  => count($records),
            'classes_matched' => $matched,
            'classes_total'   => count($localClasses),
        ];
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

    /** @return array<int,array{klassenr:string,hest_pony:?string,svaerhedsgrad:?int,drf_class_id:string}> */
    public function parse(string $html): array
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        $xp = new DOMXPath($doc);

        $rows = $xp->query("//tr[starts-with(@id, 'KLA')]");

        $out = [];
        foreach ($rows as $row) {
            if (!$row instanceof DOMElement) {
                continue;
            }
            $drfId = $row->getAttribute('id');
            $name  = $this->nodeText($xp, ".//span[@itemprop='name']", $row);
            $desc  = $this->nodeText($xp, ".//p[@itemprop='description']", $row);

            if ($name === '' || !preg_match('/^(\d+)\./', $name, $m)) {
                continue; // ikke en genkendelig klasserække
            }

            $out[] = [
                'klassenr'      => $m[1],
                'hest_pony'     => $this->hestPony($name . ' ' . $desc),
                'svaerhedsgrad' => $this->svaerhedsgrad($desc),
                'drf_class_id'  => $drfId,
            ];
        }
        return $out;
    }

    /** Afled hest/pony/begge af klassenavn+beskrivelse. */
    private function hestPony(string $s): ?string
    {
        $t = mb_strtolower($s, 'UTF-8');
        $hasPony = mb_strpos($t, 'pony') !== false;
        $hasHest = mb_strpos($t, 'hest') !== false;
        if ($hasPony && $hasHest) return 'begge';
        if ($hasPony)             return 'pony';
        if ($hasHest)             return 'hest';
        return null;
    }

    /** Udtrækker "(svh N)" fra beskrivelsen. */
    private function svaerhedsgrad(string $desc): ?int
    {
        return preg_match('/svh\s*(\d+)/i', $desc, $m) ? (int)$m[1] : null;
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
}
