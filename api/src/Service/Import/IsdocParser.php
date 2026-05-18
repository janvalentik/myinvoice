<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

/**
 * Parser ISDOC 6.0.x — extrahuje fakturu do normalizovaného array.
 *
 * Vrací {supplier_ic, invoices[]} (pro konzistenci se single-file API; ISDOC typicky 1 invoice / file).
 *
 * Reverzní mapování k IsdocExporter — DocumentType, ID, Issue/TaxDate, parties, lines, totals.
 *
 * Output shape per invoice — viz PohodaXmlParser.
 */
final class IsdocParser
{
    private const NS = 'http://isdoc.cz/namespace/2013';

    /**
     * @return array{supplier_ic:?string, invoices:list<array<string,mixed>>}
     */
    public function parse(string $xml): array
    {
        // XXE / billion-laughs hardening: odmítni DOCTYPE před libxml parsováním.
        // V PHP 8 / libxml ≥ 2.9 jsou external entities default-off, ale interní
        // entity expansion může pořád způsobit DoS — proto blokujeme DOCTYPE rovnou.
        if (preg_match('/<!DOCTYPE/i', $xml)) {
            throw new \RuntimeException('ISDOC XML obsahuje DOCTYPE, což není povoleno.');
        }

        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        $prev = libxml_use_internal_errors(true);
        // LIBXML_NONET zakáže jakékoliv načítání ze sítě; nepoužíváme NOENT.
        $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$loaded || $dom->documentElement === null) {
            throw new \RuntimeException('Nelze parsovat ISDOC XML.');
        }

        $root = $dom->documentElement;
        if ($root->localName !== 'Invoice') {
            throw new \RuntimeException('Není ISDOC — root není Invoice.');
        }

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('i', self::NS);

        try {
            $parsed = $this->parseInvoice($root, $xpath);
        } catch (\Throwable $e) {
            return ['supplier_ic' => null, 'invoices' => [['__error' => $e->getMessage()]]];
        }

        $supplierIc = $this->text($xpath, 'i:AccountingSupplierParty/i:Party/i:PartyIdentification/i:ID', $root) ?: null;

        return ['supplier_ic' => $supplierIc, 'invoices' => [$parsed]];
    }

    /**
     * @return array<string,mixed>
     */
    private function parseInvoice(\DOMElement $root, \DOMXPath $xpath): array
    {
        $docType = (int) ($this->text($xpath, 'i:DocumentType', $root) ?: '1');
        $invoiceType = match ($docType) {
            2       => 'proforma',
            5       => 'credit_note',
            default => 'invoice',
        };

        $varsymbol = $this->text($xpath, 'i:ID', $root);
        if ($varsymbol === '') {
            throw new \RuntimeException('Chybí ISDOC ID (varsymbol).');
        }

        $issueDate = $this->text($xpath, 'i:IssueDate', $root);
        $taxDate   = $this->text($xpath, 'i:TaxPointDate', $root) ?: null;
        $dueDate   = $this->text($xpath, 'i:PaymentMeans/i:Payment/i:Details/i:PaymentDueDate', $root) ?: $issueDate;

        $localCur = strtoupper($this->text($xpath, 'i:LocalCurrencyCode', $root) ?: 'CZK');
        // Schema-validní ISDOC 6.0.2 používá <ForeignCurrencyCode>; starší soubory
        // (i náš vlastní exporter před v3.6.2) používaly <CurrencyCode>. Čteme oboje
        // pro kompatibilitu s exporty od jiných systémů i s naším round-tripem.
        $foreignCur = strtoupper(
            $this->text($xpath, 'i:ForeignCurrencyCode', $root)
            ?: $this->text($xpath, 'i:CurrencyCode', $root)
            ?: ''
        );
        $currency = $foreignCur !== '' ? $foreignCur : $localCur;
        $rate = null;
        $rateRaw = $this->text($xpath, 'i:CurrRate', $root);
        if ($rateRaw !== '' && $currency !== $localCur) {
            $rate = (float) $rateRaw;
        }

        $reverseCharge = strtolower($this->text($xpath, 'i:VATApplicable', $root)) === 'false';

        // Klient: AccountingCustomerParty/Party
        $partyEl = $xpath->query('i:AccountingCustomerParty/i:Party', $root)->item(0);
        $client = $partyEl instanceof \DOMElement ? $this->parseParty($xpath, $partyEl) : [];

        // Project number — schema-validní ISDOC 6.0.2 obaluje reference do wrapper
        // kolekce (<OrderReferences>/<OrderReference>/<SalesOrderID>) a v contract
        // referenci je @id atribut + <ID> element. Starší / non-conforming exporty
        // používaly přímý <OrderReference>/<ID>. Čteme nové cesty jako primární
        // a staré jako fallback pro kompat s ISDOC od jiných systémů.
        $projectNumber = $this->text($xpath, 'i:OrderReferences/i:OrderReference/i:SalesOrderID', $root)
            ?: $this->text($xpath, 'i:OrderReference/i:SalesOrderID', $root)
            ?: $this->text($xpath, 'i:OrderReference/i:ID', $root)
            ?: $this->text($xpath, 'i:ContractReferences/i:ContractReference/i:ID', $root)
            ?: $this->text($xpath, 'i:ContractReference/i:ID', $root)
            ?: null;

        // Items
        $items = [];
        foreach ($xpath->query('i:InvoiceLines/i:InvoiceLine', $root) ?: [] as $lineEl) {
            if (!$lineEl instanceof \DOMElement) continue;
            $items[] = $this->parseLine($xpath, $lineEl);
        }

        return [
            'invoice_type'   => $invoiceType,
            'varsymbol'      => $varsymbol,
            'issue_date'     => $issueDate,
            'tax_date'       => $taxDate,
            'due_date'       => $dueDate,
            'currency'       => $currency,
            'exchange_rate'  => $rate,
            'reverse_charge' => $reverseCharge,
            'note_above'     => null,
            'project_number' => $projectNumber,
            'client'         => $client,
            'items'          => $items,
        ];
    }

    /**
     * @return array<string,?string>
     */
    private function parseParty(\DOMXPath $xpath, \DOMElement $party): array
    {
        // Schema rozděluje adresu na <StreetName> + <BuildingNumber>; pro náš
        // model držíme jednu jednolitou hodnotu `street`, takže je při čtení
        // zase slijeme. Pokud BuildingNumber chybí (legacy exporty), použijeme
        // jen StreetName beze změny.
        $streetName = $this->text($xpath, 'i:PostalAddress/i:StreetName', $party);
        $buildingNumber = $this->text($xpath, 'i:PostalAddress/i:BuildingNumber', $party);
        $street = trim($streetName . ($buildingNumber !== '' ? ' ' . $buildingNumber : ''));

        return [
            'company_name' => $this->text($xpath, 'i:PartyName/i:Name', $party) ?: null,
            'ic'           => $this->text($xpath, 'i:PartyIdentification/i:ID', $party) ?: null,
            'dic'          => $this->text($xpath, 'i:PartyTaxScheme/i:CompanyID', $party) ?: null,
            'street'       => $street !== '' ? $street : null,
            'city'         => $this->text($xpath, 'i:PostalAddress/i:CityName', $party) ?: null,
            'zip'          => $this->text($xpath, 'i:PostalAddress/i:PostalZone', $party) ?: null,
            'country_iso2' => strtoupper($this->text($xpath, 'i:PostalAddress/i:Country/i:IdentificationCode', $party)) ?: null,
            'email'        => $this->text($xpath, 'i:Contact/i:ElectronicMail', $party) ?: null,
            'phone'        => $this->text($xpath, 'i:Contact/i:Telephone', $party) ?: null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function parseLine(\DOMXPath $xpath, \DOMElement $line): array
    {
        $qtyEl = $xpath->query('i:InvoicedQuantity', $line)->item(0);
        $quantity = $qtyEl instanceof \DOMElement ? (float) $qtyEl->textContent : 1.0;
        $unit = $qtyEl instanceof \DOMElement ? ($qtyEl->getAttribute('unitCode') ?: 'ks') : 'ks';

        $unitPrice = (float) ($this->text($xpath, 'i:UnitPrice', $line) ?: '0');
        $vatRate   = (float) ($this->text($xpath, 'i:ClassifiedTaxCategory/i:Percent', $line) ?: '0');

        return [
            'description'            => $this->text($xpath, 'i:Item/i:Description', $line),
            'quantity'               => $quantity,
            'unit'                   => $unit,
            'unit_price_without_vat' => $unitPrice,
            'vat_rate'               => $vatRate,
        ];
    }

    private function text(\DOMXPath $xpath, string $expr, \DOMNode $context): string
    {
        $node = $xpath->query($expr, $context)->item(0);
        return $node ? trim($node->textContent) : '';
    }
}
