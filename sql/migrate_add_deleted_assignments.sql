-- ============================================================
--  Migrering: "tombstones" for bevidst slettede tildelinger (se class.php's
--  "Slet"-knap og Importer::isTombstoned()) - forhindrer at en senere import
--  genskaber en tildeling der bevidst er slettet manuelt.
--  Koeres EN gang paa en eksisterende Equilive-database.
-- ============================================================
USE equilive;

ALTER TABLE imports ADD COLUMN assign_deleted_skipped INT NOT NULL DEFAULT 0 AFTER warnings_flagged;

-- deleted_by er bevidst UDEN foreign key mod users - ingen anden tabel
-- referer users i forvejen.
CREATE TABLE IF NOT EXISTS deleted_assignments (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    class_id    INT UNSIGNED NOT NULL,
    official_id INT UNSIGNED NOT NULL,
    orig_rolle  VARCHAR(60)  NOT NULL,
    deleted_by  INT UNSIGNED NULL,
    deleted_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_deleted_assignment (class_id, official_id, orig_rolle),
    KEY idx_deleted_assignment_class (class_id),
    CONSTRAINT fk_deleted_assignment_class    FOREIGN KEY (class_id)    REFERENCES classes(id)   ON DELETE CASCADE,
    CONSTRAINT fk_deleted_assignment_official FOREIGN KEY (official_id) REFERENCES officials(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_danish_ci;
