-- ---------------------------------------------------------------
-- FEI officials - schema
-- Generated from the official FEI officials list PDFs (data.fei.org)
-- ---------------------------------------------------------------
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `fei_official_functions`;
DROP TABLE IF EXISTS `fei_officials`;

CREATE TABLE `fei_officials` (
  `fei_id`               BIGINT UNSIGNED NOT NULL COMMENT 'FEI person ID',
  `last_name`            VARCHAR(120)  NOT NULL,
  `first_name`           VARCHAR(120)  NOT NULL DEFAULT '',
  `nf`                   VARCHAR(80)   NOT NULL COMMENT 'National Federation / country',
  `languages_spoken`     VARCHAR(160)  NULL COMMENT 'ISO 639-1 codes, comma separated',
  `languages_understood` VARCHAR(160)  NULL COMMENT 'ISO 639-1 codes, comma separated',
  `can_officiate`        TINYINT(1)    NOT NULL DEFAULT 1
                                       COMMENT '0 = marked * in the FEI list: cannot currently officiate',
  `official_id`          INT UNSIGNED  NULL COMMENT 'matched Equilive official, see FeiOfficialMatcher',
  `updated_at`           TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                       ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`fei_id`),
  KEY `idx_officials_nf`   (`nf`),
  KEY `idx_officials_name` (`last_name`, `first_name`),
  KEY `idx_fei_official`   (`official_id`),
  CONSTRAINT `fk_fei_official` FOREIGN KEY (`official_id`)
    REFERENCES `officials` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `fei_official_functions` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `fei_id`        BIGINT UNSIGNED NOT NULL,
  `discipline`    VARCHAR(40) NOT NULL COMMENT 'Jumping, Eventing, Driving, ...',
  `function_name` VARCHAR(60) NOT NULL COMMENT 'Judge, Course Designer, Steward, ...',
  `level`         VARCHAR(4)  NOT NULL COMMENT 'FEI level: 1-4, or N (national)',
  `status`        ENUM('active','cannot_officiate','assistant_only')
                  NOT NULL DEFAULT 'active'
                  COMMENT '* = cannot currently officiate; ** = assistant only',
  `source_file`   VARCHAR(100) NULL COMMENT 'source PDF',
  `list_date`     DATE         NULL COMMENT 'publication date of the FEI list',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_official_function` (`fei_id`, `discipline`, `function_name`),
  KEY `idx_fn_lookup` (`discipline`, `function_name`, `level`),
  CONSTRAINT `fk_off_fn_official` FOREIGN KEY (`fei_id`)
    REFERENCES `fei_officials` (`fei_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
