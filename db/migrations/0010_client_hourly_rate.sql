-- MyInvoice.cz — Default hodinová sazba na klientovi.
--
-- Použije se v editoru faktury jako fallback pro položky, pokud klient
-- nemá vybranou žádnou zakázku (zakázka má svou vlastní sazbu, která má
-- přednost). 0 = nenastaveno (žádný fallback, item zůstane s 0).

SET NAMES utf8mb4;

ALTER TABLE clients
  ADD COLUMN hourly_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00
    AFTER payment_due_default;
