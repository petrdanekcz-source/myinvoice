<?php

declare(strict_types=1);

namespace MyInvoice\Service\Export;

use MyInvoice\Repository\InvoiceRepository;

/**
 * ISDOC 6.0.2 exporter (Czech standard XML invoice format).
 *
 * Spec: http://isdoc.cz/
 * Namespace: http://isdoc.cz/namespace/2013
 *
 * Vyrobí buď single .isdoc XML (pro 1 fakturu) nebo ZIP s více .isdoc soubory.
 *
 * Mapování DocumentType:
 *   1 = běžná faktura (invoice)
 *   2 = zálohová faktura (proforma)
 *   5 = opravný daňový doklad / dobropis (credit_note)
 *
 * PaymentMeansCode:
 *   42 = převod (bank transfer) — default pro CZK účty s bank_code
 *   31 = SEPA převod (pro EUR/IBAN)
 *   10 = hotovost
 */
final class IsdocExporter
{
    public const NS = 'http://isdoc.cz/namespace/2013';
    public const VERSION = '6.0.2';

    public function __construct(
        private readonly InvoiceRepository $repo,
    ) {}

    /**
     * @param int[] $invoiceIds
     * @return array{filename:string, content:string, mime:string}
     */
    public function export(array $invoiceIds, string $monthLabel = ''): array
    {
        $invoices = [];
        foreach ($invoiceIds as $id) {
            $inv = $this->repo->find((int) $id);
            if ($inv !== null) $invoices[] = $inv;
        }

        if (empty($invoices)) {
            throw new \RuntimeException('Žádné faktury k exportu.');
        }

        if (count($invoices) === 1) {
            $inv = $invoices[0];
            $vs = $inv['varsymbol'] ?? ('draft-' . $inv['id']);
            return [
                'filename' => "Faktura-{$vs}.isdoc",
                'content'  => $this->buildXml($inv),
                'mime'     => 'application/x-isdoc',
            ];
        }

        // Multi → ZIP
        $tmpZip = tempnam(sys_get_temp_dir(), 'isdoc-') . '.zip';
        $zip = new \ZipArchive();
        if ($zip->open($tmpZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Nelze vytvořit ZIP.');
        }
        foreach ($invoices as $inv) {
            $vs = $inv['varsymbol'] ?? ('draft-' . $inv['id']);
            $type = match ($inv['invoice_type']) {
                'proforma'    => 'Proforma',
                'credit_note' => 'Dobropis',
                default       => 'Faktura',
            };
            $zip->addFromString("$type-{$vs}.isdoc", $this->buildXml($inv));
        }
        $zip->close();
        $content = (string) file_get_contents($tmpZip);
        @unlink($tmpZip);

        $base = 'isdoc-' . ($monthLabel !== '' ? $monthLabel : date('Y-m-d'));
        return [
            'filename' => "$base.zip",
            'content'  => $content,
            'mime'     => 'application/zip',
        ];
    }

    public function buildXml(array $invoice): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElementNS(self::NS, 'Invoice');
        $root->setAttribute('version', self::VERSION);
        $dom->appendChild($root);

        $currencyCode = (string) ($invoice['currency'] ?? 'CZK');
        $localCurrency = 'CZK';   // účetní měna českého dodavatele — fixní pro ISDOC export
        $isForeign = $currencyCode !== $localCurrency;
        // Kurz: pro CZK fakturu vždy 1; pro cizí měnu z invoices.exchange_rate (CZK / 1 jednotka).
        // Když cizí měna nemá zafixovaný kurz (legacy data), padá na 1 — accounting soft to vezme jako 1:1
        // a uživatel si musí kurz doplnit. Backfill se snažíme udělat dřív, viz ExchangeRateApplier::ensureRate().
        $rate = $isForeign ? (float) ($invoice['exchange_rate'] ?? 1.0) : 1.0;

        // Header
        $docType = match ($invoice['invoice_type']) {
            'proforma'     => 2,
            'credit_note'  => 5,
            'cancellation' => 5,
            default        => 1,
        };
        $this->el($dom, $root, 'DocumentType', (string) $docType);
        $this->el($dom, $root, 'ID', (string) ($invoice['varsymbol'] ?? ('DRAFT-' . $invoice['id'])));
        $this->el($dom, $root, 'UUID', $this->makeUuid($invoice));
        $this->el($dom, $root, 'IssueDate', (string) $invoice['issue_date']);
        if (!empty($invoice['tax_date'])) {
            $this->el($dom, $root, 'TaxPointDate', (string) $invoice['tax_date']);
        }
        $this->el($dom, $root, 'VATApplicable', empty($invoice['reverse_charge']) ? 'true' : 'false');
        $this->el($dom, $root, 'LocalCurrencyCode', $localCurrency);
        if ($isForeign) {
            $this->el($dom, $root, 'CurrencyCode', $currencyCode);
        }
        // CurrRate = počet jednotek místní měny za 1 jednotku faktur. měny (CZK/EUR ≈ 24.36)
        $this->el($dom, $root, 'CurrRate', number_format($rate, 6, '.', ''));
        $this->el($dom, $root, 'RefCurrRate', '1');

        // Číslo zakázky / smlouvy (z projektu) — IsdocParser je čte při zpětném importu.
        if (!empty($invoice['project_number'])) {
            $orderRef = $dom->createElementNS(self::NS, 'OrderReference');
            $this->el($dom, $orderRef, 'ID', (string) $invoice['project_number']);
            $root->appendChild($orderRef);
        }
        if (!empty($invoice['contract_number'])) {
            $contractRef = $dom->createElementNS(self::NS, 'ContractReference');
            $this->el($dom, $contractRef, 'ID', (string) $invoice['contract_number']);
            $root->appendChild($contractRef);
        }

        // Supplier (snapshot first, then live)
        $supplier = $this->resolveSupplier($invoice);
        $supParty = $dom->createElementNS(self::NS, 'AccountingSupplierParty');
        $supParty->appendChild($this->buildParty($dom, $supplier));
        $root->appendChild($supParty);

        // Customer
        $client = $this->resolveClient($invoice);
        $cusParty = $dom->createElementNS(self::NS, 'AccountingCustomerParty');
        $cusParty->appendChild($this->buildParty($dom, $client));
        $root->appendChild($cusParty);

        // Invoice lines
        $lines = $dom->createElementNS(self::NS, 'InvoiceLines');
        $items = $invoice['items'] ?? [];
        foreach ($items as $i => $item) {
            $line = $dom->createElementNS(self::NS, 'InvoiceLine');
            $this->el($dom, $line, 'ID', (string) ($i + 1));
            $qty = $this->el($dom, $line, 'InvoicedQuantity', $this->fmt($item['quantity']));
            $qty->setAttribute('unitCode', (string) ($item['unit'] ?? 'ks'));
            $base = (float) ($item['total_without_vat'] ?? 0);
            $vat  = (float) ($item['total_vat'] ?? 0);
            $tot  = (float) ($item['total_with_vat'] ?? 0);
            $this->elAmount($dom, $line, 'LineExtensionAmount', $base, $currencyCode);
            $this->elAmount($dom, $line, 'LineExtensionAmountTaxInclusive', $tot, $currencyCode);
            $this->elAmount($dom, $line, 'LineExtensionTaxAmount', $vat, $currencyCode);
            $this->elAmount($dom, $line, 'UnitPrice', (float) $item['unit_price_without_vat'], $currencyCode);
            $this->elAmount($dom, $line, 'UnitPriceTaxInclusive', (float) $item['unit_price_without_vat'] * (1 + ((float) ($item['vat_rate_snapshot'] ?? 0)) / 100), $currencyCode);

            $cat = $dom->createElementNS(self::NS, 'ClassifiedTaxCategory');
            $this->el($dom, $cat, 'Percent', $this->fmt((float) ($item['vat_rate_snapshot'] ?? 0)));
            $this->el($dom, $cat, 'VATCalculationMethod', '0');
            $line->appendChild($cat);

            $itemEl = $dom->createElementNS(self::NS, 'Item');
            $this->el($dom, $itemEl, 'Description', (string) ($item['description'] ?? ''));
            $line->appendChild($itemEl);

            $lines->appendChild($line);
        }
        $root->appendChild($lines);

        // VAT breakdown
        $taxTotal = $dom->createElementNS(self::NS, 'TaxTotal');
        $vatBreakdown = $invoice['vat_breakdown'] ?? [];
        $totalVat = 0.0;
        foreach ($vatBreakdown as $row) {
            $rate = (float) $row['rate'];
            $base = (float) $row['base'];
            $vat  = (float) $row['vat'];
            $totalVat += $vat;

            $sub = $dom->createElementNS(self::NS, 'TaxSubTotal');
            $this->elAmount($dom, $sub, 'TaxableAmount', $base, $currencyCode);
            $this->elAmount($dom, $sub, 'TaxAmount', $vat, $currencyCode);
            $this->elAmount($dom, $sub, 'TaxInclusiveAmount', $base + $vat, $currencyCode);
            // Required by ISDOC schema (zálohové odpočty — pro běžnou fakturu = 0)
            $this->elAmount($dom, $sub, 'AlreadyClaimedTaxableAmount', 0.0, $currencyCode);
            $this->elAmount($dom, $sub, 'AlreadyClaimedTaxAmount', 0.0, $currencyCode);
            $this->elAmount($dom, $sub, 'AlreadyClaimedTaxInclusiveAmount', 0.0, $currencyCode);
            $this->elAmount($dom, $sub, 'DifferenceTaxableAmount', $base, $currencyCode);
            $this->elAmount($dom, $sub, 'DifferenceTaxAmount', $vat, $currencyCode);
            $this->elAmount($dom, $sub, 'DifferenceTaxInclusiveAmount', $base + $vat, $currencyCode);

            $cat = $dom->createElementNS(self::NS, 'ClassifiedTaxCategory');
            $this->el($dom, $cat, 'Percent', $this->fmt($rate));
            $this->el($dom, $cat, 'VATCalculationMethod', '0');
            $sub->appendChild($cat);
            $taxTotal->appendChild($sub);
        }
        $this->elAmount($dom, $taxTotal, 'TaxAmount', $totalVat, $currencyCode);
        $root->appendChild($taxTotal);

        // Monetary total
        $totals = $invoice['totals'] ?? [];
        $base = (float) ($totals['without_vat'] ?? 0);
        $tot  = (float) ($totals['with_vat'] ?? 0);
        $advance = (float) ($invoice['advance_paid_amount'] ?? 0);
        $payable = (float) ($invoice['amount_to_pay'] ?? $tot);
        $rounding = (float) ($totals['rounding'] ?? 0);

        $mon = $dom->createElementNS(self::NS, 'LegalMonetaryTotal');
        $this->elAmount($dom, $mon, 'TaxExclusiveAmount', $base, $currencyCode);
        $this->elAmount($dom, $mon, 'TaxInclusiveAmount', $tot, $currencyCode);
        $this->elAmount($dom, $mon, 'AlreadyClaimedTaxExclusiveAmount', 0.0, $currencyCode);
        $this->elAmount($dom, $mon, 'AlreadyClaimedTaxInclusiveAmount', $advance, $currencyCode);
        $this->elAmount($dom, $mon, 'DifferenceTaxExclusiveAmount', $base, $currencyCode);
        $this->elAmount($dom, $mon, 'DifferenceTaxInclusiveAmount', $tot - $advance, $currencyCode);
        $this->elAmount($dom, $mon, 'PayableRoundingAmount', $rounding, $currencyCode);
        $this->elAmount($dom, $mon, 'PaidDepositsAmount', $advance, $currencyCode);
        $this->elAmount($dom, $mon, 'PayableAmount', $payable, $currencyCode);
        $root->appendChild($mon);

        // Payment means (bank transfer)
        $bank = $this->resolveBank($invoice);
        if ($bank !== null && $payable > 0) {
            $pm = $dom->createElementNS(self::NS, 'PaymentMeans');
            $payment = $dom->createElementNS(self::NS, 'Payment');
            $this->elAmount($dom, $payment, 'PaidAmount', $payable, $currencyCode);
            $this->el($dom, $payment, 'PaymentMeansCode', $currencyCode === 'CZK' ? '42' : '31');

            $details = $dom->createElementNS(self::NS, 'Details');
            $this->el($dom, $details, 'PaymentDueDate', (string) $invoice['due_date']);
            $this->el($dom, $details, 'ID', (string) ($invoice['varsymbol'] ?? ''));
            if (!empty($invoice['varsymbol'])) {
                $this->el($dom, $details, 'VariableSymbol', (string) $invoice['varsymbol']);
            }
            $this->el($dom, $details, 'ConstantSymbol', '0308');

            $bankAccount = $dom->createElementNS(self::NS, 'BankAccount');
            if (!empty($bank['account_number']) && !empty($bank['bank_code'])) {
                $this->el($dom, $bankAccount, 'ID', $bank['account_number'] . '/' . $bank['bank_code']);
                $this->el($dom, $bankAccount, 'BankCode', (string) $bank['bank_code']);
            }
            if (!empty($bank['bank_name'])) {
                $this->el($dom, $bankAccount, 'Name', (string) $bank['bank_name']);
            }
            if (!empty($bank['iban'])) {
                $this->el($dom, $bankAccount, 'IBAN', (string) $bank['iban']);
            }
            if (!empty($bank['bic'])) {
                $this->el($dom, $bankAccount, 'BIC', (string) $bank['bic']);
            }
            if ($bankAccount->hasChildNodes()) {
                $details->appendChild($bankAccount);
            }
            $payment->appendChild($details);
            $pm->appendChild($payment);
            $root->appendChild($pm);
        }

        return (string) $dom->saveXML();
    }

    private function buildParty(\DOMDocument $dom, array $party): \DOMElement
    {
        $partyEl = $dom->createElementNS(self::NS, 'Party');

        $idEl = $dom->createElementNS(self::NS, 'PartyIdentification');
        $this->el($dom, $idEl, 'ID', (string) ($party['ic'] ?? '0'));
        $partyEl->appendChild($idEl);

        $nameEl = $dom->createElementNS(self::NS, 'PartyName');
        $this->el($dom, $nameEl, 'Name', (string) ($party['company_name'] ?? ''));
        $partyEl->appendChild($nameEl);

        $addr = $dom->createElementNS(self::NS, 'PostalAddress');
        $this->el($dom, $addr, 'StreetName', (string) ($party['street'] ?? ''));
        $this->el($dom, $addr, 'CityName', (string) ($party['city'] ?? ''));
        $this->el($dom, $addr, 'PostalZone', (string) ($party['zip'] ?? ''));
        $country = $dom->createElementNS(self::NS, 'Country');
        $this->el($dom, $country, 'IdentificationCode', (string) ($party['country_iso2'] ?? 'CZ'));
        $this->el($dom, $country, 'Name', (string) ($party['country_name_cs'] ?? 'Česká republika'));
        $addr->appendChild($country);
        $partyEl->appendChild($addr);

        if (!empty($party['dic'])) {
            $tax = $dom->createElementNS(self::NS, 'PartyTaxScheme');
            $this->el($dom, $tax, 'CompanyID', (string) $party['dic']);
            $this->el($dom, $tax, 'TaxScheme', 'VAT');
            $partyEl->appendChild($tax);
        }

        if (!empty($party['email']) || !empty($party['phone']) || !empty($party['main_email'])) {
            $contact = $dom->createElementNS(self::NS, 'Contact');
            if (!empty($party['phone'])) {
                $this->el($dom, $contact, 'Telephone', (string) $party['phone']);
            }
            $email = $party['email'] ?? $party['main_email'] ?? '';
            if ($email !== '') {
                $this->el($dom, $contact, 'ElectronicMail', (string) $email);
            }
            $partyEl->appendChild($contact);
        }

        return $partyEl;
    }

    private function resolveSupplier(array $invoice): array
    {
        if (!empty($invoice['supplier_snapshot'])) {
            $snap = is_string($invoice['supplier_snapshot']) ? json_decode($invoice['supplier_snapshot'], true) : $invoice['supplier_snapshot'];
            if (is_array($snap)) return $snap;
        }
        // Live fallback se v ISDOC neimplementuje — issued faktura má vždy snapshot.
        // Pro draft (kde snapshot není) vracíme defaults.
        return [
            'company_name' => '',
            'street' => '', 'city' => '', 'zip' => '',
            'country_iso2' => 'CZ', 'ic' => '', 'dic' => '',
        ];
    }

    private function resolveClient(array $invoice): array
    {
        if (!empty($invoice['client_snapshot'])) {
            $snap = is_string($invoice['client_snapshot']) ? json_decode($invoice['client_snapshot'], true) : $invoice['client_snapshot'];
            if (is_array($snap)) return $snap;
        }
        return [
            'company_name' => $invoice['client_company_name'] ?? '',
            'ic' => $invoice['client_ic'] ?? '',
            'dic' => $invoice['client_dic'] ?? '',
            'main_email' => $invoice['client_main_email'] ?? '',
        ];
    }

    private function resolveBank(array $invoice): ?array
    {
        if (!empty($invoice['bank_snapshot'])) {
            $snap = is_string($invoice['bank_snapshot']) ? json_decode($invoice['bank_snapshot'], true) : $invoice['bank_snapshot'];
            if (is_array($snap)) return $snap;
        }
        if (!empty($invoice['bank_account_number']) || !empty($invoice['bank_iban'])) {
            return [
                'account_number' => $invoice['bank_account_number'] ?? null,
                'bank_code'      => $invoice['bank_code'] ?? null,
                'bank_name'      => $invoice['bank_name'] ?? null,
                'iban'           => $invoice['bank_iban'] ?? null,
                'bic'            => $invoice['bank_bic'] ?? null,
            ];
        }
        return null;
    }

    private function el(\DOMDocument $dom, \DOMElement $parent, string $name, string $value): \DOMElement
    {
        $el = $dom->createElementNS(self::NS, $name);
        $el->appendChild($dom->createTextNode($value));
        $parent->appendChild($el);
        return $el;
    }

    private function elAmount(\DOMDocument $dom, \DOMElement $parent, string $name, float $value, string $currency): void
    {
        $el = $this->el($dom, $parent, $name, $this->fmt($value));
        $el->setAttribute('currencyID', $currency);
    }

    private function fmt(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private function makeUuid(array $invoice): string
    {
        // Deterministický UUID v5-style based na invoice ID + supplier (žádný náhodný)
        $ns = sprintf(
            '%08x-%04x-%04x-%04x-%012x',
            (int) ($invoice['supplier_id'] ?? 0),
            0x4d59, // "MY"
            0x4956, // "IV"
            0x0000,
            (int) $invoice['id'],
        );
        return $ns;
    }
}
