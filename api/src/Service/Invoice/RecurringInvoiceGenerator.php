<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\RecurringTemplateRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Currency\ExchangeRateApplier;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Service\Stats\StatsRecomputer;
use MyInvoice\Service\Validation\InvoiceAmountPolicy;

/**
 * Vygeneruje fakturu ze šablony pravidelné fakturace.
 *
 * Kroky:
 *   1. Vytvoří draft (klon šablony — client, project, currency, language,
 *      payment_method, reverse_charge, notes; položky se zkopírují s opt.
 *      regex inkrementem měsíce v popisu — viz MonthIncrementer).
 *   2. Recompute totals (InvoiceCalculator).
 *   3. Aplikuje ČNB kurz, pokud měna != CZK (ExchangeRateApplier).
 *   4. Pokud auto_issue=true:
 *        - auto_send_email=true → AutoIssueAndSendService.run() (issue + render + send)
 *        - jinak → in-place issue (varsymbol + snapshots + status='issued')
 *   5. Posune next_run_date na šabloně (PeriodicityCalculator) a updatuje
 *      last_run_date; pokud nové next > end_date, status='expired'.
 *
 * Vrací sumář pro cron / RunNowAction.
 *
 * @phpstan-type Result array{
 *     invoice_id: int,
 *     varsymbol: ?string,
 *     issued: bool,
 *     sent_to: list<string>,
 *     new_next_run_date: ?string,
 *     template_status: string,
 * }
 */
final class RecurringInvoiceGenerator
{
    public function __construct(
        private readonly Connection $db,
        private readonly RecurringTemplateRepository $templates,
        private readonly InvoiceRepository $invoices,
        private readonly InvoiceCalculator $calc,
        private readonly ExchangeRateApplier $rateApplier,
        private readonly AutoIssueAndSendService $issueAndSend,
        private readonly VarsymbolGenerator $varsymbol,
        private readonly SnapshotBuilder $snapshots,
        private readonly InvoicePdfRenderer $pdfRenderer,
        private readonly StatsRecomputer $stats,
        private readonly ActivityLogger $logger,
    ) {}

    /**
     * @return array{invoice_id:int, varsymbol:?string, issued:bool, sent_to:list<string>, new_next_run_date:?string, template_status:string}
     */
    public function generate(int $templateId, ?string $forcedIssueDate = null, ?int $userId = null, string $ip = '', string $ua = 'cron'): array
    {
        $template = $this->templates->find($templateId);
        if ($template === null) {
            throw new \RuntimeException("Šablona #$templateId nenalezena");
        }
        if (empty($template['items'])) {
            throw new \DomainException("Šablona #$templateId nemá žádné položky.");
        }

        $issueDate = $forcedIssueDate ?? (string) $template['next_run_date'];

        // Validate state — paused/expired by neměl cron volat, ale RunNow může
        if ($template['status'] === 'expired') {
            throw new \DomainException('Šablona vypršela (end_date prošel).');
        }

        $invoiceId = $this->createInvoiceFromTemplate($template, $issueDate, $userId);
        $this->calc->recompute($invoiceId);
        $invoice = $this->invoices->find($invoiceId);
        if ($invoice === null) {
            throw new \RuntimeException("Invoice #$invoiceId not found after generation");
        }
        if (!InvoiceAmountPolicy::hasPositiveAmountToPay($invoice)) {
            $this->invoices->delete($invoiceId);
            throw new \DomainException(InvoiceAmountPolicy::NON_POSITIVE_DRAFT_MESSAGE);
        }
        $this->rateApplier->applyToInvoice($invoiceId);

        $issued = false;
        $sentTo = [];
        $varsymbol = null;

        if ($template['auto_issue']) {
            if ($template['auto_send_email']) {
                $result = $this->issueAndSend->run($invoiceId, $userId, $ip, $ua);
                $issued = $result['issued'];
                $sentTo = $result['sent_to'];
                $varsymbol = $result['varsymbol'];
            } else {
                $varsymbol = $this->issueOnlyWithoutSend($invoiceId, $userId, $ip, $ua);
                $issued = true;
            }
        }

        // Posun next_run_date + případný expire
        $newNext = PeriodicityCalculator::nextRunDate(
            $issueDate,
            (string) $template['frequency'],
            (bool) $template['end_of_month'],
            $template['day_of_month'] !== null ? (int) $template['day_of_month'] : null,
        );

        $newStatus = (string) $template['status'];
        if (!empty($template['end_date']) && $newNext > (string) $template['end_date']) {
            $newStatus = 'expired';
        }

        $this->templates->advanceSchedule(
            $templateId,
            $newNext,
            $issueDate,
            $newStatus,
        );

        $this->logger->log('recurring.generated', $userId, 'recurring_template', $templateId, [
            'invoice_id'  => $invoiceId,
            'issue_date'  => $issueDate,
            'auto_issue'  => $template['auto_issue'],
            'auto_send'   => $template['auto_send_email'],
            'sent_to'     => $sentTo,
            'next_run'    => $newNext,
            'new_status'  => $newStatus,
        ], $ip, $ua);

        return [
            'invoice_id'        => $invoiceId,
            'varsymbol'         => $varsymbol,
            'issued'            => $issued,
            'sent_to'           => $sentTo,
            'new_next_run_date' => $newNext,
            'template_status'   => $newStatus,
        ];
    }

    /**
     * Insert draft + items. Zachovává payment_method, reverse_charge, language,
     * notes a item description s případným month-increment.
     */
    private function createInvoiceFromTemplate(array $template, string $issueDate, ?int $userId): int
    {
        $pdo = $this->db->pdo();

        $type = (string) ($template['invoice_type'] ?? 'invoice');
        $dueDate = date('Y-m-d', strtotime($issueDate . ' +' . (int) $template['payment_due_days'] . ' days'));
        $taxDate = $type === 'proforma' ? null : $issueDate;

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO invoices
                   (invoice_type, client_id, project_id, supplier_id,
                    issue_date, tax_date, due_date, currency_id, reverse_charge, language,
                    note_above_items, note_below_items, payment_method,
                    recurring_template_id, status, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "draft", ?)'
            );
            $stmt->execute([
                $type,
                (int) $template['client_id'],
                !empty($template['project_id']) ? (int) $template['project_id'] : null,
                (int) $template['supplier_id'],
                $issueDate,
                $taxDate,
                $dueDate,
                (int) $template['currency_id'],
                $template['reverse_charge'] ? 1 : 0,
                (string) ($template['language'] ?? 'cs'),
                $template['note_above_items'] ?? null,
                $template['note_below_items'] ?? null,
                (string) ($template['payment_method'] ?? 'bank_transfer'),
                (int) $template['id'],
                $userId,
            ]);
            $newId = (int) $pdo->lastInsertId();

            // Item-level month-increment podle frequency × interval (default 1, lze vypnout flagem)
            $monthsToIncrement = $template['increment_month_in_descriptions']
                ? PeriodicityCalculator::monthsFor((string) $template['frequency'])
                : 0;

            $itemStmt = $pdo->prepare(
                'INSERT INTO invoice_items
                   (invoice_id, description, quantity, unit, unit_price_without_vat,
                    vat_rate_id, vat_rate_snapshot,
                    total_without_vat, total_vat, total_with_vat, order_index)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, 0, ?)'
            );

            // VAT snapshot — pro účely insertu (recompute pak přepočítá z aktuální vat_rates.rate_percent;
            // sem dáváme rozumný initial 0, calc to přebije). Bez tohoto INSERTu by hodily NOT NULL.
            // Pozn.: BulkReissue ukládá `vat_rate_snapshot` z položky source faktury — my v šabloně
            // snapshot nedržíme, takže necháme 0 a recompute si poradí.
            foreach ($template['items'] as $item) {
                $description = $monthsToIncrement !== 0
                    ? MonthIncrementer::increment((string) $item['description'], $monthsToIncrement)
                    : (string) $item['description'];

                $itemStmt->execute([
                    $newId,
                    $description,
                    (float) $item['quantity'],
                    (string) $item['unit'],
                    (float) $item['unit_price_without_vat'],
                    (int) $item['vat_rate_id'],
                    0,  // vat_rate_snapshot — recompute() ho přebije z vat_rates.rate_percent
                    (int) $item['order_index'],
                ]);
            }

            $pdo->commit();
            return $newId;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Issue draft bez odeslání — vystaví VS + snapshoty + status='issued',
     * invaliduje cached PDF, recompute stats. Vrací varsymbol.
     */
    private function issueOnlyWithoutSend(int $invoiceId, ?int $userId, string $ip, string $ua): string
    {
        $invoice = $this->invoices->find($invoiceId);
        if ($invoice === null) {
            throw new \RuntimeException("Invoice #$invoiceId not found after generation");
        }

        $supplierId = (int) $invoice['supplier_id'];
        $issueDate = new \DateTimeImmutable((string) $invoice['issue_date']);
        $varsymbol = $this->varsymbol->next($supplierId, (string) $invoice['invoice_type'], $issueDate);
        $snaps = $this->snapshots->build(
            (int) $invoice['client_id'],
            (int) $invoice['currency_id'],
            $supplierId,
        );

        $this->db->pdo()->prepare(
            'UPDATE invoices SET
                varsymbol         = ?,
                client_snapshot   = ?,
                supplier_snapshot = ?,
                bank_snapshot     = ?,
                status            = "issued"
             WHERE id = ? AND status = "draft"'
        )->execute([
            $varsymbol,
            json_encode($snaps['client'], JSON_UNESCAPED_UNICODE),
            json_encode($snaps['supplier'], JSON_UNESCAPED_UNICODE),
            $snaps['bank'] !== null ? json_encode($snaps['bank'], JSON_UNESCAPED_UNICODE) : null,
            $invoiceId,
        ]);

        $this->stats->recomputeForInvoiceId($invoiceId);
        $this->pdfRenderer->invalidate($invoiceId, 'invalidate_recurring_issue');

        $this->logger->log('invoice.issued', $userId, 'invoice', $invoiceId, [
            'varsymbol'   => $varsymbol,
            'auto_reason' => 'recurring_template',
        ], $ip, $ua);

        return $varsymbol;
    }
}
