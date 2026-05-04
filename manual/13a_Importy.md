# 14. Importy (Pohoda XML, ISDOC)

Pokud máš historické vystavené faktury v jiném systému (Pohoda, jiný fakturační
software podporující ISDOC), můžeš je do MyInvoice **naimportovat** — nemusíš
je opisovat ručně.

> **Importují se jen tvoje vystavené faktury** (ne přijaté, ne nákupní doklady
> jiné firmy). Dodavatel ve vstupním souboru se musí shodovat s aktuálně
> zvoleným dodavatelem v aplikaci.

## 14.1 Obrazovka importů

V hlavním menu **Systém → Importy**.

Formulář:

- **Soubory** — přetáhni nebo klikni pro výběr. Akceptuje:
  - `.xml` (Pohoda dataPack)
  - `.isdoc` (ISDOC 6.0.x)
  - `.zip` s libovolným počtem `.xml` / `.isdoc` uvnitř
- **Importovat** — odešle a vrátí report (kolik vytvořeno / přeskočeno / chyba).

## 14.2 Co se založí

Pro každou fakturu v souboru:

| Entita | Logika |
|---|---|
| **Klient** | Lookup po IČ. Pokud neexistuje, načteme adresu z **ARES** (preferenčně), fallback na adresu z XML. Vznikne nový klient. |
| **Zakázka** | Když má faktura `číslo zakázky` (ISDOC `OrderReference/ID`, Pohoda `numberOrder`), přiřadí se k zakázce s tím číslem (vytvoří se, pokud chybí). Pokud nemá číslo zakázky, ale klient má v importovaném balíku **více různých e-mailů**, vytvoří se per-email zakázka s názvem `{Firma} – {email}`. Jinak `bez zakázky`. |
| **Faktura** | Přepíše se do `invoices` se zachovaným původním varsymbolem. Položky, sazby DPH, kurz, měna se převezmou. Snapshoty (klient/dodavatel/banka) se zafixují z aktuálních dat. |

## 14.3 Stav (paid vs issued) — pravidlo 30 dní

Aby ses nemusel po importu zabývat starými fakturami:

- **Datum splatnosti starší než 30 dní** → faktura se uloží jako **Zaplacená**
  (`paid_at` = DUZP nebo datum vystavení). Předpoklad: starý doklad už dávno
  zaplacený.
- **Datum splatnosti v posledních 30 dnech (nebo v budoucnu)** → faktura se
  uloží jako **Vystavená**. Můžeš platbu spárovat standardním flow přes
  bankovní výpis nebo ručně označit jako zaplacenou.

## 14.4 Co se přeskočí

- **Cizí dodavatel** — celý soubor se přeskočí, pokud IČ dodavatele v souboru
  neodpovídá aktuálnímu dodavateli v aplikaci. (Hláška v reportu.)
- **Duplicita** — pokud faktura s daným varsymbolem u tohoto dodavatele už
  existuje, přeskočí se. V reportu se zobrazí důvod a id existující faktury.

## 14.5 Report

Po importu vidíš tabulku:

| Sloupec | Význam |
|---|---|
| Soubor | Cesta v balíku (název ZIPu / interní cesta) |
| Stav | `vytvořeno` / `přeskočeno` / `chyba` |
| Var. symbol | Z faktury |
| Detail | Link na vytvořenou fakturu, badge `paid`/`issued`, štítky `+ klient` / `+ zakázka` (pokud něco vzniklo). U přeskočených/chybných: důvod. |

## 14.6 Tipy

- **Před importem nahraj klienty z ARES** — ne nutné, ale pokud máš čas, můžeš
  je založit ručně se správnou výchozí měnou a paušálem; import pak jen použije
  existující ID a nebude tahat ARES.
- **Pohoda → MyInvoice** — exportuj v Pohodě data balíček (XML), nahraj sem.
  Pohoda neukládá `číslo zakázky` per fakturu, takže se importují bez zakázky
  (pokud klient nemá více emailů — viz § 14.2).
- **Multi-supplier** — přepni v aplikaci na cílového dodavatele předtím, než
  spustíš import. IČ z XML se ověří proti tomuto kontextu.
- **Co dělat, když import vyhodí chybu** — soubor zkontroluj v textovém
  editoru, jestli má validní XML a očekávaný root element (`<dat:dataPack>`
  pro Pohodu, `<Invoice>` v ISDOC namespace pro ISDOC).
