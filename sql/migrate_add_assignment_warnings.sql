-- ============================================================
--  Migrering: ikke-blokerende advarsler under CSV-import (se
--  Importer::checkAssignmentWarnings()) - fx en rolle der ikke passer til
--  klassens disciplin, eller en official der mangler den DRF-type rollen
--  kræver.
--  Koeres EN gang paa en eksisterende Equilive-database.
-- ============================================================
USE equilive;

ALTER TABLE imports ADD COLUMN warnings_flagged INT NOT NULL DEFAULT 0 AFTER assign_excluded;

CREATE TABLE IF NOT EXISTS role_drf_types (
    role_id  INT UNSIGNED NOT NULL,
    drf_type VARCHAR(120) NOT NULL,
    PRIMARY KEY (role_id, drf_type),
    CONSTRAINT fk_role_drf_type_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_danish_ci;

CREATE TABLE IF NOT EXISTS assignment_warnings (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    assignment_id INT UNSIGNED NOT NULL,
    type          VARCHAR(40)  NOT NULL,
    besked        VARCHAR(255) NOT NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_assignment_warning (assignment_id, type),
    CONSTRAINT fk_assignment_warning FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_danish_ci;
