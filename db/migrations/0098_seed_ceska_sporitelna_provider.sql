-- MyInvoice.cz - FR-58 seed parser provider Česká spořitelna
--
-- Globální (supplier_id IS NULL) regex provider pro e-mailová avíza ČS.
-- Idempotentní: INSERT ... SELECT ... WHERE NOT EXISTS (unique index nehlídá NULL
-- supplier_id spolehlivě). Backslash v regexech musí být v SQL literálu zdvojený,
-- ať se přes JSON_OBJECT round-tripne na jeden zpětný lomítko (stejně jako 0096).
--
-- Specifika ČS oproti jiným bankám (řeší RegexBankEmailNoticeParser):
--   * datum platby v těle není -> fallback na datum doručení e-mailu,
--   * měna se píše symbolem „Kč" -> normalizace na CZK,
--   * směr platby řádkem „Směr platby: příchozí/odchozí" místo znaménka ±.

SET NAMES utf8mb4;

INSERT INTO bank_email_notice_providers
  (supplier_id, code, name, parser_type, enabled, sender_whitelist, subject_pattern, body_pattern, field_patterns, normalizer_config)
SELECT
  NULL,
  'ceska-sporitelna',
  'Česká spořitelna - avízo o pohybu',
  'regex',
  1,
  NULL,
  NULL,
  'Směr\\s+platby',
  JSON_OBJECT(
    'recipient_account',    'Číslo účtu:\\s*(?<value>[0-9\\-]+/[0-9]{4})',
    'counterparty_account', 'Číslo účtu protistrany:\\s*(?<value>[0-9\\-]+/[0-9]{4})',
    'amount',               'Částka v měně účtu:\\s*(?<value>[0-9 .]+,[0-9]{2})',
    'currency',             'Částka v měně účtu:\\s*[0-9 .]+,[0-9]{2}\\s*(?<value>Kč|CZK|EUR|USD|€)',
    'variable_symbol',      'Variabilní symbol:\\s*(?<value>[0-9]+)',
    'constant_symbol',      'Konstantní symbol:\\s*(?<value>[0-9]+)',
    'direction',            'Směr platby:\\s*(?<value>příchozí|odchozí)'
  ),
  JSON_OBJECT()
WHERE NOT EXISTS (
  SELECT 1 FROM bank_email_notice_providers WHERE supplier_id IS NULL AND code = 'ceska-sporitelna'
);
