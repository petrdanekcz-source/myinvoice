# XSD schémata

Commitnutá veřejná schémata pro **automatickou XSD validaci** vygenerovaného XML:

1. **EPO MFČR** výkazy (DPH/KH/SH/DPFO/DPPO) — validace daňových podání.
2. **ISDOC 6.0.2** (`isdoc-invoice-6.0.2.xsd`) — validace exportu faktur; ověřuje
   ji unit test `tests/Unit/Service/Export/IsdocExporterSchemaTest`.

Aktuální verze jsou v repo — clone má funkční validaci bez setup kroku. Re-stáhnout
přes `bash cmd/download-xsd.sh` nebo `cmd\download-xsd.cmd` (při novém ročníku MFČR,
příp. nové verzi ISDOC).

## Zdroje EPO MFČR

📋 **Seznam schémat (popis struktury):**
https://adisspr.mfcr.cz/dpr/adis/idpr_pub/epo2_info/popis_struktury_seznam.faces

📥 **Přímé URL k XSD souborům** (formát: `https://adisspr.mfcr.cz/adis/jepo/schema/{form}_epo2.xsd`):

| Filename | Formulář | URL |
|---|---|---|
| `dphdp3.xsd` | DPH přiznání DPHDP3 | https://adisspr.mfcr.cz/adis/jepo/schema/dphdp3_epo2.xsd |
| `dphkh1.xsd` | Kontrolní hlášení DPHKH1 | https://adisspr.mfcr.cz/adis/jepo/schema/dphkh1_epo2.xsd |
| `dphshv.xsd` | Souhrnné hlášení DPHSHV | https://adisspr.mfcr.cz/adis/jepo/schema/dphshv_epo2.xsd |
| `dpfdp5.xsd` | Daň z příjmů FO DPFDP5 | https://adisspr.mfcr.cz/adis/jepo/schema/dpfdp5_epo2.xsd |
| `dppdp9.xsd` | Daň z příjmů PO DPPDP9 | https://adisspr.mfcr.cz/adis/jepo/schema/dppdp9_epo2.xsd |

> **Pozn.:** soubor zde **musí mít jméno bez `_epo2` suffixu** (např. `dphdp3.xsd`, ne
> `dphdp3_epo2.xsd`). XmlSchemaValidator hledá `storage/xsd/{form_code}.xsd`.

## Zdroj ISDOC

📋 **Aktuální verze standardu (odkazy MV ČR):**
https://mv.gov.cz/isdoc/clanek/aktualni-verze.aspx

📥 **Přímé URL k XSD:**

| Filename | Standard | URL |
|---|---|---|
| `isdoc-invoice-6.0.2.xsd` | ISDOC 6.0.2 (faktura) | https://isdoc.cz/6.0.2/xsd/isdoc-invoice-6.0.2.xsd |

> **Pozor — XSD vs. business rules:** schéma ISDOC 6.0.2 neobsahuje žádný `<xs:assert>`
> a `*Curr` elementy (cizoměnové částky) jsou `minOccurs="0"`. XSD validace tedy ověří
> jen strukturu, pořadí a typy — **ne** pravidla jako „doklad v cizí měně musí nést
> `LineExtensionAmountCurr`". `<UnitPrice>` je dle standardu vždy v `LocalCurrencyCode`
> (CZK); `*Curr` sourozenci nesou hodnoty v `ForeignCurrencyCode`.

## Bez schémat

Pokud zde nejsou XSD soubory, validation je **skip** — XML se generuje a archivuje
normálně, jen v `tax_submissions` table bude `validation_status = 'skipped'`.

## S nahranými schématy

`XmlSchemaValidator::validate()` automaticky najde schema podle `form_code` a:
- **passed** — XML je validní
- **failed** — XML porušuje schema (chyby v `validation_errors` JSON)

Validation errors se zobrazí v UI `/reports/submissions` u daného záznamu.

## Proč nejsou v repo?

- Licence MFČR (schémata jsou public, ale ne tříděno do public repo)
- Velikost (každé schema má 50-500 KB s dependencies)
- Verze se mění (typically per rok) — manual update by admin

## Update workflow

Když MFČR vydá novou verzi (typicky leden):
1. Stáhni nové XSD přes `bash cmd/download-xsd.sh` (Linux/macOS) nebo `cmd\download-xsd.cmd` (Windows)
2. Soubory se přepíšou v `api/xsd/`
3. Commit → push (každý ročník je samostatný commit, ať je v historii viditelný)
4. Spusť re-validation existing archived submissions přes UI `/reports/submissions`
