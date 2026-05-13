<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class InvoiceAmountRegressionTest extends TestCase
{
    public function testManualBankMatchChecksPositiveAmountBeforeMarkPaid(): void
    {
        $code = file_get_contents(dirname(__DIR__, 3) . '/api/src/Action/Bank/BankStatementAction.php');
        self::assertIsString($code);

        self::assertStringContainsString('InvoiceAmountPolicy::hasPositiveAmountToPay($invoice)', $code);
        self::assertStringContainsString('InvoiceAmountPolicy::NON_POSITIVE_MARK_PAID_MESSAGE', $code);
    }

    public function testInvoiceListsRenderZeroAmountToPayWithoutFallbackToTotal(): void
    {
        $invoiceList = file_get_contents(dirname(__DIR__, 3) . '/web/src/pages/invoices/InvoiceList.vue');
        self::assertIsString($invoiceList);
        self::assertStringNotContainsString('formatMoney(inv.amount_to_pay || inv.total_with_vat, inv.currency)', $invoiceList);
        self::assertStringContainsString('formatMoney(inv.amount_to_pay ?? inv.total_with_vat, inv.currency)', $invoiceList);

        $projectDetail = file_get_contents(dirname(__DIR__, 3) . '/web/src/pages/projects/ProjectDetail.vue');
        self::assertIsString($projectDetail);
        self::assertStringNotContainsString('formatMoney(inv.amount_to_pay || inv.total_with_vat, inv.currency)', $projectDetail);
        self::assertStringContainsString('formatMoney(inv.amount_to_pay ?? inv.total_with_vat, inv.currency)', $projectDetail);

        $clientDetail = file_get_contents(dirname(__DIR__, 3) . '/web/src/pages/clients/ClientDetail.vue');
        self::assertIsString($clientDetail);
        self::assertStringNotContainsString('formatMoney(inv.amount_to_pay || inv.total_with_vat, inv.currency)', $clientDetail);
        self::assertStringContainsString('formatMoney(inv.amount_to_pay ?? inv.total_with_vat, inv.currency)', $clientDetail);
    }
}
