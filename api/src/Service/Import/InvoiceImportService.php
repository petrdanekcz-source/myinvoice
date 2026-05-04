<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\ProjectRepository;
use MyInvoice\Service\Invoice\InvoiceCalculator;
use MyInvoice\Service\Invoice\SnapshotBuilder;
use ZipArchive;

/**
 * Orchestrace importu vystavených faktur z Pohoda XML / ISDOC (single nebo ZIP balík).
 *
 * Pravidla:
 *   - Supplier IČ z XML musí odpovídat aktuálnímu scope. Jinak fail per file.
 *   - Klient: lookup po IČ; pokud chybí, ARES → vytvoř.
 *   - Project:
 *       a) faktura má project_number → najít nebo vytvořit (per-klient unikátní project_number).
 *       b) napříč balíkem má klient >1 odlišných emailů → per-(client, email) projekt s názvem
 *          "{company_name} – {email}", projekt se přiřadí podle emailu faktury.
 *       c) jinak project_id = NULL.
 *   - Duplicity: pokud (supplier_id, varsymbol) existuje → skip s reportem.
 *   - Status: pokud je due_date starší než 30 dní od dnešního data → 'paid'
 *     (paid_at = tax_date|issue_date), jinak 'issued' (paid_at = NULL).
 *   - Snapshoty: vyrobí čerstvé z aktuálního supplier/client/bank.
 */
final class InvoiceImportService
{
    public function __construct(
        private readonly Connection $db,
        private readonly InvoiceRepository $invoices,
        private readonly ProjectRepository $projects,
        private readonly ClientResolver $clientResolver,
        private readonly PohodaXmlParser $pohoda,
        private readonly IsdocParser $isdoc,
        private readonly SnapshotBuilder $snapshots,
        private readonly InvoiceCalculator $calculator,
    ) {}

    /**
     * @param list<array{name:string, content:string}> $files Vstupní soubory (rozbalené ze ZIP / single).
     * @return array{summary:array<string,int>, results:list<array<string,mixed>>}
     */
    public function importBundle(array $files, int $supplierId, int $userId): array
    {
        $supplierIc = $this->loadSupplierIc($supplierId);
        if ($supplierIc === null) {
            throw new \RuntimeException("Supplier #$supplierId nemá vyplněné IČ — import nemůže ověřit shodu.");
        }

        // 1. Rozbalení ZIPů na ploché soubory.
        $flat = [];
        foreach ($files as $f) {
            if ($this->isZip($f['name'], $f['content'])) {
                foreach ($this->unzip($f['content']) as $sub) {
                    $flat[] = ['name' => $f['name'] . '/' . $sub['name'], 'content' => $sub['content']];
                }
            } else {
                $flat[] = $f;
            }
        }

        // 2. Parsování všech souborů → seznam (file → invoices).
        $parsed = [];
        foreach ($flat as $f) {
            $r = $this->parseOne($f['name'], $f['content'], $supplierIc);
            $parsed[] = ['file' => $f['name']] + $r;
        }

        // 3. Cross-batch analýza emailů — pro každého klienta (po IČ) spočti unikátní emaily.
        $emailMap = $this->buildEmailMap($parsed);

        // 4. Vytvoření klientů, projektů a faktur.
        $results = [];
        $created = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($parsed as $entry) {
            if (isset($entry['error'])) {
                $results[] = ['file' => $entry['file'], 'status' => 'failed', 'reason' => $entry['error']];
                $failed++;
                continue;
            }
            foreach ($entry['invoices'] as $inv) {
                $label = $entry['file'] . ' / ' . ($inv['varsymbol'] ?? '?');
                if (isset($inv['__error'])) {
                    $results[] = ['file' => $label, 'status' => 'failed', 'reason' => $inv['__error']];
                    $failed++;
                    continue;
                }
                try {
                    $r = $this->processOne($inv, $supplierId, $userId, $emailMap);
                    $results[] = ['file' => $label, 'status' => $r['status']] + $r;
                    if ($r['status'] === 'created') $created++;
                    elseif ($r['status'] === 'skipped') $skipped++;
                    else $failed++;
                } catch (\Throwable $e) {
                    $results[] = ['file' => $label, 'status' => 'failed', 'reason' => $e->getMessage()];
                    $failed++;
                }
            }
        }

        return [
            'summary' => ['created' => $created, 'skipped' => $skipped, 'failed' => $failed],
            'results' => $results,
        ];
    }

    /**
     * @return array{invoices:list<array<string,mixed>>}|array{error:string}
     */
    private function parseOne(string $name, string $content, string $supplierIc): array
    {
        try {
            $isIsdoc = str_contains(strtolower($name), '.isdoc')
                || str_starts_with(ltrim($content), '<?xml') && str_contains($content, 'isdoc.cz/namespace');
            $parsed = $isIsdoc ? $this->isdoc->parse($content) : $this->pohoda->parse($content);
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }

        $fileSupplierIc = preg_replace('/\D/', '', (string) ($parsed['supplier_ic'] ?? ''));
        if ($fileSupplierIc !== '' && $fileSupplierIc !== preg_replace('/\D/', '', $supplierIc)) {
            return ['error' => "Soubor patří jinému dodavateli (IČ {$fileSupplierIc}), nelze importovat."];
        }

        return ['invoices' => $parsed['invoices']];
    }

    /**
     * @param list<array<string,mixed>> $parsedFiles
     * @return array<string, array<string,bool>>  IČ → set emailů
     */
    private function buildEmailMap(array $parsedFiles): array
    {
        $map = [];
        foreach ($parsedFiles as $entry) {
            foreach ($entry['invoices'] ?? [] as $inv) {
                $ic = preg_replace('/\D/', '', (string) ($inv['client']['ic'] ?? ''));
                $email = trim((string) ($inv['client']['email'] ?? ''));
                if ($ic === '' || $email === '') continue;
                $map[$ic][$email] = true;
            }
        }
        return $map;
    }

    /**
     * @param array<string,mixed> $inv
     * @param array<string, array<string,bool>> $emailMap
     * @return array<string,mixed>
     */
    private function processOne(array $inv, int $supplierId, int $userId, array $emailMap): array
    {
        $varsymbol = (string) $inv['varsymbol'];

        // Duplicate check
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM invoices WHERE supplier_id = ? AND varsymbol = ? LIMIT 1'
        );
        $stmt->execute([$supplierId, $varsymbol]);
        $existing = $stmt->fetchColumn();
        if ($existing !== false) {
            return [
                'status' => 'skipped',
                'reason' => "Faktura s varsymbolem $varsymbol již existuje (#{$existing}).",
                'invoice_id' => (int) $existing,
            ];
        }

        // Client
        $clientResult = $this->clientResolver->resolve($inv['client'] ?? [], $supplierId);
        $clientId = $clientResult['id'];

        // Project
        $projectId = $this->resolveProject($inv, $clientId, $emailMap);

        // Currency
        $currencyId = $this->currencyId($supplierId, (string) ($inv['currency'] ?? 'CZK'));

        // Status: due_date starší než 30 dní → paid, jinak issued.
        // Logika: u importu starých dokladů typicky už byly zaplacené; čerstvé faktury,
        // které jsou stále v lhůtě splatnosti, importujeme jako jen vystavené,
        // aby uživatel mohl spárovat platbu standardním flow.
        $taxDate = $inv['tax_date'] ?? null;
        $dueDate = (string) $inv['due_date'];
        $threshold = (new \DateTimeImmutable('today'))->modify('-30 days');
        $isPaid = $dueDate !== '' && new \DateTimeImmutable($dueDate) < $threshold;
        $status = $isPaid ? 'paid' : 'issued';
        $paidAt = $isPaid ? ($taxDate ?: $inv['issue_date']) : null;

        // Insert invoice
        $pdo = $this->db->pdo();
        $sql = 'INSERT INTO invoices
            (supplier_id, varsymbol, invoice_type, client_id, project_id,
             issue_date, tax_date, due_date, currency_id, exchange_rate, exchange_rate_date,
             reverse_charge, language,
             total_without_vat, total_vat, total_with_vat,
             status, paid_at, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0, ?, ?, ?)';

        $pdo->prepare($sql)->execute([
            $supplierId,
            $varsymbol,
            (string) $inv['invoice_type'],
            $clientId,
            $projectId,
            (string) $inv['issue_date'],
            $taxDate,
            $dueDate,
            $currencyId,
            $inv['exchange_rate'] !== null ? (float) $inv['exchange_rate'] : null,
            $inv['exchange_rate'] !== null ? (string) $inv['issue_date'] : null,
            !empty($inv['reverse_charge']) ? 1 : 0,
            'cs',
            $status,
            $paidAt,
            $userId,
        ]);
        $invoiceId = (int) $pdo->lastInsertId();

        // Items
        $this->insertItems($invoiceId, $inv['items'] ?? []);

        // Recompute totals (z položek)
        $this->calculator->recompute($invoiceId);

        // Snapshoty z aktuálního supplier/client/bank
        $snapshots = $this->snapshots->build($clientId, $currencyId, $supplierId);
        $pdo->prepare(
            'UPDATE invoices SET client_snapshot = ?, supplier_snapshot = ?, bank_snapshot = ? WHERE id = ?'
        )->execute([
            json_encode($snapshots['client'],   JSON_UNESCAPED_UNICODE),
            json_encode($snapshots['supplier'], JSON_UNESCAPED_UNICODE),
            $snapshots['bank'] !== null ? json_encode($snapshots['bank'], JSON_UNESCAPED_UNICODE) : null,
            $invoiceId,
        ]);

        return [
            'status' => 'created',
            'invoice_id' => $invoiceId,
            'client_id' => $clientId,
            'client_created' => $clientResult['created'],
            'project_id' => $projectId,
            'varsymbol' => $varsymbol,
            'imported_status' => $status,
        ];
    }

    /**
     * @param array<string,mixed> $inv
     * @param array<string, array<string,bool>> $emailMap
     */
    private function resolveProject(array $inv, int $clientId, array $emailMap): ?int
    {
        $projectNumber = trim((string) ($inv['project_number'] ?? ''));
        if ($projectNumber !== '') {
            return $this->findOrCreateProjectByNumber($clientId, $projectNumber);
        }

        // Multi-email rule
        $ic = preg_replace('/\D/', '', (string) ($inv['client']['ic'] ?? ''));
        $email = trim((string) ($inv['client']['email'] ?? ''));
        if ($ic !== '' && $email !== '' && count($emailMap[$ic] ?? []) > 1) {
            $companyName = (string) ($inv['client']['company_name'] ?? '');
            return $this->findOrCreateProjectByEmail($clientId, $companyName, $email);
        }

        return null;
    }

    private function findOrCreateProjectByNumber(int $clientId, string $projectNumber): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM projects WHERE client_id = ? AND project_number = ? LIMIT 1'
        );
        $stmt->execute([$clientId, $projectNumber]);
        $id = $stmt->fetchColumn();
        if ($id !== false) return (int) $id;

        return $this->projects->create([
            'client_id'        => $clientId,
            'name'             => $projectNumber,
            'project_number'   => $projectNumber,
            'status'           => 'active',
            'payment_due_days' => 14,
            'hourly_rate'      => 0,
        ]);
    }

    private function findOrCreateProjectByEmail(int $clientId, string $companyName, string $email): int
    {
        $name = trim($companyName . ' – ' . $email);
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM projects WHERE client_id = ? AND name = ? LIMIT 1'
        );
        $stmt->execute([$clientId, $name]);
        $id = $stmt->fetchColumn();
        if ($id !== false) return (int) $id;

        return $this->projects->create([
            'client_id'        => $clientId,
            'name'             => $name,
            'status'           => 'active',
            'payment_due_days' => 14,
            'hourly_rate'      => 0,
        ]);
    }

    /**
     * @param list<array<string,mixed>> $items
     */
    private function insertItems(int $invoiceId, array $items): void
    {
        if (empty($items)) return;
        $vatRates = $this->loadVatRates();

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat,
                 vat_rate_id, vat_rate_snapshot,
                 total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, 0, ?)'
        );

        foreach (array_values($items) as $i => $item) {
            $rate = (float) ($item['vat_rate'] ?? 0);
            $vatRateId = $this->matchVatRateId($vatRates, $rate);
            $stmt->execute([
                $invoiceId,
                (string) ($item['description'] ?? ''),
                (float) ($item['quantity'] ?? 1),
                (string) ($item['unit'] ?? 'ks'),
                (float) ($item['unit_price_without_vat'] ?? 0),
                $vatRateId,
                $rate,
                $i,
            ]);
        }
    }

    /**
     * @return array<int,float> id → rate_percent
     */
    private function loadVatRates(): array
    {
        $rows = $this->db->pdo()->query('SELECT id, rate_percent FROM vat_rates')->fetchAll(\PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) $out[(int) $r['id']] = (float) $r['rate_percent'];
        return $out;
    }

    /**
     * @param array<int,float> $rates
     */
    private function matchVatRateId(array $rates, float $rate): int
    {
        $bestId = 0;
        $bestDiff = INF;
        foreach ($rates as $id => $r) {
            $diff = abs($r - $rate);
            if ($diff < $bestDiff) {
                $bestDiff = $diff;
                $bestId = $id;
            }
        }
        return $bestId;
    }

    private function currencyId(int $supplierId, string $code): int
    {
        $code = strtoupper($code);
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM currencies WHERE supplier_id = ? AND code = ? ORDER BY is_default DESC, id ASC LIMIT 1'
        );
        $stmt->execute([$supplierId, $code]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new \RuntimeException("Měna $code není nakonfigurovaná pro tohoto dodavatele.");
        }
        return (int) $id;
    }

    private function loadSupplierIc(int $supplierId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT ic FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $ic = $stmt->fetchColumn();
        if ($ic === false || $ic === null || $ic === '') return null;
        return (string) $ic;
    }

    private function isZip(string $name, string $content): bool
    {
        if (str_ends_with(strtolower($name), '.zip')) return true;
        // Magic bytes — PK\x03\x04 nebo PK\x05\x06 (empty zip)
        return strncmp($content, "PK\x03\x04", 4) === 0 || strncmp($content, "PK\x05\x06", 4) === 0;
    }

    /**
     * @return list<array{name:string, content:string}>
     */
    private function unzip(string $content): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'imp-zip-');
        file_put_contents($tmp, $content);
        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) {
            @unlink($tmp);
            throw new \RuntimeException('Nelze otevřít ZIP.');
        }
        $out = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!$stat) continue;
            $name = $stat['name'];
            // Skip složky a non-XML/ISDOC
            if (str_ends_with($name, '/')) continue;
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, ['xml', 'isdoc'], true)) continue;
            $data = $zip->getFromIndex($i);
            if ($data !== false) {
                $out[] = ['name' => $name, 'content' => $data];
            }
        }
        $zip->close();
        @unlink($tmp);
        return $out;
    }
}
