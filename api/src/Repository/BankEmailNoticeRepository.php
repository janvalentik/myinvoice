<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Bank\AccountNumberNormalizer;

final class BankEmailNoticeRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly SecretEncryption $secrets,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function imapSettings(int $supplierId, bool $includeSecret = false): array
    {
        return $this->imapAccounts($supplierId, $includeSecret)[0] ?? $this->emptyImapAccount($supplierId);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function imapAccounts(int $supplierId, bool $includeSecret = false): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM bank_email_imap_settings WHERE supplier_id = ? ORDER BY name, id'
        );
        $stmt->execute([$supplierId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $this->castImapAccount($row, $includeSecret);
        }
        return $rows;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function imapAccount(int $supplierId, int $id, bool $includeSecret = false): ?array
    {
        $row = $this->imapAccountRaw($supplierId, $id);
        if ($row === null) {
            return null;
        }
        $this->castImapAccount($row, $includeSecret);
        return $row;
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function saveImapSettings(int $supplierId, array $body): array
    {
        $current = $this->imapSettings($supplierId);
        $id = isset($current['id']) ? (int) $current['id'] : null;
        return $this->saveImapAccount($supplierId, $body, $id);
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function saveImapAccount(int $supplierId, array $body, ?int $id = null): array
    {
        $current = $id !== null ? $this->imapAccountRaw($supplierId, $id) : null;
        if ($id !== null && $current === null) {
            throw new \RuntimeException('IMAP účet nebyl nalezen.');
        }
        $passwordEnc = $current['password_enc'] ?? null;
        if (array_key_exists('password', $body) && trim((string) $body['password']) !== '') {
            $passwordEnc = $this->secrets->encrypt((string) $body['password']);
        }
        $name = trim((string) ($body['name'] ?? ($current['name'] ?? 'Výchozí IMAP účet')));
        if ($name === '') {
            throw new \RuntimeException('Název IMAP účtu je povinný.');
        }

        $data = [
            'supplier_id' => $supplierId,
            'name' => $name,
            'enabled' => !empty($body['enabled']) ? 1 : 0,
            'host' => trim((string) ($body['host'] ?? '')),
            'port' => max(1, min(65535, (int) ($body['port'] ?? 993))),
            'encryption' => in_array($body['encryption'] ?? 'ssl', ['ssl', 'tls', 'none'], true) ? (string) $body['encryption'] : 'ssl',
            'validate_cert' => array_key_exists('validate_cert', $body) ? (int) (bool) $body['validate_cert'] : 1,
            'require_email_auth' => !empty($body['require_email_auth']) ? 1 : 0,
            'email_auth_serv_id' => $this->nullable($body['email_auth_serv_id'] ?? null),
            'username' => trim((string) ($body['username'] ?? '')),
            'password_enc' => $passwordEnc,
            'folder' => trim((string) ($body['folder'] ?? 'INBOX')) ?: 'INBOX',
            'max_messages_per_run' => max(1, min(500, (int) ($body['max_messages_per_run'] ?? 50))),
            'process_from_date' => $this->dateOrNull($body['process_from_date'] ?? null),
            'success_action' => $this->enum((string) ($body['success_action'] ?? 'none'), ['none', 'add_flag', 'move', 'mark_seen'], 'none'),
            'success_flag' => $this->nullable($body['success_flag'] ?? 'MyInvoiceProcessed'),
            'success_move_folder' => $this->nullable($body['success_move_folder'] ?? null),
            'failure_action' => $this->enum((string) ($body['failure_action'] ?? 'none'), ['none', 'add_flag', 'move'], 'none'),
            'failure_flag' => $this->nullable($body['failure_flag'] ?? 'MyInvoiceFailed'),
            'failure_move_folder' => $this->nullable($body['failure_move_folder'] ?? null),
            'retry_failed' => !empty($body['retry_failed']) ? 1 : 0,
            'max_attempts' => max(1, min(20, (int) ($body['max_attempts'] ?? 3))),
        ];

        if ($id !== null) {
            $data['id'] = $id;
            $sql = 'UPDATE bank_email_imap_settings
                       SET name = :name, enabled = :enabled, host = :host, port = :port, encryption = :encryption,
                           validate_cert = :validate_cert, require_email_auth = :require_email_auth,
                           email_auth_serv_id = :email_auth_serv_id, username = :username, password_enc = :password_enc,
                           folder = :folder, max_messages_per_run = :max_messages_per_run,
                           process_from_date = :process_from_date, success_action = :success_action,
                           success_flag = :success_flag, success_move_folder = :success_move_folder,
                           failure_action = :failure_action, failure_flag = :failure_flag,
                           failure_move_folder = :failure_move_folder, retry_failed = :retry_failed,
                           max_attempts = :max_attempts
                     WHERE id = :id AND supplier_id = :supplier_id';
            $this->db->pdo()->prepare($sql)->execute($data);
            return $this->imapAccount($supplierId, $id) ?? $this->emptyImapAccount($supplierId);
        }

        $sql = 'INSERT INTO bank_email_imap_settings
                  (supplier_id, name, enabled, host, port, encryption, validate_cert, require_email_auth, email_auth_serv_id,
                   username, password_enc, folder,
                   max_messages_per_run, process_from_date, success_action, success_flag, success_move_folder,
                   failure_action, failure_flag, failure_move_folder, retry_failed, max_attempts)
                VALUES
                  (:supplier_id, :name, :enabled, :host, :port, :encryption, :validate_cert, :require_email_auth, :email_auth_serv_id,
                   :username, :password_enc, :folder,
                   :max_messages_per_run, :process_from_date, :success_action, :success_flag, :success_move_folder,
                   :failure_action, :failure_flag, :failure_move_folder, :retry_failed, :max_attempts)';
        $this->db->pdo()->prepare($sql)->execute($data);

        return $this->imapAccount($supplierId, (int) $this->db->pdo()->lastInsertId()) ?? $this->emptyImapAccount($supplierId);
    }

    public function deleteImapAccount(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM bank_email_imap_settings WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$supplierId, $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function providers(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM bank_email_notice_providers
              WHERE supplier_id IS NULL OR supplier_id = ?
              ORDER BY supplier_id IS NULL DESC, name'
        );
        $stmt->execute([$supplierId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $this->castProvider($row);
        }
        return $rows;
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function saveProvider(int $supplierId, array $body, ?int $id = null): array
    {
        $fieldPatterns = $body['field_patterns'] ?? [];
        if (is_string($fieldPatterns)) {
            $fieldPatterns = json_decode($fieldPatterns, true);
        }
        if (!is_array($fieldPatterns)) {
            throw new \RuntimeException('field_patterns musí být JSON objekt.');
        }
        $normalizer = $body['normalizer_config'] ?? [];
        if (is_string($normalizer)) {
            $normalizer = json_decode($normalizer, true) ?: [];
        }

        // Regexy se uloží do DB a pak jdou rovnou do preg_match v parseru. Ověř, že se
        // zkompilují, ať admin dostane hezkou 400 chybu místo tichého selhání scanu.
        foreach ($fieldPatterns as $field => $pattern) {
            if (is_string($pattern) && trim($pattern) !== '') {
                $this->assertValidRegex($pattern, 'pole ' . (string) $field);
            }
        }
        foreach (['subject_pattern' => 'předmět', 'body_pattern' => 'tělo'] as $key => $label) {
            $value = trim((string) ($body[$key] ?? ''));
            if ($value !== '') {
                $this->assertValidRegex($value, $label);
            }
        }

        $data = [
            'supplier_id' => $supplierId,
            'code' => preg_replace('/[^a-z0-9_\\-]/', '_', strtolower(trim((string) ($body['code'] ?? '')))) ?: 'provider',
            'name' => trim((string) ($body['name'] ?? '')),
            'parser_type' => 'regex',
            'enabled' => array_key_exists('enabled', $body) ? (int) (bool) $body['enabled'] : 1,
            'sender_whitelist' => $this->nullable($body['sender_whitelist'] ?? null),
            'subject_pattern' => $this->nullable($body['subject_pattern'] ?? null),
            'body_pattern' => $this->nullable($body['body_pattern'] ?? null),
            'field_patterns' => json_encode($fieldPatterns, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'normalizer_config' => json_encode(is_array($normalizer) ? $normalizer : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        if ($data['name'] === '') {
            throw new \RuntimeException('Název provideru je povinný.');
        }

        if ($id !== null && $id > 0) {
            $owner = $this->providerSupplierId($id);
            if ($owner !== $supplierId) {
                throw new \RuntimeException('Globální provider nebo provider jiného dodavatele nelze upravit.');
            }
            $data['id'] = $id;
            $stmt = $this->db->pdo()->prepare(
                'UPDATE bank_email_notice_providers
                    SET code = :code, name = :name, parser_type = :parser_type, enabled = :enabled,
                        sender_whitelist = :sender_whitelist, subject_pattern = :subject_pattern,
                        body_pattern = :body_pattern, field_patterns = :field_patterns,
                        normalizer_config = :normalizer_config
                  WHERE id = :id AND supplier_id = :supplier_id'
            );
            $stmt->execute($data);
            return $this->providerById($id);
        }

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO bank_email_notice_providers
                (supplier_id, code, name, parser_type, enabled, sender_whitelist, subject_pattern, body_pattern, field_patterns, normalizer_config)
             VALUES (:supplier_id, :code, :name, :parser_type, :enabled, :sender_whitelist, :subject_pattern, :body_pattern, :field_patterns, :normalizer_config)'
        );
        $stmt->execute($data);
        return $this->providerById((int) $this->db->pdo()->lastInsertId());
    }

    public function deleteProvider(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM bank_email_notice_providers WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function accountMappings(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT c.id AS currency_id, c.code AS currency_code, c.label, c.account_number, c.bank_code, c.bank_name,
                    m.id, m.imap_account_id, m.provider_id, m.enabled, m.amount_tolerance,
                    im.name AS imap_account_name,
                    p.code AS provider_code, p.name AS provider_name
               FROM currencies c
          LEFT JOIN bank_email_account_mappings m ON m.currency_id = c.id
          LEFT JOIN bank_email_imap_settings im ON im.id = m.imap_account_id AND im.supplier_id = c.supplier_id
          LEFT JOIN bank_email_notice_providers p ON p.id = m.provider_id AND (p.supplier_id IS NULL OR p.supplier_id = c.supplier_id)
              WHERE c.supplier_id = ?
           ORDER BY c.code, c.is_default DESC, c.label'
        );
        $stmt->execute([$supplierId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['id'] = $row['id'] !== null ? (int) $row['id'] : null;
            $row['currency_id'] = (int) $row['currency_id'];
            $row['imap_account_id'] = $row['imap_account_id'] !== null ? (int) $row['imap_account_id'] : null;
            $row['provider_id'] = $row['provider_id'] !== null ? (int) $row['provider_id'] : null;
            $row['enabled'] = (bool) ($row['enabled'] ?? false);
            $row['amount_tolerance'] = (float) ($row['amount_tolerance'] ?? 0.05);
        }
        return $rows;
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    public function saveAccountMappings(int $supplierId, array $rows): void
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO bank_email_account_mappings
                (supplier_id, currency_id, imap_account_id, provider_id, enabled, amount_tolerance)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                imap_account_id = VALUES(imap_account_id), provider_id = VALUES(provider_id),
                enabled = VALUES(enabled), amount_tolerance = VALUES(amount_tolerance)'
        );
        foreach ($rows as $row) {
            $currencyId = (int) ($row['currency_id'] ?? 0);
            if ($currencyId <= 0 || !$this->currencyBelongsToSupplier($currencyId, $supplierId)) {
                continue;
            }
            $rawImapAccountId = $row['imap_account_id'] ?? null;
            $noImapAccount = $rawImapAccountId === 0 || $rawImapAccountId === '0';
            $imapAccountId = (!$noImapAccount && !empty($rawImapAccountId)) ? (int) $rawImapAccountId : null;
            if ($imapAccountId !== null && !$this->imapAccountBelongsToSupplier($imapAccountId, $supplierId)) {
                continue;
            }
            $providerId = !empty($row['provider_id']) ? (int) $row['provider_id'] : null;
            if ($providerId !== null && !$this->providerAvailableToSupplier($providerId, $supplierId)) {
                continue;
            }
            $stmt->execute([
                $supplierId,
                $currencyId,
                $imapAccountId,
                $providerId,
                !empty($row['enabled']) && !$noImapAccount ? 1 : 0,
                max(0.0, (float) ($row['amount_tolerance'] ?? 0.05)),
            ]);
        }
    }

     /**
     * @return array<string,mixed>|null
     */
    public function mappingForRecipientAccount(
        int $supplierId,
        string $recipientAccount,
        ?int $imapAccountId = null,
        ?int $providerId = null,
    ): ?array
    {
        [$account, $bankCode] = $this->splitAccount($recipientAccount);
        foreach ($this->accountMappings($supplierId) as $row) {
            if (!$row['enabled'] || empty($row['account_number'])) {
                continue;
            }
            if ($imapAccountId !== null && $row['imap_account_id'] !== null && (int) $row['imap_account_id'] !== $imapAccountId) {
                continue;
            }
            if ($providerId !== null && $row['provider_id'] !== null && (int) $row['provider_id'] !== $providerId) {
                continue;
            }
            if ($bankCode !== null && !empty($row['bank_code']) && (string) $row['bank_code'] !== $bankCode) {
                continue;
            }
            if (AccountNumberNormalizer::equals((string) $row['account_number'], $account)) {
                return $row;
            }
        }
        return null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function knownMessage(int $supplierId, ?int $imapAccountId, ?string $messageId, string $fallbackHash): ?array
    {
        $sql = 'SELECT * FROM bank_email_processed_messages WHERE supplier_id = ? AND imap_account_id = ? AND (fallback_hash = ?';
        $params = [$supplierId, $imapAccountId, $fallbackHash];
        if ($messageId !== null && $messageId !== '') {
            $sql .= ' OR message_id = ?';
            $params[] = $messageId;
        }
        $sql .= ') ORDER BY id DESC LIMIT 1';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @param array<string,mixed> $data
     */
    public function recordMessage(array $data): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO bank_email_processed_messages
                (supplier_id, imap_account_id, imap_uid, message_id, fallback_hash, message_date, sender, subject,
                 provider_id, provider_code, status, attempts, parsed_payload, bank_statement_id,
                 bank_transaction_id, matched_invoice_id, error_message)
             VALUES
                (:supplier_id, :imap_account_id, :imap_uid, :message_id, :fallback_hash, :message_date, :sender, :subject,
                 :provider_id, :provider_code, :status, :attempts, :parsed_payload, :bank_statement_id,
                 :bank_transaction_id, :matched_invoice_id, :error_message)'
        );
        $stmt->execute([
            'supplier_id' => (int) $data['supplier_id'],
            'imap_account_id' => $data['imap_account_id'] ?? null,
            'imap_uid' => $data['imap_uid'] ?? null,
            'message_id' => $this->nullable($data['message_id'] ?? null),
            'fallback_hash' => (string) $data['fallback_hash'],
            'message_date' => $data['message_date'] ?? null,
            'sender' => $this->nullable($data['sender'] ?? null),
            'subject' => $this->nullable($data['subject'] ?? null),
            'provider_id' => $data['provider_id'] ?? null,
            'provider_code' => $this->nullable($data['provider_code'] ?? null),
            'status' => (string) $data['status'],
            'attempts' => (int) ($data['attempts'] ?? 1),
            'parsed_payload' => isset($data['parsed_payload']) ? json_encode($data['parsed_payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'bank_statement_id' => $data['bank_statement_id'] ?? null,
            'bank_transaction_id' => $data['bank_transaction_id'] ?? null,
            'matched_invoice_id' => $data['matched_invoice_id'] ?? null,
            'error_message' => $this->nullable($data['error_message'] ?? null),
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function processedMessages(int $supplierId, int $limit = 100): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT pm.*, im.name AS imap_account_name, i.varsymbol AS matched_varsymbol
               FROM bank_email_processed_messages pm
          LEFT JOIN bank_email_imap_settings im ON im.id = pm.imap_account_id AND im.supplier_id = pm.supplier_id
          LEFT JOIN invoices i ON i.id = pm.matched_invoice_id AND i.supplier_id = pm.supplier_id
              WHERE pm.supplier_id = ?
           ORDER BY pm.processed_at DESC, pm.id DESC
              LIMIT ?'
        );
        $stmt->bindValue(1, $supplierId, \PDO::PARAM_INT);
        $stmt->bindValue(2, max(1, min(500, $limit)), \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['supplier_id'] = (int) $row['supplier_id'];
            $row['imap_account_id'] = $row['imap_account_id'] !== null ? (int) $row['imap_account_id'] : null;
            $row['imap_uid'] = $row['imap_uid'] !== null ? (int) $row['imap_uid'] : null;
            $row['provider_id'] = $row['provider_id'] !== null ? (int) $row['provider_id'] : null;
            $row['bank_statement_id'] = $row['bank_statement_id'] !== null ? (int) $row['bank_statement_id'] : null;
            $row['bank_transaction_id'] = $row['bank_transaction_id'] !== null ? (int) $row['bank_transaction_id'] : null;
            $row['matched_invoice_id'] = $row['matched_invoice_id'] !== null ? (int) $row['matched_invoice_id'] : null;
            $row['attempts'] = (int) $row['attempts'];
            $row['parsed_payload'] = $this->decodeJson($row['parsed_payload'] ?? null);
        }
        return $rows;
    }

    public function deleteProcessedMessage(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM bank_email_processed_messages WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$supplierId, $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @return array{statement_id:int,transaction_id:int,match_result:array<string,mixed>}
     */
    public function createTransactionFromNotice(
        int $supplierId,
        \MyInvoice\Service\Bank\EmailNotice\ParsedBankEmailNotice $notice,
        string $sourceRef,
        ?float $tolerance,
        \MyInvoice\Service\Bank\StatementMatcher $matcher,
    ): array {
        [$account, $bankCode] = $this->splitAccount($notice->recipientAccount);
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO bank_statements
                (source, source_ref, file_name, file_hash, account_number, bank_code, currency, statement_date,
                 transaction_count, matched_count, imported_by)
             VALUES
                (?,?,?,?,?,?,?,?,1,0,NULL)'
        )->execute([
            'email_notice',
            $sourceRef,
            'Email avizo ' . mb_substr($sourceRef, 0, 120),
            hash('sha256', 'email-notice:' . $sourceRef . ':' . microtime(true)),
            $account,
            $bankCode,
            $notice->currency,
            $notice->postedAt,
        ]);
        $statementId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO bank_transactions
                (source, source_ref, statement_id, posted_at, amount, currency, variable_symbol, constant_symbol,
                 counterparty_account, counterparty_bank, counterparty_name, description, bank_ref, match_tolerance)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            'email_notice',
            $sourceRef,
            $statementId,
            $notice->postedAt,
            $notice->amount,
            $notice->currency,
            $notice->variableSymbol,
            $notice->constantSymbol,
            $notice->counterpartyAccount,
            $notice->counterpartyBank,
            $notice->counterpartyName,
            $notice->message,
            $notice->bankRef,
            $tolerance,
        ]);
        $transactionId = (int) $pdo->lastInsertId();
        $match = $matcher->match($transactionId);
        if (in_array($match['status'] ?? '', ['auto_exact', 'auto_partial'], true)) {
            $pdo->prepare('UPDATE bank_statements SET matched_count = 1 WHERE id = ?')->execute([$statementId]);
        }

        return ['statement_id' => $statementId, 'transaction_id' => $transactionId, 'match_result' => $match];
    }

    public function updateScanStatus(int $supplierId, int $imapAccountId, string $status, ?string $message): void
    {
        $this->db->pdo()->prepare(
            'UPDATE bank_email_imap_settings
                SET last_scan_at = NOW(), last_scan_status = ?, last_scan_message = ?
              WHERE supplier_id = ? AND id = ?'
        )->execute([$status, $message !== null ? mb_substr($message, 0, 500) : null, $supplierId, $imapAccountId]);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function imapAccountRaw(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM bank_email_imap_settings WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyImapAccount(int $supplierId): array
    {
        return [
            'id' => null,
            'supplier_id' => $supplierId,
            'name' => 'Výchozí IMAP účet',
            'enabled' => false,
            'host' => '',
            'port' => 993,
            'encryption' => 'ssl',
            'validate_cert' => true,
            'require_email_auth' => false,
            'email_auth_serv_id' => null,
            'username' => '',
            'folder' => 'INBOX',
            'max_messages_per_run' => 50,
            'process_from_date' => null,
            'success_action' => 'none',
            'success_flag' => 'MyInvoiceProcessed',
            'success_move_folder' => null,
            'failure_action' => 'none',
            'failure_flag' => 'MyInvoiceFailed',
            'failure_move_folder' => null,
            'retry_failed' => false,
            'max_attempts' => 3,
            'has_password' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function providerById(int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM bank_email_notice_providers WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            throw new \RuntimeException('Provider nenalezen.');
        }
        $this->castProvider($row);
        return $row;
    }

    private function providerSupplierId(int $id): ?int
    {
        $stmt = $this->db->pdo()->prepare('SELECT supplier_id FROM bank_email_notice_providers WHERE id = ?');
        $stmt->execute([$id]);
        $value = $stmt->fetchColumn();
        if ($value === false) {
            throw new \RuntimeException('Provider nenalezen.');
        }
        return $value !== null ? (int) $value : null;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function castImapAccount(array &$row, bool $includeSecret = false): void
    {
        $row['id'] = (int) $row['id'];
        $row['supplier_id'] = (int) $row['supplier_id'];
        $row['enabled'] = (bool) $row['enabled'];
        $row['port'] = (int) $row['port'];
        $row['validate_cert'] = (bool) $row['validate_cert'];
        $row['require_email_auth'] = (bool) ($row['require_email_auth'] ?? false);
        $row['max_messages_per_run'] = (int) $row['max_messages_per_run'];
        $row['retry_failed'] = (bool) $row['retry_failed'];
        $row['max_attempts'] = (int) $row['max_attempts'];
        $row['has_password'] = !empty($row['password_enc']);
        if ($includeSecret && !empty($row['password_enc'])) {
            $row['password'] = $this->secrets->decrypt((string) $row['password_enc']);
        }
        unset($row['password_enc']);
    }

    /**
     * @param array<string,mixed> $row
     */
    private function castProvider(array &$row): void
    {
        $row['id'] = (int) $row['id'];
        $row['supplier_id'] = $row['supplier_id'] !== null ? (int) $row['supplier_id'] : null;
        $row['enabled'] = (bool) $row['enabled'];
        $row['field_patterns'] = $this->decodeJson($row['field_patterns'] ?? '{}');
        $row['normalizer_config'] = $this->decodeJson($row['normalizer_config'] ?? '{}');
    }

    private function currencyBelongsToSupplier(int $currencyId, int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM currencies WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$currencyId, $supplierId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function imapAccountBelongsToSupplier(int $imapAccountId, int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM bank_email_imap_settings WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$imapAccountId, $supplierId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function providerAvailableToSupplier(int $providerId, int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM bank_email_notice_providers WHERE id = ? AND (supplier_id IS NULL OR supplier_id = ?)'
        );
        $stmt->execute([$providerId, $supplierId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * @return array{0:string,1:?string}
     */
    private function splitAccount(string $value): array
    {
        if (preg_match('/^(?<account>[0-9\-]+)\/(?<bank>[0-9]{4})$/', trim($value), $m) === 1) {
            return [$m['account'], $m['bank']];
        }
        return [$value, null];
    }

    private function enum(string $value, array $allowed, string $default): string
    {
        return in_array($value, $allowed, true) ? $value : $default;
    }

    /**
     * Ověří, že se regex zkompiluje (stejné `~…~u` delimitery jako parser).
     */
    private function assertValidRegex(string $pattern, string $label): void
    {
        set_error_handler(static fn (): bool => true);
        $result = preg_match('~' . $pattern . '~u', '');
        restore_error_handler();
        if ($result === false) {
            throw new \RuntimeException("Neplatný regulární výraz ({$label}).");
        }
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value !== '' ? $value : null;
    }

    private function dateOrNull(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }

    /**
     * @return array<string,mixed>|list<mixed>|null
     */
    private function decodeJson(mixed $value): array|null
    {
        if ($value === null || $value === '') {
            return null;
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : null;
    }
}
