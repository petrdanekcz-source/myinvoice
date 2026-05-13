<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\InvoiceCalculator;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Hromadně klonuje označené faktury do dalšího měsíce — vytvoří DRAFTy.
 *
 * Body:
 *   {
 *     "invoice_ids": [101, 102, 103],
 *     "increment_month_in_descriptions": true,
 *     "issue_date": null  // null = today
 *   }
 *
 * Pro každou source fakturu:
 *   - vytvoří draft (status='draft', varsymbol=null)
 *   - kopie items + work_report (zatím work_report ne — M5)
 *   - auto-increment měsíce v popisech (regex /\b(\d{1,2})\/(\d{4})\b/)
 *   - tax_date/due_date = today (nebo +project.payment_due_days)
 *
 * Žádný draft se neodesílá ani nevystavuje. User musí každý ručně otevřít.
 */
final class BulkReissueAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly Connection $db,
        private readonly InvoiceCalculator $calc,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $ids = (array) ($body['invoice_ids'] ?? []);
        $incrementMonth = (bool) ($body['increment_month_in_descriptions'] ?? true);
        $issueDate = isset($body['issue_date']) && $body['issue_date'] !== null && $body['issue_date'] !== ''
            ? (string) $body['issue_date']
            : date('Y-m-d');

        if (empty($ids)) {
            return Json::error($response, 'no_invoices', 'Není vybrána žádná faktura.', 400);
        }
        if (count($ids) > 200) {
            return Json::error($response, 'too_many', 'Najednou lze klonovat maximálně 200 faktur.', 422);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);

        $created = [];
        $errors = [];

        foreach ($ids as $sourceId) {
            $sourceId = (int) $sourceId;
            // Ownership: nedovol klonovat cizí faktury
            if (!SupplierGuard::owns($request, $this->repo->find($sourceId))) {
                $errors[] = ['source_id' => $sourceId, 'error' => 'not_found'];
                continue;
            }
            try {
                $newId = $this->cloneOne($sourceId, $issueDate, $incrementMonth, $userId);
                $created[] = ['source_id' => $sourceId, 'draft_id' => $newId];
            } catch (\Throwable $e) {
                $errors[] = ['source_id' => $sourceId, 'error' => $e->getMessage()];
            }
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('invoice.reissued_bulk', $userId, null, null, [
            'source_count'  => count($ids),
            'created_count' => count($created),
            'error_count'   => count($errors),
            'increment_month' => $incrementMonth,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, [
            'created' => $created,
            'errors'  => $errors,
        ], 201);
    }

    public function cloneOne(int $sourceId, string $issueDate, bool $incrementMonth, int $userId): int
    {
        $source = $this->repo->find($sourceId);
        if ($source === null) {
            throw new \RuntimeException("Faktura #$sourceId nenalezena");
        }

        $type = $source['invoice_type'] === 'proforma' ? 'proforma' : 'invoice';

        // Default due_date podle project nebo +14
        $dueDate = $issueDate;
        if (!empty($source['project_id'])) {
            $stmt = $this->db->pdo()->prepare('SELECT payment_due_days FROM projects WHERE id = ?');
            $stmt->execute([$source['project_id']]);
            $days = (int) $stmt->fetchColumn();
            if ($days > 0) {
                $dueDate = date('Y-m-d', strtotime($issueDate . " +{$days} days"));
            }
        }

        $taxDate = $type === 'proforma' ? null : $issueDate;

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO invoices
                   (invoice_type, client_id, project_id, supplier_id,
                    issue_date, tax_date, due_date, currency_id, reverse_charge, language,
                    note_above_items, note_below_items, payment_method, status, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "draft", ?)'
            );
            $stmt->execute([
                $type,
                $source['client_id'],
                $source['project_id'],
                (int) $source['supplier_id'],
                $issueDate,
                $taxDate,
                $dueDate,
                (int) $source['currency_id'],
                $source['reverse_charge'] ? 1 : 0,
                $source['language'],
                $source['note_above_items'],
                $source['note_below_items'],
                (string) ($source['payment_method'] ?? 'bank_transfer'),
                $userId,
            ]);
            $newId = (int) $pdo->lastInsertId();

            // Zkopíruj položky s případným inkrementem měsíce
            $itemStmt = $pdo->prepare(
                'INSERT INTO invoice_items
                   (invoice_id, description, quantity, unit, unit_price_without_vat,
                    vat_rate_id, vat_rate_snapshot,
                    total_without_vat, total_vat, total_with_vat, order_index)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, 0, ?)'
            );
            foreach ($source['items'] as $item) {
                $description = $incrementMonth
                    ? $this->incrementMonthInString((string) $item['description'])
                    : (string) $item['description'];

                $itemStmt->execute([
                    $newId,
                    $description,
                    $item['quantity'],
                    $item['unit'],
                    $item['unit_price_without_vat'],
                    $item['vat_rate_id'],
                    $item['vat_rate_snapshot'],
                    $item['order_index'],
                ]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $this->calc->recompute($newId);
        return $newId;
    }

    /**
     * Inkrementuje měsíc v řetězcích, které vypadají jako rok+měsíc.
     *
     * Podporované formáty (vždy musí být přítomen 4-místný rok, jinak je
     * dvojice čísel příliš ambiguózní — např. "5/26" může být datum 26. května):
     *   M/YYYY, MM/YYYY        "3/2026"   → "4/2026",   "12/2025"  → "1/2026"
     *   YYYY-MM, YYYY-M        "2026-05"  → "2026-06",  "2025-12"  → "2026-01"
     *   YYYY/MM                "2026/05"  → "2026/06"
     *   MM.YYYY, M.YYYY        "12.2025"  → "1.2026"
     *   MM-YYYY, M-YYYY        "12-2025"  → "1-2026"
     *
     * Zachovává původní separátor i zero-padding měsíce.
     * Plná data (např. "2026-05-15") jsou chráněna lookaroundy a neinkrementují se.
     * Neplatné měsíce (0, >12) zůstávají beze změny.
     */
    public function incrementMonthInString(string $text): string
    {
        // (?<![\d./-]) … (?![\d./-]) chrání před matchem uvnitř plných dat
        // jako "2026-05-15" nebo Czech "20.5.2026".
        return preg_replace_callback(
            '/(?<![\d.\/\-])(\d{1,4})([.\/\-])(\d{1,4})(?![\d.\/\-])/',
            function ($m) {
                [$full, $left, $sep, $right] = $m;
                $leftLen  = strlen($left);
                $rightLen = strlen($right);

                // Identifikuj, která strana je rok (přesně 4 číslice) a která měsíc (1-2 číslice).
                // Padding: ISO formát "YYYY-MM" vždy paduje (konvence). Month-first
                // formáty padují jen když uživatel sám napsal leading zero ("01-2026"),
                // jinak ne ("12/2025" → "1/2026", ne "01/2026").
                if ($leftLen === 4 && $rightLen >= 1 && $rightLen <= 2) {
                    $year         = (int) $left;
                    $month        = (int) $right;
                    $yearFirst    = true;
                    $monthPadded  = true;
                } elseif ($rightLen === 4 && $leftLen >= 1 && $leftLen <= 2) {
                    $month        = (int) $left;
                    $year         = (int) $right;
                    $yearFirst    = false;
                    $monthPadded  = $leftLen === 2 && $left[0] === '0';
                } else {
                    return $full; // nezná se, který je rok
                }

                if ($month < 1 || $month > 12) {
                    return $full; // neplatný měsíc
                }
                $month++;
                if ($month > 12) {
                    $month = 1;
                    $year++;
                }

                $monthStr = $monthPadded ? sprintf('%02d', $month) : (string) $month;
                return $yearFirst
                    ? "{$year}{$sep}{$monthStr}"
                    : "{$monthStr}{$sep}{$year}";
            },
            $text,
        ) ?? $text;
    }
}
