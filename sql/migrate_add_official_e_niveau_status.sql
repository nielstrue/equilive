-- ============================================================
--  Migrering: ny status "kun_e_niveau" (uuddannet klubdommer, kun E-niveau)
--  Koeres EN gang paa en eksisterende Equilive-database, EFTER at
--  officials.status allerede findes (aktiv/ikke_aktiv).
-- ============================================================
USE equilive;

ALTER TABLE officials
    MODIFY COLUMN status ENUM('aktiv','ikke_aktiv','kun_e_niveau') NOT NULL DEFAULT 'aktiv';

-- Selve klassificeringen (hvilke officials der reelt kun har dømt E-niveau)
-- sættes IKKE automatisk af denne fil - se sql/find_e_niveau_officials.sql
-- for kandidatliste + valgfri UPDATE.
