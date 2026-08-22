-- ============================================================
--  Migrering: ryttere høstet fra DRF (navn+RiderId pr. klasse, samt
--  supplerende rytterlicens/-kategori fra rytterens DRF-profil).
--  Koeres EN gang paa en eksisterende Equilive-database.
-- ============================================================
USE equilive;

-- Rå DRF-statustekst fra stævneresultat-siden (fx "Resultatbehandling færdig") -
-- rytter-pr.-klasse-høstning (se RiderResultImporter) kræver denne status.
ALTER TABLE shows ADD COLUMN resultat_status     VARCHAR(60) NULL AFTER detail_harvested_at;
ALTER TABLE shows ADD COLUMN riders_harvested_at DATETIME  NULL AFTER resultat_status;

CREATE TABLE IF NOT EXISTS riders (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    drf_rider_id        VARCHAR(20)  NOT NULL,
    navn                VARCHAR(255) NOT NULL,
    detail_harvested_at DATETIME     NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_riders_drf_id (drf_rider_id),
    KEY idx_riders_navn (navn)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_danish_ci;

CREATE TABLE IF NOT EXISTS class_riders (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    class_id   INT UNSIGNED NOT NULL,
    rider_id   INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_class_rider (class_id, rider_id),
    KEY idx_class_riders_rider (rider_id),
    CONSTRAINT fk_class_riders_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    CONSTRAINT fk_class_riders_rider FOREIGN KEY (rider_id) REFERENCES riders(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_danish_ci;

CREATE TABLE IF NOT EXISTS rider_licenses (
    id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    rider_id INT UNSIGNED NOT NULL,
    type     VARCHAR(60) NOT NULL,
    vaerdi   VARCHAR(60) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_rider_license (rider_id, type),
    CONSTRAINT fk_rider_licenses_rider FOREIGN KEY (rider_id) REFERENCES riders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_danish_ci;

CREATE TABLE IF NOT EXISTS rider_categories (
    id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    rider_id INT UNSIGNED NOT NULL,
    kategori VARCHAR(60) NOT NULL,
    vaerdi   VARCHAR(20) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_rider_category (rider_id, kategori),
    CONSTRAINT fk_rider_categories_rider FOREIGN KEY (rider_id) REFERENCES riders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_danish_ci;
