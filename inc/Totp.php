<?php
defined('APP') or die('Direkte adgang ikke tilladt');

/**
 * TOTP (RFC 6238, HOTP-basis RFC 4226) - kompatibel med Microsoft Authenticator,
 * Google Authenticator m.fl. Håndrullet i stedet for et Composer-pakke, da
 * appen ikke i forvejen bruger Composer (se inc/Mailer.php's vendored
 * PHPMailer for samme tilgang) - algoritmen er lille og fast specificeret.
 */
class Totp
{
    private const DIGITS = 6;
    private const STEP   = 30; // sekunder pr. tidsskridt
    private const ALGO   = 'sha1';

    /** Genererer en ny tilfældig hemmelighed, Base32-kodet (til QR/manuel indtastning). */
    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    /** otpauth://-URI til QR-koden - $issuer og $account vises i authenticator-appen. */
    public static function provisioningUri(string $secretBase32, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($account);
        $query = http_build_query([
            'secret'    => $secretBase32,
            'issuer'    => $issuer,
            'algorithm' => 'SHA1',
            'digits'    => self::DIGITS,
            'period'    => self::STEP,
        ], '', '&', PHP_QUERY_RFC3986);
        return 'otpauth://totp/' . $label . '?' . $query;
    }

    /** Den aktuelle (eller et givent tidspunkts) 6-cifrede kode - til test/visning. */
    public static function currentCode(string $secretBase32, ?int $timestamp = null): string
    {
        $step = intdiv($timestamp ?? time(), self::STEP);
        return self::codeForStep($secretBase32, $step);
    }

    /**
     * Verificerer en indtastet kode mod hemmeligheden, med et lille tolerance-
     * vindue for urforskel (± $window tidsskridt). $minStep forhindrer genbrug
     * af en tidligere accepteret kode (replay) - skal være det sidst accepterede
     * tidsskridt for kontoen, eller null første gang. Returnerer det matchede
     * tidsskridt (til at gemme som næste $minStep), eller null hvis koden ikke
     * er gyldig.
     */
    public static function verify(string $secretBase32, string $code, ?int $minStep = null, int $window = 1): ?int
    {
        $code = preg_replace('/\s+/', '', $code);
        if (!preg_match('/^\d{' . self::DIGITS . '}$/', $code)) {
            return null;
        }

        $currentStep = intdiv(time(), self::STEP);
        for ($offset = -$window; $offset <= $window; $offset++) {
            $step = $currentStep + $offset;
            if ($minStep !== null && $step <= $minStep) {
                continue; // allerede brugt (eller ældre end sidst accepterede kode)
            }
            if (hash_equals(self::codeForStep($secretBase32, $step), $code)) {
                return $step;
            }
        }
        return null;
    }

    private static function codeForStep(string $secretBase32, int $step): string
    {
        $key = self::base32Decode($secretBase32);
        $bin = pack('J', $step); // 8-byte big-endian counter (RFC 4226)
        $hash = hash_hmac(self::ALGO, $bin, $key, true);

        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $part = (ord($hash[$offset]) & 0x7F) << 24
              | (ord($hash[$offset + 1]) & 0xFF) << 16
              | (ord($hash[$offset + 2]) & 0xFF) << 8
              | (ord($hash[$offset + 3]) & 0xFF);

        $code = $part % (10 ** self::DIGITS);
        return str_pad((string)$code, self::DIGITS, '0', STR_PAD_LEFT);
    }

    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private static function base32Encode(string $binary): string
    {
        $bits = '';
        foreach (str_split($binary) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $bits = str_pad($bits, (int)ceil(strlen($bits) / 5) * 5, '0', STR_PAD_RIGHT);

        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::BASE32_ALPHABET[bindec($chunk)];
        }
        return $out;
    }

    private static function base32Decode(string $base32): string
    {
        $base32 = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $base32));
        $bits = '';
        foreach (str_split($base32) as $char) {
            $pos = strpos(self::BASE32_ALPHABET, $char);
            if ($pos === false) {
                continue;
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr(bindec($chunk));
            }
        }
        return $bytes;
    }
}
