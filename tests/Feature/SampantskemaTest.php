<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use TheFountainhead\Metis\Exports\SampantskemaExport;
use TheFountainhead\Metis\Livewire\Engagement;

/**
 * Sampantskema og pantoversigt for et engagement: pantebreve lyst på flere ejendomme, samlet pr. pantebrev,
 * med alle ejendommene (også dem uden for engagementet). Aldrig debitoroplysninger.
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
        'registered_at' => '2026-02-23', 'is_own' => true, 'is_ahead' => false, 'is_pari' => false, 'priority_unknown' => false,
        'document_key' => 'aaaaaaaaaaaaaaaa', 'also_registered_on' => []];
}

function sampantEngagement(): array
{
    $oevrig = fn (string $bfe, ?string $adr, string $matr) => ['bfe' => $bfe, 'ejerlav' => '2000174', 'matrikelnr' => $matr, 'address' => $adr, 'postal_code' => $adr ? '8000' : null, 'city' => $adr ? 'Aarhus C' : null];

    return [
        'key' => 'c22222222', 'owners' => [], 'lender_kr' => 25_000_000,
        'properties' => [
            ['id' => 1, 'bfe' => '5000001', 'address' => 'Torvet 2A', 'postal_code' => '8000', 'city' => 'Aarhus C', 'own_since' => '2026-02-23', 'ahead_kr' => 1_000_000, 'total_debt_kr' => 26_000_000, 'ladder' => [
                sampantRaekke(10, ['priority' => 1, 'type' => 'realkreditpantebrev', 'creditor' => '=HYPERLINK("http://evil","Bank")', 'pledgees' => [], 'amount_kr' => 1_000_000, 'is_own' => false, 'is_ahead' => true, 'document_key' => 'bbbbbbbbbbbbbbbb']),
                sampantRaekke(11, ['also_registered_on' => [$oevrig('5000002', 'Torvet 2B', '12b'), $oevrig('5000003', 'Udenfor 9', '12c'), $oevrig('5999999', null, '7')]]),
            ]],
            ['id' => 2, 'bfe' => '5000002', 'address' => 'Torvet 2B', 'postal_code' => '8000', 'city' => 'Aarhus C', 'own_since' => '2026-02-23', 'ahead_kr' => 0, 'total_debt_kr' => 25_000_000, 'ladder' => [
                sampantRaekke(12, ['priority' => 3, 'also_registered_on' => [$oevrig('5000001', 'Torvet 2A', '12a')]]),
            ]],
        ],
        'changes' => [], 'data_quality' => [],
    ];
}

function sampantMeta(): array
{
    return ['lender' => ['cvr' => '11111111', 'name' => 'LÅNGIVER A/S'], 'measured_at' => '2026-11-19T02:00:00+00:00', 'disclaimer' => 'x', 'totals' => []];
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

it('samler samme pantebrev på tværs af ejendomme og tager ejendomme uden for engagementet med', function () {
    $pantebreve = SampantskemaExport::pantebreve(sampantEngagement());

    expect($pantebreve)->toHaveCount(2)
        ->and($pantebreve[0]['is_own'])->toBeTrue()->and($pantebreve[0]['is_sampant'])->toBeTrue()
        ->and(array_map(fn ($e) => [$e['bfe'], $e['address'], $e['matrikel'], $e['priority'], $e['in_engagement']], $pantebreve[0]['properties']))->toBe([
            ['5000001', 'Torvet 2A', '2000174 12a', 2, true],   // matriklen kommer fra den anden ejendoms liste
            ['5000002', 'Torvet 2B', '2000174 12b', 3, true],   // prioriteten er ejendommens egen
            ['5000003', 'Udenfor 9', '2000174 12c', null, false],
            ['5999999', null, '2000174 7', null, false],
        ])
        ->and($pantebreve[1]['is_sampant'])->toBeFalse()
        ->and(SampantskemaExport::sampant(sampantEngagement()))->toHaveCount(1);
});

it('🚨 uden nøgle (ældre API) slås intet sammen, og skæve data vælter ikke', function () {
    $e = sampantEngagement();
    unset($e['properties'][0]['ladder'][1]['document_key'], $e['properties'][1]['ladder'][0]['document_key']);
    $e['properties'][0]['ladder'][1]['also_registered_on'] = ['skrot', ['bfe' => null], ['bfe' => ['x']], ['bfe' => '5000003']];
    $e['properties'][] = 'skrot';
    $e['properties'][] = ['id' => 9, 'ladder' => 'skrot'];

    $pantebreve = SampantskemaExport::pantebreve($e);

    expect($pantebreve)->toHaveCount(3)
        ->and(SampantskemaExport::pantebreve([]))->toBe([])
        ->and(SampantskemaExport::pantebreve(['properties' => null]))->toBe([]);
});

it('regnearket har sampantskema, pantoversigt og en forklaring, med beløb som tal', function () {
    $bog = sampantBog(new SampantskemaExport(sampantEngagement(), sampantMeta()));

    expect($bog->getSheetNames())->toBe(['Sampantskema', 'Pantoversigt', 'Om']);

    $s = $bog->getSheetByName('Sampantskema');
    expect($s->getCell('A1')->getValue())->toBe('Pantebrev nr.')
        ->and($s->getHighestRow())->toBe(5) // overskrift + 4 ejendomme for det ene sampant
        ->and($s->getCell('E2')->getValue())->toBe(25000000)
        ->and($s->getCell('H2')->getValue())->toBe('Torvet 2A')->and($s->getCell('J2')->getValue())->toBe('5000001')->and($s->getCell('M2')->getValue())->toBe('Ja')
        ->and($s->getCell('H5')->getValue())->toBe('Adresse ikke kendt')->and($s->getCell('K5')->getValue())->toBe('2000174 7')->and($s->getCell('M5')->getValue())->toBe('Nej')
        ->and($s->getCell('N2')->getValue())->toBe(4);

    $p = $bog->getSheetByName('Pantoversigt');
    expect($p->getHighestRow())->toBe(4) // overskrift + 3 hæftelser
        ->and($p->getCell('A2')->getValue())->toBe('Torvet 2A')->and($p->getCell('D2')->getValue())->toBe(1)->and($p->getCell('K2')->getValue())->toBe('Ja')
        ->and($p->getCell('H3')->getValue())->toBe(25000000)->and($p->getCell('J3')->getValue())->toBe('Ja')->and($p->getCell('M3')->getValue())->toBe(4)
        ->and($p->getCell('M2')->getValue())->toBe(1);

    expect($bog->getSheetByName('Om')->getCell('B2')->getValue())->toBe('LÅNGIVER A/S');
});

it('🚨 tekst fra kilden bliver aldrig til en formel, og der er ingen debitorkolonne', function () {
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
});

it('et engagement uden sampant giver et regneark der siger det, ikke et tomt ark', function () {
    $e = sampantEngagement();
    $e['properties'] = [['id' => 1, 'bfe' => '1', 'address' => 'Alene 1', 'ladder' => [sampantRaekke(1)]]];

    $bog = sampantBog(new SampantskemaExport($e));

    expect($bog->getSheetByName('Sampantskema')->getCell('A2')->getValue())->toContain('Ingen pantebreve');
});

it('siden viser sampant med alle ejendomme og mærker dem uden for engagementet', function () {
    session(['metis_user_token' => '19|abc']);
    Http::fake(['*/v1/engagements/*' => Http::response(['data' => sampantEngagement(), 'meta' => sampantMeta()])]);

    $html = Livewire::test(Engagement::class, ['key' => 'c22222222'])->html();

    expect(substr_count($html, 'data-sampant-pantebrev'))->toBe(1)
        ->and($html)->toContain('Udenfor 9')->toContain('uden for engagementet')->toContain('Adresse ikke kendt')
        ->toContain('BFE 5999999')->toContain('2000174 12b')->toContain('eget pant')
        ->toContain('Hent sampantskema og pantoversigt (XLSX)');
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

    // Filens INDHOLD kommer fra det friske svar (tre hæftelser), ikke fra den tomme tilstand klienten satte.
    $tmp = tempnam(sys_get_temp_dir(), 'sampant-dl-').'.xlsx';
    file_put_contents($tmp, base64_decode($c->effects['download']['content']));
    $bog = IOFactory::load($tmp);
    @unlink($tmp);
    expect($bog->getSheetByName('Pantoversigt')->getHighestRow())->toBe(4);
});

it('🚨 fejler hentningen til regnearket, kommer der en besked og ingen fil', function () {
    session(['metis_user_token' => '19|abc']);
    Http::fake(['*/v1/engagements/*' => Http::sequence()->push(['data' => sampantEngagement(), 'meta' => sampantMeta()])->push([], 500)->push(['data' => []])]);

    $c = Livewire::test(Engagement::class, ['key' => 'c22222222']);
    $c->call('exportSampantskema')->assertNoFileDownloaded()->assertSee('Kunne ikke hente engagementet til regnearket');
    $c->call('exportSampantskema')->assertNoFileDownloaded();
});
