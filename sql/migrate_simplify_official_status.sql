-- ============================================================
--  Migrering: forenkl officials.status til kun 'aktiv'/'ikke_aktiv'
--  Koeres EN gang paa en eksisterende Equilive-database, EFTER at
--  migrate_add_official_status.sql allerede er kørt.
-- ============================================================
USE equilive;

-- Udvid midlertidigt enum'en saa 'ikke_aktiv' er gyldig ved siden af de gamle vaerdier.
ALTER TABLE officials
    MODIFY COLUMN status ENUM('aktiv','stoppet','afdoed','ikke_aktiv') NOT NULL DEFAULT 'aktiv';

-- Saml "stoppet" og "afdoed" til det nye faelles "ikke_aktiv".
UPDATE officials SET status = 'ikke_aktiv' WHERE status IN ('stoppet', 'afdoed');

-- Indsnaevr enum'en til de to endelige vaerdier.
ALTER TABLE officials
    MODIFY COLUMN status ENUM('aktiv','ikke_aktiv') NOT NULL DEFAULT 'aktiv';
