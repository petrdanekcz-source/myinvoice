<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin;

use MyInvoice\Bootstrap;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Cron\CronCatalog;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/admin/cron-jobs/{script}/run
 *
 * Spustí daný cron skript z katalogu na pozadí (fire-and-forget).
 * Skript si sám zapíše start/finish do `cron_runs` přes CronRun, takže
 * UI se aktualizuje automaticky při refresh tabulky.
 *
 * Stdout/stderr spawnutého procesu se přesměruje do `log/cron-run-<script>.log`
 * pro diagnostiku (kdyby fail nastal před tím, než CronRun stihne otevřít DB).
 * Admin only.
 */
final class RunCronJobAction
{
    public function __construct(
        private readonly ActivityLogger $logger,
        private readonly Config $config,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (($user['role'] ?? '') !== 'admin') {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }

        $script = (string) ($args['script'] ?? '');
        if (!in_array($script, CronCatalog::scripts(), true)) {
            return Json::error($response, 'not_found', 'Neznámý cron skript.', 404);
        }

        $rootDir = Bootstrap::rootDir();
        $scriptPath = $rootDir . '/api/bin/' . $script . '.php';
        if (!is_file($scriptPath)) {
            return Json::error($response, 'not_found', 'Soubor skriptu neexistuje: ' . $scriptPath, 404);
        }

        $phpBin = $this->resolveCliPhpBinary();
        if ($phpBin === null) {
            return Json::error(
                $response,
                'no_php_cli',
                'PHP CLI binárka nenalezena (PHP_BINARY=' . PHP_BINARY . '). Nastav prosím cestu k php.exe.',
                500
            );
        }

        $logPath = $this->logPath($script);
        $this->appendLog($logPath, sprintf(
            "[%s] spawn: php=%s script=%s sapi=%s\n",
            date('c'),
            $phpBin,
            $scriptPath,
            PHP_SAPI
        ));

        $spawned = $this->spawnBackground($phpBin, $scriptPath, $logPath, $rootDir, $diag);

        $this->logger->log(
            'admin.cron.run_now',
            (int) ($user['id'] ?? 0),
            null,
            null,
            [
                'script'   => $script,
                'php_bin'  => $phpBin,
                'spawned'  => $spawned,
                'log_file' => $logPath,
                'diag'     => $diag,
            ]
        );

        if (!$spawned) {
            return Json::error($response, 'spawn_failed', 'Nepodařilo se spustit skript na pozadí: ' . $diag, 500);
        }

        return Json::ok($response, [
            'script'   => $script,
            'started'  => true,
            'php_bin'  => $phpBin,
            'log_file' => $logPath,
        ], 202);
    }

    /**
     * Pod IIS FastCGI je PHP_BINARY = php-cgi.exe. CLI skripty s `if (PHP_SAPI !== 'cli') exit;`
     * v takovém prostředí tiše končí. Najdeme tedy `php.exe` ve stejné složce.
     */
    private function resolveCliPhpBinary(): ?string
    {
        $candidates = [];
        $b = PHP_BINARY;
        if ($b !== '') {
            $candidates[] = $b;
            $dir = dirname($b);
            if (PHP_OS_FAMILY === 'Windows') {
                $candidates[] = $dir . DIRECTORY_SEPARATOR . 'php.exe';
            } else {
                $candidates[] = $dir . DIRECTORY_SEPARATOR . 'php';
            }
        }
        if (PHP_OS_FAMILY === 'Windows') {
            $candidates[] = 'C:\\inetpub\\php\\php.exe';
            $candidates[] = 'C:\\Program Files\\PHP\\php.exe';
            $candidates[] = 'php.exe';
        } else {
            $candidates[] = '/usr/bin/php';
            $candidates[] = '/usr/local/bin/php';
            $candidates[] = 'php';
        }

        foreach ($candidates as $c) {
            $name = strtolower(basename($c));
            // Vyhneme se php-cgi.exe / php-win.exe / phpdbg.exe — chceme jen CLI.
            if ($name === 'php-cgi.exe' || $name === 'php-cgi' || $name === 'php-win.exe' || str_starts_with($name, 'phpdbg')) {
                continue;
            }
            if (str_contains($c, DIRECTORY_SEPARATOR) || str_contains($c, '/')) {
                if (is_file($c)) {
                    return $c;
                }
            } else {
                // PATH lookup — necháme to OS, vrátíme jak je.
                return $c;
            }
        }

        return null;
    }

    private function logPath(string $script): string
    {
        $dataDir = $this->config->dataDir() ?? Bootstrap::rootDir();
        $logDir = rtrim($dataDir, "\\/") . DIRECTORY_SEPARATOR . 'log';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0o775, true);
        }
        return $logDir . DIRECTORY_SEPARATOR . 'cron-run-' . $script . '.log';
    }

    private function appendLog(string $path, string $text): void
    {
        @file_put_contents($path, $text, FILE_APPEND | LOCK_EX);
    }

    /**
     * Spustí PHP CLI skript na pozadí, fire-and-forget.
     * `$diag` dostane krátký popis (pro activity_log).
     */
    private function spawnBackground(string $phpBin, string $scriptPath, string $logPath, string $cwd, ?string &$diag): bool
    {
        $diag = null;

        if (PHP_OS_FAMILY === 'Windows') {
            // Pomocí cmd.exe + start /B se proces odpojí od FastCGI workeru.
            // popen() na Windows spouští `cmd.exe /c <command>` — start /B v něm
            // odstartuje proces a vrátí se okamžitě. /D = working directory.
            $cmd = sprintf(
                'start /B /D %s "" %s %s >> %s 2>&1',
                escapeshellarg($cwd),
                escapeshellarg($phpBin),
                escapeshellarg($scriptPath),
                escapeshellarg($logPath)
            );
            $proc = @popen($cmd, 'r');
            if ($proc === false) {
                $diag = 'popen returned false';
                return false;
            }
            @pclose($proc);
            $diag = 'popen ok';
            return true;
        }

        // POSIX: nohup … & disown — výstup do diag log file pro pozdější inspekci.
        $cmd = sprintf(
            'cd %s && nohup %s %s >> %s 2>&1 &',
            escapeshellarg($cwd),
            escapeshellarg($phpBin),
            escapeshellarg($scriptPath),
            escapeshellarg($logPath)
        );
        @exec($cmd, $_out, $rc);
        $diag = 'exec rc=' . $rc;
        return $rc === 0;
    }
}
