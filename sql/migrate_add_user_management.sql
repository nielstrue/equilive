-- ============================================================
--  Migrering: brugeradministration (GUI) og RBAC-roller.
--  Koeres EN gang paa en eksisterende Equilive-database, EFTER at
--  migrate_add_user_roles.sql allerede er koert (users.role findes).
--
--  NB: users deles med en anden applikation (Prizesim) i produktion.
--  Denne migrering rører derfor KUN users.role (udvider rolle-listen -
--  eksisterende 'user'/'admin' vaerdier forbliver gyldige og uændrede).
--  Alt Equilive-specifikt (tvunget kodeordsskift, login-log) ligger i den
--  nye tabel equilive_user_state i stedet for som kolonner paa users, saa
--  Prizesim aldrig påvirkes af Equilive's datamodel.
-- ============================================================
USE equilive;

ALTER TABLE users MODIFY COLUMN role ENUM('admin','editor','user','readonly')
    COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'user';

-- Equilive-alene brugertilstand, 1:1 med users.id men UDEN foreign key
-- (samme begrundelse som deleted_assignments.deleted_by - users vedligeholdes
-- delvist af en anden applikation, og en FK ville koble Equilive's skema
-- direkte til noget uden for Equilive's kontrol).
CREATE TABLE IF NOT EXISTS equilive_user_state (
    user_id               INT UNSIGNED NOT NULL,
    must_change_password  TINYINT(1) NOT NULL DEFAULT 0,
    last_login_at         DATETIME NULL,
    login_count           INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
