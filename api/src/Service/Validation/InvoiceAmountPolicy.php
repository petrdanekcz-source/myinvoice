<?php

declare(strict_types=1);

namespace MyInvoice\Service\Validation;

use MyInvoice\Service\Invoice\InvoiceMath;

final class InvoiceAmountPolicy
{
    public const NON_POSITIVE_DRAFT_MESSAGE = 'Výsledná částka k úhradě musí být větší než 0. Pro čistě záporný nebo nulový doklad použij dobropis.';
    public const NON_POSITIVE_MARK_PAID_MESSAGE = 'Fakturu s částkou k úhradě 0 nebo méně nelze označit jako zaplacenou.';
    public const NON_POSITIVE_REMINDER_MESSAGE = 'Upomínat lze jen faktury s kladnou částkou k úhradě.';

    public static function requiresPositiveDraftAmountToPay(string $invoiceType, mixed $parentInvoiceId = null): bool
    {
        if (!in_array($invoiceType, ['invoice', 'proforma'], true)) {
            return false;
        }

        // Finální daňový doklad k zaplacené proformě je vedený jako `invoice`
        // s parent_invoice_id a typicky amount_to_pay = 0 po odečtu zálohy.
        if ($invoiceType === 'invoice' && (int) $parentInvoiceId > 0) {
            return false;
        }

        return true;
    }

    public static function requiresPositiveAmountToPay(string $invoiceType): bool
    {
        return in_array($invoiceType, ['invoice', 'proforma'], true);
    }

    /**
     * @param array<int, float> $vatRates
     */
    public static function validatePositiveAmountToPay(array $data, array $vatRates): ?string
    {
        $type = (string) ($data['invoice_type'] ?? 'invoice');
        if (!self::requiresPositiveDraftAmountToPay($type, $data['parent_invoice_id'] ?? null)) {
            return null;
        }

        $items = $data['items'] ?? null;
        if (!is_array($items) || $items === []) {
            return null;
        }

        $mathItems = [];
        foreach ($items as $item) {
            if (
                !is_array($item)
                || !isset($item['quantity'], $item['unit_price_without_vat'], $item['vat_rate_id'])
                || !is_numeric($item['quantity'])
                || !is_numeric($item['unit_price_without_vat'])
                || !is_numeric($item['vat_rate_id'])
            ) {
                return null;
            }

            $vatRateId = (int) $item['vat_rate_id'];
            $mathItems[] = [
                'quantity' => (float) $item['quantity'],
                'unit_price_without_vat' => (float) $item['unit_price_without_vat'],
                'vat_rate_snapshot' => $vatRates[$vatRateId] ?? 0.0,
            ];
        }

        $computed = InvoiceMath::compute($mathItems, !empty($data['reverse_charge']));
        $advance = round((float) ($data['advance_paid_amount'] ?? 0), 2);
        $amountToPay = round((float) $computed['totals']['with_vat'] - $advance, 2);

        return $amountToPay > 0 ? null : self::NON_POSITIVE_DRAFT_MESSAGE;
    }

    public static function hasPositiveAmountToPay(array $invoice): bool
    {
        $type = (string) ($invoice['invoice_type'] ?? 'invoice');
        if (!self::requiresPositiveAmountToPay($type)) {
            return true;
        }

        return (float) ($invoice['amount_to_pay'] ?? 0) > 0;
    }
}
