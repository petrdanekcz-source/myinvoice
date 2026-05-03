-- MyInvoice.cz — Volitelná informace o zápisu společnosti v obchodním rejstříku.
--
-- Zobrazuje se vycentrovaná těsně nad patičkou každé faktury (PDF). Drží se
-- ve `supplier_snapshot` aby historické faktury zachovaly text platný v čase
-- vystavení (analogie tagline / address).

SET NAMES utf8mb4;

ALTER TABLE supplier
  ADD COLUMN commercial_register VARCHAR(255) NULL DEFAULT NULL
    AFTER tagline;
