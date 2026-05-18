-- MyInvoice.cz — Cron run heartbeat
--
-- Každý běh api/bin/cron-*.php zapíše start a po dokončení update finished_at,
-- status, exit_code a stručný JSON report. UI (Systém → Plánované úlohy)
-- pak zobrazí poslední běh, stav a kdy byl, a porovná s doporučenou frekvencí.
--
-- Záměr: detekovat "cron není nastavený" / "cron běží, ale failuje" nezávisle
-- na OS (Linux crontab vs. Windows Task Scheduler vs. Docker). activity_log
-- záznamy zůstávají beze změny (audit trail), tato tabulka je provozní
-- diagnostika s rychlým indexem na (script, started_at).
--
-- Cleanup: cron-cleanup drží jen N posledních záznamů na skript (viz § 7
-- v cron-cleanup.php).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS cron_runs (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  script          VARCHAR(80) NOT NULL,
  started_at      DATETIME NOT NULL,
  finished_at     DATETIME NULL,
  status          ENUM('running','ok','error') NOT NULL DEFAULT 'running',
  duration_ms     INT UNSIGNED NULL,
  exit_code       TINYINT NULL,
  host            VARCHAR(100) NULL,
  message         TEXT NULL,
  report          JSON NULL,
  KEY idx_cron_runs_script_started (script, started_at),
  KEY idx_cron_runs_started        (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
