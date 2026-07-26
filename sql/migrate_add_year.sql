-- ============================================================
--  Migrering: tilfoej aarstal til eksisterende Equilive-database
--  Koeres EN gang hvis databasen allerede findes uden 'aar'.
-- ============================================================
USE equilive;

ALTER TABLE shows ADD COLUMN aar SMALLINT NULL AFTER dato;
ALTER TABLE shows ADD INDEX idx_shows_aar (aar);

-- Backfill: udled aar af datoen for allerede importerede staevner.
UPDATE shows SET aar = YEAR(dato) WHERE aar IS NULL AND dato IS NOT NULL;
