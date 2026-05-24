-- MyInvoice.cz — Fakturoid OAuth2 Client Credentials (issue #31)
--
-- Fakturoid v roce 2024 deprecoval personal API tokens (BasicAuth email + api_key)
-- pro nové účty. Nový účet má v Nastavení → API v3 jen "Client ID + Client Secret"
-- (OAuth2 client_credentials grant).
--
-- Tato migrace přidá per-supplier OAuth2 pole vedle stávajících BasicAuth polí
-- (migrace 0031). Oba mechanismy koexistují — pokud je vyplněn `fakturoid_client_id`,
-- klient použije OAuth2 Bearer; jinak fallback na původní BasicAuth.
--
-- OAuth2 flow:
--   POST https://app.fakturoid.cz/api/v3/oauth/token
--   Authorization: Basic base64(client_id:client_secret)
--   Content-Type:  application/x-www-form-urlencoded
--   Body:          grant_type=client_credentials
--
-- Token cache: fakturoid_access_token_enc (šifrovaný) + expires_at, TTL ~2h.
--
-- Idempotence: ADD COLUMN IF NOT EXISTS (MariaDB 10.3+).

SET NAMES utf8mb4;

ALTER TABLE supplier
    ADD COLUMN IF NOT EXISTS fakturoid_client_id VARCHAR(190) NULL
        COMMENT 'Fakturoid OAuth2 client_id (plain — public identifier)',
    ADD COLUMN IF NOT EXISTS fakturoid_client_secret_enc TEXT NULL
        COMMENT 'Fakturoid OAuth2 client_secret šifrovaný AES-256-GCM',
    ADD COLUMN IF NOT EXISTS fakturoid_access_token_enc TEXT NULL
        COMMENT 'Cache OAuth2 bearer tokenu (TTL ~2h) šifrovaný AES-256-GCM',
    ADD COLUMN IF NOT EXISTS fakturoid_access_token_expires_at TIMESTAMP NULL
        COMMENT 'Expirace cached OAuth2 access_token';
