<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Import\InvoiceImportService;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Import vystavených faktur z Pohoda XML / ISDOC.
 *
 *   POST /api/admin/import
 *   multipart/form-data: files[]
 *
 * Podporuje:
 *   - .xml (Pohoda dataPack)
 *   - .isdoc (ISDOC 6.x)
 *   - .zip s libovolným počtem .xml / .isdoc uvnitř
 *
 * Vrací JSON s reportem (created/skipped/failed per soubor).
 */
final class ImportAction
{
    public function __construct(
        private readonly InvoiceImportService $importer,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!in_array(($user['role'] ?? ''), ['admin', 'accountant'], true)) {
            return Json::error($response, 'forbidden', 'Pouze admin nebo účetní.', 403);
        }

        $supplierId = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        if ($supplierId === 0) {
            return Json::error($response, 'no_supplier', 'Chybí supplier kontext.', 400);
        }

        $uploads = $request->getUploadedFiles();
        $files = $this->collectFiles($uploads);
        if (empty($files)) {
            return Json::error($response, 'no_files', 'Nahrajte alespoň jeden soubor.', 400);
        }

        try {
            $report = $this->importer->importBundle($files, $supplierId, (int) ($user['id'] ?? 0));
        } catch (\Throwable $e) {
            return Json::error($response, 'import_failed', $e->getMessage(), 500);
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('invoices.imported', $user['id'] ?? null, null, null, [
            'files'   => count($files),
            'summary' => $report['summary'],
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, $report);
    }

    /**
     * @param array<string, UploadedFileInterface|array<int,UploadedFileInterface>> $uploads
     * @return list<array{name:string, content:string}>
     */
    private function collectFiles(array $uploads): array
    {
        $out = [];
        $walk = function ($node) use (&$walk, &$out): void {
            if ($node instanceof UploadedFileInterface) {
                if ($node->getError() !== UPLOAD_ERR_OK) return;
                $out[] = [
                    'name'    => $node->getClientFilename() ?? 'upload',
                    'content' => (string) $node->getStream()->getContents(),
                ];
            } elseif (is_array($node)) {
                foreach ($node as $sub) $walk($sub);
            }
        };
        foreach ($uploads as $node) $walk($node);
        return $out;
    }
}
