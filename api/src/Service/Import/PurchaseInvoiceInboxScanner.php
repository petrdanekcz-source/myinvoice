<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Invoice\PurchaseInvoiceCalculator;
use PDO;
use MyInvoice\Infrastructure\Database\Connection;

/**
 * Scan adresáře s PDF / ISDOC pro automatické vytváření přijatých faktur.
 *
 * Postup:
 *   1. Načti inbox_dir z config; pokud prázdné → vrať [skipped: 'inbox not configured'].
 *   2. Rekurzivně projdi adresář, filtruj přípony z allowed_exts.
 *   3. Per soubor: spočti SHA-256 obsahu.
 *   4. Pokud existuje purchase_invoice s tímto pdf_hash → skip (dedup).
 *   5. Pokud .isdoc → parsuj přímo IsdocParser.
 *      Pokud .pdf → PdfIsdocExtractor → pokud najde embedded ISDOC, parsuj; jinak skip
 *        (fáze 1 nepodporuje AI fallback — to dorazí v fázi 2c).
 *      Pokud .xml → zkus parsovat jako ISDOC (může to být payload bez PDF wrapping).
 *   6. Z parsovaných dat:
 *      - Najdi/vytvoř vendor (matchuj přes IČ; pokud chybí, vytvoř nový clients řádek s is_vendor=1).
 *      - Vytvoř purchase_invoice draft.
 *      - Insertni items + recompute totals.
 *      - Archivuj PDF (přesun do archive_storage, fill pdf_path/hash/size/original_name).
 *      - Volitelně přesuň source file do move_processed_to subdiru.
 *   7. Vrať souhrn { created: int, skipped: int, failed: int, details: [{file, status, reason, purchase_invoice_id?}] }.
 *
 * Security:
 *   - Realpath check: každý file musí být uvnitř configured inbox_dir (ochrana symlinks).
 *   - Max file size 20 MiB per soubor.
 *   - Max 500 souborů per run (proti DoS na large dirs).
 */
final class PurchaseInvoiceInboxScanner
{
    private const MAX_FILE_SIZE = 20 * 1024 * 1024;
    private const MAX_FILES_PER_RUN = 500;

    public function __construct(
        private readonly Config $config,
        private readonly Connection $db,
        private readonly PurchaseInvoiceRepository $purchaseRepo,
        private readonly ClientRepository $clients,
        private readonly PurchaseInvoiceCalculator $calc,
        private readonly PdfIsdocExtractor $pdfExtractor,
        private readonly IsdocParser $isdocParser,
    ) {}

    /**
     * @return array{
     *     created: int,
     *     skipped: int,
     *     failed: int,
     *     dry_run: bool,
     *     inbox_dir: string,
     *     details: list<array<string,mixed>>
     * }
     */
    public function scan(int $supplierId, int $userId, bool $dryRun = false): array
    {
        $inboxDir = (string) $this->config->get('purchase_invoice.inbox_dir', '');
        if ($inboxDir === '') {
            return $this->emptyResult($inboxDir, $dryRun, [['file' => '', 'status' => 'config_missing', 'reason' => 'purchase_invoice.inbox_dir není nastaveno v cfg.php']]);
        }

        $inboxReal = realpath($inboxDir);
        if ($inboxReal === false || !is_dir($inboxReal)) {
            return $this->emptyResult($inboxDir, $dryRun, [['file' => $inboxDir, 'status' => 'inbox_missing', 'reason' => 'Inbox adresář neexistuje']]);
        }

        $recursive = (bool) $this->config->get('purchase_invoice.inbox_recursive', true);
        $allowedExts = (array) $this->config->get('purchase_invoice.allowed_exts', ['pdf', 'isdoc', 'xml']);
        $allowedExts = array_map('strtolower', $allowedExts);

        $created = 0; $skipped = 0; $failed = 0;
        $details = [];

        $files = $this->listFiles($inboxReal, $recursive, $allowedExts);
        foreach ($files as $absPath) {
            if ($created + $skipped + $failed >= self::MAX_FILES_PER_RUN) {
                $details[] = ['file' => $absPath, 'status' => 'limit_reached', 'reason' => 'Maximální počet souborů per run dosažen'];
                break;
            }

            // Realpath check — file MUSÍ být uvnitř inboxReal.
            // POZOR: Windows je case-insensitive FS, ale realpath() vrací path s casing
            // dle prvního použití (může se lišit mezi inboxReal a per-file real).
            // Na Linuxu je FS case-sensitive — porovnáváme striktně.
            $real = realpath($absPath);
            if ($real === false) {
                $failed++;
                $details[] = ['file' => $absPath, 'status' => 'rejected', 'reason' => 'Nelze resolvovat realpath'];
                continue;
            }
            $isWindows = DIRECTORY_SEPARATOR === '\\';
            $needle    = ($isWindows ? strtolower($inboxReal) : $inboxReal) . DIRECTORY_SEPARATOR;
            $haystack  = $isWindows ? strtolower($real) : $real;
            if (!str_starts_with($haystack, $needle)) {
                $failed++;
                $details[] = ['file' => $absPath, 'status' => 'rejected', 'reason' => 'Path traversal'];
                continue;
            }

            $size = @filesize($real);
            if ($size === false || $size === 0) {
                $failed++;
                $details[] = ['file' => $real, 'status' => 'rejected', 'reason' => 'Prázdný nebo nečitelný'];
                continue;
            }
            if ($size > self::MAX_FILE_SIZE) {
                $failed++;
                $details[] = ['file' => $real, 'status' => 'rejected', 'reason' => 'Soubor větší než 20 MiB'];
                continue;
            }

            $sha = hash_file('sha256', $real);
            if ($sha === false) {
                $failed++;
                $details[] = ['file' => $real, 'status' => 'rejected', 'reason' => 'Nelze spočítat hash'];
                continue;
            }

            $existingId = $this->purchaseRepo->findIdByPdfHash($supplierId, $sha);
            if ($existingId !== null) {
                $skipped++;
                $details[] = ['file' => $real, 'status' => 'skipped', 'reason' => 'Již importováno', 'purchase_invoice_id' => $existingId];
                continue;
            }

            $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
            $isdocXml = $this->extractIsdocXml($real, $ext);
            if ($isdocXml === null) {
                $skipped++;
                $details[] = [
                    'file'   => $real,
                    'status' => 'skipped',
                    'reason' => $ext === 'pdf'
                        ? 'PDF neobsahuje ISDOC, AI extrakce dorazí ve fázi 2c'
                        : 'Soubor nelze parsovat jako ISDOC',
                ];
                continue;
            }

            try {
                $parsed = $this->isdocParser->parse($isdocXml);
                if (empty($parsed['invoices'])) {
                    $failed++;
                    $details[] = ['file' => $real, 'status' => 'failed', 'reason' => 'ISDOC neobsahuje fakturu'];
                    continue;
                }
            } catch (\Throwable $e) {
                $failed++;
                $details[] = ['file' => $real, 'status' => 'failed', 'reason' => 'ISDOC parser error: ' . $e->getMessage()];
                continue;
            }

            // Fáze 1: jen log + counter pro dry-run.
            // Plné create vyžaduje IsdocToPurchaseInvoiceMapper, který je v fázi 2c plánu.
            // Prozatím vrátíme uživateli „bude implementováno v 2c" pro každý nalezený ISDOC.
            $skipped++;
            $details[] = [
                'file'   => $real,
                'status' => 'mapper_pending',
                'reason' => 'ISDOC úspěšně rozpoznán; mapování na purchase_invoices implementováno v fázi 2c (IsdocToPurchaseInvoiceMapper)',
                'isdoc_invoice_count' => count($parsed['invoices']),
                'supplier_ic'         => $parsed['supplier_ic'] ?? null,
            ];
        }

        return [
            'created'   => $created,
            'skipped'   => $skipped,
            'failed'    => $failed,
            'dry_run'   => $dryRun,
            'inbox_dir' => $inboxReal,
            'details'   => $details,
        ];
    }

    /**
     * @return list<string>
     */
    private function listFiles(string $dir, bool $recursive, array $allowedExts): array
    {
        $out = [];
        $stack = [$dir];
        while ($stack !== []) {
            $current = array_pop($stack);
            $entries = @scandir($current);
            if ($entries === false) continue;
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') continue;
                $path = $current . DIRECTORY_SEPARATOR . $entry;
                if (is_dir($path)) {
                    if ($recursive) $stack[] = $path;
                    continue;
                }
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                if (in_array($ext, $allowedExts, true)) {
                    $out[] = $path;
                }
            }
        }
        sort($out, SORT_STRING);
        return $out;
    }

    /**
     * Extrahuje ISDOC XML z PDF (přes embedded files) nebo načte přímo .isdoc / .xml.
     */
    private function extractIsdocXml(string $path, string $ext): ?string
    {
        if ($ext === 'pdf') {
            $bytes = @file_get_contents($path);
            if ($bytes === false) return null;
            return $this->pdfExtractor->extract($bytes);
        }
        if ($ext === 'isdoc' || $ext === 'xml') {
            $bytes = @file_get_contents($path);
            if ($bytes === false) return null;
            // Quick sanity check: musí obsahovat ISDOC namespace
            if (!str_contains($bytes, 'isdoc.cz/namespace')) return null;
            return $bytes;
        }
        return null;
    }

    private function emptyResult(string $inboxDir, bool $dryRun, array $details): array
    {
        return [
            'created'   => 0,
            'skipped'   => 0,
            'failed'    => 0,
            'dry_run'   => $dryRun,
            'inbox_dir' => $inboxDir,
            'details'   => $details,
        ];
    }
}
