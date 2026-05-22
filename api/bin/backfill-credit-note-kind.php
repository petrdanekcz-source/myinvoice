<?php

declare(strict_types=1);

/**
 * Backfill: přijaté faktury s `document_kind='invoice'` ALE všechny items mají
 * záporné částky → překlasifikovat na 'credit_note' (dobropis).
 *
 * Use case: AI extractor občas vrátí `document_kind='invoice'` i pro PDF dobropisy
 * s explicitně zápornými částkami. Fix v AiPdfExtractor (override-by-amounts) řeší
 * nové importy; tento skript napraví už zaimportovaná data.
 *
 * Heuristika: invoice (purchase_invoices.document_kind='invoice') je dobropisem
 * pokud má alespoň 1 item se zápornou quantity NEBO unit_price_without_vat,
 * a žádný item s kladnou částkou.
 *
 * Použití:
 *   php api/bin/backfill-credit-note-kind.php           # dry-run
 *   php api/bin/backfill-credit-note-kind.php --apply   # zápis
 */

require __DIR__ . '/../vendor/autoload.php';

$dryRun = !in_array('--apply', $argv, true);

$app = \MyInvoice\Bootstrap::buildApp();
$container = $app->getContainer();
$pdo = $container->get(\MyInvoice\Infrastructure\Database\Connection::class)->pdo();

// Najdi kandidáty: invoice document_kind, total < 0 NEBO mají záporné items.
// total_with_vat < 0 je spolehlivý indikátor (calculator už sečetl items se signem).
$stmt = $pdo->query(
    "SELECT pi.id, pi.supplier_id, pi.vendor_invoice_number, pi.issue_date,
            pi.total_without_vat, pi.total_with_vat, pi.status,
            c.company_name AS vendor_name
       FROM purchase_invoices pi
       JOIN clients c ON c.id = pi.vendor_id
      WHERE pi.document_kind = 'invoice'
        AND pi.total_with_vat < 0
        AND pi.status != 'cancelled'
      ORDER BY pi.supplier_id, pi.issue_date, pi.id"
);
$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

if (empty($rows)) {
    echo "Žádné přijaté faktury s document_kind='invoice' a zápornou částkou — nic k opravě.\n";
    exit(0);
}

$mode = $dryRun ? '[DRY-RUN] ' : '';
echo "{$mode}Nalezeno " . count($rows) . " kandidátů na překlasifikování invoice → credit_note:\n\n";

$ok = 0;
foreach ($rows as $r) {
    $line = sprintf(
        "  #%-6d tenant=%-2d  %-12s  %s  %s  total=%.2f  status=%s",
        $r['id'], $r['supplier_id'], $r['vendor_invoice_number'], $r['issue_date'],
        substr($r['vendor_name'], 0, 20),
        (float) $r['total_with_vat'], $r['status']
    );

    if (!$dryRun) {
        try {
            $pdo->prepare("UPDATE purchase_invoices SET document_kind = 'credit_note' WHERE id = ?")
                ->execute([(int) $r['id']]);
            $ok++;
        } catch (\Throwable $e) {
            echo $line . "  ✗ DB: " . $e->getMessage() . "\n";
            continue;
        }
    } else {
        $ok++;
    }
    echo $line . "  → credit_note\n";
}

if ($dryRun) {
    echo "\nKandidátů k překlasifikování: {$ok}\n";
    echo "Spusť znovu s --apply pro skutečný zápis.\n";
} else {
    echo "\nHotovo. Překlasifikováno: {$ok}\n";
}
