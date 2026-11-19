<?php

use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\AlertDetail;
use TheFountainhead\Metis\Livewire\AlertsInbox;

/**
 * Detaljesiden for beskeder om et SELSKAB: ny årsrapport og ændret CVR-status (registry-api `annual_report` og
 * `company_status_change`). De har ingen ejendom og intet pantebrev, så pantvisningen må ikke bruges.
 */
beforeEach(function () {
    $this->withoutVite();
    Http::preventStrayRequests();
    config()->set('metis.mode', 'standalone');
    session()->start();
    session(['metis_user_token' => '13|test', 'metis_pilot_verificeret' => true]);
});

function aarsrapportBesked(array $meta = []): array
{
    return ['id' => 21, 'alert_type' => 'annual_report', 'title' => 'Ny årsrapport: Borger Ejendomme ApS (2025)', 'description' => 'Resultat: 2.527.000 kr.',
        'is_read' => false, 'priority' => 'low', 'created_at' => '2026-11-19T05:45:00Z',
        'metadata' => $meta + ['cvr' => '12345678', 'company_name' => 'Borger Ejendomme ApS', 'year' => '2025', 'period_start' => '2025-01-01', 'period_end' => '2025-12-31',
            'published_at' => '2026-05-28T09:15:00.000Z', 'scope' => 'consolidated', 'profit_loss' => 2_527_000, 'equity' => 92_438_600, 'assets' => 120_000_000, 'gross_profit' => 8_000_000],
        'watchlist' => ['id' => 5, 'watch_type' => 'company', 'watch_value' => '12345678', 'display_label' => 'Borger Ejendomme ApS']];
}

function statusBesked(array $meta = [], string $priority = 'high'): array
{
    return ['id' => 22, 'alert_type' => 'company_status_change', 'title' => 'Ændret status: Borger Ejendomme ApS er nu under konkurs', 'description' => 'Status i CVR er ændret fra normal til under konkurs.',
        'is_read' => false, 'priority' => $priority, 'created_at' => '2026-11-19T05:45:00Z',
        'metadata' => $meta + ['cvr' => '12345678', 'company_name' => 'Borger Ejendomme ApS', 'status_from' => 'NORMAL', 'status_to' => 'UNDERKONKURS', 'ophoert' => false],
        'watchlist' => ['id' => 5, 'watch_type' => 'company', 'watch_value' => '12345678', 'display_label' => 'Borger Ejendomme ApS']];
}

function selskabsDetalje(array $besked)
{
    Http::fake(['*/v1/alerts/'.$besked['id'] => Http::response(['data' => $besked])]);

    return Livewire::test(AlertDetail::class, ['id' => $besked['id']])->assertSet('error', null);
}

it('en ny årsrapport viser selskabet og nøgletallene i hele kroner, ikke en ejendom og et pantebrev', function () {
    $c = selskabsDetalje(aarsrapportBesked());
    $html = $c->html();

    $c->assertSee('Borger Ejendomme ApS')->assertSee('CVR 12345678')->assertSee('Ny årsrapport')
        ->assertSee('2025 (koncernregnskab)')->assertSee('01.01.2025 – 31.12.2025')->assertSee('28.05.2026')
        ->assertSee('2.527.000 kr.')->assertSee('92.438.600 kr.')->assertSee('120.000.000 kr.')->assertSee('8.000.000 kr.')
        ->assertSee('Bruttofortjeneste')
        ->assertSeeHtml('/lookup/cvr/12345678')
        ->assertDontSee('Ukendt ejendom')->assertDontSee('Se ejendom')->assertDontSee('Hovedstol')->assertDontSee('Kreditor');

    expect(substr_count($html, 'data-selskabsbesked-indhold'))->toBe(1)
        ->and(mb_strtolower($html))->not->toContain('omsætning');
});

it('en statusændring viser status før og nu, og fremhæver en advarsel', function () {
    $c = selskabsDetalje(statusBesked());

    $c->assertSee('Ændret status')->assertSee('Borger Ejendomme ApS')
        ->assertSeeInOrder(['Før', 'normal', 'Nu', 'under konkurs'])
        ->assertSeeHtml('text-red-700')
        ->assertDontSee('Ukendt ejendom')->assertDontSee('Hovedstol');
});

it('læser statusser som CVR skriver dem, sammenskrevet eller med mellemrum', function (string $status, string $forventet) {
    // Titel og beskrivelse er tomme, så teksten kun kan komme fra Før/Nu-boksen.
    $besked = ['title' => 'Besked', 'description' => ''] + statusBesked(['status_from' => 'UNDERKONKURS', 'status_to' => $status], 'low');

    selskabsDetalje($besked)->assertSeeInOrder(['Nu', $forventet])->assertDontSeeHtml('text-red-700');
})->with([
    ['NORMAL', 'normal'],
    ['UNDERTVANGSOPLØSNING', 'under tvangsopløsning'],
    ['OPLØSTEFTERKONKURS', 'opløst efter konkurs'],
    ['Under  Frivillig Likvidation', 'under frivillig likvidation'],
]);

it('🚨 en statusbesked via netværket forklares som en besked om selskabet, ikke om en ejendom', function () {
    $besked = statusBesked();
    $besked['watchlist'] = ['id' => 9, 'watch_type' => 'company', 'watch_value' => '12345678', 'display_label' => 'Borger Ejendomme ApS', 'origin' => 'lender_network'];

    selskabsDetalje($besked)->assertSeeHtml('data-via-netvaerk')
        ->assertSee('Selskabet er koncerntop for en af dine låntagere')
        ->assertDontSee('Ejendommen ligger i koncernen')->assertDontSee('pant i ejendommen');

    Http::fake(['*/v1/alerts*' => Http::response(['data' => [$besked]]), '*' => Http::response(['data' => []])]);
    Livewire::test(AlertsInbox::class)->call('fetch')->assertSeeHtml('data-via-netvaerk')
        ->assertSee('Borger Ejendomme ApS er koncerntop for en af dine låntagere.')->assertDontSee('Ejendommen ligger i koncernen');
});

it('🚨 tåler manglende og skæve data uden at vælte, og viser navne som tekst', function (array $besked) {
    $c = selskabsDetalje($besked);

    $c->assertSeeHtml('data-selskabsbesked')->assertDontSeeHtml('<script>alert(1)</script>')->assertDontSee('Hovedstol');
})->with([
    'årsrapport uden tal' => [aarsrapportBesked(['profit_loss' => null, 'equity' => null, 'assets' => null, 'gross_profit' => null, 'year' => null, 'period_start' => null, 'published_at' => 'i går', 'scope' => null])],
    'årsrapport med skæve typer' => [aarsrapportBesked(['profit_loss' => 'mange', 'equity' => ['x'], 'year' => ['2025'], 'period_end' => 12, 'cvr' => 'abc', 'company_name' => '<script>alert(1)</script>'])],
    'status uden før og nu' => [statusBesked(['status_from' => null, 'status_to' => null])],
    'status med skæve typer' => [statusBesked(['status_from' => ['x'], 'status_to' => 7, 'cvr' => 12345678, 'company_name' => '<script>alert(1)</script>'])],
    'uden metadata' => [['metadata' => null] + aarsrapportBesked()],
]);

it('uden et gyldigt CVR-nummer er der intet link til selskabet', function () {
    selskabsDetalje(aarsrapportBesked(['cvr' => '1234567']))->assertDontSee('Se selskabet')->assertDontSee('CVR 1234567');
});

it('🚨 en pantbesked er uændret: ejendommen og pantebrevet vises som før', function () {
    Http::fake(['*/v1/alerts/31' => Http::response(['data' => [
        'id' => 31, 'alert_type' => 'mortgage_new', 'title' => 'Nyt realkreditpantebrev: Testvej 1', 'description' => 'Hovedstol: 100 kr.', 'is_read' => false, 'priority' => 'low', 'created_at' => '2026-11-19T05:45:00Z',
        'metadata' => ['address' => 'Testvej 1', 'postal_code' => '2100', 'change_kind' => 'new', 'mortgage_type' => 'realkreditpantebrev', 'principal_amount_kr' => 100, 'creditor' => 'Bank A/S', 'cvr' => '12345678', 'company_name' => 'Ikke En Selskabsbesked ApS'],
        'watchlist' => ['id' => 5, 'watch_type' => 'property', 'watch_value' => '1', 'display_label' => 'Testvej 1'],
    ]])]);

    Livewire::test(AlertDetail::class, ['id' => 31])
        ->assertSee('Testvej 1')->assertSee('Kreditor')->assertSee('Bank A/S')
        ->assertDontSeeHtml('data-selskabsbesked')->assertDontSee('Ikke En Selskabsbesked ApS')->assertDontSee('Se selskabet');
});

it('🚨 en ukendt eller manipuleret type behandles som en pantbesked, aldrig som en selskabsbesked', function (mixed $type) {
    $besked = ['alert_type' => $type] + aarsrapportBesked();

    selskabsDetalje($besked)->assertDontSeeHtml('data-selskabsbesked');
})->with([['annual_report<script>'], ['ANNUAL_REPORT'], [null], [['annual_report']], ['noget_helt_andet']]);

it('indbakken viser de nye beskeder med selskabet som kilde', function () {
    Http::fake([
        '*/v1/alerts*' => Http::response(['data' => [aarsrapportBesked(), statusBesked()]]),
        '*' => Http::response(['data' => []]),
    ]);

    Livewire::test(AlertsInbox::class)->call('fetch')
        ->assertSee('Ny årsrapport: Borger Ejendomme ApS (2025)')
        ->assertSee('Ændret status: Borger Ejendomme ApS er nu under konkurs')
        ->assertSee('Via: Selskab Borger Ejendomme ApS')
        ->assertSee('Høj prioritet');
});
