-- ============================================================
--  Migrering: alias-historik for officials (navneskift)
--  Koeres EN gang paa en eksisterende Equilive-database.
-- ============================================================
USE equilive;

-- Udfyldes automatisk naar to officials flettes (se OfficialMerger), saa
-- personen stadig genkendes under det gamle navn ved fremtidig CSV-import
-- og DRF-matchning. navn er globalt unikt: et navn kan kun vaere alias
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
