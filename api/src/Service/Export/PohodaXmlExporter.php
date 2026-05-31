<?php

declare(strict_types=1);

namespace MyInvoice\Service\Export;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;

/**
 * Stormware Pohoda XML data package exporter.
 *
 * Spec: https://www.stormware.cz/xml/  (Pohoda mServer XML komunikace)
 * Namespaces:
 *   dat — http://www.stormware.cz/schema/version_2/data.xsd
 *   inv — http://www.stormware.cz/schema/version_2/invoice.xsd
 *   typ — http://www.stormware.cz/schema/version_2/type.xsd
 *
 * Vytváří jeden `<dat:dataPack>` se všemi fakturami za dané období.
 *
 * Mapování invoice_type → invoiceType:
 *   invoice      → issuedInvoice
 *   proforma     → issuedAdvanceInvoice
 *   credit_note  → issuedCreditNotice
 *   cancellation → (přeskakuje se — interní storno se do Pohody neexportuje)
 *
 * Per-supplier konfigurace (volitelná):
 *   pohoda_account_code  → <inv:account><typ:ids>...</typ:ids></inv:account>
 *   pohoda_centre_code   → <inv:centre><typ:ids>...</typ:ids></inv:centre>
 *   pohoda_activity_code → <inv:activity><typ:ids>...</typ:ids></inv:activity>
 *   pohoda_contract_code → <inv:contract><typ:ids>...</typ:ids></inv:contract>
 *
 * VAT classification (`<inv:classificationVAT>`) hardcoded podle vat_rate_snapshot:
 *   21 %  → UDA5     (tuzemské plnění základní)
 *   12 %  → UDA5_12  (snížené)
 *    0 %  → UNX      (osvobozeno)
 *   reverse_charge → PNAR (přenesená daňová povinnost)
 */
final class PohodaXmlExporter
{
    public const NS_DAT = 'http://www.stormware.cz/schema/version_2/data.xsd';
    public const NS_INV = 'http://www.stormware.cz/schema/version_2/invoice.xsd';
    public const NS_TYP = 'http://www.stormware.cz/schema/version_2/type.xsd';

    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly Connection $db,
    ) {}

    /**
     * @param int[] $invoiceIds
     * @return array{filename:string, content:string, mime:string}
     */
    public function export(array $invoiceIds, int $supplierId, string $monthLabel = ''): array
    {
        $invoices = [];
        foreach ($invoiceIds as $id) {
            $inv = $this->repo->find((int) $id);
            if ($inv !== null && $inv['invoice_type'] !== 'cancellation') {
                $invoices[] = $inv;
            }
        }

        if (empty($invoices)) {
            throw new \RuntimeException('Žádné faktury k exportu (cancellation se přeskakuje).');
        }

        // Supplier config + IČO pro dataPackHeader
        $stmt = $this->db->pdo()->prepare(
            'SELECT ic, pohoda_account_code, pohoda_centre_code, pohoda_activity_code, pohoda_contract_code
               FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $cfg = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        $xml = $this->buildXml($invoices, $cfg);

        $base = 'pohoda-' . ($monthLabel !== '' ? $monthLabel : date('Y-m-d'));
        return [
            'filename' => "$base.xml",
            'content'  => $xml,
            'mime'     => 'application/xml',
        ];
    }

    /**
     * @param array $invoices Pole faktur z InvoiceRepository::find().
     * @param array $cfg supplier row (ic + pohoda_*_code).
     */
    public function buildXml(array $invoices, array $cfg): string
    {
        // UTF-8 — moderní Pohoda (2010+) UTF-8 akceptuje, žádné mojibake na
        // exotičtější diakritice, konzistentní s ISDOC i zbytkem aplikace.
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $dataPack = $dom->createElementNS(self::NS_DAT, 'dat:dataPack');
        $dataPack->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:inv', self::NS_INV);
        $dataPack->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:typ', self::NS_TYP);
        $dataPack->setAttribute('version', '2.0');
        $dataPack->setAttribute('id', 'myinvoice-' . date('YmdHis'));
        $dataPack->setAttribute('ico', (string) ($cfg['ic'] ?? ''));
        $dataPack->setAttribute('application', 'MyInvoice.cz');
        $dataPack->setAttribute('note', 'Export ' . date('Y-m-d H:i'));
        $dom->appendChild($dataPack);

        foreach ($invoices as $idx => $invoice) {
            $item = $dom->createElementNS(self::NS_DAT, 'dat:dataPackItem');
            $item->setAttribute('version', '2.0');
            $item->setAttribute('id', 'inv-' . $invoice['id']);
            $dataPack->appendChild($item);

            $inv = $dom->createElementNS(self::NS_INV, 'inv:invoice');
            $inv->setAttribute('version', '2.0');
            $item->appendChild($inv);

            // Header
            $hdr = $dom->createElementNS(self::NS_INV, 'inv:invoiceHeader');
            $invType = match ($invoice['invoice_type']) {
                'proforma'    => 'issuedAdvanceInvoice',
                'credit_note' => 'issuedCreditNotice',
                default       => 'issuedInvoice',
            };
            $this->el($dom, $hdr, self::NS_INV, 'inv:invoiceType', $invType);

            $num = $dom->createElementNS(self::NS_INV, 'inv:number');
            $this->el($dom, $num, self::NS_TYP, 'typ:numberRequested', (string) ($invoice['varsymbol'] ?? ''));
            $hdr->appendChild($num);

            $this->el($dom, $hdr, self::NS_INV, 'inv:symVar', (string) ($invoice['varsymbol'] ?? ''));
            $this->el($dom, $hdr, self::NS_INV, 'inv:date', (string) $invoice['issue_date']);
            if (!empty($invoice['tax_date'])) {
                $this->el($dom, $hdr, self::NS_INV, 'inv:dateTax', (string) $invoice['tax_date']);
                $this->el($dom, $hdr, self::NS_INV, 'inv:dateAccounting', (string) $invoice['tax_date']);
            }
            $this->el($dom, $hdr, self::NS_INV, 'inv:dateDue', (string) $invoice['due_date']);

            // Klasifikace DPH (per-faktura — vezme se první VAT rate z položek; v praxi mix se řeší per-položka v invoiceItem)
            $defaultVatClass = $this->classifyVat($invoice);
            $this->el($dom, $hdr, self::NS_INV, 'inv:classificationVAT', $defaultVatClass);

            // Číslo objednávky / poznámka
            if (!empty($invoice['note_above_items'])) {
                $this->el($dom, $hdr, self::NS_INV, 'inv:text', mb_substr((string) $invoice['note_above_items'], 0, 240));
            } else {
                $this->el($dom, $hdr, self::NS_INV, 'inv:text', 'Faktura ' . ($invoice['varsymbol'] ?? ''));
            }

            // Per-faktura číslo zakázky (z projektu) — přepíše se po reimportu zpět na project_number
            if (!empty($invoice['project_number'])) {
                $this->el($dom, $hdr, self::NS_INV, 'inv:numberOrder', (string) $invoice['project_number']);
            }

            // Účet / středisko / činnost / zakázka (per-supplier)
            if (!empty($cfg['pohoda_account_code'])) {
                $this->codeRef($dom, $hdr, 'inv:account', (string) $cfg['pohoda_account_code']);
            }
            if (!empty($cfg['pohoda_centre_code'])) {
                $this->codeRef($dom, $hdr, 'inv:centre', (string) $cfg['pohoda_centre_code']);
            }
            if (!empty($cfg['pohoda_activity_code'])) {
                $this->codeRef($dom, $hdr, 'inv:activity', (string) $cfg['pohoda_activity_code']);
            }
            if (!empty($cfg['pohoda_contract_code'])) {
                $this->codeRef($dom, $hdr, 'inv:contract', (string) $cfg['pohoda_contract_code']);
            }

            // Klient (partnerIdentity)
            $client = $this->resolveClient($invoice);
            $partner = $dom->createElementNS(self::NS_INV, 'inv:partnerIdentity');
            $address = $dom->createElementNS(self::NS_TYP, 'typ:address');
            $this->el($dom, $address, self::NS_TYP, 'typ:company', (string) ($client['company_name'] ?? ''));
            if (!empty($client['ic']))  $this->el($dom, $address, self::NS_TYP, 'typ:ico', (string) $client['ic']);
            if (!empty($client['dic'])) $this->el($dom, $address, self::NS_TYP, 'typ:dic', (string) $client['dic']);
            $this->el($dom, $address, self::NS_TYP, 'typ:street', (string) ($client['street'] ?? ''));
            $this->el($dom, $address, self::NS_TYP, 'typ:city',   (string) ($client['city'] ?? ''));
            $this->el($dom, $address, self::NS_TYP, 'typ:zip',    (string) ($client['zip'] ?? ''));
            if (!empty($client['country_iso2'])) {
                $country = $dom->createElementNS(self::NS_TYP, 'typ:country');
                $this->el($dom, $country, self::NS_TYP, 'typ:ids', (string) $client['country_iso2']);
                $address->appendChild($country);
            }
            $email = $client['email'] ?? $client['main_email'] ?? null;
            if ($email) $this->el($dom, $address, self::NS_TYP, 'typ:email', (string) $email);
            if (!empty($client['phone'])) $this->el($dom, $address, self::NS_TYP, 'typ:phone', (string) $client['phone']);
            $partner->appendChild($address);
            $hdr->appendChild($partner);

            // Reverse charge (přenesená daň. povinnost) je vyjádřen přes <inv:classificationVAT>
            // = PNAR (viz classifyVat). <inv:isExecuted> se zde NEPOUŽÍVÁ — je to posting
            // příznak („zlikvidováno"), ne reverse charge (issue #41).

            $inv->appendChild($hdr);

            // Foreign currency? Pak summary musí mít jak homeCurrency (CZK z czk_recap),
            // tak foreignCurrency (EUR + kurz). Položky pro foreign-currency fakturu jdou
            // do inv:foreignCurrency (Pohoda si CZK dopočítá z global kurzu).
            $invCurrency = (string) ($invoice['currency'] ?? 'CZK');
            $isForeign = $invCurrency !== 'CZK';
            $exchangeRate = $isForeign ? (float) ($invoice['exchange_rate'] ?? 1.0) : 1.0;

            // Detail (položky)
            $detail = $dom->createElementNS(self::NS_INV, 'inv:invoiceDetail');
            foreach ($invoice['items'] ?? [] as $item) {
                $row = $dom->createElementNS(self::NS_INV, 'inv:invoiceItem');
                $this->el($dom, $row, self::NS_INV, 'inv:text', (string) ($item['description'] ?? ''));
                $this->el($dom, $row, self::NS_INV, 'inv:quantity', $this->fmt((float) $item['quantity']));
                $this->el($dom, $row, self::NS_INV, 'inv:unit', (string) ($item['unit'] ?? 'ks'));
                // CoefficientOfRefundables (1 = celé)
                $this->el($dom, $row, self::NS_INV, 'inv:coefficient', '1.0');
                // payVAT: false = pricing without VAT in unit price (default)
                $this->el($dom, $row, self::NS_INV, 'inv:payVAT', 'false');
                // Sazba DPH
                $rate = (float) ($item['vat_rate_snapshot'] ?? 0);
                $rateCode = match (true) {
                    $rate >= 20.5 => 'high',
                    $rate >= 11.5 => 'low',
                    $rate >= 9.5  => 'low2',  // 10% (Pohoda historic)
                    default       => 'none',
                };
                $vatRate = $dom->createElementNS(self::NS_INV, 'inv:rateVAT');
                $vatRate->appendChild($dom->createTextNode($rateCode));
                $row->appendChild($vatRate);

                // CZK invoice → homeCurrency; foreign → foreignCurrency (s EUR cenami,
                // Pohoda dopočítá CZK z kurzu uvedeného v summary)
                $blockName = $isForeign ? 'inv:foreignCurrency' : 'inv:homeCurrency';
                $block = $dom->createElementNS(self::NS_INV, $blockName);
                // payVAT=false → unitPrice musí být BEZ DPH. V režimu „ceny s DPH" nese
                // unit_price_without_vat brutto, proto dopočítáme netto z řádkového základu.
                $qtyItem = (float) $item['quantity'];
                $unitPriceNet = (!empty($invoice['prices_include_vat']) && $qtyItem != 0.0)
                    ? round(((float) ($item['total_without_vat'] ?? 0)) / $qtyItem, 2)
                    : (float) $item['unit_price_without_vat'];
                $this->el($dom, $block, self::NS_TYP, 'typ:unitPrice', $this->fmt($unitPriceNet));
                $this->el($dom, $block, self::NS_TYP, 'typ:price',     $this->fmt((float) ($item['total_without_vat'] ?? 0)));
                $this->el($dom, $block, self::NS_TYP, 'typ:priceVAT',  $this->fmt((float) ($item['total_vat'] ?? 0)));
                $this->el($dom, $block, self::NS_TYP, 'typ:priceSum',  $this->fmt((float) ($item['total_with_vat'] ?? 0)));
                $row->appendChild($block);

                $detail->appendChild($row);
            }
            $inv->appendChild($detail);

            // Summary
            $sum = $dom->createElementNS(self::NS_INV, 'inv:invoiceSummary');
            $this->el($dom, $sum, self::NS_INV, 'inv:roundingDocument', 'none');
            $this->el($dom, $sum, self::NS_INV, 'inv:roundingVAT', 'none');

            $totals = $invoice['totals'] ?? [];
            $bd = $invoice['vat_breakdown'] ?? [];

            // homeCurrency = vždy v CZK. Pro CZK fakturu z totals/vat_breakdown,
            // pro foreign fakturu z czk_recap (přepočet ČNB kurzem). Když czk_recap
            // chybí (foreign faktura bez kurzu — legacy), padáme na 1:1 z totals
            // (uživatel by měl doplnit kurz; export jinak nemá CZK účetní hodnoty).
            $homeCurrency = $dom->createElementNS(self::NS_INV, 'inv:homeCurrency');
            $homeBuckets  = $isForeign && !empty($invoice['czk_recap'])
                ? $this->bucketsFromCzkRecap($invoice['czk_recap'])
                : $this->bucketsFromBreakdown($bd);
            $homeTotal = $isForeign && !empty($invoice['czk_recap'])
                ? (float) $invoice['czk_recap']['total_with_vat_czk']
                : (float) ($totals['with_vat'] ?? 0);

            $this->el($dom, $homeCurrency, self::NS_TYP, 'typ:priceNone',    $this->fmt($homeBuckets['none']));
            $this->el($dom, $homeCurrency, self::NS_TYP, 'typ:priceLow',     $this->fmt($homeBuckets['low']));
            $this->el($dom, $homeCurrency, self::NS_TYP, 'typ:priceLowVAT',  $this->fmt($homeBuckets['lowVat']));
            $this->el($dom, $homeCurrency, self::NS_TYP, 'typ:priceLowSum',  $this->fmt($homeBuckets['low'] + $homeBuckets['lowVat']));
            $this->el($dom, $homeCurrency, self::NS_TYP, 'typ:priceHigh',    $this->fmt($homeBuckets['high']));
            $this->el($dom, $homeCurrency, self::NS_TYP, 'typ:priceHighVAT', $this->fmt($homeBuckets['highVat']));
            $this->el($dom, $homeCurrency, self::NS_TYP, 'typ:priceHighSum', $this->fmt($homeBuckets['high'] + $homeBuckets['highVat']));
            $this->el($dom, $homeCurrency, self::NS_TYP, 'typ:price3', '0.00');
            $this->el($dom, $homeCurrency, self::NS_TYP, 'typ:price3VAT', '0.00');
            $this->el($dom, $homeCurrency, self::NS_TYP, 'typ:price3Sum', '0.00');
            $this->el($dom, $homeCurrency, self::NS_TYP, 'typ:round',
                ($r = (float) ($totals['rounding'] ?? 0)) !== 0.0 && !$isForeign ? $this->fmt($r) : '0.00');
            $this->el($dom, $homeCurrency, self::NS_TYP, 'typ:priceSum', $this->fmt($homeTotal));
            $sum->appendChild($homeCurrency);

            // foreignCurrency — jen pro non-CZK faktury. Obsahuje měnu, kurz, množství
            // a totals v cizí měně. Pohoda po importu má jak CZK účetní hodnoty
            // (homeCurrency), tak originál v cizí měně (foreignCurrency).
            if ($isForeign) {
                $foreign = $dom->createElementNS(self::NS_INV, 'inv:foreignCurrency');
                $cur = $dom->createElementNS(self::NS_TYP, 'typ:currency');
                $this->el($dom, $cur, self::NS_TYP, 'typ:ids', $invCurrency);
                $foreign->appendChild($cur);
                $this->el($dom, $foreign, self::NS_TYP, 'typ:rate', number_format($exchangeRate, 6, '.', ''));
                $this->el($dom, $foreign, self::NS_TYP, 'typ:amount', '1');

                $fb = $this->bucketsFromBreakdown($bd);
                $this->el($dom, $foreign, self::NS_TYP, 'typ:priceNone',    $this->fmt($fb['none']));
                $this->el($dom, $foreign, self::NS_TYP, 'typ:priceLow',     $this->fmt($fb['low']));
                $this->el($dom, $foreign, self::NS_TYP, 'typ:priceLowVAT',  $this->fmt($fb['lowVat']));
                $this->el($dom, $foreign, self::NS_TYP, 'typ:priceLowSum',  $this->fmt($fb['low'] + $fb['lowVat']));
                $this->el($dom, $foreign, self::NS_TYP, 'typ:priceHigh',    $this->fmt($fb['high']));
                $this->el($dom, $foreign, self::NS_TYP, 'typ:priceHighVAT', $this->fmt($fb['highVat']));
                $this->el($dom, $foreign, self::NS_TYP, 'typ:priceHighSum', $this->fmt($fb['high'] + $fb['highVat']));
                $this->el($dom, $foreign, self::NS_TYP, 'typ:priceSum',     $this->fmt((float) ($totals['with_vat'] ?? 0)));
                $sum->appendChild($foreign);
            }

            $inv->appendChild($sum);
        }

        return (string) $dom->saveXML();
    }

    /** <inv:account><typ:ids>CODE</typ:ids></inv:account> */
    private function codeRef(\DOMDocument $dom, \DOMElement $parent, string $name, string $code): void
    {
        $wrap = $dom->createElementNS(self::NS_INV, $name);
        $this->el($dom, $wrap, self::NS_TYP, 'typ:ids', $code);
        $parent->appendChild($wrap);
    }

    private function el(\DOMDocument $dom, \DOMElement $parent, string $ns, string $name, string $value): \DOMElement
    {
        $el = $dom->createElementNS($ns, $name);
        $el->appendChild($dom->createTextNode($value));
        $parent->appendChild($el);
        return $el;
    }

    private function classifyVat(array $invoice): string
    {
        if (!empty($invoice['reverse_charge'])) return 'PNAR';
        $bd = $invoice['vat_breakdown'] ?? [];
        if (empty($bd)) return 'UDA5';
        $maxRate = 0.0;
        foreach ($bd as $b) {
            if ((float) $b['rate'] > $maxRate) $maxRate = (float) $b['rate'];
        }
        if ($maxRate >= 20.5) return 'UDA5';
        if ($maxRate >= 11.5) return 'UDA5_12';
        return 'UNX';
    }

    private function resolveClient(array $invoice): array
    {
        // Live data ze clients tabulky jako defensive base (pro legacy faktury
        // s prázdným snapshotem nebo snapshoty bez všech polí — chybějící
        // street/city/zip/country v Pohoda XML by jinak vyrobilo prázdný
        // partner address). Snapshot vyhrává nad live (zachovává historický
        // stav vystavené faktury). Stejný pattern jako v IsdocExporter.
        $live = $this->loadLiveClient((int) ($invoice['client_id'] ?? 0));
        if (!empty($invoice['client_snapshot'])) {
            $snap = is_string($invoice['client_snapshot']) ? json_decode($invoice['client_snapshot'], true) : $invoice['client_snapshot'];
            if (is_array($snap)) {
                return array_merge($live, $snap);
            }
        }
        if (empty($live)) {
            return [
                'company_name' => $invoice['client_company_name'] ?? '',
                'ic' => $invoice['client_ic'] ?? '',
                'dic' => $invoice['client_dic'] ?? '',
                'main_email' => $invoice['client_main_email'] ?? '',
                'country_iso2' => 'CZ',
            ];
        }
        return $live;
    }

    private function loadLiveClient(int $clientId): array
    {
        if ($clientId <= 0) return [];
        $stmt = $this->db->pdo()->prepare(
            'SELECT c.*, co.iso2 AS country_iso2, co.name_cs AS country_name_cs, co.name_en AS country_name_en
               FROM clients c JOIN countries co ON co.id = c.country_id WHERE c.id = ?'
        );
        $stmt->execute([$clientId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    private function fmt(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    /**
     * @param list<array{rate: float, base: float, vat: float}> $breakdown
     * @return array{none: float, low: float, lowVat: float, high: float, highVat: float}
     */
    private function bucketsFromBreakdown(array $breakdown): array
    {
        $out = ['none' => 0.0, 'low' => 0.0, 'lowVat' => 0.0, 'high' => 0.0, 'highVat' => 0.0];
        foreach ($breakdown as $b) {
            $r = (float) $b['rate'];
            if ($r >= 20.5) {
                $out['high']    += (float) $b['base'];
                $out['highVat'] += (float) $b['vat'];
            } elseif ($r >= 11.5) {
                $out['low']    += (float) $b['base'];
                $out['lowVat'] += (float) $b['vat'];
            } else {
                $out['none'] += (float) $b['base'];
            }
        }
        return $out;
    }

    /**
     * @param array{breakdown: list<array{rate: float, base_czk: float, vat_czk: float}>} $recap
     * @return array{none: float, low: float, lowVat: float, high: float, highVat: float}
     */
    private function bucketsFromCzkRecap(array $recap): array
    {
        $out = ['none' => 0.0, 'low' => 0.0, 'lowVat' => 0.0, 'high' => 0.0, 'highVat' => 0.0];
        foreach ($recap['breakdown'] ?? [] as $b) {
            $r = (float) $b['rate'];
            if ($r >= 20.5) {
                $out['high']    += (float) $b['base_czk'];
                $out['highVat'] += (float) $b['vat_czk'];
            } elseif ($r >= 11.5) {
                $out['low']    += (float) $b['base_czk'];
                $out['lowVat'] += (float) $b['vat_czk'];
            } else {
                $out['none'] += (float) $b['base_czk'];
            }
        }
        return $out;
    }
}
