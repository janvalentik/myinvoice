-- Sekce Dokumenty (plán source/11) — úložiště libovolných souborů s hybridní
-- organizací: strom složek + vazba na entity + tagy + fulltext + koš + ZFO/ZIP.
-- Idempotentní (CREATE TABLE/INDEX IF NOT EXISTS). Vše per-supplier.

-- Strom složek (virtuální — soubory leží na disku podle hashe, ne podle stromu).
CREATE TABLE IF NOT EXISTS document_folders (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id BIGINT UNSIGNED NOT NULL,
  parent_id   BIGINT UNSIGNED NULL,          -- NULL = root
  name        VARCHAR(255) NOT NULL,
  created_by  BIGINT UNSIGNED NULL,
  deleted_at  TIMESTAMP NULL,                 -- soft-delete (koš); NULL = aktivní
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_docfld_supplier (supplier_id, parent_id, deleted_at, name),
  CONSTRAINT fk_docfld_parent FOREIGN KEY (parent_id) REFERENCES document_folders(id) ON DELETE CASCADE,
  CONSTRAINT fk_docfld_user   FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dokument = jeden soubor.
CREATE TABLE IF NOT EXISTS documents (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id        BIGINT UNSIGNED NOT NULL,
  folder_id          BIGINT UNSIGNED NULL,
  title              VARCHAR(255) NOT NULL,
  description        TEXT NULL,
  original_name      VARCHAR(255) NOT NULL,
  filename           VARCHAR(255) NOT NULL,        -- {sha8}-{safeName} na disku
  sha256             CHAR(64) NOT NULL,
  mime_type          VARCHAR(100) NOT NULL,
  size_bytes         BIGINT UNSIGNED NOT NULL,
  doc_type           ENUM('pdf','docx','xlsx','xml','zfo','p7s','zip','image','other') NOT NULL DEFAULT 'other',
  source             ENUM('manual','zfo_extract','zip_extract') NOT NULL DEFAULT 'manual',
  parent_document_id BIGINT UNSIGNED NULL,         -- přílohy ZFO → .zfo kontejner
  signature_for_id   BIGINT UNSIGNED NULL,         -- P7S → podepsaný dokument
  content_text       MEDIUMTEXT NULL,              -- extrahovaný text pro fulltext
  text_status        ENUM('none','extracted','unsupported','failed') NOT NULL DEFAULT 'none',
  thumb_path         VARCHAR(255) NULL,            -- náhled (PDF 1. strana / obrázek)
  thumb_status       ENUM('none','generated','unsupported','failed') NOT NULL DEFAULT 'none',
  uploaded_by        BIGINT UNSIGNED NULL,
  deleted_at         TIMESTAMP NULL,               -- soft-delete (koš); NULL = aktivní
  deleted_by         BIGINT UNSIGNED NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_doc_supplier_folder (supplier_id, folder_id, deleted_at, created_at),
  KEY idx_doc_trash (supplier_id, deleted_at),
  KEY idx_doc_parent (parent_document_id),
  KEY idx_doc_sha (supplier_id, sha256),
  FULLTEXT KEY ft_doc_meta (title, description),
  FULLTEXT KEY ft_doc_content (content_text),
  CONSTRAINT fk_doc_folder FOREIGN KEY (folder_id) REFERENCES document_folders(id) ON DELETE SET NULL,
  CONSTRAINT fk_doc_parent FOREIGN KEY (parent_document_id) REFERENCES documents(id) ON DELETE CASCADE,
  CONSTRAINT fk_doc_sig    FOREIGN KEY (signature_for_id) REFERENCES documents(id) ON DELETE SET NULL,
  CONSTRAINT fk_doc_user   FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ISDS metadata pro .zfo kontejner — uchováváme veškerá metadata zprávy.
-- Typované sloupce pro zobrazení/filtr + envelope_xml = kompletní obálka verbatim.
CREATE TABLE IF NOT EXISTS document_dms_messages (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  document_id       BIGINT UNSIGNED NOT NULL,
  dm_id             VARCHAR(32) NULL,            -- ID zprávy (dmID)
  direction         ENUM('sent','received','unknown') NOT NULL DEFAULT 'unknown',
  sender_box_id     VARCHAR(32) NULL,            -- dbIDSender
  sender_name       VARCHAR(255) NULL,           -- dmSender
  sender_address    VARCHAR(512) NULL,           -- dmSenderAddress
  sender_type       VARCHAR(16) NULL,            -- dmSenderType
  recipient_box_id  VARCHAR(32) NULL,            -- dbIDRecipient (komu šla)
  recipient_name    VARCHAR(255) NULL,           -- dmRecipient
  recipient_address VARCHAR(512) NULL,           -- dmRecipientAddress
  annotation        VARCHAR(512) NULL,           -- dmAnnotation (předmět)
  sender_ref_number    VARCHAR(64) NULL,         -- dmSenderRefNumber
  sender_ident         VARCHAR(64) NULL,         -- dmSenderIdent
  recipient_ref_number VARCHAR(64) NULL,         -- dmRecipientRefNumber
  recipient_ident      VARCHAR(64) NULL,         -- dmRecipientIdent
  dm_type           VARCHAR(16) NULL,            -- dmType
  dm_status         VARCHAR(8)  NULL,            -- dmMessageStatus / dmStatus kód
  delivery_time     DATETIME NULL,               -- dmDeliveryTime (dodání)
  acceptance_time   DATETIME NULL,               -- dmAcceptanceTime (datum odeslání/přijetí)
  envelope_xml      MEDIUMTEXT NULL,             -- KOMPLETNÍ obálka verbatim (audit)
  KEY idx_dms_document (document_id),
  KEY idx_dms_dmid (dm_id),
  CONSTRAINT fk_dms_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tagy.
CREATE TABLE IF NOT EXISTS document_tags (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id BIGINT UNSIGNED NOT NULL,
  name        VARCHAR(64) NOT NULL,
  UNIQUE KEY uq_doctag (supplier_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS document_tag_map (
  document_id BIGINT UNSIGNED NOT NULL,
  tag_id      BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (document_id, tag_id),
  CONSTRAINT fk_dtm_doc FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
  CONSTRAINT fk_dtm_tag FOREIGN KEY (tag_id) REFERENCES document_tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Polymorfní vazba na entitu (klient / faktura / přijatá faktura / projekt).
CREATE TABLE IF NOT EXISTS document_links (
  document_id BIGINT UNSIGNED NOT NULL,
  entity_type ENUM('client','invoice','purchase_invoice','project') NOT NULL,
  entity_id   BIGINT UNSIGNED NOT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (document_id, entity_type, entity_id),
  KEY idx_dl_entity (entity_type, entity_id),
  CONSTRAINT fk_dl_doc FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
