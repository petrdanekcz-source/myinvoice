<?php

declare(strict_types=1);

/**
 * Backfill chybějících `vat_classification_code` na purchase_invoice_items
 * (a fallback na purchase_invoices) podle vat_rate_snapshot.
 *
 * Use case: faktury importované přes AI / Pohoda XML / ručně vytvořené před
 * existencí auto-klasifikace nemají `vat_classification_code`. VatClassificationMapper
 * tyhle řádky SKIPNE → faktury nedorazí do DPH přiznání ani KH.
 *
 * Mapování (purchase, tuzemsko, s nárokem na odpočet):
 *   21% → '40'
 *   12% → '41'
 *   0%  → ponechat NULL (nelze rozumně guessnout)
 *
 * Pro EU acquire / RC / dovoz si uživatel musí kód změnit ručně v UI — defaultem
 * je tuzemsko (nejčastější případ pro CZ tenanta).
 *
 * Použití:
 *   php api/bin/backfill-vat-classification.php           # dry-run
 *   php api/bin/backfill-vat-classification.php --apply   # zápis
 */

require __DIR__ . '/../vendor/autoload.php';

$dryRun = !in_array('--apply', $argv, true);

$app = \MyInvoice\Bootstrap::buildApp();
$container = $app->getContainer();
$pdo = $container->get(\MyInvoice\Infrastructure\Database\Connection::class)->pdo();

// Najdi všechny řádky bez classification_code, kde nadřazená faktura není cancelled
$stmt = $pdo->query(
    "SELECT pii.id, pii.purchase_invoice_id, pii.vat_rate_snapshot, pi.supplier_id, pi.vendor_invoice_number, pi.status
       FROM purchase_invoice_items pii
       JOIN purchase_invoices pi ON pi.id = pii.purchase_invoice_id
      WHERE pii.vat_classification_code IS NULL
        AND pi.status != 'cancelled'
      ORDER BY pi.supplier_id, pi.id, pii.id"
);
$items = $stmt->fetchAll(\PDO::FETCH_ASSOC);

if (empty($items)) {
    echo "Žádné řádky bez vat_classification_code — nic k doplnění.\n";
    exit(0);
}

$mode = $dryRun ? '[DRY-RUN] ' : '';
echo "{$mode}Nalezeno " . count($items) . " purchase_invoice_items bez vat_classification_code:\n\n";

$counts = ['40' => 0, '41' => 0, 'skipped' => 0];
$updateStmt = $pdo->prepare(
    "UPDATE purchase_invoice_items SET vat_classification_code = ? WHERE id = ?"
);

foreach ($items as $it) {
    $rate = (float) $it['vat_rate_snapshot'];
    $code = null;
    $r = (int) round($rate);
    if ($r >= 21)                 $code = '40';
    elseif ($r >= 5 && $r <= 15)  $code = '41';

    $line = sprintf(
        "  item#%-6d pi#%-6d tenant=%-2d  %-9s  rate=%5.2f%%  vendor#=%s  →  %s",
        $it['id'],
        $it['purchase_invoice_id'],
        $it['supplier_id'],
        $it['status'],
        $rate,
        $it['vendor_invoice_number'] ?: '(none)',
        $code ?? '(skip, rate=0)'
    );

    if ($code === null) {
        $counts['skipped']++;
        echo $line . "\n";
        continue;
    }
    $counts[$code]++;
    if (!$dryRun) {
        $updateStmt->execute([$code, $it['id']]);
    }
    echo $line . "\n";
}

echo "\nSouhrn: kód 40 → {$counts['40']}, kód 41 → {$counts['41']}, skipped (rate=0) → {$counts['skipped']}\n";

if ($dryRun) {
    echo "\nSpusť znovu s --apply pro skutečný zápis.\n";
} else {
    echo "\nHotovo. Po backfill spusť 'Přepočítat' v /crm dashboardu, aby se DPH přiznání aktualizovala.\n";
}
