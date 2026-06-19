<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payment;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PaymentOrderRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Ares\CrpDphClient;
use MyInvoice\Service\Bank\VariableSymbolNormalizer;
use MyInvoice\Service\Export\ExportFilename;
use MyInvoice\Service\Pdf\PaymentOrderPdfRenderer;

/**
 * Platební příkazy (payment orders) — hromadné generování příkazu k úhradě z
 * nezaplacených přijatých faktur.
 *
 * Tok: kandidáti (nezaplacené faktury + ověření účtu) → výběr + účet plátce + datum
 * → snapshot dávky (payment_orders + items) → export CSV / PDF / ABO(KPC).
 * Po vytvoření se faktury označí `payment_ordered_at` („Zařazeno k úhradě");
 * status se NEpřeklápí na paid (to dělá až párování výpisu), pokud uživatel výslovně
 * nezvolí `mark_paid`.
 *
 * ABO/KPC je tuzemský CZK platební styk → dávka má jednu měnu (= měna účtu plátce);
 * cizí měny (EUR…) lze exportovat jen do CSV/PDF.
 */
final class PaymentOrderService
{
    public function __construct(
        private readonly PurchaseInvoiceRepository $invoices,
        private readonly PaymentOrderRepository $orders,
        private readonly CrpDphClient $crpdph,
        private readonly AboPaymentOrderWriter $abo,
        private readonly PaymentOrderCsvWriter $csv,
        private readonly PaymentOrderPdfRenderer $pdf,
        private readonly Connection $db,
    ) {}

    /**
     * Kandidáti do příkazu + dostupné účty plátce. Volitelný filtr měny.
     *
     * @return array{payer_accounts: list<array<string,mixed>>, candidates: list<array<string,mixed>>}
     */
    public function candidates(int $supplierId, ?string $currency = null): array
    {
        $payerAccounts = $this->orders->payerAccounts($supplierId);
        $rows = $this->invoices->listPaymentCandidates($supplierId, $currency);

        $candidates = [];
        foreach ($rows as $r) {
            $payee = [
                'account_number' => $r['payment_account_number'] ?? null,
                'bank_code'      => $r['payment_bank_code'] ?? null,
                'iban'           => $r['payment_iban'] ?? null,
                'bic'            => $r['payment_bic'] ?? null,
            ];
            $hasCz   = ($payee['account_number'] ?? '') !== '' && ($payee['bank_code'] ?? '') !== '';
            $hasIban = ($payee['iban'] ?? '') !== '';

            $candidates[] = [
                'id'                     => $r['id'],
                'vendor_id'              => $r['vendor_id'],
                'vendor_company_name'    => $r['vendor_company_name'],
                'vendor_dic'             => $r['vendor_dic'],
                'vendor_invoice_number'  => $r['vendor_invoice_number'],
                'varsymbol'              => $r['varsymbol'],
                'document_kind'          => $r['document_kind'],
                'issue_date'             => $r['issue_date'],
                'due_date'               => $r['due_date'],
                'currency'               => $r['currency'],
                'currency_symbol'        => $r['currency_symbol'],
                'amount_to_pay'          => $r['amount_to_pay'],
                'total_with_vat'         => $r['total_with_vat'],
                'account_number'         => $payee['account_number'],
                'bank_code'              => $payee['bank_code'],
                'iban'                   => $payee['iban'],
                'bic'                    => $payee['bic'],
                'variable_symbol'        => $this->variableSymbol($r),
                'constant_symbol'        => $r['payment_constant_symbol'] ?? null,
                'payment_account_source' => $r['payment_account_source'] ?? null,
                'payment_ordered_at'     => $r['payment_ordered_at'] ?? null,
                'has_account'            => $hasCz || $hasIban,
                'has_pdf'                => (bool) ($r['has_pdf'] ?? false),
                'abo_eligible'           => $hasCz && strtoupper((string) $r['currency']) === 'CZK',
                'can_verify'             => $this->canVerify((string) ($r['vendor_dic'] ?? '')),
                'account_verified'       => $this->verify((string) ($r['vendor_dic'] ?? ''), $payee),
            ];
        }

        return ['payer_accounts' => $payerAccounts, 'candidates' => $candidates];
    }

    /**
     * Vytvoří (uloží) platební příkaz ze zvolených faktur.
     *
     * @param array{invoice_ids?:list<int>, payer_currency_id?:int, payment_date?:string,
     *              constant_symbol?:?string, note?:?string, mark_paid?:bool} $input
     * @return array{order_id:int, view:array<string,mixed>, skipped:list<array{id:int,reason:string}>,
     *               clamped_date:bool}
     *
     * @throws \InvalidArgumentException při neplatném vstupu / prázdné dávce
     */
    public function create(int $supplierId, array $input, ?int $userId): array
    {
        $ids = array_values(array_unique(array_map('intval', (array) ($input['invoice_ids'] ?? []))));
        if ($ids === []) {
            throw new \InvalidArgumentException('Není vybrána žádná faktura.');
        }
        if (count($ids) > 500) {
            throw new \InvalidArgumentException('Najednou lze zařadit maximálně 500 faktur.');
        }

        $payerCurrencyId = (int) ($input['payer_currency_id'] ?? 0);
        $payer = $payerCurrencyId > 0 ? $this->orders->payerAccount($payerCurrencyId, $supplierId) : null;
        if ($payer === null) {
            throw new \InvalidArgumentException('Vyberte platný účet plátce.');
        }
        $orderCurrency = strtoupper((string) $payer['code']);

        // Datum splatnosti: ABO nesmí mít datum v minulosti → ořízni na dnešek.
        $today = date('Y-m-d');
        $paymentDate = (string) ($input['payment_date'] ?? '');
        $paymentDate = $paymentDate !== '' ? date('Y-m-d', strtotime($paymentDate)) : $today;
        $clamped = false;
        if ($paymentDate < $today) {
            $paymentDate = $today;
            $clamped = true;
        }

        $batchKs = $this->digitsOrNull((string) ($input['constant_symbol'] ?? ''));

        $items = [];
        $skipped = [];
        $validIds = [];
        foreach ($ids as $id) {
            $inv = $this->invoices->find($id, $supplierId);
            if ($inv === null) {
                $skipped[] = ['id' => $id, 'reason' => 'not_found'];
                continue;
            }
            if (strtoupper((string) ($inv['currency'] ?? '')) !== $orderCurrency) {
                $skipped[] = ['id' => $id, 'reason' => 'currency_mismatch'];
                continue;
            }
            $amount = (float) ($inv['amount_to_pay'] ?? 0);
            if ($amount <= 0) {
                $skipped[] = ['id' => $id, 'reason' => 'nothing_to_pay'];
                continue;
            }
            $account = $inv['payment_account_number'] ?? null;
            $bank    = $inv['payment_bank_code'] ?? null;
            $iban    = $inv['payment_iban'] ?? null;
            $hasCz   = ($account ?? '') !== '' && ($bank ?? '') !== '';
            if (!$hasCz && ($iban ?? '') === '') {
                $skipped[] = ['id' => $id, 'reason' => 'no_account'];
                continue;
            }

            $payee = ['account_number' => $account, 'bank_code' => $bank, 'iban' => $iban, 'bic' => $inv['payment_bic'] ?? null];
            $vs = $this->variableSymbol($inv);
            $message = (string) ($inv['vendor_invoice_number'] ?? '');
            if ($message === '') {
                $message = $vs;
            }

            $items[] = [
                'purchase_invoice_id' => $id,
                'payee_name'          => $inv['vendor_company_name'] ?? null,
                'payee_account_number' => $account,
                'payee_bank_code'     => $bank,
                'payee_iban'          => $iban,
                'payee_bic'           => $inv['payment_bic'] ?? null,
                'amount'              => $amount,
                'currency'            => $orderCurrency,
                'variable_symbol'     => $vs !== '' ? $vs : null,
                'constant_symbol'     => $batchKs ?? $this->digitsOrNull((string) ($inv['payment_constant_symbol'] ?? '')),
                'specific_symbol'     => null,
                'message'             => $message !== '' ? $message : null,
                'account_verified'    => $this->verify((string) ($inv['vendor_dic'] ?? ''), $payee),
            ];
            $validIds[] = $id;
        }

        if ($items === []) {
            throw new \InvalidArgumentException('Žádná z vybraných faktur není pro příkaz použitelná.');
        }

        $total = 0.0;
        foreach ($items as $it) {
            $total += (float) $it['amount'];
        }

        $orderId = $this->orders->create([
            'supplier_id'          => $supplierId,
            'currency'             => $orderCurrency,
            'payer_currency_id'    => $payerCurrencyId,
            'payer_account_number' => $payer['account_number'] ?? null,
            'payer_bank_code'      => $payer['bank_code'] ?? null,
            'payer_iban'           => $payer['iban'] ?? null,
            'payer_bic'            => $payer['bic'] ?? null,
            'payer_account_label'  => $payer['label'] ?? null,
            'payment_date'         => $paymentDate,
            'total_amount'         => $total,
            'note'                 => $this->nullableString($input['note'] ?? null),
            'mark_paid'            => !empty($input['mark_paid']),
            'created_by_user_id'   => $userId,
        ], $items);

        // „Zařazeno k úhradě" + volitelný flip na zaplaceno.
        $this->invoices->markPaymentOrdered($validIds, $supplierId);
        if (!empty($input['mark_paid'])) {
            foreach ($validIds as $id) {
                $this->invoices->setStatus($id, 'paid', $supplierId, $paymentDate);
            }
        }

        $view = $this->view($orderId, $supplierId);

        return [
            'order_id'     => $orderId,
            'view'         => $view ?? [],
            'skipped'      => $skipped,
            'clamped_date' => $clamped,
        ];
    }

    /**
     * Kanonický pohled na uloženou dávku (pro frontend i writery). NULL když neexistuje.
     *
     * @return array<string,mixed>|null
     */
    public function view(int $orderId, int $supplierId): ?array
    {
        $order = $this->orders->find($orderId, $supplierId);
        if ($order === null) {
            return null;
        }
        $supplier = $this->supplierInfo($supplierId);

        $items = [];
        foreach ((array) $order['items'] as $it) {
            $items[] = [
                'purchase_invoice_id' => $it['purchase_invoice_id'],
                'payee_name'          => $it['payee_name'],
                'account_number'      => $it['payee_account_number'],
                'bank_code'           => $it['payee_bank_code'],
                'iban'                => $it['payee_iban'],
                'bic'                 => $it['payee_bic'],
                'amount'              => $it['amount'],
                'currency'            => $it['currency'],
                'variable_symbol'     => $it['variable_symbol'],
                'constant_symbol'     => $it['constant_symbol'],
                'specific_symbol'     => $it['specific_symbol'],
                'message'             => $it['message'],
                'account_verified'    => $it['account_verified'],
            ];
        }

        return [
            'id'           => $order['id'],
            'currency'     => $order['currency'],
            'payment_date' => $order['payment_date'],
            'created_at'   => $order['created_at'],
            'note'         => $order['note'],
            'mark_paid'    => $order['mark_paid'],
            'total_amount' => $order['total_amount'],
            'item_count'   => $order['item_count'],
            'payer'        => [
                'account_number' => $order['payer_account_number'],
                'bank_code'      => $order['payer_bank_code'],
                'iban'           => $order['payer_iban'],
                'bic'            => $order['payer_bic'],
                'label'          => $order['payer_account_label'],
            ],
            'supplier'     => $supplier,
            'items'        => $items,
        ];
    }

    /** Historie dávek (bez položek). @return list<array<string,mixed>> */
    public function history(int $supplierId): array
    {
        return $this->orders->history($supplierId);
    }

    /**
     * „Jen označit" — zařadí vybrané faktury k úhradě (payment_ordered_at) BEZ vytvoření
     * dávky/exportu. Volitelně rovnou paid. Vrací počet skutečně označených (vlastněných).
     *
     * @param list<int> $invoiceIds
     */
    public function markOrdered(int $supplierId, array $invoiceIds, bool $markPaid): int
    {
        $valid = [];
        foreach (array_unique(array_map('intval', $invoiceIds)) as $id) {
            if ($this->invoices->find($id, $supplierId) !== null) {
                $valid[] = $id;
            }
        }
        if ($valid === []) {
            return 0;
        }
        $this->invoices->markPaymentOrdered($valid, $supplierId);
        if ($markPaid) {
            foreach ($valid as $id) {
                $this->invoices->setStatus($id, 'paid', $supplierId);
            }
        }
        return count($valid);
    }

    /**
     * Vyrenderuje dávku do zvoleného formátu.
     *
     * @return array{filename:string, content_type:string, bytes:string}|null
     * @throws \RuntimeException při nepodporovaném formátu / nevhodných datech pro ABO
     */
    public function download(int $orderId, int $supplierId, string $format): ?array
    {
        $view = $this->view($orderId, $supplierId);
        if ($view === null) {
            return null;
        }
        $datePart = ExportFilename::sanitize((string) $view['payment_date'], 'prikaz');
        $base = 'platebni-prikaz-' . $orderId . '-' . $datePart;

        return match ($format) {
            'csv' => [
                'filename'     => $base . '.csv',
                'content_type' => 'text/csv; charset=utf-8',
                'bytes'        => $this->csv->build($view),
            ],
            'pdf' => [
                'filename'     => $base . '.pdf',
                'content_type' => 'application/pdf',
                'bytes'        => $this->pdf->render($view),
            ],
            'abo', 'kpc' => [
                'filename'     => $base . '.kpc',
                'content_type' => 'text/plain; charset=utf-8',
                'bytes'        => $this->abo->build($this->toAboInput($view)),
            ],
            default => throw new \RuntimeException('Nepodporovaný formát: ' . $format),
        };
    }

    /**
     * Mapuje kanonický pohled na vstup pro AboPaymentOrderWriter.
     *
     * @param array<string,mixed> $view
     * @return array<string,mixed>
     */
    private function toAboInput(array $view): array
    {
        if (strtoupper((string) $view['currency']) !== 'CZK') {
            throw new \RuntimeException('ABO/KPC export je možný jen pro CZK příkazy.');
        }
        $payer = (array) $view['payer'];
        $supplier = (array) $view['supplier'];

        $items = [];
        foreach ((array) $view['items'] as $it) {
            $items[] = [
                'account_number'  => $it['account_number'],
                'bank_code'       => $it['bank_code'],
                'amount'          => $it['amount'],
                'variable_symbol' => $it['variable_symbol'],
                'constant_symbol' => $it['constant_symbol'],
                'specific_symbol' => $it['specific_symbol'],
                'message'         => $it['message'],
            ];
        }

        return [
            'client_name'          => $supplier['company_name'] ?? '',
            'client_number'        => $supplier['abo_client_number'] ?? null,
            'file_number'          => str_pad((string) ((int) $view['id'] % 1000000), 6, '0', STR_PAD_LEFT),
            'payer_account_number' => (string) ($payer['account_number'] ?? ''),
            'payer_bank_code'      => (string) ($payer['bank_code'] ?? ''),
            'payment_date'         => (string) $view['payment_date'],
            'items'                => $items,
        ];
    }

    /**
     * On-demand kontrola účtu jedné faktury proti zveřejněným účtům plátce DPH (CRPDPH).
     * Vrací stav + seznam zveřejněných účtů (k ručnímu porovnání). NULL když faktura není.
     *
     * @return array{account_verified:string, found:bool, unreliable:?bool,
     *               accounts:list<string>, dic:?string}|null
     */
    public function verifyInvoiceAccount(int $supplierId, int $invoiceId): ?array
    {
        $inv = $this->invoices->find($invoiceId, $supplierId);
        if ($inv === null) {
            return null;
        }
        $dic = (string) ($inv['vendor_dic'] ?? '');
        $payee = [
            'account_number' => $inv['payment_account_number'] ?? null,
            'bank_code'      => $inv['payment_bank_code'] ?? null,
            'iban'           => $inv['payment_iban'] ?? null,
        ];

        if (!$this->canVerify($dic)) {
            return ['account_verified' => 'na', 'found' => false, 'unreliable' => null, 'accounts' => [], 'dic' => $dic ?: null];
        }

        $res = $this->crpdph->lookup($dic);
        $verified = $this->verify($dic, $payee);
        $accounts = array_values(array_filter(array_map(
            static fn ($a) => (string) ($a['display'] ?? ''),
            (array) ($res['accounts'] ?? [])
        )));

        return [
            'account_verified' => $verified,
            'found'            => (bool) ($res['found'] ?? false),
            'unreliable'       => $res['unreliable'] ?? null,
            'accounts'         => $accounts,
            'dic'              => $dic,
        ];
    }

    /** Lze ověřit přes CRPDPH? (tuzemské DIČ — 8–10 číslic). */
    private function canVerify(string $dic): bool
    {
        return preg_match('/^\d{8,10}$/', (string) preg_replace('/\D/', '', $dic)) === 1;
    }

    /**
     * Ověření účtu příjemce proti registru plátců DPH (CRPDPH). Vrací:
     *   verified   = zveřejněný účet plátce,
     *   not_listed = plátce nalezen, ale účet není mezi zveřejněnými,
     *   unreliable = nespolehlivý plátce (riziko ručení za DPH),
     *   na         = nelze ověřit (ne-CZ DIČ, prázdné, služba nedostupná).
     *
     * @param array{account_number?:?string, bank_code?:?string, iban?:?string} $payee
     */
    private function verify(string $vendorDic, array $payee): string
    {
        $digits = (string) preg_replace('/\D/', '', $vendorDic);
        if (strlen($digits) < 8) {
            return 'na';
        }
        $res = $this->crpdph->lookup($vendorDic);
        if (($res['source'] ?? '') === 'error' || empty($res['found'])) {
            return 'na';
        }
        if (($res['unreliable'] ?? null) === true) {
            return 'unreliable';
        }
        return $this->accountMatches($payee, (array) ($res['accounts'] ?? [])) ? 'verified' : 'not_listed';
    }

    /**
     * @param array{account_number?:?string, bank_code?:?string, iban?:?string} $payee
     * @param list<array{prefix:string,number:string,bank_code:string,iban:?string}> $accounts
     */
    private function accountMatches(array $payee, array $accounts): bool
    {
        $payeeIban = strtoupper((string) preg_replace('/\s+/', '', (string) ($payee['iban'] ?? '')));
        [$pPrefix, $pNumber] = $this->splitAccount((string) ($payee['account_number'] ?? ''));
        $pBank = (string) preg_replace('/\D/', '', (string) ($payee['bank_code'] ?? ''));

        foreach ($accounts as $a) {
            $aIban = strtoupper((string) ($a['iban'] ?? ''));
            if ($payeeIban !== '' && $aIban !== '' && $payeeIban === $aIban) {
                return true;
            }
            $aNumber = (string) ($a['number'] ?? '');
            $aBank   = (string) ($a['bank_code'] ?? '');
            $aPrefix = (string) ($a['prefix'] ?? '');
            if ($pNumber !== '' && $aNumber !== ''
                && ltrim($pNumber, '0') === ltrim($aNumber, '0')
                && $pBank === $aBank
                && ltrim($pPrefix, '0') === ltrim($aPrefix, '0')) {
                return true;
            }
        }
        return false;
    }

    /** @return array{0:string,1:string} [prefix, number] (jen číslice, bez paddingu) */
    private function splitAccount(string $account): array
    {
        $account = (string) preg_replace('/\s+/', '', $account);
        $slash = strpos($account, '/');
        if ($slash !== false) {
            $account = substr($account, 0, $slash);
        }
        if (str_contains($account, '-')) {
            [$p, $n] = explode('-', $account, 2);
        } else {
            $p = '';
            $n = $account;
        }
        return [(string) preg_replace('/\D/', '', $p), (string) preg_replace('/\D/', '', $n)];
    }

    /** VS pro platbu: payment_variable_symbol → vendor_invoice_number → varsymbol. */
    private function variableSymbol(array $inv): string
    {
        foreach ([
            (string) ($inv['payment_variable_symbol'] ?? ''),
            (string) ($inv['vendor_invoice_number'] ?? ''),
            (string) ($inv['varsymbol'] ?? ''),
        ] as $c) {
            $vs = VariableSymbolNormalizer::forPayment($c);
            if ($vs !== '') {
                return $vs;
            }
        }
        return '';
    }

    /** @return array{company_name:?string, abo_client_number:?string} */
    private function supplierInfo(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT company_name, abo_client_number FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        return [
            'company_name'      => $row['company_name'] ?? null,
            'abo_client_number' => $row['abo_client_number'] ?? null,
        ];
    }

    private function digitsOrNull(string $s): ?string
    {
        $d = (string) preg_replace('/\D/', '', $s);
        return $d === '' ? null : $d;
    }

    private function nullableString(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);
        return $s === '' ? null : mb_substr($s, 0, 255);
    }
}
