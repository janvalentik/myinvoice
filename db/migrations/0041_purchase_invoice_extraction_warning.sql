-- MyInvoice.cz — Diagnostické varování po AI extrakci přijaté faktury
--
-- Když AI (Claude) extrahuje data z PDF, občas chybně započítá subtotalové řádky
-- (např. "Celkem Práce", "Mezisoučet") jako další položky, nebo halucinuje qty/cenu
-- aby suma seděla na falešný total z mezisoučtu sekce. Typický příklad: faktury
-- od NC Auto s.r.o. (BMW Service) — strukturované do skupin prací s vlastními
-- mezisoučty.
--
-- Sanity check v AiPdfExtractor::createDraft() porovná `Σ(qty × unit_price)` proti
-- AI-vrácenému `total_without_vat`. Pokud relativní rozdíl > 2 %, zapíše textový
-- popis do tohoto sloupce; UI ho zobrazí jako žluté upozornění v editaci/detailu.
--
-- Idempotentní: ADD COLUMN IF NOT EXISTS (MariaDB 10.6+ native).

SET NAMES utf8mb4;

ALTER TABLE purchase_invoices
    ADD COLUMN IF NOT EXISTS extraction_warning TEXT NULL
        COMMENT 'Diagnostické varování po AI extrakci (např. AI sečetla mezisoučty jako další položky). NULL = OK.';
