-- ============================================================
--  Migrering: DRF officials-liste (find-dommer)
--  Koeres EN gang paa en eksisterende Equilive-database.
-- ============================================================
USE equilive;

-- Markering paa den enkelte official: fundet paa DRF's officielle liste?
ALTER TABLE officials ADD COLUMN drf_listed TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE officials ADD INDEX idx_officials_drf (drf_listed);

-- Hoestet DRF-liste. En person kan optraede flere gange (flere typer/distrikter).
CREATE TABLE IF NOT EXISTS drf_officials (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    navn         VARCHAR(255) NOT NULL,
    navn_norm    VARCHAR(255) NOT NULL,       -- normaliseret navn (til matching)
    kategori     VARCHAR(40)  NULL,           -- afledt disciplin/kategori
    type         VARCHAR(160) NOT NULL,       -- fx 'Springdommer - A'
    distrikt     VARCHAR(60)  NULL,           -- fx 'Distrikt 03'
    postnr       VARCHAR(12)  NULL,
    postdistrikt VARCHAR(120) NULL,
    tlf          VARCHAR(40)  NULL,
    email        VARCHAR(160) NULL,
    official_id  INT UNSIGNED NULL,           -- matchet official (hvis fundet)
    harvested_at DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_drf (navn, type, distrikt),
    KEY idx_drf_norm (navn_norm),
    KEY idx_drf_official (official_id),
    KEY idx_drf_kategori (kategori),
    CONSTRAINT fk_drf_official FOREIGN KEY (official_id) REFERENCES officials(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_danish_ci;
