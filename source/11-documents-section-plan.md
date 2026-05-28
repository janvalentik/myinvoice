# Plán 11 — Sekce Dokumenty

Status: **IMPLEMENTOVÁNO** (2026-05-29, čeká na otestování) · Návrh: 2026-05-28 · Navazuje na: [09-fork-integration-plan](09-fork-integration-plan.md)

## 0. Stav implementace (skutečnost)

Celá sekce je hotová a otestovaná lokálně (39 Document testů unit+integrační, celá
suite 526 OK; frontend build OK). Odchylky a doplňky oproti původnímu návrhu níže:

- **Migrace:** `db/migrations/0067_documents.sql` + `0068_documents_jobs.sql`
  (plán psal placeholder 0043; reálné číslo navazuje na 0066).
- **Úložiště — content-addressed:** na disk se ukládá pod čistým `{sha256}`
  (`sup-{id}/{sha[0:2]}/{sha256}`), **bez uživatelského názvu v cestě** — exaktní
  dedup + korektní dedup-aware mazání. Původní název žije jen v DB (`original_name`).
  (Návrh počítal s `{sha8}-{safeName}`; finální řešení je bezpečnější a čistší.)
- **Background joby** (nad rámec MVP, vzor `reports/monthly-export`): rozbalení
  ZIP importu (`document_zip_import`) a hromadný ZIP export (`document_zip_export`) —
  `import_jobs` ENUM rozšířen, dispatch v `import-worker.php`, `DocumentJobService`,
  `DocumentJobsAction`, frontend polling panel. Běžný upload souborů i „Nahrát složku"
  zůstávají synchronní; jako job jede ZIP-explode a ZIP export.
- **Párování — našeptávač napříč entitami** (`LinkSearchAction`): faktury, přijaté
  faktury, klienti, projekty; **mix search** (např. „fialka 2605" = klient + VS,
  AND napříč tokeny), hledání dle čísla dokladu / firmy / e-mailu / IČ-DIČ / projektu.
  Oboustranný panel `LinkedDocumentsPanel` v detailu klienta/faktury/přijaté faktury/projektu.
- **Tagy:** komponenta `TagInput` s našeptáváním existujících tagů (hromadné tagování
  i editace v detailu), **filtr tagem** (select u hledání), automatické mazání
  osamocených tagů po vysypání koše / editaci.
- **ZFO:** ověřeno na reálné zprávě; u kontejneru s více PDF tlačítko „Zobrazit" u každé
  náhledovatelné přílohy; veškerá metadata zachována.
- **ZIP názvy:** dekódování OEM **CP852** (Windows ZIP bez UTF-8 příznaku → české názvy správně).
- **UI:** sjednoceno s webem (ikony v tlačítkách, `h-9`/`rounded-md`, breadcrumbs,
  grid/list s řazením název/velikost/nahráno + směr ↑/↓, drag&drop na celé okno,
  koš stav v URL, „Odpojit" místo „Smazat" u vazeb, žluté varování neuložených změn).
- **Reset:** `reset.php` maže document tabulky i `storage/documents`.
- **Backup:** `cron-backup-documents` (vyřazuje `_thumbs`/`_jobs`), v `CronCatalog`.
- **Dokumentace:** `manual/26_Dokumenty.md` + INDEX, README, OpenAPI (`/api/v1/documents*`).

Detailní původní návrh (beze změny) níže.

## 1. Cíl

Přidat do aplikace samostatnou sekci **Dokumenty** — úložiště pro libovolné soubory
(PDF, smlouvy, DOC(X), XLS(X), XML, ZFO datové zprávy, P7S podpisy, obrázky, ZIP…)
se **stromem složek**, **propojením na entity** (klient / faktura / projekt), **tagy**
a **fulltextovým vyhledáváním v obsahu**.

Klíčové uživatelské scénáře:

- Nahrát strukturovanou složku (drag-and-drop celého adresáře) → rekonstruovat strom.
- Nahrát ZIP a buď ho **rozbalit a kategorizovat** (včetně podsložek), nebo ho **nechat
  jako jeden ZIP** ke stažení.
- Nahrát **ZFO** (stažená/odeslaná datová zpráva) → **automaticky rozbalit** na metadata
  zprávy + jednotlivé přílohy jako samostatné dokumenty.
- Prohledat obsah PDF/Office/XML fulltextem.

## 2. Rozhodnutí (odsouhlasená)

| Téma | Rozhodnutí |
|------|-----------|
| Organizace | **Hybrid**: volný strom složek + nepovinná vazba na entitu + tagy |
| Fulltext | **Text-layer + MariaDB FULLTEXT** (skenované PDF bez textové vrstvy → `unsupported`, OCR mimo MVP) |
| ZFO | **Auto-rozbalit** na metadata + přílohy + doručenku |
| ZIP | **Dvojí režim** při uploadu: rozbalit+kategorizovat (vč. složek) / ponechat jako 1 ZIP |
| Verzování | **Bez verzování (MVP)** — schéma připravené na pozdější doplnění |
| Limit velikosti | **Konfigurovatelný v nastavení** (ne natvrdo) |
| Menu | Položka **Dokumenty před sekcí Daně** |
| Záloha | **Samostatný cron** `cron-backup-documents`; stávající PDF backup Dokumenty **nezahrnuje** |

## 3. Formát ZFO — ověřeno

Testovací soubor `C:\tmp\zprava_…_odeslana.zfo` ověřen: jde o **DER PKCS#7 SignedData**,
payload je XML v ISDS formátu (`http://isds.czechpoint.cz/v20/…`, `SentMessage` /
`MessageDownloadResponse`).

> **Pozor:** „ZFO" jsou dva různé formáty se stejnou příponou:
> 1. **datová zpráva** = PKCS#7/CMS podepsaná XML obálka (DER, CAdES-T) — tento případ,
> 2. **formulář Software602 Form Filler** = ZIP s XSL-FO (mimo scope).

Struktura envelope (z reálného souboru):

- Metadata: `dmID`, `dbIDSender`, `dmSender`, `dmSenderAddress`, `dmRecipient`,
  `dmRecipientAddress`, `dmAnnotation` (předmět), `dmDeliveryTime`, `dmAcceptanceTime`.
- Přílohy: `dmFiles/dmFile` s atributy `dmFileMetaType` (`main`/`enclosure`),
  `dmFileDescr` (název souboru), `dmMimeType`; obsah v `dmEncodedContent` (base64).

**Extrakce v PHP bez shellování na `openssl` CLI** (stejné chování Windows i Docker):
parsovat PKCS#7 → vytáhnout `pkcs7-data` content (reassembly BER constructed/indefinite
OCTET STRING) → XML. Podpis **neověřujeme**, jen extrahujeme obsah. Implementace
v `ZfoExtractor` (viz §6).

**Zachování metadat (požadavek):** při rozbalení ZFO uchováváme **veškerá metadata
zprávy** — zejména **datum odeslání/dodání** (`dmAcceptanceTime`/`dmDeliveryTime`),
**ID zprávy** (`dmID`) a **komu šla** (`dbIDRecipient` + `dmRecipient` + adresa) — do
typovaných sloupců `document_dms_messages` (§4). Navíc se ukládá **kompletní obálka
verbatim** do `envelope_xml`, takže žádné pole (referenční čísla, právní tituly,
`dmType`, stavy, hashe/časová razítka v obálce) se neztratí, i kdyby pro něj sloupec
nebyl. V detailu dokumentu se metadata zobrazí v panelu datové zprávy.

## 4. Datový model — migrace `0043_documents.sql`

Idempotentní (native `CREATE TABLE IF NOT EXISTS`, `CREATE INDEX IF NOT EXISTS`), tenant
izolace přes `supplier_id`.

```sql
-- Strom složek (virtuální — soubory leží na disku podle hashe, ne podle stromu)
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

-- Dokument = jeden soubor
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
  thumb_path         VARCHAR(255) NULL,            -- náhled (PDF 1. strana / obrázek), NULL=žádný
  thumb_status       ENUM('none','generated','unsupported','failed') NOT NULL DEFAULT 'none',
  uploaded_by        BIGINT UNSIGNED NULL,
  deleted_at         TIMESTAMP NULL,               -- soft-delete (koš); NULL = aktivní
  deleted_by         BIGINT UNSIGNED NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_doc_supplier_folder (supplier_id, folder_id, deleted_at, created_at DESC),
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

-- ISDS metadata pro .zfo kontejner — uchováváme VEŠKERÁ metadata zprávy.
-- Typované sloupce pro zobrazení/filtr; navíc envelope_xml = kompletní obálka
-- verbatim, takže se nic neztratí (i pole, která zde sloupec nemají).
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
  sender_ref_number    VARCHAR(64) NULL,         -- dmSenderRefNumber (naše čj./spis. zn.)
  sender_ident         VARCHAR(64) NULL,         -- dmSenderIdent
  recipient_ref_number VARCHAR(64) NULL,         -- dmRecipientRefNumber
  recipient_ident      VARCHAR(64) NULL,         -- dmRecipientIdent
  dm_type           VARCHAR(16) NULL,            -- dmType
  dm_status         VARCHAR(8)  NULL,            -- dmMessageStatus / dmStatus kód
  delivery_time     DATETIME NULL,               -- dmDeliveryTime (dodání)
  acceptance_time   DATETIME NULL,               -- dmAcceptanceTime (datum odeslání/přijetí)
  envelope_xml      MEDIUMTEXT NULL,             -- KOMPLETNÍ obálka verbatim (audit, nic se neztratí)
  KEY idx_dms_document (document_id),
  KEY idx_dms_dmid (dm_id),                      -- tenant scope řešíme joinem na documents.supplier_id
  CONSTRAINT fk_dms_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tagy
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

-- Polymorfní vazba na entitu
CREATE TABLE IF NOT EXISTS document_links (
  document_id BIGINT UNSIGNED NOT NULL,
  entity_type ENUM('client','invoice','purchase_invoice','project') NOT NULL,
  entity_id   BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (document_id, entity_type, entity_id),
  KEY idx_dl_entity (entity_type, entity_id),
  CONSTRAINT fk_dl_doc FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Pozn. k modelu:
- **Per-supplier:** každá tabulka i dotaz scopovány přes `supplier_id` (přímo nebo joinem),
  vynuceno `SupplierGuard`. Žádný dokument/složka/zpráva nepřekročí hranici dodavatele.
- **Složky jsou virtuální** → přesun složky/dokumentu = jen UPDATE `folder_id`/`parent_id`,
  žádný pohyb souborů na disku.
- **Koš (soft-delete):** smazání nastaví `deleted_at` (dokument i složka). Aktivní pohledy
  filtrují `deleted_at IS NULL`. „Vysypat koš" = tvrdé smazání DB řádků **a** souborů z disku
  (s ohledem na dedup — soubor smažeme jen pokud na stejný `sha256` neukazuje žádný jiný
  aktivní/koš dokument daného dodavatele). Smazání složky → soft-delete složky i obsahu.
- **Náhledy (thumbnails):** generují se po uploadu — PDF 1. strana + obrázky → malý JPG/WebP
  do `storage/documents/sup-{id}/_thumbs/{sha8}.webp`. Selhání negeneruje chybu uploadu
  (`thumb_status='failed'/'unsupported'`).
- **Verzování (později):** doplníme tabulku `document_versions` + `current_version_id`;
  dnešní `documents` zůstane „aktuální verze". Schéma nic neblokuje.
- **FULLTEXT** v MariaDB: čeština bez stemmingu, default min. délka tokenu — akceptujeme;
  je to upgrade oproti dnešnímu `LIKE` quick-search. (viz [feedback_mariadb_106])

## 5. Úložiště (disk)

```
storage/documents/sup-{supplierId}/{sha[0:2]}/{sha8}-{safeName}
```

- Dedup přes SHA-256, shardováno prvními 2 znaky hashe.
- Přes `RuntimePaths::storage('documents')` → respektuje `MYINVOICE_DATA_DIR`
  (viz [feedback_runtime_paths]).
- **Oddělené od PDF backupu**: `storage/documents/` se nepřidává do zdrojů
  `cron-backup-pdf.php` (ten sbírá jen `*.pdf` z `invoices`/`work-reports`/`purchase-invoices`).

## 6. Zabezpečení a ochrana proti zneužití

Sekce přijímá libovolné soubory od uživatele — proto je bezpečnost prvotřídní, ne dodatek.

### Upload
- **MIME z obsahu, ne z klienta** — `finfo` na reálném obsahu; whitelist povolených typů
  (PDF, Office, ODF, XML, TXT/CSV, obrázky, ZIP, ZFO, P7S). Klientský `Content-Type` i přípona
  jsou jen vodítka, neautoritativní.
- **Sanitizace názvu** — odstranit path separátory, control chars, `..`, max délka; uložené jméno
  je vždy `{sha8}-{safeName}`, nikdy klientská cesta.
- **Path-traversal guard** — cílová cesta musí ležet uvnitř `storage/documents/sup-{id}/`;
  porovnání realpath **case-insensitive na Windows** (viz [feedback_windows_paths]).
- **Limit velikosti** — konfigurovatelný (`documents.max_file_bytes`), kontrola už při streamu,
  ne až po načtení do paměti; limit i na počet souborů v jednom requestu.
- **Atomický zápis** — `.tmp-{rand}` → hash → rename; cleanup `.tmp-*` při chybě.

### Rozbalení ZIP (anti zip-bomb)
- Tvrdé limity jako `InvoiceImportService`: **max počet entries**, **max nekomprimovaná
  velikost celkem**, **max per-entry**, kontrola **kompresního poměru** (detekce bomby).
- **Žádný symlink/absolutní cesty/`..`** v entries; každá rozbalená cesta se validuje proti
  cílovému kořeni (Zip Slip).
- Vnořené archivy **nerozbalovat rekurzivně** (uložit jako soubor) — prevence amplifikace.
- Per-entry stejná MIME whitelist jako u běžného uploadu.

### Parsování ZFO / XML (anti XXE / billion-laughs)
- **Zákaz DOCTYPE** před parsováním (jako `IsdocParser`), `libxml_disable_entity_loader`
  / `LIBXML_NONET | LIBXML_NOENT` off — žádné externí entity, žádné síťové fetnutí.
- PKCS#7 reassembly s **limitem dekódované velikosti** (anti dekompresní/alokační bomba).
- Base64 přílohy dekódovat streamově s limitem; každá příloha projde stejnou MIME whitelist.
- Podpis **neověřujeme**, ale ani nevyhodnocujeme jako důvěryhodný — jen extrakce dat.

### Servírování souborů (download / preview)
- Default **`Content-Disposition: attachment`** + `X-Content-Type-Options: nosniff`.
- **Inline preview jen pro PDF a obrázky** (`?inline=1` → `Content-Disposition: inline`),
  stejný vzor jako přijaté faktury (iframe + `#view=FitH`, sbalený default). HTML/SVG/skripty
  se **nikdy** neservírují jako `text/html`/`image/svg+xml` inline — vždy attachment, ať
  nehrozí stored XSS. Vhodná `Content-Security-Policy` na preview odpovědích.
- Stahování přes Action s `SupplierGuard` (kontrola vlastnictví), ne přímý odkaz na disk;
  žádné `id` zneužitelné cross-supplier (IDOR) — vždy `… AND supplier_id = ?`.

### Obecné
- Vše per-supplier, zápisy `requiresWrite`, audit přes `ActivityLogger` (upload/move/delete/
  empty-trash/restore s user/IP/UA).
- Dedup-aware mazání: fyzický soubor smazat jen když na `sha256` neukazuje žádný jiný záznam.

## 7. Backend (`api/src/`)

Vzor Action / Repository / Service jako u `invoice_attachments`.

### Služby (`Service/Document/`)
- **`DocumentStorage`** — uložení/čtení/mazání souboru, sanitizace názvu, dedup
  (klon logiky z `UploadAttachmentAction`).
- **`DocumentTextExtractor`** — extrakce textu pro fulltext:
  - PDF: textová vrstva (poppler `pdftotext`, případně pure-PHP `smalot/pdfparser`);
    bez textu → `text_status='unsupported'`.
  - DOCX/XLSX/PPTX: ZIP + XML (`word/document.xml`, `xl/sharedStrings.xml`).
  - XML/TXT/CSV: strip tagů.
  - běží synchronně při uploadu; chyba → `text_status='failed'`, upload nepadá.
- **`ZfoExtractor`** — PKCS#7 DER → envelope XML → `{metadata, attachments[]}`
  (pure-PHP, bez shellu). Naplní `document_dms_messages` + vytvoří děti `documents`.
- **`ZipImporter`** — režim **explode**: rozbalí ZIP (limity proti zip-bombě jako
  `InvoiceImportService`: max N entries, max uncompressed total, max per-entry),
  rekonstruuje strom `document_folders` z cest uvnitř archivu, každý soubor → `documents`
  (`source='zip_extract'`). Režim **keep**: uloží ZIP jako jeden `documents` (`doc_type='zip'`).

### Akce (`Action/Document/`)
- Folders: `ListFoldersAction`, `CreateFolderAction`, `RenameFolderAction`,
  `MoveFolderAction`, `DeleteFolderAction` (→ koš).
- Documents: `UploadDocumentAction` (multipart; parametry `folder_id`, `zip_mode=explode|keep`;
  detekce ZFO → auto-explode), `ListDocumentsAction`, `GetDocumentAction`,
  `DownloadDocumentAction` (stream + `Content-Disposition`), `PreviewDocumentAction`
  (inline PDF/obrázky), `ThumbnailAction`, `DeleteDocumentAction` (→ koš),
  `MoveDocumentAction`, `UpdateDocumentAction` (**uživatelská editace metadat** —
  title/description/tagy/vazby), tag/link akce.
- **Hromadné akce** (`BulkDocumentAction`): move / delete (→ koš) / tag / download-as-zip
  nad seznamem `ids` (vše ověřeno per-supplier).
- **Koš**: `ListTrashAction`, `RestoreDocumentAction`/`RestoreFolderAction`,
  `EmptyTrashAction` (tvrdé smazání DB + dedup-aware smazání souborů).
- `DocumentSearchAction` — `GET /api/documents/search?q=` (FULLTEXT na meta + content).
- `ListDocumentsByEntityAction` — dokumenty navázané na danou entitu (pro detaily klienta/
  faktury/…); viz §8 oboustranné provázání.
- Vše přes `SupplierGuard` + `ActivityLogger`; zápisy = `requiresWrite`.

### Routy (`Routes.php`)
```
GET    /api/documents                       seznam (filtr folder_id, doc_type, tag, entity, trashed)
POST   /api/documents                       upload (multipart)
POST   /api/documents/bulk                  hromadné akce (move/delete/tag/zip)
GET    /api/documents/search?q=
GET    /api/documents/trash                 obsah koše
POST   /api/documents/trash/empty           vysypat koš (hard delete)
GET    /api/documents/{id}
PATCH  /api/documents/{id}                  editace metadat (title/description)
DELETE /api/documents/{id}                  → koš (soft delete)
POST   /api/documents/{id}/restore          obnova z koše
GET    /api/documents/{id}/download         attachment
GET    /api/documents/{id}/preview          inline (jen PDF/obrázky)
GET    /api/documents/{id}/thumb            náhled
POST   /api/documents/{id}/move
GET/POST/PATCH/DELETE /api/document-folders[...]   (DELETE = → koš, +restore)
POST/DELETE           /api/documents/{id}/tags, /api/documents/{id}/links
GET    /api/documents/by-entity/{type}/{id}        navázané dokumenty entity
```

### Rozšíření existujícího
- `GlobalSearchAction` — přidat sekci `documents` do výsledků (`/api/search`).
- **Limit velikosti** — nový konfig klíč (např. `documents.max_file_bytes`) v Nastavení;
  default rozumný (např. 50 MiB), čte ho `UploadDocumentAction`.

## 8. Frontend (`web/src/`)

- **Menu** (`components/layout/AppLayout.vue`): nová jednopoložková sekce **Dokumenty**
  vložená **před `section_taxes`** (tj. mezi Finance a Daně). i18n klíč `nav.documents`.
- **Stránky** `pages/documents/`:
  - `DocumentsBrowser.vue` — split: strom složek vlevo, seznam souborů vpravo, breadcrumb,
    **náhledy (thumbnaily)** v grid/list view, barevné format-ikony (sjednocený
    [feedback_design_language]), mobilní karty. **Multi-select + hromadná lišta**
    (přesun/smazat do koše/otagovat/stáhnout jako ZIP). Přepínač zobrazení **Koš**.
  - **Upload modal** — drag-and-drop souborů i **celé struktury složek**
    (`<input webkitdirectory>` → rekonstrukce stromu z relativních cest); u ZIP přepínač
    **„Rozbalit a kategorizovat" / „Nahrát jako jeden ZIP"**.
  - `DocumentDetail.vue` — **inline PDF náhled** (iframe `?inline=1` + `#view=FitH`, sbalený
    default — stejný vzor jako `purchase-invoices/InvoiceDetail.vue`), náhled obrázků,
    **editovatelná metadata** (název, popis, tagy, vazby na entity), **panel datové zprávy**
    u ZFO (odesílatel/příjemce/předmět/ID/datum odeslání + všechna metadata), seznam příloh.
- **Koš** — pohled se seznamem smazaných, akce *Obnovit* / *Vysypat koš* (s potvrzením).
- API client `api/documents.ts`, router routes (`requiresWrite` na zápisy), i18n přes `t()`
  od začátku (viz [feedback_i18n]).
- Po změnách `web/src` vždy `npm run build` (viz [feedback_build_after_ts]).

## 9. Oboustranné provázání s entitami

Vazba `document_links` se zobrazuje z **obou stran**:

- **Z dokumentu** → v `DocumentDetail.vue` sekce „Souvisí s" (klient / faktura / přijatá
  faktura / projekt) s prokliky na detail entity.
- **Z entity** → v detailech **klienta, vydané faktury, přijaté faktury a projektu** přibude
  panel **„Dokumenty"**, který přes `GET /api/documents/by-entity/{type}/{id}` vypíše navázané
  dokumenty (s náhledem/ikonou, stažením, prokliku do sekce Dokumenty) a umožní
  **přidat/odebrat vazbu** přímo z detailu entity.
- Dotčené stránky: `pages/clients/*Detail`, `pages/invoices/InvoiceDetail.vue`,
  `pages/purchase-invoices/InvoiceDetail.vue`, `pages/projects/*Detail` — reusable komponenta
  `LinkedDocumentsPanel.vue`.

## 10. Zálohování — samostatný cron

- **`api/bin/cron-backup-documents.php`** + wrappery `cmd/cron-backup-documents.{sh,cmd}`.
- Zdroj: `storage/documents/` (všechny typy souborů, ne jen PDF), ZIP do
  `storage/backup/{dbName}-documents-YYYY-MM-DD_H-i.zip`.
- Retention jako PDF backup (30 denních + měsíční drženy déle), prefix
  `{dbName}-documents-` (aby se nepletly DB dumpy ani PDF zálohy).
- Registrovat v admin cron přehledu (`/admin/cron-jobs`).
- **`cron-backup-pdf.php` neměnit** — Dokumenty záměrně nezahrnuje.

## 11. RBAC

- `readonly`: procházení, download, preview, náhledy, fulltext, export.
- `write`: upload, delete (→ koš), restore, vysypat koš, move, rename, tag, link, editace metadat.
- Konvence `auth.canWrite` + route meta `requiresWrite` (viz [rbac-readonly]).

## 12. Fázování

1. **Kostra + UX základ**: migrace + `DocumentStorage` + folders CRUD + upload/list/detail/
   download + **bezpečnostní vrstva uploadu** + drag-drop nahrání struktury složek +
   **inline PDF preview** + **náhledy (thumbnaily)** + **hromadné akce** + **koš (soft-delete,
   restore, vysypat)** + frontend browser. (plnohodnotné samo o sobě)
2. **Metadata + provázání**: editace metadat + tagy + vazby na entity + **oboustranné panely
   v detailu klienta/faktury/projektu** (`LinkedDocumentsPanel`).
3. **ZIP**: dvojí režim (explode+kategorizace / keep-as-is) přes `ZipImporter` (anti zip-bomb).
4. **ZFO**: `ZfoExtractor` + auto-rozbalení + uchování všech metadat + DMS panel + P7S asociace
   (test na reálném souboru).
5. **Fulltext**: `DocumentTextExtractor` + FULLTEXT + napojení na `GlobalSearchAction`.
6. **Provoz**: `cron-backup-documents` + admin cron + dokumentace do manuálu.

## 13. Otevřené / pozdější

- Verzování dokumentů (schéma připraveno).
- OCR / AI pro skenovaná PDF bez textové vrstvy (recyklace `AiPdfExtractor`).
- Ověření platnosti podpisů (P7S/ZFO) — zatím jen extrakce obsahu.
