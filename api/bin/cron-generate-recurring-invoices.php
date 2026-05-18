<?php

declare(strict_types=1);

/**
 * Cron — vygeneruje faktury z aktivních pravidelných šablon.
 *
 * Použití:
 *   php api/bin/cron-generate-recurring-invoices.php
 *   php api/bin/cron-generate-recurring-invoices.php --dry-run
 *
 * Pro každou šablonu kde:
 *   - status = 'active'
 *   - next_run_date <= CURDATE()
 *   - (end_date IS NULL OR next_run_date <= end_date)
 *   - supplier.auto_generate_recurring = 1
 *
 * Vygeneruje fakturu přes RecurringInvoiceGenerator (klon šablony + items,
 * volitelně rovnou vystaví a odešle podle per-šablona flagů auto_issue
 * a auto_send_email). Posune next_run_date o jeden cyklus; pokud nový
 * datum překročí end_date, šablona dostane status='expired'.
 *
 * Catch-up: pokud cron neběžel několik dní, generuje jen JEDNU fakturu
 * (aktuální cyklus) a posune o 1 krok. Backlog se odbavuje den po dni.
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Repository\RecurringTemplateRepository;
use MyInvoice\Service\Cron\CronRun;
use MyInvoice\Service\Invoice\RecurringInvoiceGenerator;

$dryRun = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') { $dryRun = true; continue; }
    fwrite(STDERR, "Unknown arg: $arg\n");
    exit(1);
}

$app = Bootstrap::buildApp();
$container = $app->getContainer();
if ($container === null) {
    fwrite(STDERR, "Container not available.\n");
    exit(1);
}

/** @var \MyInvoice\Infrastructure\Database\Connection $conn */
$conn = $container->get(\MyInvoice\Infrastructure\Database\Connection::class);
$pdo = $conn->pdo();

$run = CronRun::start($pdo, 'cron-generate-recurring-invoices');

/** @var RecurringTemplateRepository $repo */
$repo = $container->get(RecurringTemplateRepository::class);
/** @var RecurringInvoiceGenerator $generator */
$generator = $container->get(RecurringInvoiceGenerator::class);

$startedAt = microtime(true);

$candidates = $repo->findDue();
$report = [
    'dry_run'    => $dryRun,
    'candidates' => count($candidates),
    'generated'  => 0,
    'issued'     => 0,
    'sent'       => 0,
    'errors'     => 0,
];

echo "[" . date('Y-m-d H:i:s') . "] cron-generate-recurring-invoices"
    . ($dryRun ? ' --dry-run' : '') . " — found " . count($candidates) . " templates\n";

if (empty($candidates)) {
    $ms = (int) ((microtime(true) - $startedAt) * 1000);
    echo "  (nothing to do, {$ms} ms)\n";
    $pdo->prepare("INSERT INTO activity_log (action, payload) VALUES ('cron.generate_recurring', ?)")
        ->execute([json_encode($report, JSON_UNESCAPED_UNICODE)]);
    $run->finish('ok', $report);
    exit(0);
}

if ($dryRun) {
    foreach ($candidates as $t) {
        printf(
            "  [DRY] #%d \"%s\" client=%s freq=%s next=%s auto_issue=%d auto_send=%d\n",
            (int) $t['id'],
            (string) $t['name'],
            (string) ($t['client_company_name'] ?? '?'),
            (string) $t['frequency'],
            (string) $t['next_run_date'],
            $t['auto_issue'] ? 1 : 0,
            $t['auto_send_email'] ? 1 : 0,
        );
    }
    $ms = (int) ((microtime(true) - $startedAt) * 1000);
    echo "  ({$ms} ms — DRY RUN, nic se nevytvořilo)\n";
    $run->finish('ok', $report);
    exit(0);
}

foreach ($candidates as $t) {
    $tplId = (int) $t['id'];
    try {
        $r = $generator->generate($tplId, null, null, '', 'cron-generate-recurring-invoices/1.0');
        $report['generated']++;
        if ($r['issued']) $report['issued']++;
        if (!empty($r['sent_to'])) $report['sent']++;
        printf(
            "  ✓ #%d \"%s\" → invoice #%d %s%s (next: %s%s)\n",
            $tplId,
            (string) $t['name'],
            $r['invoice_id'],
            $r['varsymbol'] !== null ? $r['varsymbol'] : '(draft)',
            !empty($r['sent_to']) ? ' → ' . implode(', ', $r['sent_to']) : '',
            $r['new_next_run_date'] ?? '?',
            $r['template_status'] === 'expired' ? ', EXPIRED' : '',
        );
    } catch (\Throwable $e) {
        $report['errors']++;
        fprintf(STDERR, "  ✗ #%d \"%s\" — %s\n", $tplId, (string) $t['name'], $e->getMessage());
    }
}

$ms = (int) ((microtime(true) - $startedAt) * 1000);
echo "  done ({$ms} ms): generated={$report['generated']}, issued={$report['issued']}, sent={$report['sent']}, errors={$report['errors']}\n";

$pdo->prepare("INSERT INTO activity_log (action, payload) VALUES ('cron.generate_recurring', ?)")
    ->execute([json_encode($report, JSON_UNESCAPED_UNICODE)]);

$run->finish($report['errors'] > 0 ? 'error' : 'ok', $report);
