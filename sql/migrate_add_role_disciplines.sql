-- ============================================================
--  Migrering: rollekatalog med disciplin-tilknytning
--  Koeres EN gang paa en eksisterende Equilive-database.
--
--  assignments.rolle forbliver fritekst (uaendret) - roles er et
--  opslagskatalog der matches på navn, saa den eksisterende rolle-editor
--  (class.php) ikke skal aendres. Roller uden nogen række i
--  role_disciplines OG alle_discipliner=0 er blot endnu ikke klassificeret.
-- ============================================================
USE equilive;

CREATE TABLE IF NOT EXISTS roles (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    navn             VARCHAR(60)  NOT NULL,          -- matcher assignments.rolle
    alle_discipliner TINYINT(1)   NOT NULL DEFAULT 0, -- rollen gælder alle discipliner
    PRIMARY KEY (id),
    UNIQUE KEY uq_roles_navn (navn)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_danish_ci;

-- En rolle kan tilhøre flere discipliner (fx en steward der bruges til både
-- dressur og springning) - derfor en separat mange-til-mange-tabel i stedet
-- for én disciplin-kolonne på roles.
CREATE TABLE IF NOT EXISTS role_disciplines (
    role_id   INT UNSIGNED NOT NULL,
    disciplin VARCHAR(40)  NOT NULL,
    PRIMARY KEY (role_id, disciplin),
    CONSTRAINT fk_role_disc_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_danish_ci;

-- ---------- Katalogisér alle roller der allerede bruges ----------
INSERT INTO roles (navn)
SELECT DISTINCT rolle FROM assignments
ON DUPLICATE KEY UPDATE navn = VALUES(navn);

-- ---------- Best-effort startgaet (kan rettes under "Roller" i appen) ----------

-- Rent dressur-specifikke roller.
UPDATE roles SET alle_discipliner = 0
WHERE navn IN ('dressage_judge', 'style_judge');
INSERT INTO role_disciplines (role_id, disciplin)
SELECT id, 'dressage' FROM roles WHERE navn IN ('dressage_judge', 'style_judge');

-- Rent springnings-specifikke roller (inkl. banedesign, som er et springningsbegreb).
UPDATE roles SET alle_discipliner = 0
WHERE navn IN ('show_jumping_judge', 'course_designer', 'course_designer_assistant',
               'show_jumping_course_designer', 'water_jump_judge');
INSERT INTO role_disciplines (role_id, disciplin)
SELECT id, 'show_jumping' FROM roles
WHERE navn IN ('show_jumping_judge', 'show_jumping_course_designer', 'water_jump_judge');

-- Banedesign-roller uden discipline-praefiks bruges baade til springning og military
-- (terrænbane), saa de faar begge.
INSERT INTO role_disciplines (role_id, disciplin)
SELECT id, 'show_jumping' FROM roles WHERE navn IN ('course_designer', 'course_designer_assistant');
INSERT INTO role_disciplines (role_id, disciplin)
SELECT id, 'eventing' FROM roles WHERE navn IN ('course_designer', 'course_designer_assistant',
                                                 'cross_country_course_designer');

-- Distance (endurance): "controller" er FEI's betegnelse for vet-gate-kontrollør.
UPDATE roles SET alle_discipliner = 0 WHERE navn = 'controller';
INSERT INTO role_disciplines (role_id, disciplin)
SELECT id, 'endurance' FROM roles WHERE navn = 'controller';

-- Alt andet (dommerkomité, stewards, ledelse, dyrlæger m.fl.) er generiske
-- FEI-/DRF-funktioner der bruges på tværs af discipliner - default er allerede 0/false
-- ovenfor, saa vi saetter dem eksplicit til "alle discipliner".
UPDATE roles SET alle_discipliner = 1
WHERE navn NOT IN (
    'dressage_judge', 'style_judge',
    'show_jumping_judge', 'course_designer', 'course_designer_assistant',
    'show_jumping_course_designer', 'water_jump_judge', 'cross_country_course_designer',
    'controller'
);
