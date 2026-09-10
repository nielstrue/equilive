<?php
defined('APP') or die('Direkte adgang ikke tilladt');

/**
 * To-faktor login (MFA): TOTP som primær metode (kompatibel med Microsoft
 * Authenticator, Google Authenticator m.fl. - se inc/Totp.php), genoprettelses-
 * koder til hvis telefonen er væk, og email-engangskode som fallback hvis
 * authenticator-appen ikke er tilgængelig.
 *
 * Frivilligt pr. bruger - aktiveres kun når brugeren selv beder om det via
 * mfa_setup.php, ikke tvunget af en admin. Al tilstand ligger i
 * equilive_user_state og to nye equilive_-tabeller, af samme grund som
 * beskrevet i inc/bootstrap.php's require_login()-note: users deles med
 * Prizesim, og må ikke udvides med Equilive-specifikke kolonner.
 */
class Mfa
{
    public const RECOVERY_CODE_COUNT        = 8;
    public const EMAIL_CODE_TTL_MINUTES     = 10;
    public const EMAIL_CODE_COOLDOWN_SECONDS = 60;
    public const EMAIL_CODE_MAX_ATTEMPTS    = 5;

    /** Equilive-user_state's MFA-felter for én bruger, eller null hvis rækken ikke findes endnu. */
    public static function state(int $userId): ?array
    {
        return db()->one(
            'SELECT mfa_enabled, mfa_secret_encrypted, mfa_enrolled_at, mfa_last_totp_step
             FROM equilive_user_state WHERE user_id = ?',
            [$userId]
        );
    }

    public static function isEnabled(int $userId): bool
    {
        return (bool)(self::state($userId)['mfa_enabled'] ?? false);
    }

    // ---------------- Enrollment (TOTP) ----------------

    /** Starter en ny tilmelding - hemmeligheden er IKKE gemt endnu, kun returneret
     *  til kaldestedet (som bør ligge den i sessionen, se mfa_setup.php). */
    public static function beginEnrollment(string $accountLabel): array
    {
        $secret = Totp::generateSecret();
        return [
            'secret' => $secret,
            'uri'    => Totp::provisioningUri($secret, $accountLabel, 'Equilive'),
        ];
    }

    /** Bekræfter tilmeldingen med en kode fra appen og gemmer hemmeligheden (krypteret). */
    public static function confirmEnrollment(int $userId, string $pendingSecret, string $code): bool
    {
        $step = Totp::verify($pendingSecret, $code);
        if ($step === null) {
            return false;
        }

        ensure_equilive_user_state($userId);
        db()->run(
            'UPDATE equilive_user_state
             SET mfa_enabled = 1, mfa_secret_encrypted = ?, mfa_enrolled_at = NOW(), mfa_last_totp_step = ?
             WHERE user_id = ?',
            [self::encryptSecret($pendingSecret), $step, $userId]
        );
        return true;
    }

    /** Deaktiverer to-faktor login helt og rydder tilknyttede koder. */
    public static function disable(int $userId): void
    {
        db()->run(
            'UPDATE equilive_user_state
             SET mfa_enabled = 0, mfa_secret_encrypted = NULL, mfa_enrolled_at = NULL, mfa_last_totp_step = NULL
             WHERE user_id = ?',
            [$userId]
        );
        db()->run('DELETE FROM equilive_mfa_recovery_codes WHERE user_id = ?', [$userId]);
        db()->run('DELETE FROM equilive_mfa_email_codes WHERE user_id = ?', [$userId]);
    }

    /** Verificerer en TOTP-kode for en allerede tilmeldt bruger (login/mfa_verify.php). */
    public static function verifyTotp(int $userId, string $code): bool
    {
        $row = self::state($userId);
        if (!$row || !$row['mfa_enabled'] || !$row['mfa_secret_encrypted']) {
            return false;
        }

        $secret = self::decryptSecret($row['mfa_secret_encrypted']);
        $step   = Totp::verify($secret, $code, $row['mfa_last_totp_step'] !== null ? (int)$row['mfa_last_totp_step'] : null);
        if ($step === null) {
            return false;
        }

        db()->run('UPDATE equilive_user_state SET mfa_last_totp_step = ? WHERE user_id = ?', [$step, $userId]);
        return true;
    }

    // ---------------- Kryptering af TOTP-hemmeligheden ----------------
    // Kan (i modsætning til et kodeord) ikke hashes, da appen skal kunne
    // genskabe den oprindelige hemmelighed for at beregne koder - krypteres i
    // stedet med en nøgle der KUN ligger i config.php (samme opbevaringssted
    // som appens øvrige hemmeligheder, se config.example.php).

    private static function encryptionKey(): string
    {
        $b64 = $GLOBALS['config']['mfa_encryption_key'] ?? '';
        $key = $b64 !== '' ? sodium_base642bin($b64, SODIUM_BASE64_VARIANT_ORIGINAL) : '';
        if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('Ugyldig eller manglende config["mfa_encryption_key"] - se config.example.php.');
        }
        return $key;
    }

    private static function encryptSecret(string $secret): string
    {
        $key = self::encryptionKey();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($secret, $nonce, $key);
        return base64_encode($nonce . $ciphertext);
    }

    private static function decryptSecret(string $encrypted): string
    {
        $key = self::encryptionKey();
        $decoded = base64_decode($encrypted, true);
        if ($decoded === false) {
            throw new RuntimeException('Ugyldig krypteret MFA-hemmelighed.');
        }
        $nonceLen = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        $nonce = substr($decoded, 0, $nonceLen);
        $ciphertext = substr($decoded, $nonceLen);
        $secret = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
        if ($secret === false) {
            throw new RuntimeException('Kunne ikke dekryptere MFA-hemmeligheden (forkert mfa_encryption_key?).');
        }
        return $secret;
    }

    // ---------------- Genoprettelseskoder ----------------

    /** Genererer nye genoprettelseskoder (ugyldiggør evt. gamle) - returnerer klartekst ÉN gang. */
    public static function generateRecoveryCodes(int $userId): array
    {
        db()->run('DELETE FROM equilive_mfa_recovery_codes WHERE user_id = ?', [$userId]);

        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            $codes[] = self::formatRecoveryCode(bin2hex(random_bytes(5)));
        }
        foreach ($codes as $code) {
            db()->run(
                'INSERT INTO equilive_mfa_recovery_codes (user_id, code_hash) VALUES (?, ?)',
                [$userId, password_hash($code, PASSWORD_DEFAULT)]
            );
        }
        return $codes;
    }

    private static function formatRecoveryCode(string $hex): string
    {
        return strtoupper(substr($hex, 0, 5) . '-' . substr($hex, 5, 5));
    }

    public static function recoveryCodesRemaining(int $userId): int
    {
        return (int)db()->scalar(
            'SELECT COUNT(*) FROM equilive_mfa_recovery_codes WHERE user_id = ? AND used_at IS NULL',
            [$userId]
        );
    }

    /** Bruger én genoprettelseskode (engangsbrug) - normaliserer input (mellemrum/store/små bogstaver). */
    public static function verifyRecoveryCode(int $userId, string $code): bool
    {
        $code = strtoupper(trim($code));
        $rows = db()->all(
            'SELECT id, code_hash FROM equilive_mfa_recovery_codes WHERE user_id = ? AND used_at IS NULL',
            [$userId]
        );
        foreach ($rows as $row) {
            if (password_verify($code, $row['code_hash'])) {
                db()->run('UPDATE equilive_mfa_recovery_codes SET used_at = NOW() WHERE id = ?', [$row['id']]);
                return true;
            }
        }
        return false;
    }

    // ---------------- Email-engangskode (fallback) ----------------

    /** Sender en ny engangskode pr. email - returnerer en fejlbesked, eller null hvis den blev sendt. */
    public static function sendEmailCode(int $userId, string $email, string $navn): ?string
    {
        $lastSentAt = db()->scalar(
            'SELECT created_at FROM equilive_mfa_email_codes WHERE user_id = ? ORDER BY created_at DESC LIMIT 1',
            [$userId]
        );
        if ($lastSentAt && (time() - strtotime($lastSentAt)) < self::EMAIL_CODE_COOLDOWN_SECONDS) {
            return 'Vent lidt før du beder om en ny kode.';
        }

        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $mailError = Mailer::sendMfaCode($email, $navn, $code);
        if ($mailError !== null) {
            return $mailError;
        }

        db()->run(
            'INSERT INTO equilive_mfa_email_codes (user_id, code_hash, expires_at)
             VALUES (?, ?, NOW() + INTERVAL ? MINUTE)',
            [$userId, password_hash($code, PASSWORD_DEFAULT), self::EMAIL_CODE_TTL_MINUTES]
        );
        return null;
    }

    public static function verifyEmailCode(int $userId, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code);
        $row = db()->one(
            'SELECT id, code_hash FROM equilive_mfa_email_codes
             WHERE user_id = ? AND consumed_at IS NULL AND expires_at > NOW() AND attempts < ?
             ORDER BY created_at DESC LIMIT 1',
            [$userId, self::EMAIL_CODE_MAX_ATTEMPTS]
        );
        if (!$row) {
            return false;
        }

        db()->run('UPDATE equilive_mfa_email_codes SET attempts = attempts + 1 WHERE id = ?', [$row['id']]);
        if (!password_verify($code, $row['code_hash'])) {
            return false;
        }

        db()->run('UPDATE equilive_mfa_email_codes SET consumed_at = NOW() WHERE id = ?', [$row['id']]);
        return true;
    }
}
