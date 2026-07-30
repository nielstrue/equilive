-- ============================================================
--  Equilive - databaseskema (MySQL / MariaDB, WAMP)
--  Normaliseret model bygget til danske ridestævner (DRF).
--  Tegnsæt: utf8mb4 (understøtter æøå og specialtegn i klassenavne)
-- ============================================================
--  Kør denne fil én gang for at oprette databasen og tabellerne,
--  fx via phpMyAdmin eller:  mysql -u root < sql/schema.sql
-- ============================================================

CREATE DATABASE IF NOT EXISTS equilive
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_danish_ci;

USE equilive;

-- ---------- Opslagstabel: niveauer ----------
CREATE TABLE IF NOT EXISTS levels (
    slug   VARCHAR(20)  NOT NULL,
    `rank` TINYINT      NOT NULL,
    code   VARCHAR(4)   NOT NULL,
    label  VARCHAR(40)  NOT NULL,
    PRIMARY KEY (slug),
    UNIQUE KEY uq_levels_rank (`rank`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_danish_ci;

INSERT INTO levels (slug, `rank`, code, label) VALUES
    ('club',          1, 'E',   'Rideskolestævne (E)'),
    ('local',         2, 'D',   'Klubstævne (D)'),
    ('regional',      3, 'C',   'Distriktsstævne (C)'),
    ('national',      4, 'B',   'Landsstævne (B)'),
    ('elite',         5, 'A',   'Elitestævne (A)'),
    ('international',  6, 'FEI', 'Internationalt stævne (FEI)')
ON DUPLICATE KEY UPDATE `rank`=VALUES(`rank`), code=VALUES(code), label=VALUES(label);

-- ---------- Klubber ----------
CREATE TABLE IF NOT EXISTS clubs (
    id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    club_key  VARCHAR(120) NOT NULL,
    forkort   VARCHAR(40)  NULL,
    navn      VARCHAR(255) NOT NULL,
    distrikt  VARCHAR(60)  NULL,             -- fra DRF's klubliste (find-klubber)
    PRIMARY KEY (id),
    UNIQUE KEY uq_clubs_key (club_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_danish_ci;

-- ---------- Stævner ----------
CREATE TABLE IF NOT EXISTS shows (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    natural_key  CHAR(40)     NOT NULL,
    prop         VARCHAR(40)  NOT NULL,
    club_id      INT UNSIGNED NULL,
    dato         DATE         NULL,
    aar          SMALLINT     NULL,          -- saeson/aar (fra filnavn officials_YYYY eller dato)
    disciplin    VARCHAR(40)  NULL,
    top_slug     VARCHAR(20)  NULL,
    top_rank     TINYINT      NULL,
    top_code     VARCHAR(4)   NULL,
    has_lower    TINYINT(1)   NOT NULL DEFAULT 0,
    prop_unknown TINYINT(1)   NOT NULL DEFAULT 0,
    detail_harvested_at DATETIME NULL,     -- sidst hentet klassedetaljer (hest/pony, svh) fra DRF
    PRIMARY KEY (id),
    UNIQUE KEY uq_shows_natkey (natural_key),
    KEY idx_shows_prop (prop),
    KEY idx_shows_aar (aar),
    KEY idx_shows_club (club_id),
    CONSTRAINT fk_shows_club FOREIGN KEY (club_id) REFERENCES clubs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_danish_ci;

-- ---------- Klasser ----------
CREATE TABLE IF NOT EXISTS classes (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    natural_key    CHAR(40)     NOT NULL,
    show_id        INT UNSIGNED NOT NULL,
    klassenr       VARCHAR(40)  NULL,
    klassenavn     VARCHAR(500) NOT NULL,
    disciplin      VARCHAR(40)  NULL,
    niveau_slug    VARCHAR(20)  NULL,
    starter        INT          NULL,
    stilspringning TINYINT(1)   NOT NULL DEFAULT 0,
    hest_pony      VARCHAR(10)  NULL,      -- 'hest' | 'pony' | 'begge' (fra DRF-stævneresultat)
    svaerhedsgrad  TINYINT UNSIGNED NULL,  -- "svh N" fra DRF-stævneresultat
    drf_class_id   VARCHAR(20)  NULL,      -- DRF's SectionId (KLA...), til robust genhøstning
    PRIMARY KEY (id),
    UNIQUE KEY uq_classes_natkey (natural_key),
    KEY idx_classes_show (show_id),
    KEY idx_classes_niveau (niveau_slug),
    CONSTRAINT fk_classes_show  FOREIGN KEY (show_id)     REFERENCES shows(id) ON DELETE CASCADE,
    CONSTRAINT fk_classes_level FOREIGN KEY (niveau_slug) REFERENCES levels(slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_danish_ci;

-- ---------- Officials (personer) ----------
CREATE TABLE IF NOT EXISTS officials (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    navn       VARCHAR(255) NOT NULL,
    status     ENUM('aktiv','ikke_aktiv','kun_e_niveau','fei_official') NOT NULL DEFAULT 'aktiv', -- sættes manuelt: alder/dødsfald, uuddannet klubdommer (kun E-niveau), hhv. FEI-official uden match paa DRF-listen
    drf_listed TINYINT(1)   NOT NULL DEFAULT 0,   -- fundet paa DRF's officielle liste
    PRIMARY KEY (id),
    UNIQUE KEY uq_officials_navn (navn),
    KEY idx_officials_drf (drf_listed),
    KEY idx_officials_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_danish_ci;

-- ---------- Officials - tidligere navne (alias-historik) ----------
-- Udfyldes automatisk naar to officials flettes (se OfficialMerger), saa
-- personen stadig genkendes under det gamle navn ved fremtidig CSV-import
-- og DRF-matchning (fx efter et navneskift som "Jens Hansen" -> "Jens
-- Fidipus Hansen"). navn er globalt unikt: et navn kan kun vaere alias
-- for én official.
CREATE TABLE IF NOT EXISTS official_aliases (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    official_id INT UNSIGNED NOT NULL,
    navn        VARCHAR(255) NOT NULL,
    created_at  DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_official_alias_navn (navn),
    KEY idx_official_alias_official (official_id),
    CONSTRAINT fk_official_alias FOREIGN KEY (official_id) REFERENCES officials(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_danish_ci;

-- ---------- Tildelinger ----------
CREATE TABLE IF NOT EXISTS assignments (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    class_id    INT UNSIGNED NOT NULL,
    official_id INT UNSIGNED NOT NULL,
    rolle       VARCHAR(60)  NOT NULL,
    nummer      VARCHAR(40)  NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_assign (class_id, official_id, rolle),
    KEY idx_assign_official (official_id),
    KEY idx_assign_class (class_id),
    CONSTRAINT fk_assign_class    FOREIGN KEY (class_id)    REFERENCES classes(id)   ON DELETE CASCADE,
    CONSTRAINT fk_assign_official FOREIGN KEY (official_id) REFERENCES officials(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_danish_ci;

-- ---------- Rollekatalog (rolle -> disciplin) ----------
-- assignments.rolle forbliver fritekst; roles er et opslagskatalog der
-- matches paa navn, saa den frie rolle-editor (class.php) er upaavirket.
CREATE TABLE IF NOT EXISTS roles (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    navn             VARCHAR(60)  NOT NULL,           -- matcher assignments.rolle
    alle_discipliner TINYINT(1)   NOT NULL DEFAULT 0, -- rollen gælder alle discipliner
    PRIMARY KEY (id),
    UNIQUE KEY uq_roles_navn (navn)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_danish_ci;

-- En rolle kan tilhøre flere discipliner (fx en steward brugt til både dressur
-- og springning), derfor en mange-til-mange-tabel i stedet for én kolonne.
CREATE TABLE IF NOT EXISTS role_disciplines (
    role_id   INT UNSIGNED NOT NULL,
    disciplin VARCHAR(40)  NOT NULL,
    PRIMARY KEY (role_id, disciplin),
    CONSTRAINT fk_role_disc_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_danish_ci;

-- ---------- DRF officials-liste (høstet fra find-dommer) ----------
CREATE TABLE IF NOT EXISTS drf_officials (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    navn         VARCHAR(255) NOT NULL,
    navn_norm    VARCHAR(255) NOT NULL,
    kategori     VARCHAR(40)  NULL,
    type         VARCHAR(160) NOT NULL,
    distrikt     VARCHAR(60)  NULL,
    postnr       VARCHAR(12)  NULL,
    postdistrikt VARCHAR(120) NULL,
    tlf          VARCHAR(40)  NULL,
    email        VARCHAR(160) NULL,
    official_id  INT UNSIGNED NULL,
    harvested_at DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_drf (navn, type, distrikt),
    KEY idx_drf_norm (navn_norm),
    KEY idx_drf_official (official_id),
    KEY idx_drf_kategori (kategori),
    CONSTRAINT fk_drf_official FOREIGN KEY (official_id) REFERENCES officials(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_danish_ci;

-- ---------- DRF klubliste (høstet fra find-klubber) ----------
CREATE TABLE IF NOT EXISTS drf_clubs (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    navn         VARCHAR(255) NOT NULL,
    navn_norm    VARCHAR(255) NOT NULL,
    forkort      VARCHAR(40)  NULL,
    distrikt     VARCHAR(60)  NULL,
    adresse      VARCHAR(255) NULL,
    postnr       VARCHAR(12)  NULL,
    postdistrikt VARCHAR(120) NULL,
    url          VARCHAR(255) NULL,
    club_id      INT UNSIGNED NULL,
    harvested_at DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_drf_clubs (navn, forkort),
    KEY idx_drf_clubs_norm (navn_norm),
    KEY idx_drf_clubs_club (club_id),
    CONSTRAINT fk_drf_clubs_club FOREIGN KEY (club_id) REFERENCES clubs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_danish_ci;

-- ---------- Importlog ----------
CREATE TABLE IF NOT EXISTS imports (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    filename     VARCHAR(255) NULL,
    imported_at  DATETIME     NOT NULL,
    rows_total   INT          NOT NULL DEFAULT 0,
    rows_skipped INT          NOT NULL DEFAULT 0,
    assign_new   INT          NOT NULL DEFAULT 0,
    assign_seen  INT          NOT NULL DEFAULT 0,
    note         VARCHAR(255) NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_danish_ci;


CREATE TABLE IF NOT EXISTS  users (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `address` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `role` enum('user','admin') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'user',
  `is_active` tinyint(1) NOT NULL DEFAULT '0',
  `activated_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
