-- Sekce Dokumenty — background joby pro rozbalení ZIPu (import) a ZIP export.
-- Rozšiřuje ENUM import_jobs.source o dva nové typy. MODIFY je idempotentní.

ALTER TABLE import_jobs
    MODIFY COLUMN source ENUM(
        'idoklad', 'fakturoid', 'pdf_isdoc_inbox', 'pdf_ai', 'monthly_export',
        'document_zip_import', 'document_zip_export'
    ) NOT NULL;
