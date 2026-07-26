-- ============================================================
--  Migrering: DRF klubliste (find-klubber) + clubs.distrikt
--  Koeres EN gang paa en eksisterende Equilive-database.
-- ============================================================
USE equilive;

-- Distrikt paa den enkelte klub (fra DRF's klubliste).
ALTER TABLE clubs ADD COLUMN distrikt VARCHAR(60) NULL;

-- Hoestet DRF-klubliste. Bruges til at matche/udfylde clubs.distrikt.
CREATE TABLE IF NOT EXISTS drf_clubs (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    navn         VARCHAR(255) NOT NULL,
    navn_norm    VARCHAR(255) NOT NULL,       -- normaliseret navn (til matching)
    forkort      VARCHAR(40)  NULL,           -- DRF's ClubId, matcher clubs.club_key/forkort
    distrikt     VARCHAR(60)  NULL,           -- fx 'Distrikt 03'
    adresse      VARCHAR(255) NULL,
    postnr       VARCHAR(12)  NULL,
    postdistrikt VARCHAR(120) NULL,
    url          VARCHAR(255) NULL,
    club_id      INT UNSIGNED NULL,           -- matchet klub (hvis fundet)
    harvested_at DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_drf_clubs (navn, forkort),
    KEY idx_drf_clubs_norm (navn_norm),
    KEY idx_drf_clubs_club (club_id),
    CONSTRAINT fk_drf_clubs_club FOREIGN KEY (club_id) REFERENCES clubs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_danish_ci;
