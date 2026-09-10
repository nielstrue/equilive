-- ============================================================
--  Migrering: to-faktor login (TOTP, kompatibel med Microsoft Authenticator
--  m.fl.), genoprettelseskoder og email-fallback.
--  Koeres EN gang paa en eksisterende Equilive-database, EFTER at
--  migrate_add_login_security.sql allerede er koert.
--
--  Kræver desuden config['mfa_encryption_key'] i config.php - se
--  config.example.php for hvordan den genereres.
-- ============================================================
USE equilive;

ALTER TABLE equilive_user_state
    ADD COLUMN mfa_enabled          TINYINT(1) NOT NULL DEFAULT 0 AFTER locked_until,
    ADD COLUMN mfa_secret_encrypted TEXT NULL AFTER mfa_enabled,
    ADD COLUMN mfa_enrolled_at      DATETIME NULL AFTER mfa_secret_encrypted,
    ADD COLUMN mfa_last_totp_step   BIGINT UNSIGNED NULL AFTER mfa_enrolled_at;

CREATE TABLE IF NOT EXISTS equilive_mfa_recovery_codes (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NOT NULL,
    code_hash  VARCHAR(255) NOT NULL,
    used_at    DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_mfa_recovery_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS equilive_mfa_email_codes (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NOT NULL,
    code_hash   VARCHAR(255) NOT NULL,
    expires_at  DATETIME NOT NULL,
    attempts    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    consumed_at DATETIME NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_mfa_email_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
