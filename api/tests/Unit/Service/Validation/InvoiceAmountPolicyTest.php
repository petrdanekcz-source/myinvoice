<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Validation;

use MyInvoice\Service\Validation\InvoiceAmountPolicy;
use PHPUnit\Framework\TestCase;

final class InvoiceAmountPolicyTest extends TestCase
{
    public function testInvoiceWithDiscountLineKeepsPositiveAmountToPay(): void
    {
        $err = InvoiceAmountPolicy::validatePositiveAmountToPay([
            'invoice_type' => 'invoice',
            'advance_paid_amount' => 0,
            'reverse_charge' => false,
            'items' => [
                ['description' => 'Služba', 'quantity' => 1, 'unit_price_without_vat' => 1000, 'vat_rate_id' => 1],
                ['description' => 'Sleva', 'quantity' => 1, 'unit_price_without_vat' => -100, 'vat_rate_id' => 1],
            ],
        ], [1 => 21.0]);

        self::assertNull($err);
    }

    public function testInvoiceRejectsZeroAmountToPay(): void
    {
        $err = InvoiceAmountPolicy::validatePositiveAmountToPay([
            'invoice_type' => 'invoice',
            'advance_paid_amount' => 0,
            'reverse_charge' => false,
            'items' => [
                ['description' => 'Služba', 'quantity' => 1, 'unit_price_without_vat' => 1000, 'vat_rate_id' => 1],
                ['description' => 'Sleva', 'quantity' => 1, 'unit_price_without_vat' => -1000, 'vat_rate_id' => 1],
            ],
        ], [1 => 0.0]);

        self::assertSame('Výsledná částka k úhradě musí být větší než 0. Pro čistě záporný nebo nulový doklad použij dobropis.', $err);
    }

    public function testProformaRejectsNegativeAmountToPayAfterAdvance(): void
    {
        $err = InvoiceAmountPolicy::validatePositiveAmountToPay([
            'invoice_type' => 'proforma',
            'advance_paid_amount' => 1500,
            'reverse_charge' => false,
            'items' => [
                ['description' => 'Záloha', 'quantity' => 1, 'unit_price_without_vat' => 1000, 'vat_rate_id' => 1],
            ],
        ], [1 => 0.0]);

        self::assertSame('Výsledná částka k úhradě musí být větší než 0. Pro čistě záporný nebo nulový doklad použij dobropis.', $err);
    }

    public function testCreditNoteCanStayNonPositive(): void
    {
        $err = InvoiceAmountPolicy::validatePositiveAmountToPay([
            'invoice_type' => 'credit_note',
            'advance_paid_amount' => 0,
            'reverse_charge' => false,
            'items' => [
                ['description' => 'Vrácení', 'quantity' => -1, 'unit_price_without_vat' => 1000, 'vat_rate_id' => 1],
            ],
        ], [1 => 21.0]);

        self::assertNull($err);
    }

    public function testFinalInvoiceFromProformaCanStayAtZeroAmountToPay(): void
    {
        $err = InvoiceAmountPolicy::validatePositiveAmountToPay([
            'invoice_type' => 'invoice',
            'parent_invoice_id' => 123,
            'advance_paid_amount' => 1210,
            'reverse_charge' => false,
            'items' => [
                ['description' => 'Daňový doklad k záloze', 'quantity' => 1, 'unit_price_without_vat' => 1000, 'vat_rate_id' => 1],
            ],
        ], [1 => 21.0]);

        self::assertNull($err);
    }

    public function testHasPositiveAmountToPayRejectsNonPositiveInvoice(): void
    {
        self::assertFalse(InvoiceAmountPolicy::hasPositiveAmountToPay([
            'invoice_type' => 'invoice',
            'amount_to_pay' => 0,
        ]));
        self::assertFalse(InvoiceAmountPolicy::hasPositiveAmountToPay([
            'invoice_type' => 'proforma',
            'amount_to_pay' => -10,
        ]));
        self::assertTrue(InvoiceAmountPolicy::hasPositiveAmountToPay([
            'invoice_type' => 'credit_note',
            'amount_to_pay' => -10,
        ]));
    }

    public function testRequiresPositiveDraftAmountToPaySkipsFinalInvoiceFromProforma(): void
    {
        self::assertTrue(InvoiceAmountPolicy::requiresPositiveDraftAmountToPay('invoice'));
        self::assertTrue(InvoiceAmountPolicy::requiresPositiveDraftAmountToPay('proforma'));
        self::assertFalse(InvoiceAmountPolicy::requiresPositiveDraftAmountToPay('invoice', 123));
        self::assertFalse(InvoiceAmountPolicy::requiresPositiveDraftAmountToPay('credit_note'));
    }
}
