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

final class MarkPaidAction
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
        $id = (int) ($args['id'] ?? 0);
        $invoice = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }
        if (!in_array($invoice['status'], ['issued', 'sent', 'reminded'], true)) {
            return Json::error($response, 'invalid_state', 'Lze označit jako zaplacené jen vystavenou nebo odeslanou fakturu.', 409);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $paidAt = (string) ($body['paid_at'] ?? date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $paidAt)) {
            return Json::error($response, 'invalid_date', 'Neplatné datum.', 400);
        }

        $stmt = $this->db->pdo()->prepare(
            'UPDATE invoices SET status = "paid", paid_at = ? WHERE id = ?'
        );
        $stmt->execute([$paidAt, $id]);

        // Cached PDF má embedded status (UHRAZENO stamp, QR skip) — bez invalidace by
        // se servíroval starý soubor s výzvou k platbě i po označení za zaplacené.
        $this->pdf->invalidate($id, 'invalidate_mark_paid');

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('invoice.paid', $user['id'] ?? null, 'invoice', $id, [
            'paid_at' => $paidAt,
        ], $ip, $request->getHeaderLine('User-Agent'));

        $this->stats->recomputeForInvoiceId($id);

        return Json::ok($response, $this->repo->find($id));
    }
}
