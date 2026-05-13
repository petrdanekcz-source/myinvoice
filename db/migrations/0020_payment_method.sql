-- MyInvoice.cz — způsob úhrady na fakturách
--
-- Motivace: e-shopy a další scénáře, kde se faktura uhradí mimo bankovní převod
-- (platební karta v platební bráně, hotově, …). V takovém případě se v PDF/emailu
-- nesmí generovat QR kód ani zobrazit bankovní spojení / výzva k platbě.
--
-- Renderery (InvoicePdfRenderer, InvoiceEmailVarsBuilder) tuto hodnotu čtou
-- a u != 'bank_transfer' QR + bankovní panel vypustí.

SET NAMES utf8mb4;

ALTER TABLE invoices
  ADD COLUMN IF NOT EXISTS payment_method
    ENUM('bank_transfer','card','cash','other')
    NOT NULL DEFAULT 'bank_transfer'
    AFTER status;
