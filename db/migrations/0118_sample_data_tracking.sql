-- MyInvoice.cz — evidence vygenerovaných ukázkových (sample) dat
--
-- Sample data (5 klientů, zakázky, faktury, dobropisy, dodavatelé, přijaté faktury,
-- pravidelné fakturace, kniha jízd) se generují JEN do prázdné DB. Dosud nebyla nijak
-- označená → nešlo je zpětně odlišit od reálných dat ani cíleně smazat (issue #162).
--
-- Tato tabulka zaznamenává kořenové entity vytvořené generátorem, takže je lze později
-- přesně odebrat („Odebrat ukázková data") a zároveň slouží jako příznak „sample existují"
-- (řídí zobrazení tlačítka v UI). Děti (invoice_items, trips, fuelings, …) se mažou přes
-- FK kaskádu nebo explicitně podle rodiče, proto tu stačí evidovat jen kořeny.
--
-- Idempotence: CREATE TABLE IF NOT EXISTS.

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';

CREATE TABLE IF NOT EXISTS sample_data_entries (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id  INT UNSIGNED NOT NULL,

    -- Logický typ kořenové entity: client | vendor | project | invoice | credit_note
    --                              | purchase_invoice | recurring_template | car
    entity_type  VARCHAR(40) NOT NULL,
    entity_id    BIGINT UNSIGNED NOT NULL,

    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_sde_supplier (supplier_id),
    UNIQUE KEY uq_sde_entity (supplier_id, entity_type, entity_id),

    CONSTRAINT fk_sde_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
