-- ============================================================
--  Migrering: klubbens postnummer (clubs.postnr)
--  Udfyldes fra DRF's klubliste (find-klubber), analogt til clubs.distrikt -
--  se DrfClubImporter.
--  Koeres EN gang paa en eksisterende Equilive-database.
-- ============================================================
USE equilive;

ALTER TABLE clubs ADD COLUMN postnr VARCHAR(12) NULL AFTER distrikt;
