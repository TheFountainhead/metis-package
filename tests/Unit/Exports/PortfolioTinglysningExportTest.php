<?php

use PhpOffice\PhpSpreadsheet\IOFactory;
use TheFountainhead\Metis\Exports\PortfolioTinglysningExport;

/**
 * Build an export instance with sane defaults; tests override what they need.
 */
function makeExport(array $overrides = []): PortfolioTinglysningExport
{
    $defaults = [
        'company' => ['cvr' => '28963610', 'name' => 'Mimo Invest ApS'],
        'treeMeta' => [
            'total_descendant_companies' => 7,
            'total_properties' => 18,
            'total_mortgages' => 24,
            'total_principal_amount' => 24_730_000_000, // ører
            'weighted_ltv' => 0.77,
        ],
        'tierBreakdown' => [
            [
                'cvr' => '28963610',
                'name' => 'Mimo Invest ApS (root)',
                'depth' => 0,
                'property_count' => 4,
                'mortgage_count' => 4,
                'principal_amount' => 4_700_000_000,
                'weighted_ltv' => 0.74,
            ],
        ],
        'mortgages' => [
            [
                'id' => 9999,
                'tinglysning_right_id' => 'uuid-aaa',
                'address' => 'Roligedsvej 12-14, 2400 København NV',
                'bfe' => '1234567',
                'mortgage_type' => 'ejerpantebrev',
                'priority' => 6,
                'creditor' => 'Mimo Hotel ApS',
                'debitor' => 'Mimo Hotel ApS',
                'principal_amount' => 820_000_000, // 8.2M DKK in ører
                'registration_date' => '2024-08-15',
                'is_sampant' => false,
                'owner_company' => ['cvr' => '28963610', 'name' => 'Mimo Invest ApS'],
                'tier_depth' => 0,
                'ltv' => ['value' => 0.76, 'method' => 'skoede_price'],
            ],
        ],
        'filters' => ['status' => 'active', 'sort' => 'principal_amount_desc'],
    ];

    $payload = array_replace($defaults, $overrides);

    return new PortfolioTinglysningExport(
        company: $payload['company'],
        treeMeta: $payload['treeMeta'],
        tierBreakdown: $payload['tierBreakdown'],
        mortgages: $payload['mortgages'],
        filters: $payload['filters'],
    );
}

/**
 * Helper: write the export to a temp file and return a loaded Spreadsheet.
 */
function loadExportSpreadsheet(PortfolioTinglysningExport $export)
{
    $tmp = tempnam(sys_get_temp_dir(), 'pte-test-') . '.xlsx';
    $stream = fopen($tmp, 'wb');
    $export->writeTo($stream);
    fclose($stream);

    $spreadsheet = IOFactory::load($tmp);
    @unlink($tmp);

    return $spreadsheet;
}

it('computeTotalsFromMortgages DISTINCTs on tinglysning_right_id (sampant)', function () {
    // Fixture per spec lines 605-610: 3 mortgage rows, same UUID across 3 properties
    $mortgages = [
        ['id' => 1, 'tinglysning_right_id' => 'uuid-X', 'principal_amount' => 100_000_00, 'is_sampant' => true],
        ['id' => 2, 'tinglysning_right_id' => 'uuid-X', 'principal_amount' => 100_000_00, 'is_sampant' => true],
        ['id' => 3, 'tinglysning_right_id' => 'uuid-X', 'principal_amount' => 100_000_00, 'is_sampant' => true],
    ];

    $totals = PortfolioTinglysningExport::computeTotalsFromMortgages($mortgages);

    expect($totals['unique_mortgages'])->toBe(1)
        ->and($totals['sampant_pantebreve'])->toBe(2) // 2 re-encounters of same UUID
        ->and($totals['total_principal_amount_oere'])->toBe(100_000_00); // counted once
});

it('computeTotalsFromMortgages falls back to mortgage_id for pre-2009 (no UUID)', function () {
    $mortgages = [
        ['id' => 100, 'tinglysning_right_id' => null, 'principal_amount' => 50_000_00, 'is_sampant' => false],
        ['id' => 200, 'tinglysning_right_id' => null, 'principal_amount' => 25_000_00, 'is_sampant' => false],
    ];

    $totals = PortfolioTinglysningExport::computeTotalsFromMortgages($mortgages);

    expect($totals['unique_mortgages'])->toBe(2)
        ->and($totals['sampant_pantebreve'])->toBe(0)
        ->and($totals['total_principal_amount_oere'])->toBe(75_000_00);
});

it('computeTotalsFromMortgages mixes UUID + fallback rows correctly', function () {
    $mortgages = [
        ['id' => 1, 'tinglysning_right_id' => 'uuid-A', 'principal_amount' => 100_000_00],
        ['id' => 1, 'tinglysning_right_id' => 'uuid-A', 'principal_amount' => 100_000_00], // sampant on A
        ['id' => 2, 'tinglysning_right_id' => null, 'principal_amount' => 50_000_00], // pre-2009
        ['id' => 3, 'tinglysning_right_id' => 'uuid-B', 'principal_amount' => 25_000_00],
    ];

    $totals = PortfolioTinglysningExport::computeTotalsFromMortgages($mortgages);

    expect($totals['unique_mortgages'])->toBe(3) // A, B, fallback#2
        ->and($totals['sampant_pantebreve'])->toBe(1)
        ->and($totals['total_principal_amount_oere'])->toBe(175_000_00);
});

it('writeTo produces valid XLSX with all three sheets', function () {
    $spreadsheet = loadExportSpreadsheet(makeExport());

    $sheetNames = $spreadsheet->getSheetNames();

    expect($sheetNames)->toContain('Oversigt')
        ->and($sheetNames)->toContain('Tier-breakdown')
        ->and($sheetNames)->toContain('Pantebreve');
});

it('Oversigt sheet contains company name + computed totals matching mortgages', function () {
    $export = makeExport();
    $spreadsheet = loadExportSpreadsheet($export);

    $oversigt = $spreadsheet->getSheetByName('Oversigt');

    // A1 row: company name + CVR
    expect($oversigt->getCell('A1')->getValue())->toContain('Mimo Invest ApS')
        ->and($oversigt->getCell('A1')->getValue())->toContain('28963610');

    // Totals from computeTotalsFromMortgages on the actual mortgages list
    // Single mortgage (UUID 'uuid-aaa'), 820_000_000 ører = 8.200.000 DKK
    $totals = PortfolioTinglysningExport::computeTotalsFromMortgages($export->mortgages);
    $expectedDkk = $totals['total_principal_amount_oere'] / 100;

    // Find the "Samlet hovedstol" cell value
    $hovedstolValue = $oversigt->getCell('B3')->getValue();
    expect((float) $hovedstolValue)->toBe((float) $expectedDkk);

    expect((int) $oversigt->getCell('B4')->getValue())->toBe($totals['unique_mortgages']);
});

it('XLSX summary totals match API tree_meta when no sampant present', function () {
    // When mortgages have unique UUIDs and tree_meta is consistent,
    // computeTotalsFromMortgages should match tree_meta.total_principal_amount.
    $mortgages = [
        ['id' => 1, 'tinglysning_right_id' => 'u1', 'principal_amount' => 1_000_000_00],
        ['id' => 2, 'tinglysning_right_id' => 'u2', 'principal_amount' => 2_000_000_00],
        ['id' => 3, 'tinglysning_right_id' => 'u3', 'principal_amount' => 500_000_00],
    ];

    $treeMeta = [
        'total_descendant_companies' => 1,
        'total_properties' => 3,
        'total_mortgages' => 3,
        'total_principal_amount' => 3_500_000_00, // 3.5M DKK in ører
        'weighted_ltv' => 0.6,
    ];

    $export = makeExport(['mortgages' => $mortgages, 'treeMeta' => $treeMeta]);
    $spreadsheet = loadExportSpreadsheet($export);
    $oversigt = $spreadsheet->getSheetByName('Oversigt');

    // Frontend-computed total = tree_meta total when no sampant
    $expectedDkk = $treeMeta['total_principal_amount'] / 100;
    expect((float) $oversigt->getCell('B3')->getValue())->toBe((float) $expectedDkk);
});

it('Pantebreve sheet has one row per mortgage with header row', function () {
    $mortgages = [
        ['id' => 1, 'tinglysning_right_id' => 'u1', 'address' => 'Vej 1', 'principal_amount' => 100_000_00],
        ['id' => 2, 'tinglysning_right_id' => 'u2', 'address' => 'Vej 2', 'principal_amount' => 200_000_00],
    ];

    $spreadsheet = loadExportSpreadsheet(makeExport(['mortgages' => $mortgages]));
    $sheet = $spreadsheet->getSheetByName('Pantebreve');

    // Row 1 = header, rows 2..N = data
    expect($sheet->getCell('A1')->getValue())->toBe('Adresse');
    expect($sheet->getCell('A2')->getValue())->toBe('Vej 1');
    expect($sheet->getCell('A3')->getValue())->toBe('Vej 2');
    expect($sheet->getHighestDataRow())->toBe(3);
});

it('Tier-breakdown sheet has one row per tier entry with header', function () {
    $tiers = [
        ['cvr' => '111', 'name' => 'Co A', 'depth' => 0, 'property_count' => 2, 'mortgage_count' => 3, 'principal_amount' => 100_000_00, 'weighted_ltv' => 0.5],
        ['cvr' => '222', 'name' => 'Co B', 'depth' => 1, 'property_count' => 1, 'mortgage_count' => 1, 'principal_amount' => 50_000_00, 'weighted_ltv' => 0.6],
    ];

    $spreadsheet = loadExportSpreadsheet(makeExport(['tierBreakdown' => $tiers]));
    $sheet = $spreadsheet->getSheetByName('Tier-breakdown');

    expect($sheet->getCell('A1')->getValue())->toBe('Selskab');
    expect($sheet->getCell('A2')->getValue())->toBe('Co A');
    expect($sheet->getCell('A3')->getValue())->toBe('Co B');
});
