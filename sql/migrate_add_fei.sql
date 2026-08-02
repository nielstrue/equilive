-- ============================================================
--  Migrering: FEI officials-liste (fei_officials / fei_official_functions)
--  Forudsætter at tabellerne fei_officials og fei_official_functions
--  allerede findes (se sql/fei_officials_schema.SQL).
--  Koeres EN gang paa en eksisterende Equilive-database.
-- ============================================================
USE equilive;

-- Markering paa den enkelte official: fundet paa FEI's officielle liste?
ALTER TABLE officials ADD COLUMN fei_listed TINYINT(1) NOT NULL DEFAULT 0 AFTER drf_listed;
ALTER TABLE officials ADD INDEX idx_officials_fei (fei_listed);

-- Match mellem en FEI-person og en official (analogt til drf_officials.official_id).
ALTER TABLE fei_officials ADD COLUMN official_id INT UNSIGNED NULL AFTER can_officiate;
ALTER TABLE fei_officials ADD INDEX idx_fei_official (official_id);
ALTER TABLE fei_officials ADD CONSTRAINT fk_fei_official FOREIGN KEY (official_id) REFERENCES officials(id) ON DELETE SET NULL;
