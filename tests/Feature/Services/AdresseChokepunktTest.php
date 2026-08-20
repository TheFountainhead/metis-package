<?php

use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\Sections\AddressBbr;
use TheFountainhead\Metis\Services\RegistryApi;

/*
 * 🚨 GUARDEN SKAL SIDDE VED CHOKEPUNKTET, IKKE PAA HVER DOER.
 *
 * PR #179 lagde en postnummer-guard i `Lookup::mount()`. Maalt EFTER merge:
 * den er fuldstaendig omgaaet. De 12 adresse-sektioner er selvstaendigt
 * mountbare `lazy`-komponenter, saa et `__lazyLoad`-payload rammer
 * `AddressBbr::mount()` DIREKTE — `Lookup::mount()` koerer aldrig.
 *
 *   Livewire::test(AddressBbr::class, ['query' => 'Søndergade 43A'])
 *   => POST /v1/property/analysis {"street":"Søndergade","number":"43A","zip":""}
 *
 * `resolveAddressAnalysis()` er det ENESTE punkt alle 14 kaldesteder deler
 * (12 sektioner + MapPanel + MetisPdfController). Praecis samme argument som
 * kvote-gaten i `client()`: "en guard pr. mount() ville vaere samme fejl ét
 * niveau nede: den 29. sektion ville mangle den".
 *
 * 🔑 `MetisSection::opslagFejlede()` mapper ALLEREDE status 422 til
 * 'address_ambiguous', saa sektionerne viser den rigtige besked uden
 * aendring. Kontrakten fandtes; den blev bare ikke brugt.
 */

it('kalder ALDRIG property/analysis for en adresse uden postnummer', function () {
    Http::fake(['*' => Http::response(['data' => ['property' => []]], 200)]);

    $svar = app(RegistryApi::class)->resolveAddressAnalysis('Søndergade 43A');

    // Selve tingen: det kald der gav 422 maa ikke ske.
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/v1/property/analysis'));
    expect($svar)->toBe(['error' => 'address_ambiguous', 'status' => 422]);
});

it('lukker ogsaa doeren gennem en sektion mountet DIREKTE', function () {
    Http::fake(['*' => Http::response(['data' => ['property' => []]], 200)]);

    // Praecis hvad et __lazyLoad-payload goer — uden om Lookup::mount().
    Livewire::test(AddressBbr::class, ['query' => 'Søndergade 43A'])
        ->assertSet('hasError', true)
        ->assertSet('errorMessage', 'address_ambiguous');

    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/v1/property/analysis'));
});

it('lukker doeren gennem PDF-controlleren', function () {
    Http::fake(['*' => Http::response(['data' => ['property' => []]], 200)]);

    $svar = app(RegistryApi::class)->resolveAddressAnalysis('Søndergade 43A');

    // PDF'en kan i dag ikke skelne fejl fra tom; den skal i det mindste ikke
    // udloese kaldet. (Egen fejlgren i pdf.blade er en separat opgave.)
    expect($svar)->toHaveKey('error');
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/v1/property/analysis'));
});

/*
 * 🚨 `zip` ALENE ER IKKE NOK. Upstream kraever street/number/zip.
 * `parseAddress('Agernskrænten+33,+2750')` giver zip='2750' men number=''
 * — den slap forbi PR #179's guard og fejlede 422 paa `number` bagved den.
 */
it('afviser ogsaa naar husnummeret mangler, ikke kun postnummeret', function () {
    Http::fake(['*' => Http::response(['data' => ['property' => []]], 200)]);

    $svar = app(RegistryApi::class)->resolveAddressAnalysis('Agernskrænten+33,+2750');

    expect($svar)->toBe(['error' => 'address_ambiguous', 'status' => 422]);
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/v1/property/analysis'));
});

it('lader en komplet adresse passere HELT igennem til API-kaldet', function () {
    Http::fake(['*' => Http::response(['data' => ['property' => ['bbr' => ['total_area' => 120]]]], 200)]);

    $svar = app(RegistryApi::class)->resolveAddressAnalysis('Søndergade 43A, 4653');

    // Positiv kontrol: beviser at guarden blev EVALUERET og gav lov —
    // ikke bare at en default overlevede.
    Http::assertSent(fn ($r) => str_contains($r->url(), '/v1/property/analysis')
        && $r->data() === ['street' => 'Søndergade', 'number' => '43A', 'zip' => '4653']);
    expect($svar['property']['bbr']['total_area'])->toBe(120);
});

it('cacher ALDRIG en afvist adresse', function () {
    Http::fake(['*' => Http::response(['data' => ['property' => []]], 200)]);

    app(RegistryApi::class)->resolveAddressAnalysis('Søndergade 43A');

    expect(Cache::get('metis:address_analysis:'.md5('Søndergade 43A')))->toBeNull();
});
