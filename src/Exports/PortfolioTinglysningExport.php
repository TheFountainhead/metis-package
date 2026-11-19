<?php

namespace TheFountainhead\Metis\Exports;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * Builds the Tinglysning XLSX export with three sheets:
 *  - "Oversigt"        — KPI summary
 *  - "Tier-breakdown"  — one row per company tier
 *  - "Pantebreve"      — flat list of mortgages
 *
 * Single source-of-truth for totals: `computeTotalsFromMortgages()`,
 * which DISTINCTs on `Pantrettighed.RettighedIdentifikator` (UUID) with
 * `mortgage_id` fallback for pre-2009 pantebreve. UI + XLSX (+ Sprint 2 PDF)
 * call this same helper so totals stay consistent.
 */
class PortfolioTinglysningExport
{
    public function __construct(
        public readonly array $company,
        public readonly array $treeMeta,
        public readonly array $tierBreakdown,
        public readonly array $mortgages,
        public readonly array $filters = [],
        // Sat når listen kun er delvist hentet: så mange pantebreve er der i alt.
        public readonly ?int $ialt = null,
    ) {
        // phpoffice/phpspreadsheet is a `suggest` dependency — only required
        // for the XLSX export feature. Guard at construction so callers get
        // an actionable error message instead of a fatal class-not-found
        // when the optional dep is missing.
        if (! class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
            throw new \RuntimeException(
                'XLSX export requires phpoffice/phpspreadsheet. Install via: composer require phpoffice/phpspreadsheet'
            );
        }
    }

    /**
     * DISTINCT-aggregate of mortgages by `tinglysning_right_id` (UUID).
     * Falls back to `mortgage_id_<id>` for pre-2009 pantebreve where the
     * Tinglysningsretten 2.0 UUID is missing.
     *
     * Returns:
     *   - total_principal_amount_oere — summed once per unique UUID
     *   - unique_mortgages            — distinct count
     *   - sampant_pantebreve          — re-encounters of an already-seen UUID
     *
     * @param  array<int, array<string, mixed>>  $mortgages
     * @return array{total_principal_amount_oere: int, unique_mortgages: int, sampant_pantebreve: int}
     */
    public static function computeTotalsFromMortgages(array $mortgages): array
    {
        $seen = [];
        $total = 0;
        $sampantCount = 0;

        // Første række pr. dokument vinder, så rækkefølgen afgør hvilken. Arket Pantebreve følger brugerens sortering,
        // men summen må ikke gøre det: to rækker af samme dokument kan have forskellig hovedstol (ejendommene er
        // hentet på hver sin revision). Derfor altid laveste id først, som før listen kunne sorteres.
        usort($mortgages, fn ($a, $b) => ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0)));

        foreach ($mortgages as $m) {
            // 🚨 dokument_identifikator FØRST — det er feltet API'et faktisk sender.
            //
            // Før læste denne metode kun `tinglysning_right_id`, som
            // MortgageRowResource ikke eksponerer. Feltet var derfor ALTID null i
            // produktion, hver række faldt tilbage til mortgage_id_<id>, og
            // eksporten dedup'ede ingenting. Målt mod prod 31/7 på AKACIETORVET:
            // 242.092.298 kr over 18 rækker, hvor skærmen viste 96.092.298 kr over
            // 13 dokumenter.
            //
            // Testene var grønne hele tiden, fordi deres fixtures satte et felt
            // API'et aldrig leverer.
            //
            // rettighed beholdes som fallback: den er den gamle nøgle, og en
            // konsument der stadig sender den skal ikke pludselig miste sin dedup.
            $uuid = $m['dokument_identifikator'] ?? $m['tinglysning_right_id'] ?? null;
            $key = $uuid ?: ('mortgage_id_' . ($m['id'] ?? spl_object_hash((object) $m)));

            if (isset($seen[$key])) {
                if ($uuid) {
                    // Re-encounter of the same key = sampant
                    $sampantCount++;
                }
                continue;
            }

            $seen[$key] = true;
            $total += (int) ($m['principal_amount'] ?? 0);
        }

        return [
            'total_principal_amount_oere' => $total,
            'unique_mortgages' => count($seen),
            'sampant_pantebreve' => $sampantCount,
        ];
    }

    /**
     * Write the XLSX to the given stream resource (e.g. `STDOUT` for streamDownload).
     *
     * @param  resource  $stream
     */
    public function writeTo($stream): void
    {
        $spreadsheet = $this->buildSpreadsheet();

        $writer = new XlsxWriter($spreadsheet);
        $writer->save($stream);
    }

    protected function buildSpreadsheet(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();

        // Default sheet → Oversigt
        $oversigt = $spreadsheet->getActiveSheet();
        $oversigt->setTitle('Oversigt');
        $this->buildOversigt($oversigt);

        $tier = $spreadsheet->createSheet();
        $tier->setTitle('Tier-breakdown');
        $this->buildTierBreakdown($tier);

        $pantebreve = $spreadsheet->createSheet();
        $pantebreve->setTitle('Pantebreve');
        $this->buildPantebreve($pantebreve);

        $spreadsheet->setActiveSheetIndexByName('Oversigt');

        return $spreadsheet;
    }

    protected function buildOversigt($sheet): void
    {
        $totals = self::computeTotalsFromMortgages($this->mortgages);

        $companyName = $this->company['name'] ?? '';
        $cvr = $this->company['cvr'] ?? '';

        $sheet->setCellValueExplicit('A1', trim("$companyName ($cvr)"), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $sheet->setCellValue('A3', 'Samlet hovedstol (DKK)');
        $sheet->setCellValue('B3', $totals['total_principal_amount_oere'] / 100);
        $sheet->getStyle('B3')->getNumberFormat()->setFormatCode('#,##0');

        $sheet->setCellValue('A4', 'Antal pantebreve (unikke)');
        $sheet->setCellValue('B4', $totals['unique_mortgages']);

        $sheet->setCellValue('A5', 'Antal ejendomme');
        $sheet->setCellValue('B5', (int) ($this->treeMeta['total_properties'] ?? 0));

        $sheet->setCellValue('A6', 'Vægtet LTV');
        $sheet->setCellValue('B6', (float) ($this->treeMeta['weighted_ltv'] ?? 0));
        $sheet->getStyle('B6')->getNumberFormat()->setFormatCode('0.00%');

        $sheet->setCellValue('A7', 'Filtre anvendt');
        $sheet->setCellValueExplicit('B7', (string) $this->summarizeFilters(), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);

        $sheet->setCellValue('A8', 'Sampant-pantebreve i resultatet');
        $sheet->setCellValue('B8', $totals['sampant_pantebreve']);

        $sheet->setCellValue('A9', 'Eksporteret');
        $sheet->setCellValue('B9', now()->toIso8601String());

        if ($this->ialt !== null) {
            $sheet->setCellValue('A10', 'UFULDSTÆNDIG');
            $sheet->setCellValueExplicit('B10', sprintf('Indeholder %d af %d rækker. Resten var ikke hentet, da filen blev dannet. Samlet hovedstol, antal pantebreve og sampant ovenfor gælder kun de hentede rækker; antal ejendomme, vægtet LTV og arket Tier-breakdown gælder hele koncernen.', count($this->mortgages), $this->ialt), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->getStyle('A10:B10')->getFont()->setBold(true)->getColor()->setRGB('B91C1C');
        }

        foreach (['A1', 'A3', 'A4', 'A5', 'A6', 'A7', 'A8', 'A9'] as $cell) {
            if ($cell === 'A1') {
                continue; // already styled
            }
            $sheet->getStyle($cell)->getFont()->setBold(true);
        }

        $sheet->getColumnDimension('A')->setWidth(36);
        $sheet->getColumnDimension('B')->setWidth(40);
    }

    protected function buildTierBreakdown($sheet): void
    {
        $headers = ['Selskab', 'CVR', 'Tier-niveau', 'Antal ejendomme', 'Antal pantebreve', 'Hovedstol DKK', 'Weighted LTV'];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:G1')->getFont()->setBold(true);

        $row = 2;
        foreach ($this->tierBreakdown as $tier) {
            $sheet->setCellValueExplicit('A' . $row, (string) ($tier['name'] ?? ''), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('B' . $row, (string) ($tier['cvr'] ?? ''), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue('C' . $row, (int) ($tier['depth'] ?? 0));
            $sheet->setCellValue('D' . $row, (int) ($tier['property_count'] ?? 0));
            $sheet->setCellValue('E' . $row, (int) ($tier['mortgage_count'] ?? 0));
            $sheet->setCellValue('F' . $row, (int) ($tier['principal_amount'] ?? 0) / 100);
            $sheet->setCellValue('G' . $row, (float) ($tier['weighted_ltv'] ?? 0));

            $sheet->getStyle('F' . $row)->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getStyle('G' . $row)->getNumberFormat()->setFormatCode('0.00%');

            $row++;
        }

        foreach (range('A', 'G') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    protected function buildPantebreve($sheet): void
    {
        $headers = [
            'Adresse', 'BFE', 'Pantebrevs-type', 'Prioritet', 'Debitor', 'Kreditor',
            'Hovedstol DKK', 'Tinglysningsdato', 'LTV %', 'Sampant?', 'Ejer-selskab', 'Tier-niveau',
        ];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:L1')->getFont()->setBold(true);

        $row = 2;
        foreach ($this->mortgages as $m) {
            // 🚨 Tekst fra Tinglysningen skrives som TEKST-celler: et navn der begynder med "=" må ikke blive til
            // en formel i modtagerens regneark.
            $tekst = fn (string $celle, mixed $v) => $sheet->setCellValueExplicit($celle, is_scalar($v) ? (string) $v : '', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $tekst('A' . $row, $m['address'] ?? '');
            $tekst('B' . $row, $m['bfe'] ?? '');
            $tekst('C' . $row, $m['mortgage_type'] ?? '');
            $sheet->setCellValue('D' . $row, is_numeric($m['priority'] ?? null) ? (int) $m['priority'] : '');
            $tekst('E' . $row, $m['debitor'] ?? '');
            $tekst('F' . $row, $m['creditor'] ?? '');
            $sheet->setCellValue('G' . $row, (int) ($m['principal_amount'] ?? 0) / 100);
            $sheet->setCellValueExplicit('H' . $row, (string) ($m['registration_date'] ?? ''), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue('I' . $row, isset($m['ltv']['value']) ? (float) $m['ltv']['value'] : '');
            $sheet->setCellValue('J' . $row, ! empty($m['is_sampant']) ? 'Ja' : 'Nej');
            $tekst('K' . $row, $m['owner_company']['name'] ?? ($m['owner_company']['cvr'] ?? ''));
            $sheet->setCellValue('L' . $row, $m['tier_depth'] ?? '');

            $sheet->getStyle('G' . $row)->getNumberFormat()->setFormatCode('#,##0');
            if (isset($m['ltv']['value'])) {
                $sheet->getStyle('I' . $row)->getNumberFormat()->setFormatCode('0.00%');
            }

            $row++;
        }

        foreach (range('A', 'L') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    protected function summarizeFilters(): string
    {
        if (empty($this->filters)) {
            return '(ingen)';
        }

        $parts = [];
        foreach ($this->filters as $key => $value) {
            if (is_array($value)) {
                $parts[] = $key . '=' . implode('|', $value);
            } elseif ($value !== null && $value !== '') {
                $parts[] = $key . '=' . $value;
            }
        }

        return $parts ? implode(', ', $parts) : '(ingen)';
    }
}
