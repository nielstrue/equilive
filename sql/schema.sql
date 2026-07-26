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
    ('club',          1, 'E',   'Klubstævne (E)'),
    ('local',         2, 'D',   'Lokalstævne (D)'),
    ('regional',      3, 'C',   'Landsdelsstævne (C)'),
    ('national',      4, 'B',   'Nationalt stævne (B)'),
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
    drf_listed TINYINT(1)   NOT NULL DEFAULT 0,   -- fundet paa DRF's officielle liste
    PRIMARY KEY (id),
    UNIQUE KEY uq_officials_navn (navn),
    KEY idx_officials_drf (drf_listed)
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

-- ---------- DRF officials-liste (hoestet fra find-dommer) ----------
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

-- ---------- DRF klubliste (hoestet fra find-klubber) ----------
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
