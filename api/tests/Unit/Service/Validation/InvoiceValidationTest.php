<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Validation;

use MyInvoice\Service\Validation\InvoiceValidation;
use PHPUnit\Framework\TestCase;

final class InvoiceValidationTest extends TestCase
{
    public function testValidInvoicePasses(): void
    {
        $data = [
            'invoice_type' => 'invoice',
            'client_id'    => 1,
            'currency_id'  => 1,
            'issue_date'   => '2026-04-15',
            'due_date'     => '2026-04-30',
            'tax_date'     => '2026-04-15',
            'items' => [
                ['description' => 'Konzultace', 'quantity' => 2, 'unit_price_without_vat' => 1500, 'vat_rate_id' => 1],
            ],
        ];
        self::assertSame([], InvoiceValidation::invoice($data));
    }

    public function testInvalidTypeRejected(): void
    {
        $err = InvoiceValidation::invoice(['invoice_type' => 'fake', 'client_id' => 1]);
        self::assertArrayHasKey('invoice_type', $err);
    }

    public function testMissingClientId(): void
    {
        $err = InvoiceValidation::invoice(['invoice_type' => 'invoice']);
        self::assertArrayHasKey('client_id', $err);
    }

    public function testInvalidCurrencyIdRejected(): void
    {
        $err = InvoiceValidation::invoice(['client_id' => 1, 'currency_id' => 0]);
        self::assertArrayHasKey('currency_id', $err);
    }

    public function testInvalidDateFormat(): void
    {
        $err = InvoiceValidation::invoice([
            'client_id' => 1,
            'currency_id' => 1,
            'issue_date' => '15.4.2026',
        ]);
        self::assertArrayHasKey('issue_date', $err);
    }

    public function testProformaSkipsTaxDateValidation(): void
    {
        // Proforma nemá DUZP — i kdyby tam bylo nesmyslné, validace ho neřeší
        $err = InvoiceValidation::invoice([
            'invoice_type' => 'proforma',
            'client_id'    => 1,
            'currency_id'  => 1,
            'tax_date'     => 'garbage', // zaplaceno proforma typu — nikdy se nečte
            'items' => [['description' => 'x', 'quantity' => 1, 'unit_price_without_vat' => 100, 'vat_rate_id' => 1]],
        ]);
        self::assertArrayNotHasKey('tax_date', $err);
    }

    public function testItemMissingDescription(): void
    {
        $err = InvoiceValidation::invoice([
            'client_id' => 1,
            'currency_id' => 1,
            'items'     => [['description' => '   ', 'quantity' => 1, 'unit_price_without_vat' => 100, 'vat_rate_id' => 1]],
        ]);
        self::assertArrayHasKey('items.0.description', $err);
    }

    public function testInvoiceAllowsNegativeQuantityForDiscountLine(): void
    {
        $err = InvoiceValidation::invoice([
            'invoice_type' => 'invoice',
            'client_id'    => 1,
            'currency_id'  => 1,
            'items'        => [['description' => 'Sleva', 'quantity' => -1, 'unit_price_without_vat' => 100, 'vat_rate_id' => 1]],
        ]);
        self::assertArrayNotHasKey('items.0.quantity', $err);
        self::assertArrayNotHasKey('items.0.unit_price_without_vat', $err);
    }

    public function testItemZeroQuantityRejected(): void
    {
        $err = InvoiceValidation::invoice([
            'client_id' => 1,
            'currency_id' => 1,
            'items'     => [['description' => 'Sleva', 'quantity' => 0, 'unit_price_without_vat' => 100, 'vat_rate_id' => 1]],
        ]);
        self::assertArrayHasKey('items.0.quantity', $err);
    }

    public function testInvoiceAllowsNegativeUnitPriceForDiscountLine(): void
    {
        $err = InvoiceValidation::invoice([
            'invoice_type' => 'invoice',
            'client_id'    => 1,
            'currency_id'  => 1,
            'items'        => [['description' => 'Sleva', 'quantity' => 1, 'unit_price_without_vat' => -100, 'vat_rate_id' => 1]],
        ]);
        self::assertArrayNotHasKey('items.0.quantity', $err);
        self::assertArrayNotHasKey('items.0.unit_price_without_vat', $err);
    }

    public function testItemWithBothNegativeQuantityAndPriceRejected(): void
    {
        $err = InvoiceValidation::invoice([
            'client_id' => 1,
            'currency_id' => 1,
            'items'     => [['description' => 'Nejednoznačná sleva', 'quantity' => -1, 'unit_price_without_vat' => -100, 'vat_rate_id' => 1]],
        ]);
        self::assertArrayHasKey('items.0.quantity', $err);
        self::assertArrayHasKey('items.0.unit_price_without_vat', $err);
    }

    public function testItemMissingVatRate(): void
    {
        $err = InvoiceValidation::invoice([
            'client_id' => 1,
            'currency_id' => 1,
            'items'     => [['description' => 'x', 'quantity' => 1, 'unit_price_without_vat' => 100]],
        ]);
        self::assertArrayHasKey('items.0.vat_rate_id', $err);
    }

    public function testFinalInvoiceFromProformaAllowsZeroAmountToPay(): void
    {
        $err = InvoiceValidation::invoice([
            'invoice_type' => 'invoice',
            'parent_invoice_id' => 123,
            'client_id' => 1,
            'currency_id' => 1,
            'issue_date' => '2026-04-15',
            'due_date' => '2026-04-15',
            'tax_date' => '2026-04-15',
            'advance_paid_amount' => 1210,
            'items' => [
                ['description' => 'Daňový doklad k záloze', 'quantity' => 1, 'unit_price_without_vat' => 1000, 'vat_rate_id' => 1],
            ],
        ], [1 => 21.0]);

        self::assertArrayNotHasKey('amount_to_pay', $err);
    }
}
