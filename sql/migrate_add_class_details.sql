-- ============================================================
--  Migrering: DRF stævneresultat-detaljer (hest/pony, sværhedsgrad)
--  Koeres EN gang paa en eksisterende Equilive-database.
-- ============================================================
USE equilive;

-- Klasse-detaljer hoestet fra DRF's stævneresultat-side (pr. EventId=shows.prop).
ALTER TABLE classes ADD COLUMN hest_pony     VARCHAR(10)      NULL;      -- 'hest' | 'pony' | 'begge'
ALTER TABLE classes ADD COLUMN svaerhedsgrad TINYINT UNSIGNED NULL;      -- "svh N"
ALTER TABLE classes ADD COLUMN drf_class_id  VARCHAR(20)      NULL;      -- DRF's SectionId (KLA...)

-- Tidsstempel for sidste høstning pr. stævne.
ALTER TABLE shows ADD COLUMN detail_harvested_at DATETIME NULL;
