# Changelog

All notable changes to MyInvoice.cz are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- **GPC parser: Air Bank výpisy s diakritikou v názvu účtu** ([#1]). Pole
  fixed-width hlavičky (074) se parsovala až po `iconv CP1250→UTF-8`, takže
  vícebajtové znaky (`í`, `ý` v `Hlavní podnikatelský`) posunuly všechny
  offsety za polem názvu o 2 bajty — `statement_date` vyšel jako null a
  insert do `bank_statements` failoval s `Integrity constraint violation`.
  Parser teď extrahuje pole z **raw CP1250 bajtů** (single-byte) a UTF-8
  konverzi aplikuje až na konkrétní textová pole. Přidán defenzivní fallback:
  pokud `statement_date` přesto vyjde null, použije se `old_balance_date`
  místo SQL crashe.

[#1]: https://github.com/radekhulan/myinvoice/issues/1

## [1.3.0] — 2026-05-04

### Added

- **Zrušení spárování bankovní transakce.** Tlačítko „Zrušit spárování" v
  detailu výpisu pro stavy `auto_exact / auto_partial / manual / ignored`.
  Konzervativně: fakturu vrátí z `paid` na `issued` jen pokud `paid_at`
  odpovídá datu této transakce a žádná jiná transakce už není spárována
  (chrání ručně označené úhrady). Endpoint
  `POST /api/bank-transactions/{id}/unmatch`, audit `bank.tx_unmatch`.
- **Rychlý filtr na měsíc** v seznamu faktur (ve zvoleném roce). Aktivní
  jen pokud je vybraný rok a není custom datum-rozsah. Funguje i v CSV
  exportu (`filter[month]=N`).

### Changed

- **Graf „Obrat po měsících" → posledních 12 měsíců (rolling).** Místo
  „letošní vs. minulý rok dle kalendářního roku" teď bar zobrazuje
  posledních 12 měsíců a porovnávací linie stejných 12 měsíců o rok
  dříve. X-osa formát `MM/YYYY`. Tooltip ukazuje pár současného a
  minulého měsíce.
- **YoY procento na dashboardu (`change_pct`) je YTD-vs-YTD.** Předtím
  porovnávalo letošní YTD vs. **celý** minulý rok, takže nedokončený rok
  vypadal výrazně hůř. Teď se porovnává minulý rok jen do stejné
  kalendářní pozice (`<= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)`); v
  tooltipu ukázané oba kontexty (YTD i celý rok).
- **Proformy se nepočítají do obratu nikde.** Dashboard
  (`issued_count_ytd`), detail klienta (`revenue_by_year`,
  `revenue_by_month`), `project_revenue_cache`, `client_revenue_cache`
  i `ProjectStatsAction` (top zakázky, totals) — všechny filtrují na
  `invoice_type IN ('invoice', 'credit_note')`. Proforma není daňový
  doklad, neměla by ovlivňovat metriky obratu. Cache se přepočítá přes
  `php api/bin/recompute-stats.php`.
- **Pagination invoices** zvětšen z 20 na 50 řádků na stránku
  (`pagination.invoices_per_page`).

## [1.2.0] — 2026-05-03

### Added

- **Approval token expiration.** Schvalovací odkaz vyprší za N dní (config
  `approval.token_ttl_days`, default 30). Předtím token nikdy neexpiroval —
  bezpečnostní upgrade. Detail faktury ukazuje `Platnost odkazu do …` a po
  vypršení badge „Vypršel" + nabídku „Odeslat znovu" (regenerace tokenu).
- **Reminder cron pro neschválené výkazy.** Nový skript
  `api/bin/cron-send-approval-reminders.php` (volatelný denně) najde
  faktury s `approval_status='requested'` starší než N dní a pošle stejný
  e-mail jako původní žádost, jen s flagem reminder (jiný subject + úvodní
  upozornění). Konfigurace `approval.reminder_after_days`, `max_reminders`
  (default 5 dní, max 3 upomínky), `cc_supplier_on_reminder` (BCC dodavateli
  pro audit). Audit log entry: `invoice.approval_reminder_sent`.
- **Volitelný komentář při schválení.** Veřejná schvalovací stránka má teď
  textareu „Komentář ke schválení (volitelné)" v review mode + admin
  „Změnit stav → Schválen" také. Komentář sdílí existující sloupec
  `approval_rejection_reason` (žádná DB migrace), v detailu faktury
  zobrazený s vhodným labelem podle stavu (důvod zamítnutí / komentář).
- **Admin „Approval inbox"** (`/admin/approvals`, admin-only). Globální
  tabulka všech schvalování s filtry (Vyžádán / Schválen / Zamítnut / Vše),
  toggle „Jen po 5 dnech bez reakce", počty per stav, sloupce: faktura,
  klient, zakázka, K úhradě, stav (badge včetně „Vypršel"), datum žádosti
  + „před X dny", počet upomínek, komentář/důvod. Položka v admin menu.
- **Migrace 0003** — `invoices.approval_token_expires_at`,
  `approval_reminder_at`, `approval_reminder_count` + index pro cron query.

### Changed

- `RequestApprovalAction` čerpá TTL tokenu z `cfg.approval.token_ttl_days`
  místo natvrdo bez expiry.
- `findByApprovalToken()` filtruje expired tokeny — public stránka pak
  vrátí stejný `token_invalid_or_expired` jako pro neexistující.

## [1.1.0] — 2026-05-03

### Added

- **Work-report approval workflow** (M8). Customers can approve a work
  report via emailed link before the related invoice is issued.
  - Project flag `requires_work_report_approval` (Project edit form,
    detail badge).
  - Public token-based approval page at `/approval/{token}` (CAPTCHA-protected,
    no login required).
  - Standalone work-report PDF (`Vykaz-XYZ.pdf`) generated for the approval
    email — full invoice PDF only after approval.
  - `invoice_approval` email template (cs/en, html+txt) with a prominent
    "Approve work report" CTA.
  - `IssueInvoiceAction` blocks issue when project requires approval **and**
    the invoice has a work report — invoices on the same project without
    a work report still issue normally.
  - On approval (public or admin override), `AutoIssueAndSendService` issues
    the invoice and sends it through the standard `invoice_send` flow.
  - Admin-only "Change status" modal in invoice detail (manual override).
  - Audit-log entries for `approval_requested`, `approval_approved`,
    `approval_rejected`, `approval_reset`.
  - Migration `0002_work_report_approval.sql` (project flag + invoice
    approval columns + unique token index).
  - Manual chapters 1, 7.6 and 9.7 with screenshots; README updated.
- **"Issue invoice" button** on project detail (only for active projects);
  pre-fills client + project in the invoice editor.
- **PHP runtime errors routed to `log/php-errors.log`** instead of the
  system php_errors.log. `display_errors` follows `app.env` (dev=on,
  prod=off).
- **Manual: light fixed sidebar redesign** with high-contrast headers,
  accent group bars and a primary "Back to admin" button.
- **i18n coverage** for invoice detail/editor (force-edit warning + popup,
  bank not set, items table headers, work-report buttons), CS+EN.

### Changed

- **Toast unification** across admin pages (Codebooks, Settings,
  InvoiceDetail, ClientDetail, ProjectDetail) — replaced page-local flash
  divs and native `alert()` with the global `useToast` composable so
  notifications are visible regardless of scroll position.
- **Empty work-report rows** silently skipped on the frontend so totals
  stay consistent with what is persisted; backend still validates
  defensively with row-level human-readable error messages.
- **`pushWrToInvoiceItem`** now reuses the empty placeholder row from
  `blankItem()` instead of appending a duplicate.
- **Confirm dialog before save** when the work report is out of sync with
  the corresponding invoice item (different hours/rate, or report exists
  but no matching item description).
- **Manual chapters 7 and 9** rewritten/extended to cover the approval
  workflow, with two new screenshots (`09_schvalit_vykaz_prace.webp`,
  refreshed `09_vykaz_vicepraci.webp`).

### Fixed

- **PDF cache invalidated after issue** (manual `IssueInvoiceAction` and
  automatic `AutoIssueAndSendService`). Without this the renderer would
  return the stale draft PDF (wrong varsymbol, missing 2nd-page work
  report) when a PDF preview existed before issue.

### Build / DevOps

- **`production.cmd` deploy speed-up** (variant B): `api/vendor` is
  renamed to `api/vendor.dev.bak` before `composer install --no-dev`,
  then restored by an instant rename instead of a second
  `composer install`. Saves ~30–60 s per deploy. Safety guard at script
  start aborts if a stale `vendor.dev.bak` is found.
  *(`production.cmd` is gitignored — change is local-only.)*

## [1.0.0] — 2026-05-02

### Initial public release

First public release on GitHub. Highlights:

- **Invoicing.** 4 document types (invoice, proforma, credit note,
  internal cancellation), draft → issued → paid lifecycle with immutable
  snapshots, work reports as page 2 of the PDF, bulk actions (reissue,
  send, mark paid, reminder).
- **Payments.** QR codes in PDF (SPAYD for CZK, SEPA EPC for EUR), GPC
  bank-statement import (KB / FIO / ČSOB / RB / ČS) with SHA256 dedupe
  and auto-matching by VS + amount.
- **Clients & projects.** ARES + VIES lookup, projects 1:N under a
  client, per-project billing emails, reverse charge per client.
- **Multi-supplier.** One installation can invoice for any number of
  suppliers (companies / IČs); isolated data, per-supplier varsymbol
  series, currencies, ARES details, logo, SMTP `From:` and `Reply-To:`,
  Pohoda codes.
- **Exports.** PDF ZIP per month, ISDOC 6.0.2, Pohoda XML (Stormware
  data package).
- **Email.** Symfony Mailer + Twig templates editable in admin UI
  (cs/en, html+txt), DKIM signing.
- **Security.** TOTP 2FA, IP allowlist (IPv4 + IPv6 + CIDR),
  Cloudflare Turnstile CAPTCHA, brute-force protection (Redis or MariaDB
  MEMORY fallback), CSRF + Origin check, peppered bcrypt passwords,
  RBAC (admin / accountant / readonly), activity log of all mutations.
- **Dashboard.** KPI tiles per active currency, top clients, monthly
  revenue chart, overdue / unpaid invoice list.
- **CZ + EN UI** and invoice templates.
- **Docker** (3-min quick start) + native install.
- **17-chapter user manual** (`/manual`) generated from Markdown.
- **MIT license**, security policy.

[1.2.0]: https://github.com/radekhulan/myinvoice/releases/tag/v1.2.0
[1.1.0]: https://github.com/radekhulan/myinvoice/releases/tag/v1.1.0
[1.0.0]: https://github.com/radekhulan/myinvoice/releases/tag/v1.0.0
