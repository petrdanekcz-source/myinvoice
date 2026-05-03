# 1. Úvod — co MyInvoice.cz umí

MyInvoice.cz je **český fakturační systém pro freelancery, OSVČ a malé firmy**.
Běží na vlastním serveru (nebo v Dockeru) — žádné měsíční poplatky, žádný
externí cloud, tvoje data jsou jen u tebe.

![Přehled (dashboard)](img/01_dashboard.webp)

## 1.1 Hlavní funkce

- **Vystavování faktur** — faktura, zálohová (proforma), opravný daňový doklad
  (dobropis), interní storno
- **Klonování faktury** s automatickým inkrementem měsíce v popisech (`3/2026 → 4/2026`)
- **Hromadné akce** — vystavit znovu (N), odeslat klientovi (N), upomínka (N),
  označit jako zaplacené (N)
- **Výkaz víceprací** — druhá strana PDF s rozpisem hodin, suma se přenese do
  položky faktury
- **Schvalování výkazu zákazníkem** — volitelné per zakázka. Před vystavením
  faktury pošleš zákazníkovi e-mail s odkazem na veřejnou stránku (chráněno
  jednorázovým tokenem + CAPTCHA). Po schválení se faktura **automaticky vystaví
  a odešle**. ![Schvalovací stránka](img/09_schvalit_vykaz_prace.webp)
- **PDF s QR platbou** — SPAYD pro CZK, SEPA EPC pro EUR
- **E-mail s PDF přílohou** přes vlastní SMTP, s DKIM podpisem
- **Klienti** s lookupem v ARES (IČ → adresa, název, DIČ) a VIES (DIČ pro EU)
- **Zakázky** 1:N pod klientem, fakturační e-maily na úrovni zakázky
- **Banka** — import GPC výpisů (ABO formát: KB / FIO / ČSOB / Raiffeisen / ČS),
  automatické párování na faktury
- **Upomínky** — manuální tlačítko, hromadná akce, nebo cron
- **Exporty pro účetní** — PDF ZIP po měsících, ISDOC 6.0.2, Pohoda XML
  (Stormware data package)
- **Multi-supplier** — z jedné instalace fakturuj za libovolný počet
  dodavatelů (firem / IČ), data jsou izolovaná
- **2FA** (TOTP), **IP allowlist** (IPv4 + IPv6 + CIDR), **brute-force ochrana**

## 1.2 Pro koho

- **Freelancer** — pár faktur měsíčně, jednoduchá tvorba podle šablony.
- **Malá firma / OSVČ** — desítky faktur měsíčně, hromadné akce, automatické
  párování plateb z banky.
- **Účetní kancelář** — multi-supplier (až ~50 firem), exporty do Pohody, ISDOC.

## 1.3 Co manuál neobsahuje

- Vývojářskou dokumentaci API → viz `source/04-api.md` v repu projektu
- Detaily databázového schématu → viz `source/02-database.md`
- Specifikace jednotlivých formátů (ISDOC, Pohoda XML) → odkaz v
  [13. Exporty](13_Exporty.md)
