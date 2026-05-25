<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Export;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Export\IsdocExporter;
use PHPUnit\Framework\TestCase;

/**
 * Validuje výstup IsdocExporter::buildXml() proti oficiálnímu XSD
 * (api/xsd/isdoc-invoice-6.0.2.xsd, staženo z https://isdoc.cz/6.0.2/xsd/).
 *
 * Co test chytá: strukturální regrese — pořadí elementů v sekvenci, povinné
 * elementy, datové typy. Element ordering je v exportéru ručně udržované (viz
 * komentáře "přesné pořadí dle isdoc-invoice-6.0.2.xsd"), takže je křehké.
 *
 * Co test NEchytá: business-rule omezení, která čisté XSD neumí vyjádřit. ISDOC
 * 6.0.2 nemá žádný <xs:assert> a `LineExtensionAmountCurr` je minOccurs="0", takže
 * pravidlo "doklad v cizí měně musí nést *Curr hodnoty" se validací neověří —
 * to je Schematron-level kontrola. Cizoměnové faktury proto projdou XSD validací
 * i s aktuálním (vůči standardu nekonformním) mapováním cizí měny do <UnitPrice>.
 */
final class IsdocExporterSchemaTest extends TestCase
{
    private const XSD = __DIR__ . '/../../../../xsd/isdoc-invoice-6.0.2.xsd';

    private IsdocExporter $exporter;

    protected function setUp(): void
    {
        if (!is_file(self::XSD)) {
            self::markTestSkipped('ISDOC XSD chybí — spusť cmd/download-xsd.sh isdoc.');
        }
        // buildXml() pracuje čistě nad polem; resolve* sahá na DB jen pro
        // supplier_id/client_id > 0. Faktury níže nesou jen snapshoty, takže
        // stuby repo/db nejsou nikdy zavolané.
        $this->exporter = new IsdocExporter(
            $this->createStub(InvoiceRepository::class),
            $this->createStub(Connection::class),
        );
    }

    public function testDomesticCzkInvoiceIsSchemaValid(): void
    {
        $this->assertValidIsdoc($this->exporter->buildXml($this->invoice()));
    }

    public function testForeignCurrencyInvoiceIsSchemaValid(): void
    {
        $xml = $this->exporter->buildXml($this->invoice([
            'currency'      => 'EUR',
            'exchange_rate' => 24.36,
        ]));
        $this->assertValidIsdoc($xml);
    }

    public function testCreditNoteIsSchemaValid(): void
    {
        $this->assertValidIsdoc($this->exporter->buildXml($this->invoice([
            'invoice_type' => 'credit_note',
        ])));
    }

    public function testReverseChargeInvoiceIsSchemaValid(): void
    {
        $this->assertValidIsdoc($this->exporter->buildXml($this->invoice([
            'reverse_charge' => true,
        ])));
    }

    public function testMultiItemInvoiceWithProjectAndContractIsSchemaValid(): void
    {
        $this->assertValidIsdoc($this->exporter->buildXml($this->invoice([
            'project_number'  => 'OBJ-2026-12',
            'contract_number' => 'SML-7',
            'items'           => [
                $this->item(['description' => 'Vývoj', 'quantity' => 10.0, 'unit_price_without_vat' => 1000.0]),
                $this->item(['description' => 'Konzultace', 'quantity' => 2.0, 'unit_price_without_vat' => 1500.0, 'vat_rate_snapshot' => 12.0]),
            ],
            'vat_breakdown'   => [
                ['rate' => 21.0, 'base' => 10000.0, 'vat' => 2100.0],
                ['rate' => 12.0, 'base' => 3000.0,  'vat' => 360.0],
            ],
            'totals'          => ['without_vat' => 13000.0, 'with_vat' => 15460.0, 'rounding' => 0.0],
            'amount_to_pay'   => 15460.0,
        ])));
    }

    /**
     * Reálná data MyInvoice (snapshoty) — co vrací InvoiceRepository::find().
     *
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function invoice(array $overrides = []): array
    {
        $base = [
            'id'               => 1,
            'invoice_type'     => 'invoice',
            'varsymbol'        => '2026001',
            'issue_date'       => '2026-05-04',
            'tax_date'         => '2026-05-04',
            'due_date'         => '2026-05-18',
            'currency'         => 'CZK',
            'exchange_rate'    => null,
            'reverse_charge'   => false,
            'project_number'   => null,
            'contract_number'  => null,
            'advance_paid_amount' => 0.0,
            'amount_to_pay'    => 2520.0,
            'supplier_snapshot' => [
                'ic'           => '01698401',
                'dic'          => 'CZ01698401',
                'company_name' => 'Dodavatel s.r.o.',
                'street'       => 'Kardinála Berana 1104/36',
                'city'         => 'Plzeň',
                'zip'          => '30100',
                'country_iso2' => 'CZ',
                'main_email'   => 'fakturace@dodavatel.cz',
            ],
            'client_snapshot'  => [
                'ic'           => '27140130',
                'dic'          => 'CZ27140130',
                'company_name' => 'Odběratel a.s.',
                'street'       => 'Václavské náměstí 1',
                'city'         => 'Praha 1',
                'zip'          => '11000',
                'country_iso2' => 'CZ',
            ],
            'bank_snapshot'    => [
                'account_number' => '1000000005',
                'bank_code'      => '0100',
                'bank_name'      => 'Komerční banka',
            ],
            'items'            => [$this->item()],
            'vat_breakdown'    => [['rate' => 21.0, 'base' => 2520.0, 'vat' => 529.2]],
            'totals'           => ['without_vat' => 2520.0, 'with_vat' => 3049.2, 'rounding' => 0.0],
        ];

        return array_merge($base, $overrides);
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function item(array $overrides = []): array
    {
        return array_merge([
            'description'            => 'Vývoj systému',
            'quantity'               => 1.0,
            'unit'                   => 'ks',
            'unit_price_without_vat' => 2520.0,
            'vat_rate_snapshot'      => 21.0,
            'total_without_vat'      => 2520.0,
            'total_vat'              => 529.2,
            'total_with_vat'         => 3049.2,
        ], $overrides);
    }

    private function assertValidIsdoc(string $xml): void
    {
        $dom = new \DOMDocument();
        self::assertTrue($dom->loadXML($xml), 'Export není well-formed XML.');

        $prev = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $ok = $dom->schemaValidate(self::XSD);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (!$ok) {
            $lines = array_map(
                static fn (\LibXMLError $e): string => sprintf('  [ř. %d] %s', $e->line, trim($e->message)),
                $errors,
            );
            self::fail("ISDOC XML není validní vůči isdoc-invoice-6.0.2.xsd:\n" . implode("\n", $lines));
        }

        self::assertTrue($ok);
    }
}
