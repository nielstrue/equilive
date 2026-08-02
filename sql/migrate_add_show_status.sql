-- ============================================================
--  Migrering: livscyklus-status paa stævner (shows.status)
--  Et stævne kan saettes til 'udelukket' for helt at holde det ude af
--  statistikker, officials-opgørelser, klub-opgørelser mm. - fx et
--  fejlagtigt importeret udenlandsk stævne (se Stats.php).
--  Koeres EN gang paa en eksisterende Equilive-database.
-- ============================================================
USE equilive;

ALTER TABLE shows ADD COLUMN status ENUM('aktiv','udelukket') NOT NULL DEFAULT 'aktiv' AFTER prop_unknown;
ALTER TABLE shows ADD COLUMN status_note VARCHAR(255) NULL AFTER status;
ALTER TABLE shows ADD INDEX idx_shows_status (status);
