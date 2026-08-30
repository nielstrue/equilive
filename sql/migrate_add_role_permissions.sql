-- ============================================================
--  Migrering: rolle-rettigheder flyttes fra hardcodet PHP til databasen,
--  saa de kan redigeres i GUI'et (siden "Brugere" → "Rolle-rettigheder").
--  Koeres EN gang paa en eksisterende Equilive-database, EFTER at
--  migrate_add_user_management.sql allerede er koert.
--
--  NB: tabellen hedder equilive_role_permissions (ikke role_permissions) for
--  aldrig at kunne kollidere med en tabel Prizesim allerede har eller senere
--  opretter i den delte database - se noten i migrate_add_user_management.sql.
-- ============================================================
USE equilive;

CREATE TABLE IF NOT EXISTS equilive_role_permissions (
    role       VARCHAR(20) NOT NULL,
    permission VARCHAR(40) NOT NULL,
    PRIMARY KEY (role, permission)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seedes med de hidtidige hardcodede standardrettigheder (se tidligere
-- role_permissions() i inc/bootstrap.php), saa opgraderingen er ufarlig -
-- ingen bruger mister adgang de havde før migreringen.
INSERT IGNORE INTO equilive_role_permissions (role, permission) VALUES
    ('admin',    'USER_READ'),
    ('admin',    'USER_WRITE'),
    ('admin',    'USER_DELETE'),
    ('admin',    'REPORT_VIEW'),
    ('admin',    'ADMIN_ACCESS'),
    ('editor',   'USER_READ'),
    ('editor',   'USER_WRITE'),
    ('editor',   'REPORT_VIEW'),
    ('user',     'REPORT_VIEW'),
    ('readonly', 'REPORT_VIEW');
