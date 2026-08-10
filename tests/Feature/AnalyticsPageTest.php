<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\Analytics;
use TheFountainhead\Metis\Models\MetisLead;

uses(RefreshDatabase::class);

/**
 * "Spørg om markedet" — analyse-siden.
 *
 * 🔑 Et ANDET produkt end opslaget: søgefeltet finder én ting man kender
 * navnet på; her afgrænses en population.
 *
 * 🚨 Kernen i disse tests er ikke at tallet er rigtigt — det er at siden
 * ALTID viser sin dækning og hvad den forstod. Målt på prod 10/8: kun ~70 %
 * af pantebrevene i et typisk postnummer har en kendt rentesats.
 */
beforeEach(function () {
    $this->withoutVite();
    config()->set('metis.mode', 'standalone');
    config()->set('metis.gating.enabled', true);
    config()->set('metis.gating.free_lookups', 1);
    Http::preventStrayRequests();
});

function fakeAnalyse(array $data, array $meta): void
{
    Http::fake(['*' => Http::response(['data' => $data, 'meta' => $meta])]);
}

it('viser antallet og hvad spoergsmaalet blev forstaaet som', function () {
    fakeAnalyse(
        ['antal' => 30, 'ejendomme' => [], 'vist' => 0],
        ['forstaaet' => ['postnummer' => '2100', 'rente' => 'over 10 %'], 'uforstaaet' => [], 'daekning' => []]
    );

    Livewire::test(Analytics::class)
        ->set('spoergsmaal', 'erhvervsejendomme i 2100 med rente over 10%')
        ->call('spoerg')
        ->assertSee('30')
        ->assertSee('2100')
        ->assertSee('over 10 %');
});

/**
 * 🚨 DEN VIGTIGSTE TEST. Et præcist tal uden sit forbehold kan føre til en
 * forkert kreditbeslutning.
 */
it('🚨 viser ALTID forbeholdet naar daekningen er ufuldstaendig', function () {
    fakeAnalyse(
        ['antal' => 30, 'ejendomme' => [], 'vist' => 0],
        ['forstaaet' => [], 'uforstaaet' => [], 'daekning' => [
            'pct_med_kendt_rente' => 69.8,
            'variabel_rente' => 144,
            'kontantlaan' => 85,
            'forbehold' => 'Svaret daekker kun pantebreve med kendt rentesats.',
        ]]
    );

    Livewire::test(Analytics::class)
        ->set('spoergsmaal', 'erhvervsejendomme i 2100 med rente over 10%')
        ->call('spoerg')
        ->assertSee('69.8')
        ->assertSee('144')
        ->assertSee('85');
});

it('🪤 skjuler forbeholdet naar daekningen er fuld', function () {
    // Ellers ville advarslen staa paa hvert svar og blive ignoreret.
    fakeAnalyse(
        ['antal' => 5, 'ejendomme' => [], 'vist' => 0],
        ['forstaaet' => [], 'uforstaaet' => [], 'daekning' => ['pct_med_kendt_rente' => 100.0, 'forbehold' => null]]
    );

    Livewire::test(Analytics::class)
        ->set('spoergsmaal', 'erhvervsejendomme i 2100 med rente over 10%')
        ->call('spoerg')
        ->assertDontSee('kan derfor være for lavt');
});

it('🚨 viser API ets EGEN forklaring naar spoergsmaalet ikke forstods', function () {
    // "jeg forstod ingen kriterier" er langt mere brugbart end
    // "der opstod en fejl".
    Http::fake(['*' => Http::response([
        'error' => [
            'code' => 'question_not_understood',
            'message' => 'Jeg forstod ingen kriterier i spoergsmaalet.',
            'details' => ['uforstaaet' => ['Angiv et postnummer.']],
        ],
    ], 422)]);

    Livewire::test(Analytics::class)
        ->set('spoergsmaal', 'hvad er meningen med livet')
        ->call('spoerg')
        ->assertSee('Angiv et postnummer');
});

/**
 * 🚨 Et analyse-spoergsmaal kan afdaekke en HEL population og er derfor mere
 * vaerdifuldt end ét opslag. Det maa ikke vaere vejen udenom kvoten.
 */
it('🚨 gates af kvoten praecis som et opslag', function () {
    Http::fake(['*' => Http::response(['data' => [], 'meta' => []])]);

    Livewire::test(Analytics::class)
        ->set('spoergsmaal', 'erhvervsejendomme i 2100 med rente over 10%')
        ->call('spoerg')
        ->assertSet('gated', false);

    // Kvote opbrugt -> gate
    session(['metis_lookup_count' => 99]);

    Livewire::test(Analytics::class)
        ->set('spoergsmaal', 'erhvervsejendomme i 2100 med rente over 10%')
        ->call('spoerg')
        ->assertSet('gated', true)
        ->assertDispatched('show-email-gate');
});

it('🪤 et gatet spoergsmaal kalder ALDRIG API et', function () {
    // Ellers ville kvoten kun skjule svaret, ikke spare kaldet.
    Http::fake(['*' => Http::response(['data' => [], 'meta' => []])]);
    session(['metis_lookup_count' => 99]);

    Livewire::test(Analytics::class)
        ->set('spoergsmaal', 'erhvervsejendomme i 2100 med rente over 10%')
        ->call('spoerg');

    Http::assertNothingSent();
});

it('taeller opslaget paa lead et som en almindelig soegning', function () {
    MetisLead::create(['email' => 'test@ktemadev.dk', 'lookup_count' => 0, 'lookup_quota' => 5]);
    session(['metis_verified_email' => 'test@ktemadev.dk']);

    fakeAnalyse(['antal' => 1, 'ejendomme' => [], 'vist' => 0], ['forstaaet' => [], 'uforstaaet' => [], 'daekning' => []]);

    Livewire::test(Analytics::class)
        ->set('spoergsmaal', 'erhvervsejendomme i 2100 med rente over 10%')
        ->call('spoerg');

    expect(MetisLead::where('email', 'test@ktemadev.dk')->value('lookup_count'))->toBe(1);
});

it('afviser et tomt spoergsmaal uden at kalde API et', function () {
    Http::fake(['*' => Http::response(['data' => [], 'meta' => []])]);

    Livewire::test(Analytics::class)->set('spoergsmaal', 'ab')->call('spoerg')
        ->assertSet('fejl', 'Skriv et spørgsmål.');

    Http::assertNothingSent();
});

it('siden er tilgaengelig paa /spoerg', function () {
    Http::fake(['*' => Http::response(['data' => [], 'meta' => []])]);

    $this->get('/spoerg')->assertOk()->assertSee('Spørg om ejendomsmarkedet');
});

it('🔑 forsiden linker til analyse-siden', function () {
    // Uden en indgang ville ruten findes, men ingen ville finde den.
    Http::fake(['*' => Http::response(['data' => []])]);

    $this->get('/')->assertOk()->assertSee('Spørg om markedet');
});
