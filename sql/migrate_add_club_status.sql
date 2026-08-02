-- ============================================================
--  Migrering: livscyklus-status paa klubber (clubs.status)
--  Mirror af shows.status - lader en klub markeres som ophørt uden at
--  paavirke dens historiske stævnedata/statistik. Navnet kan rettes
--  direkte (ingen alias/historik, jf. ClubMerger).
--  Koeres EN gang paa en eksisterende Equilive-database.
-- ============================================================
USE equilive;

ALTER TABLE clubs ADD COLUMN status ENUM('aktiv','ophoert') NOT NULL DEFAULT 'aktiv' AFTER distrikt;
ALTER TABLE clubs ADD INDEX idx_clubs_status (status);
