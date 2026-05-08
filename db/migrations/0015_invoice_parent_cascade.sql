-- MyInvoice.cz — ON DELETE CASCADE pro invoices.parent_invoice_id
--
-- Bez CASCADE jakékoliv `cancellation` nebo `credit_note` blokuje smazání původní
-- faktury (FK violation). Po feat-extension delete vystavených/stornovaných faktur
-- (admin only) chceme, aby smazání rodičovské faktury automaticky odstranilo
-- i navázané doklady (storno, dobropis, jejich items + work_reports — vše už
-- má CASCADE směrem dolů).
--
-- Smazání samotného storno / dobropisu rodičovskou fakturu neovlivní
-- (FK je směrem child → parent).
--
-- Idempotentní: information_schema check, ALTER se pustí jen pokud rule není už CASCADE.

SET NAMES utf8mb4;

SET @rule := (
  SELECT DELETE_RULE
    FROM information_schema.REFERENTIAL_CONSTRAINTS
   WHERE CONSTRAINT_SCHEMA = DATABASE()
     AND CONSTRAINT_NAME   = 'fk_inv_parent'
);

SET @sql_drop := IF(@rule IS NOT NULL AND @rule <> 'CASCADE',
  'ALTER TABLE invoices DROP FOREIGN KEY fk_inv_parent',
  'DO 0');
PREPARE stmt FROM @sql_drop; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql_add := IF(@rule IS NOT NULL AND @rule <> 'CASCADE',
  'ALTER TABLE invoices ADD CONSTRAINT fk_inv_parent
     FOREIGN KEY (parent_invoice_id) REFERENCES invoices(id) ON DELETE CASCADE',
  'DO 0');
PREPARE stmt FROM @sql_add; EXECUTE stmt; DEALLOCATE PREPARE stmt;
