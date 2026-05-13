<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Currency\ExchangeRateApplier;
use MyInvoice\Service\Invoice\InvoiceCalculator;
use MyInvoice\Service\Invoice\InvoiceDefaults;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Validation\InvoiceValidation;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class CreateInvoiceAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly ClientRepository $clients,
        private readonly InvoiceDefaults $defaults,
        private readonly InvoiceCalculator $calc,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly ExchangeRateApplier $rateApplier,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $body = $this->defaults->resolve($body);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'integrity_violation', $e->getMessage(), 400);
        }

        $errors = InvoiceValidation::invoice($body, $this->repo->vatRateMap());
        if (!empty($errors)) {
            return Json::error($response, 'validation_failed', 'Validace selhala', 400, ['fields' => $errors]);
        }

        // Klient musí existovat A patřit aktuálnímu supplier (proti cross-supplier injection)
        if (!SupplierGuard::owns($request, $this->clients->find((int) $body['client_id']))) {
            return Json::error($response, 'client_not_found', 'Klient neexistuje.', 400);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);

        $id = $this->repo->createDraft($body, $userId);
        $this->repo->replaceItems($id, (array) ($body['items'] ?? []));
        $this->calc->recompute($id);
        $rateMeta = $this->rateApplier->applyToInvoice($id);

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('invoice.created', $userId, 'invoice', $id, [
            'client_id' => $body['client_id'],
            'type'      => $body['invoice_type'] ?? 'invoice',
        ], $ip, $request->getHeaderLine('User-Agent'));

        $invoice = $this->repo->find($id);
        if ($rateMeta !== null) {
            $invoice['_meta'] = ['exchange_rate' => $rateMeta];
        }
        return Json::ok($response, $invoice, 201);
    }
}
