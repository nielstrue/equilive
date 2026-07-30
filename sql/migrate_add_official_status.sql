-- ============================================================
--  Migrering: status på officials (aktiv/ikke_aktiv/kun_e_niveau)
--  Koeres EN gang paa en eksisterende Equilive-database.
--
--  Har du allerede kørt en tidligere udgave af denne fil, saa kør i stedet
--  den relevante opfoelgende migrering:
--    - 'aktiv'/'stoppet'/'afdoed'        -> sql/migrate_simplify_official_status.sql
--    - 'aktiv'/'ikke_aktiv' (uden E-niveau) -> sql/migrate_add_official_e_niveau_status.sql
-- ============================================================
USE equilive;

ALTER TABLE officials
    ADD COLUMN status ENUM('aktiv','ikke_aktiv','kun_e_niveau') NOT NULL DEFAULT 'aktiv' AFTER navn;
ALTER TABLE officials ADD INDEX idx_officials_status (status);
