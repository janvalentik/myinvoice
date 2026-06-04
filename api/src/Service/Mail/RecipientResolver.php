<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mail;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Jednotný výpočet e-mailových příjemců pro odchozí zprávy klientům (#86).
 *
 * Nahrazuje šest dříve duplicitních resolveRecipients() implementací
 * (SendEmailAction, ReminderService, AutoIssueAndSendService,
 * RequestApprovalAction, cron-send-approval-reminders, PaymentThanksMailer).
 *
 * Vstupy: typ zprávy + invoice row (client_id, client_main_email, project_id).
 *
 * Logika (priorita shora):
 *   1. Aktivní kontakty klienta (client_email_contacts) s účelem = typ zprávy;
 *      u upomínek fallback na účel `documents` (issue #86).
 *   2. Bez kontaktů pro daný účel platí PŘESNĚ dosavadní legacy logika:
 *        documents/reminders: main_email + project_billing_emails (append),
 *        approvals:           project_billing_emails NEBO main_email (replace,
 *                             nikdy nesměšovat).
 *
 * E-maily zakázky (project_billing_emails) se kombinují dle
 * projects.billing_emails_mode:
 *   auto    (default) — kontakty existují → append; legacy větev → dosavadní
 *                       per-typ sémantika (viz výše). 100% zpětná kompatibilita.
 *   append  — e-maily zakázky se vždy přidají (i pro approvals legacy větev).
 *   replace — e-maily zakázky (jsou-li) příjemce nahradí; prázdné → jako auto.
 *
 * Dedup napříč to/cc/bcc s prioritou to > cc > bcc, stabilní pořadí
 * (kontakty dle sort_order, e-maily zakázky dle position), validace
 * filter_var. `resolved` nese provenanci pro UI modal („kontakt: doklady" /
 * „zakázka" / „hlavní e-mail").
 */
final class RecipientResolver
{
    public const TYPE_DOCUMENTS = 'documents';
    public const TYPE_REMINDERS = 'reminders';
    public const TYPE_APPROVALS = 'approvals';

    public const USAGES = ['communication', 'documents', 'reminders', 'approvals'];
    public const RECIPIENT_KINDS = ['to', 'cc', 'bcc'];

    public function __construct(private readonly Connection $db) {}

    /**
     * @param array<string,mixed> $invoice řádek faktury (client_id, client_main_email, project_id)
     * @return array{to: list<string>, cc: list<string>, bcc: list<string>,
     *               resolved: list<array{email:string, recipient:string, source:string, usage:?string, label:?string}>}
     */
    public function resolve(string $type, array $invoice): array
    {
        if (!in_array($type, [self::TYPE_DOCUMENTS, self::TYPE_REMINDERS, self::TYPE_APPROVALS], true)) {
            throw new \InvalidArgumentException("Neznámý typ zprávy: {$type}");
        }

        $clientId  = (int) ($invoice['client_id'] ?? 0);
        $projectId = !empty($invoice['project_id']) ? (int) $invoice['project_id'] : null;
        $mainEmail = trim((string) ($invoice['client_main_email'] ?? ''));

        [$projectEmails, $mode] = $this->projectEmails($projectId, $type);

        // Kontakty klienta pro daný účel (upomínky: fallback na doklady).
        $entries = $this->contactEntries($clientId, $type);
        if ($entries === [] && $type === self::TYPE_REMINDERS) {
            $entries = $this->contactEntries($clientId, self::TYPE_DOCUMENTS);
        }

        $resolved = [];
        if ($entries !== []) {
            // Nový režim — kontakty jsou autoritativní pro daný účel.
            if ($mode === 'replace' && $projectEmails !== []) {
                foreach ($projectEmails as $pe) {
                    $resolved[] = ['email' => $pe['email'], 'recipient' => 'to', 'source' => 'project', 'usage' => null, 'label' => $pe['label']];
                }
            } else {
                foreach ($entries as $e) {
                    $resolved[] = $e;
                }
                foreach ($projectEmails as $pe) {
                    $resolved[] = ['email' => $pe['email'], 'recipient' => 'to', 'source' => 'project', 'usage' => null, 'label' => $pe['label']];
                }
            }
        } else {
            // Legacy větev — bez kontaktů se chová PŘESNĚ jako před #86.
            $useReplace = ($mode === 'replace')
                || ($mode === 'auto' && $type === self::TYPE_APPROVALS);
            if ($useReplace && $projectEmails !== []) {
                foreach ($projectEmails as $pe) {
                    $resolved[] = ['email' => $pe['email'], 'recipient' => 'to', 'source' => 'project', 'usage' => null, 'label' => $pe['label']];
                }
            } else {
                if ($mainEmail !== '') {
                    $resolved[] = ['email' => $mainEmail, 'recipient' => 'to', 'source' => 'main_email', 'usage' => null, 'label' => null];
                }
                foreach ($projectEmails as $pe) {
                    $resolved[] = ['email' => $pe['email'], 'recipient' => 'to', 'source' => 'project', 'usage' => null, 'label' => $pe['label']];
                }
            }
        }

        return $this->buckets($resolved);
    }

    /**
     * Záznamy kontaktů klienta pro daný účel — jeden entry per (kontakt, účel).
     *
     * @return list<array{email:string, recipient:string, source:string, usage:?string, label:?string}>
     */
    private function contactEntries(int $clientId, string $usage): array
    {
        if ($clientId <= 0) {
            return [];
        }
        $entries = [];
        foreach ($this->activeContacts($clientId) as $contact) {
            foreach ($contact['usages'] as $u) {
                if (($u['usage'] ?? '') !== $usage) {
                    continue;
                }
                $recipient = in_array($u['recipient'] ?? 'to', self::RECIPIENT_KINDS, true)
                    ? (string) $u['recipient'] : 'to';
                $entries[] = [
                    'email'     => $contact['email'],
                    'recipient' => $recipient,
                    'source'    => 'contact',
                    'usage'     => $usage,
                    'label'     => $contact['label'],
                ];
            }
        }
        return $entries;
    }

    /**
     * @return list<array{email:string, label:?string, usages:list<array{usage:string, recipient:string}>}>
     */
    private function activeContacts(int $clientId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT email, label, usages FROM client_email_contacts
              WHERE client_id = ? AND is_active = 1
              ORDER BY sort_order, id'
        );
        $stmt->execute([$clientId]);
        $contacts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $usages = json_decode((string) $row['usages'], true);
            $contacts[] = [
                'email'  => trim((string) $row['email']),
                'label'  => $row['label'] !== null && $row['label'] !== '' ? (string) $row['label'] : null,
                'usages' => is_array($usages) ? $usages : [],
            ];
        }
        return $contacts;
    }

    /**
     * E-maily zakázky relevantní pro daný typ zprávy. Sloupec `usages` (JSON
     * pole stringů) omezuje e-mail na typy zpráv; NULL/prázdné = všechny typy
     * (default, zpětná kompatibilita existujících řádků).
     *
     * @return array{0: list<array{email:string, label:?string}>, 1: string} [e-maily zakázky, mode]
     */
    private function projectEmails(?int $projectId, string $type): array
    {
        if ($projectId === null) {
            return [[], 'auto'];
        }
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT billing_emails_mode FROM projects WHERE id = ?');
        $stmt->execute([$projectId]);
        $mode = (string) ($stmt->fetchColumn() ?: 'auto');
        if (!in_array($mode, ['auto', 'append', 'replace'], true)) {
            $mode = 'auto';
        }

        $stmt = $pdo->prepare(
            'SELECT email, label, usages FROM project_billing_emails WHERE project_id = ? ORDER BY position'
        );
        $stmt->execute([$projectId]);
        $emails = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $em = trim((string) $row['email']);
            if ($em === '') {
                continue;
            }
            $usages = $row['usages'] !== null ? json_decode((string) $row['usages'], true) : null;
            if (is_array($usages) && $usages !== [] && !in_array($type, $usages, true)) {
                continue; // e-mail omezen na jiné typy zpráv
            }
            $emails[] = ['email' => $em, 'label' => $row['label'] !== null && $row['label'] !== '' ? (string) $row['label'] : null];
        }
        return [$emails, $mode];
    }

    /**
     * Rozdělí resolved entries do to/cc/bcc: validace, dedup s prioritou
     * to > cc > bcc (duplicitní e-mail si nechá nejsilnější roli; v rámci
     * stejné role vyhrává první výskyt — stabilní pořadí).
     *
     * @param list<array{email:string, recipient:string, source:string, usage:?string, label:?string}> $resolved
     * @return array{to: list<string>, cc: list<string>, bcc: list<string>,
     *               resolved: list<array{email:string, recipient:string, source:string, usage:?string, label:?string}>}
     */
    private function buckets(array $resolved): array
    {
        $priority = ['to' => 0, 'cc' => 1, 'bcc' => 2];

        // 1. validace + nejsilnější role per e-mail (case-insensitive klíč)
        $best = [];
        foreach ($resolved as $entry) {
            $em = trim($entry['email']);
            if ($em === '' || !filter_var($em, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $key = mb_strtolower($em);
            // První výskyt v nejsilnější roli vyhrává (pořadí entries je stabilní).
            if (!isset($best[$key]) || $priority[$entry['recipient']] < $priority[$best[$key]['recipient']]) {
                $entry['email'] = $em;
                $best[$key] = $entry;
            }
        }

        // 2. buckety ve stabilním pořadí podle prvního výskytu v $resolved
        $out = ['to' => [], 'cc' => [], 'bcc' => [], 'resolved' => []];
        $seen = [];
        foreach ($resolved as $entry) {
            $key = mb_strtolower(trim($entry['email']));
            if ($key === '' || !isset($best[$key]) || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $final = $best[$key];
            $out[$final['recipient']][] = $final['email'];
            $out['resolved'][] = $final;
        }
        return $out;
    }
}
