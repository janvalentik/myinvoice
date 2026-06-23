-- MyInvoice.cz — whitelist e-mailu přeposílatele přeposlaných (FW) bankovních avíz
--
-- Doplňuje 0116 (allow_forwarded): když je příjem přeposlaných avíz zapnutý,
-- lze omezit, OD KOHO smí přeposlaná avíza chodit (adresa nebo doména přeposílatele).
-- Prázdné = libovolný přeposílatel (banka se pozná z těla). Routing-only kontrola.

ALTER TABLE bank_email_imap_settings
  ADD COLUMN IF NOT EXISTS forwarded_from VARCHAR(190) NULL AFTER allow_forwarded;
