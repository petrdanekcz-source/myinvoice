-- MyInvoice.cz — KH/DPH konzistence: is_reverse_charge flag + oprava řádků oddílu C
--
-- Issue #35 review (Pavel Třešňák, květen 2026) — dvě data-fix oblasti v
-- číselníku vat_classifications, které způsobovaly tiché chyby ve výkazech:
--
-- (A) **is_reverse_charge nebyl nikdy nastaven na 1**
--     Seed v 0037 i pozdější migrace nechávaly is_reverse_charge=0 u VŠECH kódů
--     včetně '5' (tuzemský RC příjemce) a '23' (pořízení zboží z JČS). Samovyměření
--     daně v DphPriznaniBuilder i sběr sekce B.1 v KH proto stály jen na per-faktura
--     flagu purchase_invoices.reverse_charge. Když uživatel ručně přiřadil kód 5/23,
--     ale nezaškrtl RC checkbox → v DPHDP3 se daň nesamovyměřila (ř. 3/10 jen základ,
--     daň 0) a v KH doklad vypadl i z B.1. Nastavením flagu je chování konzistentní
--     nezávisle na per-faktura příznaku.
--
-- (B) **dphdp3_line oddílu C (řádky 20–26) měl nesprávná čísla**
--     Seed mapoval kód 22 "služba do JČS" na ř. 22 (správně ř. 21 — pln_sluzby)
--     a kód 26 "vývoz" na ř. 26 (správně ř. 22 — pln_vyvoz). DphPriznaniBuilder
--     navíc tyto řádky vůbec negeneroval (chyběly v lineMap — řešeno v kódu).
--     Mapping je čten živě při sestavení výkazu, takže oprava platí i zpětně.
--
-- Idempotentní: UPDATE guardované na aktuální hodnotu.

SET NAMES utf8mb4;

-- (A) RC flag pro tuzemský RC příjem (5) a pořízení zboží z JČS (23)
UPDATE vat_classifications
   SET is_reverse_charge = 1
 WHERE code IN ('5', '23')
   AND supplier_id IS NULL
   AND is_reverse_charge = 0;

-- (B) Oprava čísel řádků DPHDP3 oddílu C
UPDATE vat_classifications
   SET dphdp3_line = '21'
 WHERE code = '22'
   AND supplier_id IS NULL
   AND dphdp3_line = '22';

UPDATE vat_classifications
   SET dphdp3_line = '22'
 WHERE code = '26'
   AND supplier_id IS NULL
   AND dphdp3_line = '26';
