-- ============================================================
--  Migrering: rollebaseret adgang (users.role)
--  Koeres EN gang paa en eksisterende Equilive-database, EFTER at
--  users-tabellen allerede er oprettet.
-- ============================================================
USE equilive;

ALTER TABLE users ADD COLUMN role ENUM('user','admin') NOT NULL DEFAULT 'user' AFTER password_hash;

-- Foerste (aeldste) bruger goeres til admin, saa der er adgang til Import fra start.
-- Roller kan herefter aendres direkte i databasen: UPDATE users SET role='admin' WHERE email='...';
UPDATE users SET role = 'admin' ORDER BY id ASC LIMIT 1;
