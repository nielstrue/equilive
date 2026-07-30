-- ============================================================
--  Migrering: tilføj officials.status = 'fei_official'
--  Koeres EN gang paa en eksisterende Equilive-database.
--
--  Bruges til at markere officials der ikke kan findes paa DRF's liste,
--  men som er FEI-officials (fx internationale dommere/stewards uden
--  dansk DRF-registrering).
-- ============================================================
USE equilive;

ALTER TABLE officials
    MODIFY COLUMN status ENUM('aktiv','ikke_aktiv','kun_e_niveau','fei_official') NOT NULL DEFAULT 'aktiv';
