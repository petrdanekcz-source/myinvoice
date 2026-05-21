<?php

declare(strict_types=1);

/**
 * Backfill chybějících exchange_rate na non-CZK purchase_invoices.
 *
 * Use case: faktury importované přes ISDOC / iDoklad / Fakturoid / ručně bez
 * explicitního exchange_rate v EUR/USD/jiné měně mají `exchange_rate IS NULL`.
 * V CRM costs sumaci se pak EUR řádky počítají jako multiplier=1 (jako CZK),
 * což je špatně.
 *
 * Skript dotahuje ČNB kurz k DUZP (tax_date, fallback issue_date) a UPDATE
 * purchase_invoices.exchange_rate + exchange_rate_date + exchange_rate_source='cnb'.
 *
 * Použití:
 *   php api/bin/backfill-exchange-rates.php           # dry-run
 *   php api/bin/backfill-exchange-rates.php --apply   # zápis
 */

require __DIR__ . '/../vendor/autoload.php';

$dryRun = !in_array('--apply', $argv, true);

$app = \MyInvoice\Bootstrap::buildApp();
$container = $app->getContainer();
$pdo = $container->get(\MyInvoice\Infrastructure\Database\Connection::class)->pdo();
$cnb = $container->get(\MyInvoice\Service\Currency\CnbExchangeRateClient::class);
$repo = $container->get(\MyInvoice\Repository\PurchaseInvoiceRepository::class);

$stmt = $pdo->query(
    "SELECT pi.id, pi.supplier_id, pi.vendor_invoice_number, pi.status,
            pi.issue_date, pi.tax_date, pi.exchange_rate, cur.code AS currency
       FROM purchase_invoices pi
       JOIN currencies cur ON cur.id = pi.currency_id
      WHERE pi.exchange_rate IS NULL
        AND cur.code != 'CZK'
        AND pi.status != 'cancelled'
      ORDER BY pi.supplier_id, pi.issue_date, pi.id"
);
$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

if (empty($rows)) {
    echo "Žádné non-CZK přijaté faktury bez kurzu — nic k doplnění.\n";
    exit(0);
}

$mode = $dryRun ? '[DRY-RUN] ' : '';
echo "{$mode}Nalezeno " . count($rows) . " non-CZK přijatých faktur bez exchange_rate:\n\n";

$ok = 0;
$failed = 0;
foreach ($rows as $r) {
    $dateStr = $r['tax_date'] ?: $r['issue_date'];
    try {
        $date = new \DateTimeImmutable($dateStr);
        $result = $cnb->getRate($r['currency'], $date);
    } catch (\Throwable $e) {
        echo sprintf("  #%-6d tenant=%-2d  %s  %s  ✗ ČNB: %s\n",
            $r['id'], $r['supplier_id'], $dateStr, $r['currency'], $e->getMessage());
        $failed++;
        continue;
    }
    if ($result === null || !isset($result['rate'])) {
        echo sprintf("  #%-6d tenant=%-2d  %s  %s  ✗ ČNB vrátil null\n",
            $r['id'], $r['supplier_id'], $dateStr, $r['currency']);
        $failed++;
        continue;
    }
    $rate = (float) $result['rate'];
    $rateDate = (string) ($result['rate_date'] ?? $dateStr);

    $line = sprintf("  #%-6d tenant=%-2d  %s  %s  →  %.4f (k %s)",
        $r['id'], $r['supplier_id'], $dateStr, $r['currency'], $rate, $rateDate);

    if (!$dryRun) {
        try {
            $repo->setExchangeRate((int) $r['id'], $rate, $rateDate, 'cnb', (int) $r['supplier_id']);
            $ok++;
        } catch (\Throwable $e) {
            echo $line . "  ✗ DB: " . $e->getMessage() . "\n";
            $failed++;
            continue;
        }
    } else {
        $ok++;
    }
    echo $line . "\n";
}

if ($dryRun) {
    echo "\nOK k zápisu: {$ok}, selhání ČNB: {$failed}\n";
    echo "Spusť znovu s --apply pro skutečný zápis.\n";
} else {
    echo "\nHotovo. Doplněno: {$ok}, selhalo: {$failed}\n";
    if ($failed > 0) exit(1);
}
