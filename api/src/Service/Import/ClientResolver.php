<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Service\Ares\AresClient;

/**
 * Resolve klienta z importovaného XML/ISDOC:
 *   1. Lookup v `clients` podle (supplier_id, ic) — pokud existuje, vrátí jeho id.
 *   2. Pokud ne, ARES lookup podle IČ — preferovaná fakturační adresa z ARES.
 *   3. Fallback na adresu z XML, pokud ARES IČ nezná (zahraniční, neexistující).
 *   4. Vytvoří nový clients row, vrátí id.
 *
 * Vstup: array z parseru (`company_name, ic, dic, street, city, zip, country_iso2, email, phone`).
 * Email se použije jako `main_email` (povinné pole).
 */
final class ClientResolver
{
    public function __construct(
        private readonly Connection $db,
        private readonly ClientRepository $clients,
        private readonly AresClient $ares,
    ) {}

    /**
     * @param array<string,?string> $parsedClient
     * @return array{id:int, created:bool}
     */
    public function resolve(array $parsedClient, int $supplierId): array
    {
        $ic = $this->normalizeIc($parsedClient['ic'] ?? null);

        // 1. Lookup podle (supplier_id, ic)
        if ($ic !== null) {
            $stmt = $this->db->pdo()->prepare(
                'SELECT id FROM clients WHERE supplier_id = ? AND ic = ? LIMIT 1'
            );
            $stmt->execute([$supplierId, $ic]);
            $existing = $stmt->fetchColumn();
            if ($existing !== false) {
                return ['id' => (int) $existing, 'created' => false];
            }
        }

        // 2. ARES merge — pokud IČ je české (8 číslic) a ARES odpoví
        $aresData = null;
        if ($ic !== null && strlen($ic) === 8) {
            $resp = $this->ares->lookup($ic);
            if ($resp !== null && ($resp['found'] ?? false) && isset($resp['data'])) {
                $aresData = $resp['data'];
            }
        }

        // 3. Sestavení dat klienta — ARES preferenčně, pak fallback na XML
        $email = trim((string) ($parsedClient['email'] ?? ''));
        if ($email === '') {
            $email = 'unknown@import.local'; // placeholder — main_email je NOT NULL
        }

        $data = [
            'company_name' => $aresData['company_name']
                ?? ($parsedClient['company_name'] ?? 'Importovaný klient'),
            'ic'           => $ic,
            'dic'          => $aresData['dic'] ?? ($parsedClient['dic'] ?? null) ?: null,
            'street'       => $aresData['street'] ?? ($parsedClient['street'] ?? '') ?: '—',
            'city'         => $aresData['city']   ?? ($parsedClient['city']   ?? '') ?: '—',
            'zip'          => $aresData['zip']    ?? ($parsedClient['zip']    ?? '') ?: '00000',
            'country_iso2' => $aresData['country_iso2'] ?? ($parsedClient['country_iso2'] ?? 'CZ') ?: 'CZ',
            'main_email'   => $email,
            'phone'        => $parsedClient['phone'] ?? null,
            'language'     => 'cs',
        ];

        // 4. Create
        $id = $this->clients->create($data, $supplierId);
        return ['id' => $id, 'created' => true];
    }

    private function normalizeIc(?string $ic): ?string
    {
        if ($ic === null) return null;
        $clean = preg_replace('/\D/', '', $ic) ?? '';
        return $clean !== '' ? $clean : null;
    }
}
