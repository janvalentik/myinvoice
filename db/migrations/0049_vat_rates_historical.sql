-- MyInvoice.cz — historické snížené sazby DPH (issue #36, Pavel Třešňák)
--
-- vat_rates seedoval jen aktuální sazby (CZ-21 / CZ-12 / CZ-0 / CZ-RC, valid_from
-- 2024-01-01). Doklady před 2024 se sníženou sazbou tak nešlo zadat/naimportovat se
-- správnou sazbou (purchase_invoice_items.vat_rate_id je NOT NULL + validace proti
-- vatRateMap) → nouzově jako 21 %, což nadhodnotí odpočet (typicky periodika 10 %).
--
-- Schéma to už podporuje (valid_from/valid_to) — doplňujeme jen seed. Report buildery
-- historické sazby zvládají od opravy #35. Potřeba hlavně pro migraci historických dat.
--
-- Idempotentní: code je UNIQUE → ON DUPLICATE KEY UPDATE (re-runnable).

SET NAMES utf8mb4;

INSERT INTO vat_rates
    (code, rate_percent, country, label_cs, label_en, is_default, is_reverse_charge, valid_from, valid_to, display_order)
VALUES
    ('CZ-15', 15.00, 'CZ', 'Snížená 15 % (2013–2023)', 'Reduced 15 % (2013–2023)', 0, 0, '2013-01-01', '2023-12-31', 22),
    ('CZ-10', 10.00, 'CZ', 'Snížená 10 % (od 2015)',   'Reduced 10 % (since 2015)', 0, 0, '2015-01-01', '2023-12-31', 21)
ON DUPLICATE KEY UPDATE
    rate_percent  = VALUES(rate_percent),
    valid_from    = VALUES(valid_from),
    valid_to      = VALUES(valid_to),
    label_cs      = VALUES(label_cs),
    label_en      = VALUES(label_en),
    display_order = VALUES(display_order);
