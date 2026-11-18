<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use TheFountainhead\Metis\Exports\PortfolioTinglysningExport;
use TheFountainhead\Metis\Exports\SampantskemaExport;
use TheFountainhead\Metis\Livewire\Engagement;

/**
 * Sampantskema og pantoversigt for et engagement: pantebreve lyst på flere ejendomme, samlet pr. pantebrev.
 * For långiverens EGNE pantebreve vises alle ejendommene (også uden for engagementet); for andre kreditorers kun
 * antallet. Aldrig debitoroplysninger.
 */
beforeEach(function () {
    $this->withoutVite();
    Http::preventStrayRequests();
    config()->set('metis.mode', 'standalone');
    config()->set('metis.gating.enabled', true);
    session()->start();
});

function sampantRaekke(int $id, array $o = []): array
{
    return $o + ['id' => $id, 'priority' => 2, 'type' => 'ejerpantebrev', 'creditor' => 'Ejer ApS', 'pledgees' => ['Långiver A/S'], 'amount_kr' => 25_000_000,
        'registered_at' => '2026-02-23', 'is_own' => true, 'is_ahead' => false, 'is_pari' => false, 'priority_unknown' => false, 'is_afgiftspantebrev' => false,
        'document_key' => 'aaaaaaaaaaaaaaaa', 'also_registered_count' => 3];
}

function sampantOevrig(string $bfe, ?string $adr, string $matr, array $o = []): array
{
    return $o + ['bfe' => $bfe, 'ejerlav' => '2000174', 'ejerlav_navn' => null, 'matrikelnr' => $matr, 'ejerlejlighed' => null,
        'address' => $adr, 'postal_code' => $adr ? '8000' : null, 'city' => $adr ? 'Aarhus C' : null];
}

function sampantEngagement(): array
{
    return [
        'key' => 'c22222222', 'owners' => [], 'lender_kr' => 25_000_000,
        'properties' => [
            ['id' => 1, 'bfe' => '5000001', 'address' => 'Torvet 2A', 'postal_code' => '8000', 'city' => 'Aarhus C', 'own_since' => '2026-02-23', 'ahead_kr' => 1_000_000, 'total_debt_kr' => 26_000_000, 'ladder' => [
                // En anden kreditors pantebrev, lyst på 4 andre ejendomme vi IKKE får oplyst.
                sampantRaekke(10, ['priority' => 1, 'type' => 'realkreditpantebrev', 'creditor' => '=HYPERLINK("http://evil","Bank")', 'pledgees' => [], 'amount_kr' => 1_000_000, 'is_own' => false, 'is_ahead' => true, 'document_key' => 'bbbbbbbbbbbbbbbb', 'also_registered_count' => 4]),
                sampantRaekke(11),
            ]],
            ['id' => 2, 'bfe' => '5000002', 'address' => 'Torvet 2B', 'postal_code' => '8000', 'city' => 'Aarhus C', 'own_since' => '2026-02-23', 'ahead_kr' => 0, 'total_debt_kr' => 25_000_000, 'ladder' => [
                sampantRaekke(12, ['priority' => 3]),
                sampantRaekke(13, ['priority' => 5, 'type' => 'skadesloesbrev', 'creditor' => 'Alene A/S', 'amount_kr' => 500_000, 'is_own' => false, 'document_key' => 'cccccccccccccccc', 'also_registered_count' => 0]),
            ]],
            // I engagementet, men uden en række for det egne pantebrev (fx en afløst revision).
            ['id' => 3, 'bfe' => '5000004', 'address' => 'Torvet 2D', 'postal_code' => '8000', 'city' => 'Aarhus C', 'own_since' => null, 'ahead_kr' => null, 'total_debt_kr' => 0, 'ladder' => []],
        ],
        'sampant' => [[
            'document_key' => 'aaaaaaaaaaaaaaaa', 'truncated' => false,
            'properties' => [
                sampantOevrig('5000002', 'Torvet 2B', '12b'),
                sampantOevrig('5000001', 'Torvet 2A', '12a', ['ejerlav_navn' => 'Aarhus Bygrunde', 'ejerlejlighed' => '3']),
                sampantOevrig('5000003', 'Ørstedsvej 9', '12c'),
                sampantOevrig('5999999', null, '7'),
                sampantOevrig('5000004', 'Torvet 2D', '12d'),
            ],
        ]],
        'changes' => [], 'data_quality' => [],
    ];
}

function sampantMeta(): array
{
    return ['lender' => ['cvr' => '11111111', 'name' => 'LÅNGIVER A/S'], 'measured_at' => '2026-11-19T02:00:00+00:00', 'disclaimer' => 'Beløbet er hvad långiveren står anført for.', 'totals' => []];
}

function sampantBog(SampantskemaExport $export)
{
    $tmp = tempnam(sys_get_temp_dir(), 'sampant-').'.xlsx';
    $stream = fopen($tmp, 'wb');
    $export->writeTo($stream);
    fclose($stream);
    $bog = IOFactory::load($tmp);
    @unlink($tmp);

    return $bog;
}

it('samler et eget pantebrev på tværs af ejendomme og tager ejendomme uden for engagementet med', function () {
    $pantebreve = collect(SampantskemaExport::pantebreve(sampantEngagement()))->keyBy('key');
    $eget = $pantebreve['aaaaaaaaaaaaaaaa'];

    expect($pantebreve)->toHaveCount(3)
        ->and($eget['is_own'])->toBeTrue()->and($eget['is_sampant'])->toBeTrue()->and($eget['properties_total'])->toBe(5)->and($eget['unlisted'])->toBe(0)
        ->and(array_map(fn ($e) => [$e['bfe'], $e['address'], $e['matrikel'], $e['priority'], $e['in_engagement']], $eget['properties']))->toBe([
            ['5000001', 'Torvet 2A', 'Aarhus Bygrunde 12a, lejl. 3', 2, true],  // ejerlavets navn og ejerlejlighedsnummer
            ['5000002', 'Torvet 2B', '2000174 12b', 3, true],                    // prioriteten er ejendommens egen
            ['5000004', 'Torvet 2D', '2000174 12d', null, true],                 // i engagementet, selv uden en række for pantebrevet
            ['5000003', 'Ørstedsvej 9', '2000174 12c', null, false],             // Ø sorterer før "adresse ikke kendt"
            ['5999999', null, '2000174 7', null, false],
        ]);
});

it('🚨 en anden kreditors pantebrev viser kun antallet af øvrige ejendomme, ikke hvilke', function () {
    $pantebreve = collect(SampantskemaExport::pantebreve(sampantEngagement()))->keyBy('key');
    $fremmed = $pantebreve['bbbbbbbbbbbbbbbb'];

    expect($fremmed['is_own'])->toBeFalse()->and($fremmed['is_sampant'])->toBeTrue()
        ->and(array_column($fremmed['properties'], 'bfe'))->toBe(['5000001'])
        ->and($fremmed['properties_total'])->toBe(5)->and($fremmed['unlisted'])->toBe(4)
        // Et pantebrev på kun én ejendom er ikke sampant.
        ->and($pantebreve['cccccccccccccccc']['is_sampant'])->toBeFalse()->and($pantebreve['cccccccccccccccc']['properties_total'])->toBe(1)
        ->and(array_column(SampantskemaExport::sampant(sampantEngagement()), 'key'))->toBe(['aaaaaaaaaaaaaaaa', 'bbbbbbbbbbbbbbbb']);
});

it('🚨 uden nøgle (ældre API), også uden id, slås intet sammen, og skæve data vælter ikke', function () {
    $e = sampantEngagement();
    foreach ($e['properties'] as &$p) {
        foreach ($p['ladder'] as &$r) {
            unset($r['document_key'], $r['id'], $r['also_registered_count']);
        }
    }
    unset($p, $r, $e['sampant']);
    $e['properties'][] = 'skrot';
    $e['properties'][] = ['id' => 9, 'ladder' => ['skrot', null]];

    $pantebreve = SampantskemaExport::pantebreve($e);

    expect($pantebreve)->toHaveCount(4)
        ->and(array_unique(array_column($pantebreve, 'key')))->toHaveCount(4)
        ->and(SampantskemaExport::sampant($e))->toBe([])
        ->and(SampantskemaExport::pantebreve([]))->toBe([])
        ->and(SampantskemaExport::pantebreve(['properties' => null, 'sampant' => 'skrot']))->toBe([])
        ->and(SampantskemaExport::pantebreve(['properties' => [], 'sampant' => [['document_key' => 'x', 'properties' => [['bfe' => null], 'skrot']]]]))->toBe([]);
});

it('et afgiftspantebrev hedder det, det er, og typen står ens på siden og i regnearket', function () {
    $e = sampantEngagement();
    $e['properties'][0]['ladder'][1]['is_afgiftspantebrev'] = true;

    expect(collect(SampantskemaExport::pantebreve($e))->firstWhere('key', 'aaaaaaaaaaaaaaaa')['type'])->toBe('Afgiftspantebrev')
        ->and(collect(SampantskemaExport::pantebreve($e))->firstWhere('key', 'cccccccccccccccc')['type'])->toBe('Skadesløsbrev');

    $bog = sampantBog(new SampantskemaExport($e, sampantMeta()));
    expect($bog->getSheetByName('Pantoversigt')->getCell('E3')->getValue())->toBe('Afgiftspantebrev')
        ->and($bog->getSheetByName('Pantoversigt')->getCell('E5')->getValue())->toBe('Skadesløsbrev');
});

it('regnearket har sampantskema, pantoversigt og en forklaring, med beløb som tal', function () {
    $bog = sampantBog(new SampantskemaExport(sampantEngagement(), sampantMeta()));

    expect($bog->getSheetNames())->toBe(['Sampantskema', 'Pantoversigt', 'Om']);

    $s = $bog->getSheetByName('Sampantskema');
    expect($s->getCell('A1')->getValue())->toBe('Pantebrev nr.')
        ->and($s->getHighestRow())->toBe(7) // overskrift + 5 ejendomme for det egne + 1 for den anden kreditors
        ->and($s->getCell('E2')->getValue())->toBe(25000000)
        ->and($s->getCell('H2')->getValue())->toBe('Torvet 2A')->and($s->getCell('J2')->getValue())->toBe('5000001')->and($s->getCell('M2')->getValue())->toBe('Ja')
        ->and($s->getCell('H6')->getValue())->toBe('Adresse ikke kendt')->and($s->getCell('K6')->getValue())->toBe('2000174 7')->and($s->getCell('M6')->getValue())->toBe('Nej')
        ->and($s->getCell('N2')->getValue())->toBe(5)->and((string) $s->getCell('O2')->getValue())->toBe('')
        // Den anden kreditors pantebrev: én ejendom, 5 i alt, og en bemærkning om de fire uoplyste.
        ->and($s->getCell('G7')->getValue())->toBe('Nej')->and($s->getCell('N7')->getValue())->toBe(5)
        ->and($s->getCell('O7')->getValue())->toBe('Anden kreditors pantebrev: 4 øvrige ejendomme er ikke oplyst.');

    $p = $bog->getSheetByName('Pantoversigt');
    expect($p->getHighestRow())->toBe(5) // overskrift + 4 hæftelser
        ->and($p->getCell('A2')->getValue())->toBe('Torvet 2A')->and($p->getCell('D2')->getValue())->toBe(1)->and($p->getCell('K2')->getValue())->toBe('Ja')
        ->and($p->getCell('H3')->getValue())->toBe(25000000)->and($p->getCell('J3')->getValue())->toBe('Ja')->and((string) $p->getCell('K3')->getValue())->toBe('')
        ->and($p->getCell('M3')->getValue())->toBe(5)->and($p->getCell('M2')->getValue())->toBe(5)->and($p->getCell('M5')->getValue())->toBe(1);

    $om = $bog->getSheetByName('Om');
    expect($om->getCell('B2')->getValue())->toBe('LÅNGIVER A/S')->and($om->getCell('A6')->getValue())->toBe('Beløbet er hvad långiveren står anført for.');
});

it('🚨 kan "foran eget pant" ikke afgøres, står der Ukendt og ikke Nej', function () {
    $e = sampantEngagement();
    $e['properties'][0]['ahead_kr'] = null; // API'et: prioriteten på det egne pant er ukendt
    $e['properties'][0]['ladder'][0]['is_ahead'] = false;

    $p = sampantBog(new SampantskemaExport($e, sampantMeta()))->getSheetByName('Pantoversigt');

    expect($p->getCell('K2')->getValue())->toBe('Ukendt')->and($p->getCell('L2')->getValue())->toBe('Ukendt')
        // På en ejendom hvor det KAN afgøres, står der stadig Nej.
        ->and($p->getCell('K5')->getValue())->toBe('Nej');
});

it('🚨 tekst fra kilden bliver aldrig til en formel, heller ikke i den ældre eksport, og der er ingen debitorkolonne', function () {
    $bog = sampantBog(new SampantskemaExport(sampantEngagement(), sampantMeta()));
    $p = $bog->getSheetByName('Pantoversigt');

    expect($p->getCell('F2')->getDataType())->toBe(\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING)
        ->and($p->getCell('F2')->getValue())->toBe('=HYPERLINK("http://evil","Bank")');

    $overskrifter = [];
    foreach ($bog->getAllSheets() as $ark) {
        foreach ($ark->toArray()[0] as $celle) {
            $overskrifter[] = mb_strtolower((string) $celle);
        }
    }
    expect(implode('|', $overskrifter))->not->toContain('debitor')->not->toContain('skyldner');

    // Den ældre eksport på selskabssiden skriver samme slags tekst.
    $gammel = new PortfolioTinglysningExport(company: ['cvr' => '12345678'], treeMeta: [], tierBreakdown: [], mortgages: [[
        'address' => '=1+1', 'bfe' => '5000001', 'mortgage_type' => '+cmd', 'priority' => 2, 'creditor' => '=HYPERLINK("http://evil","Bank")',
        'principal_amount' => 100_00, 'owner_company' => ['name' => '@SUM(A1)'],
    ]]);
    $tmp = tempnam(sys_get_temp_dir(), 'pte-').'.xlsx';
    $stream = fopen($tmp, 'wb');
    $gammel->writeTo($stream);
    fclose($stream);
    $ark = IOFactory::load($tmp)->getSheetByName('Pantebreve');
    @unlink($tmp);

    foreach (['A2' => '=1+1', 'C2' => '+cmd', 'F2' => '=HYPERLINK("http://evil","Bank")', 'K2' => '@SUM(A1)'] as $celle => $tekst) {
        expect($ark->getCell($celle)->getDataType())->toBe(\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING)
            ->and($ark->getCell($celle)->getValue())->toBe($tekst);
    }
    expect($ark->getCell('D2')->getValue())->toBe(2)->and($ark->getCell('G2')->getValue())->toBe(100);
});

it('et engagement uden sampant giver et regneark der siger det, ikke et tomt ark', function () {
    $e = sampantEngagement();
    $e['properties'] = [['id' => 1, 'bfe' => '1', 'address' => 'Alene 1', 'ladder' => [sampantRaekke(1, ['also_registered_count' => 0])]]];
    $e['sampant'] = [];

    $bog = sampantBog(new SampantskemaExport($e));

    expect($bog->getSheetByName('Sampantskema')->getCell('A2')->getValue())->toContain('Ingen pantebreve');
});

it('en afkortet liste meldes på siden og i regnearket', function () {
    $e = sampantEngagement();
    $e['sampant'][0]['truncated'] = true;

    expect(collect(SampantskemaExport::pantebreve($e))->firstWhere('key', 'aaaaaaaaaaaaaaaa')['truncated'])->toBeTrue()
        ->and(sampantBog(new SampantskemaExport($e))->getSheetByName('Sampantskema')->getCell('O2')->getValue())->toBe('Listen over ejendomme er afkortet.');
});

it('siden viser sampant med ejendommene, mærker dem uden for engagementet og siger hvad der ikke er oplyst', function () {
    session(['metis_user_token' => '19|abc']);
    Http::fake(['*/v1/engagements/*' => Http::response(['data' => sampantEngagement(), 'meta' => sampantMeta()])]);

    $html = Livewire::test(Engagement::class, ['key' => 'c22222222'])->html();

    expect(substr_count($html, 'data-sampant-pantebrev'))->toBe(2)
        ->and(substr_count($html, 'data-sampant-uoplyst'))->toBe(1)
        ->and($html)->toContain('Ørstedsvej 9')->toContain('uden for engagementet')->toContain('Adresse ikke kendt')
        ->toContain('BFE 5999999')->toContain('2000174 12b')->toContain('Aarhus Bygrunde 12a, lejl. 3')->toContain('eget pant')
        ->toContain('Lyst på 5 ejendomme i alt.')->toContain('De 4 øvrige vises ikke for andre kreditorers pantebreve.')
        ->toContain('Hent sampantskema og pantoversigt (XLSX)')
        // Formlen fra kilden står som tekst på siden.
        ->toContain('=HYPERLINK(&quot;http://evil&quot;,&quot;Bank&quot;)');
});

it('🚨 download henter engagementet friskt med brugerens token og kræver adgang', function () {
    Http::fake(['*/v1/engagements/*' => Http::response(['data' => sampantEngagement(), 'meta' => sampantMeta()])]);

    // Uden token: intet kald og ingen fil, heller ikke hvis klienten selv har lagt et engagement i tilstanden.
    Livewire::test(Engagement::class, ['key' => 'c22222222'])->set('engagement', sampantEngagement())->call('exportSampantskema')->assertNoFileDownloaded();
    Http::assertNothingSent();

    session(['metis_user_token' => '19|abc']);
    $c = Livewire::test(Engagement::class, ['key' => 'c22222222']);
    Http::assertSentCount(1);

    // Klienten ændrer tilstanden: filen bygges stadig af det friske svar.
    $c->set('engagement', ['key' => 'c22222222', 'properties' => []])->call('exportSampantskema')
        ->assertFileDownloaded(contentType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/v1/engagements/c22222222') && $r->hasHeader('Authorization', 'Bearer 19|abc'));
    expect($c->effects['download']['name'])->toMatch('/^sampantskema-\d{4}-\d{2}-\d{2}\.xlsx$/');

    // Filens INDHOLD kommer fra det friske svar (fire hæftelser), ikke fra den tomme tilstand klienten satte.
    $tmp = tempnam(sys_get_temp_dir(), 'sampant-dl-').'.xlsx';
    file_put_contents($tmp, base64_decode($c->effects['download']['content']));
    $bog = IOFactory::load($tmp);
    @unlink($tmp);
    expect($bog->getSheetByName('Pantoversigt')->getHighestRow())->toBe(5);
});

it('🚨 fejler hentningen til regnearket, kommer der en besked, siden bliver stående, og beskeden forsvinder ved næste forsøg', function () {
    session(['metis_user_token' => '19|abc']);
    Http::fake(['*/v1/engagements/*' => Http::sequence()
        ->push(['data' => sampantEngagement(), 'meta' => sampantMeta()])
        ->push([], 500)
        ->push(['data' => sampantEngagement(), 'meta' => sampantMeta()])]);

    $c = Livewire::test(Engagement::class, ['key' => 'c22222222']);

    $c->call('exportSampantskema')->assertNoFileDownloaded()
        ->assertSee('Kunne ikke hente engagementet til regnearket')
        ->assertSee('Torvet 2A'); // det indlæste engagement er der stadig

    $c->call('exportSampantskema')->assertFileDownloaded()->assertSet('exportFejl', null)->assertDontSee('Kunne ikke hente engagementet til regnearket');
});
