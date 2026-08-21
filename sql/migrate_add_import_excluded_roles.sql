-- ============================================================
--  Migrering: admin-styret liste over roller der skal springes over ved
--  CSV-import (se inc/Importer.php og import_role_exclusions.php).
--  Koeres EN gang paa en eksisterende Equilive-database.
-- ============================================================
USE equilive;

ALTER TABLE imports ADD COLUMN assign_excluded INT NOT NULL DEFAULT 0 AFTER assign_seen;

CREATE TABLE IF NOT EXISTS import_excluded_roles (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    rolle      VARCHAR(60) NOT NULL,
    created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_import_excluded_roles_rolle (rolle)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_danish_ci;
