<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\EmailNotice;

use MyInvoice\Repository\BankEmailNoticeRepository;
use MyInvoice\Service\Bank\StatementMatcher;
use MyInvoice\Service\Bank\EmailNotice\Parser\BankEmailNoticeParserRepository;

final class BankEmailNoticeScanner
{
    public function __construct(
        private readonly BankEmailNoticeRepository $repo,
        private readonly BankEmailNoticeParserRepository $parsers,
        private readonly ImapMailboxClientInterface $imap,
        private readonly StatementMatcher $matcher,
        private readonly EmailAuthenticationVerifier $authVerifier = new EmailAuthenticationVerifier(),
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function scanSupplier(int $supplierId, ?int $limitOverride = null): array
    {
        $accounts = array_values(array_filter(
            $this->repo->imapAccounts($supplierId, true),
            static fn (array $account): bool => !empty($account['enabled']),
        ));
        if ($accounts === []) {
            return ['supplier_id' => $supplierId, 'skipped' => 'disabled', 'accounts' => []];
        }

        $summary = [
            'supplier_id' => $supplierId,
            'fetched' => 0,
            'processed' => 0,
            'matched' => 0,
            'known_skipped' => 0,
            'old_skipped' => 0,
            'security_rejected' => 0,
            'errors' => 0,
            'postprocess_errors' => 0,
            'accounts' => [],
        ];

        foreach ($accounts as $settings) {
            $accountSummary = $this->scanAccount($supplierId, $settings, $limitOverride);
            $summary['accounts'][] = $accountSummary;
            foreach (['fetched', 'processed', 'matched', 'known_skipped', 'old_skipped', 'security_rejected', 'errors', 'postprocess_errors'] as $key) {
                $summary[$key] += (int) ($accountSummary[$key] ?? 0);
            }
        }

        return $summary;
    }

    /**
     * @param array<string,mixed> $settings
     * @return array<string,mixed>
     */
    private function scanAccount(int $supplierId, array $settings, ?int $limitOverride): array
    {
        $accountId = (int) ($settings['id'] ?? 0);
        $accountName = (string) ($settings['name'] ?? ('IMAP #' . $accountId));
        foreach (['host', 'username', 'password'] as $required) {
            if (trim((string) ($settings[$required] ?? '')) === '') {
                $msg = "IMAP není nakonfigurován: chybí {$required}.";
                $this->repo->updateScanStatus($supplierId, $accountId, 'error', $msg);
                return [
                    'supplier_id' => $supplierId,
                    'imap_account_id' => $accountId,
                    'imap_account_name' => $accountName,
                    'error' => $msg,
                    'fetched' => 0,
                    'processed' => 0,
                    'matched' => 0,
                    'known_skipped' => 0,
                    'old_skipped' => 0,
                    'security_rejected' => 0,
                    'errors' => 1,
                    'postprocess_errors' => 0,
                    'details' => [],
                ];
            }
        }

        $limit = $limitOverride !== null
            ? max(1, min(500, $limitOverride))
            : max(1, (int) ($settings['max_messages_per_run'] ?? 50));

        $summary = [
            'supplier_id' => $supplierId,
            'imap_account_id' => $accountId,
            'imap_account_name' => $accountName,
            'fetched' => 0,
            'processed' => 0,
            'matched' => 0,
            'known_skipped' => 0,
            'old_skipped' => 0,
            'security_rejected' => 0,
            'errors' => 0,
            'postprocess_errors' => 0,
            'details' => [],
        ];

        try {
            $messages = $this->imap->latest($settings, $limit);
            $summary['fetched'] = count($messages);
            foreach ($messages as $message) {
                $result = $this->processMessage($supplierId, $settings, $message);
                $summary['details'][] = $result;
                $status = (string) ($result['status'] ?? '');
                if ($status === 'processed_success') {
                    $summary['processed']++;
                    if (!empty($result['matched'])) {
                        $summary['matched']++;
                    }
                } elseif ($status === 'skipped_known') {
                    $summary['known_skipped']++;
                } elseif ($status === 'skipped_old') {
                    $summary['old_skipped']++;
                } elseif ($status === 'security_rejected') {
                    $summary['security_rejected']++;
                } else {
                    $summary['errors']++;
                }
                if (!empty($result['postprocess_error'])) {
                    $summary['postprocess_errors']++;
                }
            }
            $this->repo->updateScanStatus($supplierId, $accountId, 'ok', null);
        } catch (\Throwable $e) {
            $summary['error'] = $e->getMessage();
            $this->repo->updateScanStatus($supplierId, $accountId, 'error', $e->getMessage());
        }

        return $summary;
    }

    /**
     * @param array<string,mixed> $settings
     * @return array<string,mixed>
     */
    private function processMessage(int $supplierId, array $settings, BankEmailNoticeMessage $message): array
    {
        $imapAccountId = (int) ($settings['id'] ?? 0);
        $messageId = $message->messageId;
        $hash = $message->fallbackHash();
        $base = [
            'supplier_id' => $supplierId,
            'imap_account_id' => $imapAccountId,
            'imap_uid' => $message->uid,
            'message_id' => $messageId,
            'fallback_hash' => $hash,
            'message_date' => $message->date?->format('Y-m-d H:i:s'),
            'sender' => $message->sender,
            'subject' => $message->subject,
        ];

        $processFrom = trim((string) ($settings['process_from_date'] ?? ''));
        if ($processFrom !== '' && $message->date instanceof \DateTimeImmutable) {
            $min = new \DateTimeImmutable($processFrom . ' 00:00:00');
            if ($message->date < $min) {
                return ['status' => 'skipped_old', 'message_id' => $messageId, 'uid' => $message->uid];
            }
        }

        $known = $this->repo->knownMessage($supplierId, $imapAccountId, $messageId, $hash);
        if ($known !== null) {
            return [
                'status' => 'skipped_known',
                'message_id' => $messageId,
                'uid' => $message->uid,
                'imap_account_id' => $imapAccountId,
                'known_id' => (int) $known['id'],
            ];
        }

        // Možnost A: ověření autenticity z hlavičky Authentication-Results (DKIM/DMARC).
        // Fail-open: kontrola se spustí jen když ji admin u účtu zapnul.
        if (!empty($settings['require_email_auth'])) {
            $auth = $this->authVerifier->verify(
                $message->authResults,
                $this->authVerifier->domainFromSender($message->sender),
                isset($settings['email_auth_serv_id']) ? (string) $settings['email_auth_serv_id'] : null,
            );
            if (!$auth['pass']) {
                $this->repo->recordMessage($base + [
                    'status' => 'security_rejected',
                    'error_message' => 'E-mail neprošel ověřením autenticity (DKIM/DMARC): ' . $auth['detail'],
                ]);
                $this->safePostProcess($settings, $message, 'failure');
                return [
                    'status' => 'security_rejected',
                    'message_id' => $messageId,
                    'imap_account_id' => $imapAccountId,
                    'reason' => $auth['detail'],
                ];
            }
        }

        try {
            $resolved = $this->parseAndResolveMapping($supplierId, $imapAccountId, $message);
            $provider = $resolved['provider'];
            $notice = $resolved['notice'];
            $mapping = $resolved['mapping'];
            if ($mapping === null) {
                $this->repo->recordMessage($base + [
                    'provider_id' => $provider['id'] ?? null,
                    'provider_code' => $provider['code'] ?? null,
                    'status' => 'match_failed',
                    'parsed_payload' => $notice->toArray(),
                    'error_message' => 'Cílový účet avíza není namapovaný na bankovní účet dodavatele.',
                ]);
                $this->safePostProcess($settings, $message, 'failure');
                return ['status' => 'match_failed', 'message_id' => $messageId, 'reason' => 'account_mapping_missing'];
            }

            $tx = $this->repo->createTransactionFromNotice(
                $supplierId,
                $notice,
                'imap-' . $imapAccountId . ':' . ($messageId ?: $hash),
                (float) ($mapping['amount_tolerance'] ?? 0.05),
                $this->matcher,
            );
            $match = $tx['match_result'];
            $matchedInvoiceId = isset($match['invoice_id']) ? (int) $match['invoice_id'] : null;
            $status = $matchedInvoiceId !== null ? 'processed_success' : 'match_failed';
            $postError = null;
            if ($status === 'processed_success') {
                $postError = $this->safePostProcess($settings, $message, 'success');
                if ($postError !== null) {
                    $status = 'postprocess_failed';
                }
            } else {
                $postError = $this->safePostProcess($settings, $message, 'failure');
            }
            $recordId = $this->repo->recordMessage($base + [
                'provider_id' => $provider['id'] ?? null,
                'provider_code' => $provider['code'] ?? null,
                'status' => $status,
                'parsed_payload' => $notice->toArray() + ['match_result' => $match],
                'bank_statement_id' => $tx['statement_id'],
                'bank_transaction_id' => $tx['transaction_id'],
                'matched_invoice_id' => $matchedInvoiceId,
                'error_message' => $postError,
            ]);

            return [
                'status' => $status,
                'id' => $recordId,
                'message_id' => $messageId,
                'imap_account_id' => $imapAccountId,
                'transaction_id' => $tx['transaction_id'],
                'matched' => $matchedInvoiceId !== null,
                'match_status' => $match['status'] ?? null,
                'postprocess_error' => $postError,
            ];
        } catch (\Throwable $e) {
            $this->repo->recordMessage($base + [
                'status' => 'parse_failed',
                'error_message' => $e->getMessage(),
            ]);
            $postError = $this->safePostProcess($settings, $message, 'failure');
            return [
                'status' => 'parse_failed',
                'message_id' => $messageId,
                'imap_account_id' => $imapAccountId,
                'error' => $e->getMessage(),
                'postprocess_error' => $postError,
            ];
        }
    }

    /**
     * @param array<string,mixed> $settings
     */
    private function safePostProcess(array $settings, BankEmailNoticeMessage $message, string $kind): ?string
    {
        return $this->imap->postProcess($settings, $message, $kind);
    }

    /**
     * @return array{provider:array<string,mixed>,notice:ParsedBankEmailNotice,mapping:array<string,mixed>|null}
     */
    private function parseAndResolveMapping(int $supplierId, int $imapAccountId, BankEmailNoticeMessage $message): array
    {
        $imapFilter = $imapAccountId > 0 ? $imapAccountId : null;
        $fallback = null;
        $lastError = null;

        foreach ($this->preferredProviderIds($supplierId, $imapAccountId) as $providerId) {
            try {
                $parsed = $this->parsers->parse($message, $providerId, $supplierId);
            } catch (\Throwable $e) {
                $lastError = $e;
                continue;
            }

            $provider = $parsed['provider'];
            $notice = $parsed['parsed'];
            $mapping = $this->repo->mappingForRecipientAccount(
                $supplierId,
                $notice->recipientAccount,
                $imapFilter,
                (int) $provider['id'],
            );
            $resolved = ['provider' => $provider, 'notice' => $notice, 'mapping' => $mapping];
            if ($mapping !== null) {
                return $resolved;
            }
            $fallback ??= $resolved;
        }

        try {
            $parsed = $this->parsers->parse($message, null, $supplierId);
            $provider = $parsed['provider'];
            $notice = $parsed['parsed'];
            return [
                'provider' => $provider,
                'notice' => $notice,
                'mapping' => $this->repo->mappingForRecipientAccount(
                    $supplierId,
                    $notice->recipientAccount,
                    $imapFilter,
                    isset($provider['id']) ? (int) $provider['id'] : null,
                ),
            ];
        } catch (\Throwable $e) {
            if ($fallback !== null) {
                return $fallback;
            }
            throw $lastError ?? $e;
        }
    }

    /**
     * @return list<int>
     */
    private function preferredProviderIds(int $supplierId, int $imapAccountId): array
    {
        $ids = [];
        foreach ($this->repo->accountMappings($supplierId) as $mapping) {
            if (empty($mapping['enabled']) || empty($mapping['provider_id'])) {
                continue;
            }
            if ($imapAccountId > 0 && $mapping['imap_account_id'] !== null && (int) $mapping['imap_account_id'] !== $imapAccountId) {
                continue;
            }
            $ids[(int) $mapping['provider_id']] = true;
        }
        return array_keys($ids);
    }
}
