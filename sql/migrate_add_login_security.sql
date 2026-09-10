-- ============================================================
--  Migrering: rate limiting, konto-lockout og session-timeout på login.
--  Koeres EN gang paa en eksisterende Equilive-database, EFTER at
--  migrate_add_user_management.sql allerede er koert (equilive_user_state findes).
--
--  Session-timeout kræver ingen skemaændring (håndteres i selve sessionen,
--  se require_login() i inc/bootstrap.php).
-- ============================================================
USE equilive;

ALTER TABLE equilive_user_state
    ADD COLUMN failed_login_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER login_count,
    ADD COLUMN locked_until       DATETIME NULL AFTER failed_login_count;

CREATE TABLE IF NOT EXISTS login_attempts (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ip         VARCHAR(45)  NOT NULL,
    email      VARCHAR(190) NULL,
    success    TINYINT(1)   NOT NULL DEFAULT 0,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_login_attempts_ip (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
