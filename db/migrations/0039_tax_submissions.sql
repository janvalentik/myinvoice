-- 0039: Tax submission archive
-- Archivuje každé generování EPO XML (DPH/KH/SH/DPFO/DPPO) s timestamp + status.
-- Slouží pro audit "co bylo kdy podáno" (i když faktické podání proběhne na EPO portálu mimo MyInvoice).

CREATE TABLE IF NOT EXISTS `tax_submissions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `supplier_id` INT UNSIGNED NOT NULL,
    `form_code` VARCHAR(20) NOT NULL COMMENT 'dphdp3|dphkh1|dphshv|dpfdp5|dppdp9',
    `period_year` SMALLINT UNSIGNED NOT NULL,
    `period_month` TINYINT UNSIGNED NULL COMMENT 'NULL pro roční/kvartální výkazy',
    `period_quarter` TINYINT UNSIGNED NULL COMMENT 'NULL pokud měsíční',
    `xml_content` LONGTEXT NOT NULL,
    `xml_size_bytes` INT UNSIGNED NOT NULL,
    `xml_sha256` CHAR(64) NOT NULL,
    `validation_status` ENUM('passed','failed','skipped') NOT NULL DEFAULT 'skipped',
    `validation_errors` JSON NULL,
    `summary_json` JSON NULL COMMENT 'Summary data (counts, totals, deadline) pro UI náhled bez parsování XML',
    `generated_by` INT UNSIGNED NULL COMMENT 'users.id',
    `generated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `notes` TEXT NULL COMMENT 'Volitelné poznámky uživatele po podání',
    PRIMARY KEY (`id`),
    KEY `idx_supplier_form_period` (`supplier_id`, `form_code`, `period_year`, `period_month`, `period_quarter`),
    KEY `idx_generated_at` (`generated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
