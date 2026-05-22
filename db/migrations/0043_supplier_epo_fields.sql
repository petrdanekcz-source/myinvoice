-- MyInvoice.cz — doplnění chybějících polí pro DPH/KH EPO XML
--
-- Z reálného DPHDP3 XML (EPO) je vidět, že VetaP obsahuje:
--   c_orient  — orientační číslo (zkratka z `street`, např. "36")
--   c_pop     — popisné číslo (zkratka z `street`, např. "1104")
--   c_telef   — telefon (už máme v `supplier.phone`)
--   opr_jmeno, opr_prijmeni, opr_postaveni — oprávněná osoba
--     (jednatel u PO, podpisující osoba u FO) — POVINNÉ u právnických osob
--   sest_*    — sestavitel (už máme v `supplier`)
--
-- Doplníme orientační+popisné číslo (kdyby si je uživatel chtěl udržovat
-- separátně od `street`) a osobní údaje oprávněné osoby.

SET NAMES utf8mb4;

ALTER TABLE supplier
    ADD COLUMN IF NOT EXISTS street_number_pop    VARCHAR(20)  NULL
        COMMENT 'Číslo popisné (c_pop) — vyplňuje se v DPH/KH XML samostatně',
    ADD COLUMN IF NOT EXISTS street_number_orient VARCHAR(20)  NULL
        COMMENT 'Číslo orientační (c_orient) — vyplňuje se v DPH/KH XML samostatně',
    ADD COLUMN IF NOT EXISTS opr_jmeno            VARCHAR(60)  NULL
        COMMENT 'Jméno osoby oprávněné k podpisu (typicky jednatel u s.r.o.)',
    ADD COLUMN IF NOT EXISTS opr_prijmeni         VARCHAR(60)  NULL
        COMMENT 'Příjmení osoby oprávněné k podpisu',
    ADD COLUMN IF NOT EXISTS opr_postaveni        VARCHAR(60)  NULL
        COMMENT 'Postavení oprávněné osoby (např. "jednatel", "majitel")';
