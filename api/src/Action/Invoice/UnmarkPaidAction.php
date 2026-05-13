<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Service\Stats\StatsRecomputer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/invoices/{id}/unmark-paid (admin only)
 *
 * Vrátí fakturu ze stavu 'paid' zpět do 'sent' (pokud byla odeslaná) nebo 'issued',
 * vyčistí paid_at, přepočítá statistiky.
 *
 * Bezpečnostní guard: pokud je faktura spárovaná s aktivní bankovní transakcí,
 * vrátí 409 — uživatel má použít „Zrušit spárování" v detailu výpisu, který
 * cascades správně (vrátí jak invoice, tak bank tx).
 */
final class UnmarkPaidAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly Connection $db,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly StatsRecomputer $stats,
        private readonly InvoicePdfRenderer $pdf,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (($user['role'] ?? '') !== 'admin') {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }

        $id = (int) ($args['id'] ?? 0);
        $invoice = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }
        if ($invoice['status'] !== 'paid') {
            return Json::error($response, 'invalid_state', 'Lze vrátit zpět jen zaplacenou fakturu.', 409);
        }

        // Guard: nesmí být spárovaná aktivní bankovní transakce — uživatel má
        // použít bank unmatch flow, který odpáruje tx i invoice atomicky.
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM bank_transactions
              WHERE matched_invoice_id = ?
                AND match_status IN ('auto_exact', 'auto_partial', 'manual')"
        );
        $stmt->execute([$id]);
        $matchedCount = (int) $stmt->fetchColumn();
        if ($matchedCount > 0) {
            return Json::error(
                $response,
                'has_matched_tx',
                'Faktura je spárovaná s bankovní transakcí. Nejdřív zruš spárování v detailu výpisu.',
                409,
            );
        }

        // Revert na předchozí stav: 'sent' pokud byla odeslaná (sent_at != NULL), jinak 'issued'.
        // Reminder stav (reminded) se nezachovává — uživatel může upomínku poslat znovu.
        $previousPaidAt = (string) ($invoice['paid_at'] ?? '');
        $this->db->pdo()->prepare(
            "UPDATE invoices
                SET status  = IF(sent_at IS NOT NULL, 'sent', 'issued'),
                    paid_at = NULL
              WHERE id = ? AND status = 'paid'"
        )->execute([$id]);

        // Cached PDF nese „UHRAZENO" stamp — bez invalidace by se po unmark vracel.
        $this->pdf->invalidate($id, 'invalidate_unmark_paid');

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('invoice.unmark_paid', $user['id'] ?? null, 'invoice', $id, [
            'previous_paid_at' => $previousPaidAt ?: null,
        ], $ip, $request->getHeaderLine('User-Agent'));

        $this->stats->recomputeForInvoiceId($id);

        return Json::ok($response, $this->repo->find($id));
    }
}
