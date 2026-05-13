<?php

declare(strict_types=1);

namespace MyInvoice\Action\Recurring;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\RecurringTemplateRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\PeriodicityCalculator;
use MyInvoice\Service\Invoice\RecurringInvoiceGenerator;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Validation\InvoiceAmountPolicy;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * REST handlery pro pravidelné fakturace.
 *
 *   GET    /api/recurring                 → list
 *   POST   /api/recurring                 → create
 *   GET    /api/recurring/{id}            → detail
 *   PUT    /api/recurring/{id}            → update
 *   DELETE /api/recurring/{id}            → delete
 *   POST   /api/recurring/{id}/pause      → pause
 *   POST   /api/recurring/{id}/resume     → resume + přepočet next_run
 *   POST   /api/recurring/{id}/run-now    → manuální spuštění (testování)
 */
final class RecurringTemplateAction
{
    public function __construct(
        private readonly RecurringTemplateRepository $repo,
        private readonly RecurringInvoiceGenerator $generator,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly Connection $db,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $filters = ['supplier_id' => $supplierId];
        $q = $request->getQueryParams();
        if (!empty($q['client_id'])) $filters['client_id'] = (int) $q['client_id'];
        if (!empty($q['status']))    $filters['status'] = (string) $q['status'];

        $rows = $this->repo->list($filters);
        return Json::ok($response, ['data' => $rows]);
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $tpl = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $tpl)) {
            return Json::error($response, 'not_found', 'Šablona nenalezena.', 404);
        }
        return Json::ok($response, $tpl);
    }

    /**
     * GET /api/recurring/{id}/invoices
     * Vrátí flat list faktur vygenerovaných z této šablony (poslední první).
     */
    public function invoices(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $tpl = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $tpl)) {
            return Json::error($response, 'not_found', 'Šablona nenalezena.', 404);
        }

        $stmt = $this->db->pdo()->prepare(
            "SELECT i.id, i.varsymbol, i.invoice_type, i.status, i.issue_date, i.due_date, i.paid_at,
                    i.total_with_vat, i.amount_to_pay, cur.code AS currency
               FROM invoices i
               JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.recurring_template_id = ?
              ORDER BY i.issue_date DESC, i.id DESC"
        );
        $stmt->execute([$id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Cast numerics
        foreach ($rows as &$r) {
            $r['id']             = (int) $r['id'];
            $r['total_with_vat'] = (float) $r['total_with_vat'];
            $r['amount_to_pay']  = (float) $r['amount_to_pay'];
        }
        unset($r);

        return Json::ok($response, ['data' => $rows]);
    }

    public function create(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $supplierId = SupplierGuard::currentId($request);
        $body['supplier_id'] = $supplierId;

        $errors = $this->validate($body);
        if (!empty($errors)) {
            return Json::error($response, 'validation_failed', 'Validace selhala', 400, ['fields' => $errors]);
        }

        // next_run_date = anchor_date pokud není explicitně zadané
        $body['next_run_date'] = $body['next_run_date'] ?? $body['anchor_date'];

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());

        $id = $this->repo->create($body, $userId);
        $this->repo->replaceItems($id, (array) ($body['items'] ?? []));

        $this->logger->log('recurring.created', $userId, 'recurring_template', $id, [
            'client_id' => $body['client_id'],
            'frequency' => $body['frequency'],
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, $this->repo->find($id), 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $tpl = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $tpl)) {
            return Json::error($response, 'not_found', 'Šablona nenalezena.', 404);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $body['supplier_id'] = (int) $tpl['supplier_id'];

        $errors = $this->validate($body);
        if (!empty($errors)) {
            return Json::error($response, 'validation_failed', 'Validace selhala', 400, ['fields' => $errors]);
        }

        $this->repo->update($id, $body);
        $this->repo->replaceItems($id, (array) ($body['items'] ?? []));

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('recurring.updated', $user['id'] ?? null, 'recurring_template', $id, null, $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, $this->repo->find($id));
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $tpl = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $tpl)) {
            return Json::error($response, 'not_found', 'Šablona nenalezena.', 404);
        }

        $this->repo->delete($id);

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('recurring.deleted', $user['id'] ?? null, 'recurring_template', $id, [
            'name' => $tpl['name'] ?? null,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, ['deleted' => true]);
    }

    public function pause(Request $request, Response $response, array $args): Response
    {
        return $this->setStatus($request, $response, $args, 'paused', 'recurring.paused');
    }

    public function resume(Request $request, Response $response, array $args): Response
    {
        // Při resume zkontroluj, jestli next_run_date není v minulosti za end_date.
        $id = (int) ($args['id'] ?? 0);
        $tpl = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $tpl)) {
            return Json::error($response, 'not_found', 'Šablona nenalezena.', 404);
        }
        if (!empty($tpl['end_date']) && (string) $tpl['next_run_date'] > (string) $tpl['end_date']) {
            return Json::error($response, 'expired', 'Šablona vypršela (next_run > end_date).', 409);
        }
        return $this->setStatus($request, $response, $args, 'active', 'recurring.resumed');
    }

    public function runNow(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $tpl = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $tpl)) {
            return Json::error($response, 'not_found', 'Šablona nenalezena.', 404);
        }
        if ($tpl['status'] === 'expired') {
            return Json::error($response, 'expired', 'Šablona vypršela.', 409);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $forcedIssueDate = !empty($body['issue_date']) ? (string) $body['issue_date'] : null;

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $ua = $request->getHeaderLine('User-Agent');

        try {
            $result = $this->generator->generate($id, $forcedIssueDate, $userId, $ip, $ua);
        } catch (\DomainException $e) {
            return Json::error($response, 'cannot_generate', $e->getMessage(), 409);
        } catch (\Throwable $e) {
            return Json::error($response, 'generation_failed', $e->getMessage(), 500);
        }

        return Json::ok($response, $result, 201);
    }

    private function setStatus(Request $request, Response $response, array $args, string $status, string $action): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $tpl = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $tpl)) {
            return Json::error($response, 'not_found', 'Šablona nenalezena.', 404);
        }
        $this->repo->setStatus($id, $status);
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log($action, $user['id'] ?? null, 'recurring_template', $id, null, $ip, $request->getHeaderLine('User-Agent'));
        return Json::ok($response, $this->repo->find($id));
    }

    /**
     * @return array<string, string[]>
     */
    private function validate(array $data): array
    {
        $err = [];

        if (empty($data['client_id']) || !is_numeric($data['client_id'])) {
            $err['client_id'][] = 'Klient je povinný';
        }
        if (empty($data['name']) || trim((string) $data['name']) === '') {
            $err['name'][] = 'Název šablony je povinný';
        }
        $frequency = (string) ($data['frequency'] ?? '');
        if (!in_array($frequency, PeriodicityCalculator::FREQUENCIES, true)) {
            $err['frequency'][] = 'Neplatná periodicita';
        }
        if (empty($data['anchor_date']) || !self::isValidDate((string) $data['anchor_date'])) {
            $err['anchor_date'][] = 'Neplatné datum zahájení';
        }
        if (!empty($data['end_date'])) {
            if (!self::isValidDate((string) $data['end_date'])) {
                $err['end_date'][] = 'Neplatné datum ukončení';
            } elseif (!empty($data['anchor_date']) && (string) $data['end_date'] < (string) $data['anchor_date']) {
                $err['end_date'][] = 'Datum ukončení musí být po zahájení';
            }
        }
        $endOfMonth = !empty($data['end_of_month']);
        $dom = $data['day_of_month'] ?? null;
        if ($endOfMonth && $dom !== null && $dom !== '') {
            $err['day_of_month'][] = 'Nelze kombinovat „poslední den měsíce" a konkrétní den.';
        }
        if (!$endOfMonth && $dom !== null && $dom !== '') {
            $domInt = (int) $dom;
            if ($domInt < 1 || $domInt > 28) {
                $err['day_of_month'][] = 'Den v měsíci musí být 1–28';
            }
        }
        if (empty($data['currency_id']) || (int) $data['currency_id'] <= 0) {
            $err['currency_id'][] = 'Neplatná měna';
        }
        $paymentMethod = (string) ($data['payment_method'] ?? 'bank_transfer');
        if (!in_array($paymentMethod, ['bank_transfer', 'card', 'cash', 'other'], true)) {
            $err['payment_method'][] = 'Neplatný způsob úhrady';
        }
        // auto_send_email vyžaduje auto_issue (nelze poslat draft)
        if (!empty($data['auto_send_email']) && empty($data['auto_issue'])) {
            $err['auto_send_email'][] = 'Automatické odeslání vyžaduje automatické vystavení.';
        }
        // U non-bank-transfer ztrácí auto_send_email smysl (klient nemá co platit) — povolíme,
        // ale ne reminder; reminder cron už non-bank přeskakuje.

        $items = $data['items'] ?? [];
        if (!is_array($items) || count($items) === 0) {
            $err['items'][] = 'Šablona musí mít alespoň jednu položku';
        } else {
            foreach (array_values($items) as $i => $item) {
                if (!is_array($item)) { $err["items.{$i}"][] = 'Neplatná položka'; continue; }
                if (empty($item['description']) || trim((string) $item['description']) === '') {
                    $err["items.{$i}.description"][] = 'Popis je povinný';
                }
                if (!isset($item['vat_rate_id']) || !is_numeric($item['vat_rate_id'])) {
                    $err["items.{$i}.vat_rate_id"][] = 'DPH sazba je povinná';
                }
                $qty = (float) ($item['quantity'] ?? 0);
                if ($qty == 0.0) {
                    $err["items.{$i}.quantity"][] = 'Množství nesmí být 0';
                }
                if (!isset($item['unit_price_without_vat']) || !is_numeric($item['unit_price_without_vat'])) {
                    $err["items.{$i}.unit_price_without_vat"][] = 'Cena je povinná';
                } else {
                    $price = (float) $item['unit_price_without_vat'];
                    if ($qty < 0 && $price < 0) {
                        $msg = 'Záporné množství i záporná cena zároveň nejsou povolené';
                        $err["items.{$i}.quantity"][] = $msg;
                        $err["items.{$i}.unit_price_without_vat"][] = $msg;
                    }
                }
            }
        }

        $amountError = InvoiceAmountPolicy::validatePositiveAmountToPay([
            'invoice_type' => (string) ($data['invoice_type'] ?? 'invoice'),
            'advance_paid_amount' => 0,
            'reverse_charge' => !empty($data['reverse_charge']),
            'items' => $items,
        ], $this->loadVatRateMap());
        if ($amountError !== null) {
            $err['amount_to_pay'][] = $amountError;
        }

        return $err;
    }

    /**
     * @return array<int, float>
     */
    private function loadVatRateMap(): array
    {
        $rows = $this->db->pdo()->query('SELECT id, rate_percent FROM vat_rates')->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['id']] = (float) $row['rate_percent'];
        }
        return $out;
    }

    private static function isValidDate(string $date): bool
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
        return $d !== false && $d->format('Y-m-d') === $date;
    }
}
