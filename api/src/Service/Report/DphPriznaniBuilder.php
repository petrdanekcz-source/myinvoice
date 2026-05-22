<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Builder XML pro DPH přiznání (DPHDP3) — EPO portál MFČR.
 *
 * Verze EPO: 03.01 (platná 2025-2026).
 *
 * ⚠️ Vygenerované XML je POUZE POMŮCKA. Před odesláním vždy ověřit s účetní
 *    nebo daňovým poradcem. Aplikace nezaručuje regulatorní správnost.
 *
 * Schema: https://adisspr.mfcr.cz/dpr/adis/idpr_pub/dpr_info/schemas.faces
 */
final class DphPriznaniBuilder
{
    public function __construct(
        private readonly Connection $db,
        private readonly VatClassificationMapper $mapper,
    ) {}

    /**
     * Sestaví XML pro DPH přiznání za daný měsíc/kvartál.
     *
     * @param string $period 'monthly' (default) nebo 'quarterly' (sumuje celý kvartál)
     * @return array{xml: string, summary: array<string, mixed>, warnings: list<string>}
     */
    public function build(int $supplierId, int $year, int $month, ?string $period = null): array
    {
        $supplier = $this->loadSupplier($supplierId);
        // Default period z supplier.vat_period, fallback 'monthly'
        if ($period === null) {
            $period = (string) ($supplier['vat_period'] ?? 'monthly');
        }
        if (!in_array($period, ['monthly', 'quarterly'], true)) {
            $period = 'monthly';
        }
        $warnings = [];
        if (!$supplier['is_vat_payer']) {
            $warnings[] = 'Tenant není evidovaný jako plátce DPH — výkaz nemusí být relevantní.';
        }
        if (empty($supplier['financial_office_code'])) {
            $warnings[] = 'Chybí kód finančního úřadu — XML nemusí projít validací EPO.';
        }
        if (empty($supplier['ic'])) {
            $warnings[] = 'Chybí IČO tenanta.';
        }
        if (empty($supplier['dic'])) {
            $warnings[] = 'Chybí DIČ tenanta.';
        }

        $lines = $this->mapper->aggregateForDphPriznani($supplierId, $year, $month, $period);
        $quarter = $period === 'quarterly' ? (int) ceil($month / 3) : null;

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;

        // Root: <Pisemnost nazevSW="MyInvoice.cz" verzeSW="X.Y.Z">
        $pisemnost = $dom->createElement('Pisemnost');
        $pisemnost->setAttribute('nazevSW', 'MyInvoice.cz');
        $pisemnost->setAttribute('verzeSW', (string) ($this->loadAppVersion() ?? '0'));
        $dom->appendChild($pisemnost);

        // <DPHDP3 verzePis="03.01">
        $dphdp3 = $dom->createElement('DPHDP3');
        $dphdp3->setAttribute('verzePis', '03.01');
        $pisemnost->appendChild($dphdp3);

        // ── VetaD: identifikační údaje (typ podání + perioda) ─────────
        // Per EPO XSD: typ_platce je v VetaD, typ_ds v VetaP.
        $vetaD = $dom->createElement('VetaD');
        $vetaD->setAttribute('k_uladis', 'DPH');
        $vetaD->setAttribute('rok', (string) $year);
        if ($quarter !== null) {
            $vetaD->setAttribute('ctvrt', (string) $quarter);
        } else {
            $vetaD->setAttribute('mesic', (string) $month);
        }
        $vetaD->setAttribute('dapdph_forma', 'B'); // B = řádné (default), O/D/E = opravné/dodatečné
        $vetaD->setAttribute('dokument', 'DP3');   // identifikace typu výkazu
        $vetaD->setAttribute('typ_platce', 'P');   // P = plátce DPH (default; I = identifikovaná, S = skupina, N = neplátce)
        // CZ-NACE klasifikace (hlavní ekonomická činnost, 6-digit) — vyplňuje se
        // z `supplier.cz_nace_code`. Hodnotu očekávanou EPO ověřuje uživatel
        // proti číselníku https://mojedane.gov.cz/pmd/dokumentace/ciselniky/ukazka/okec.
        $okec = EpoSupplierBlockBuilder::normalizeOkec((string) ($supplier['cz_nace_code'] ?? ''));
        if ($okec !== null) {
            $vetaD->setAttribute('c_okec', $okec);
        }
        $vetaD->setAttribute('d_poddp', date('d.m.Y')); // datum podání (dnes)
        // trans: A = vznikla daňová povinnost (vlastní daň > 0), N = nevznikla.
        // Spočteme níže po sestavení Veta6 a setneme přes setAttribute.
        $dphdp3->appendChild($vetaD);

        // ── VetaP: identifikace daňového subjektu ─────────────────────
        $vetaP = $dom->createElement('VetaP');
        EpoSupplierBlockBuilder::fillVetaP($vetaP, $supplier);
        $dphdp3->appendChild($vetaP);

        // ── Veta1: tuzemská plnění (řádky 1, 2, 40, 41) ────────────────
        // Schema má pevná pole: obrat23/dan23 (ř.1, sale 21%), obrat5/dan5 (ř.2, sale 12%),
        // p_zb23/dan_pzb23 (ř.40, purchase 21%), p_zb5/dan_pzb5 (ř.41, purchase 12%).
        $totalDanZdanitelne = 0.0;
        $totalDanOdpocitatelne = 0.0;
        $veta1Attrs = []; // map line# → [obrat_attr, dan_attr]
        $lineMap = [
            '1'  => ['obrat23', 'dan23'],     // sale 21%
            '2'  => ['obrat5',  'dan5'],      // sale 12%
            '40' => ['p_zb23',  'dan_pzb23'], // purchase 21% (s odpočtem)
            '41' => ['p_zb5',   'dan_pzb5'],  // purchase 12% (s odpočtem)
        ];
        foreach ($lines as $lineNum => $data) {
            $lineKey = (string) $lineNum;
            if (isset($lineMap[$lineKey])) {
                [$obratAttr, $danAttr] = $lineMap[$lineKey];
                $veta1Attrs[$obratAttr] = $this->formatAmount($data['base']);
                $veta1Attrs[$danAttr]   = $this->formatAmount($data['vat']);
            }
            if ($this->isOutputLine($lineKey)) {
                $totalDanZdanitelne += $data['vat'];
            } else {
                $totalDanOdpocitatelne += $data['vat'];
            }
        }
        if (!empty($veta1Attrs)) {
            $veta1 = $dom->createElement('Veta1');
            foreach ($veta1Attrs as $k => $v) $veta1->setAttribute($k, $v);
            $dphdp3->appendChild($veta1);
        }

        // ── VetaR: poradi (wrapper element, summary attrs jdou jinam) ────
        $vetaR = $dom->createElement('VetaR');
        $vetaR->setAttribute('poradi', '1');
        $dphdp3->appendChild($vetaR);

        $vlastniDan = $totalDanZdanitelne - $totalDanOdpocitatelne;

        // trans: A = vznikla daňová povinnost (kladná vlastní daň), N = nevznikla
        // (nadměrný odpočet / nulový rozdíl). Setneme až teď, kdy máme spočítáno.
        $vetaD->setAttribute('trans', $vlastniDan > 0 ? 'A' : 'N');

        // Termín podání: 25. den následujícího měsíce po skončení období
        $deadlineMonth = $quarter !== null ? ($quarter * 3 + 1) : ($month + 1);
        $deadlineYear  = $year;
        if ($deadlineMonth > 12) {
            $deadlineMonth -= 12;
            $deadlineYear += 1;
        }
        $deadline = sprintf('%04d-%02d-25', $deadlineYear, $deadlineMonth);

        $summary = [
            'period'                  => sprintf('%04d-%02d', $year, $month),
            'period_type'             => $period,
            'quarter'                 => $quarter,
            'lines'                   => $lines,
            'total_vat_output'        => round($totalDanZdanitelne, 2),
            'total_vat_input'         => round($totalDanOdpocitatelne, 2),
            'tax_due'                 => round($vlastniDan, 2),
            'is_excess_deduction'     => $vlastniDan < 0,
            'submission_deadline'     => $deadline,
            'supplier_vat_period'     => (string) ($supplier['vat_period'] ?? ''),
        ];

        return [
            'xml'      => $dom->saveXML() ?: '',
            'summary'  => $summary,
            'warnings' => $warnings,
        ];
    }

    /**
     * Načti tax-relevantní info o tenantovi.
     * @return array<string,mixed>
     */
    private function loadSupplier(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT s.id, s.company_name, s.street, s.city, s.zip,
                    COALESCE(c.iso2, 'CZ') AS country_iso2,
                    s.ic, s.dic, s.is_vat_payer,
                    s.taxpayer_type, s.vat_period, s.financial_office_code,
                    s.workplace_code, s.cz_nace_code, s.data_box_type, s.data_box_id,
                    s.email, s.phone,
                    s.street_number_pop, s.street_number_orient,
                    s.opr_jmeno, s.opr_prijmeni, s.opr_postaveni,
                    s.sest_jmeno, s.sest_telefon, s.sest_email, s.sest_funkce
               FROM supplier s
          LEFT JOIN countries c ON c.id = s.country_id
              WHERE s.id = ?"
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new \RuntimeException("Supplier #{$supplierId} nenalezen.");
        }
        return $row;
    }

    private function loadAppVersion(): ?string
    {
        $verFile = __DIR__ . '/../../../../VERSION';
        if (is_file($verFile)) {
            return trim((string) file_get_contents($verFile)) ?: null;
        }
        return null;
    }

    // VetaP a normalizeOkec přesunuto do EpoSupplierBlockBuilder (sdíleno s KH/SHV).

    /**
     * Output lines (DPH na výstupu): 1-29 dle DPHDP3.
     * Input lines (DPH na vstupu, odpočet): 40+ dle DPHDP3.
     */
    private function isOutputLine(string $line): bool
    {
        return (int) $line < 40;
    }

    /**
     * Veta typ podle čísla řádku v DPHDP3.
     * Řádky 1-26 (dodání) → Veta1
     * Řádky 30-35 (sjednocené plnění) → Veta2
     * Řádky 40-52 (odpočet) → Veta3
     */
    private function vetaTypeForLine(string $line): string
    {
        $n = (int) $line;
        if ($n >= 40) return '3';
        if ($n >= 30) return '2';
        return '1';
    }

    /**
     * Formátování částky pro EPO XML — celé číslo Kč (zaokrouhleno).
     */
    private function formatAmount(float $amount): string
    {
        return (string) (int) round($amount);
    }
}
