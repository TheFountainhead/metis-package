<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\Sections\AddressMortgages;
use TheFountainhead\Metis\Services\RegistryApi;

/**
 * Et FEJLET opslag maa aldrig rendere som "ingen data".
 *
 * resolveAddressAnalysis() returnerede `[]` for BAADE fejl og tom, saa de 12
 * address-sektioner ikke kunne skelne "vi spurgte og fik intet" fra "vi kunne
 * ikke spoerge". Observeret i prod 18/8: én adresse uden postnummer gav 422,
 * og alle 12 sektioner skrev "Ingen data fundet".
 *
 * 🚨 "Ingen pantebreve fundet" laeses som GAELDFRIHED. I en kreditvurdering er
 * det en konklusion nogen handler paa — den vaerste falske benaegtelse i due
 * diligence.
 *
 * 🪤 Og fejlen blev CACHET I 24 TIMER, saa ét daarligt svar laase loegnen fast
 * et doegn. Kodebasens egen regel siger det modsatte (#134).
 */
beforeEach(fn () => Cache::flush());

it('🚨 giver en FEJL videre i stedet for en tom liste', function () {
    Http::fake(['*property/analysis*' => Http::response(['message' => 'Unprocessable'], 422)]);

    $svar = app(RegistryApi::class)->resolveAddressAnalysis('Søndergade 43A');

    // Foer: [] — ikke til at skelne fra "ejendommen har ingen data".
    expect($svar)->toHaveKey('error')
        ->and($svar['status'])->toBe(422)
        ->and($svar)->not->toHaveKey('property');
});

it('🪤 cacher ALDRIG en fejl — ét daarligt svar maa ikke laase et doegn', function () {
    // Foerste kald fejler, andet lykkes. Uden fixet ville 24t-cachen
    // servere fejlen som "ingen data" resten af doegnet.
    Http::fakeSequence()
        ->push(['message' => 'Unprocessable'], 422)
        ->push(['data' => ['property' => ['address' => 'Søndergade 43A, 4653 Karise', 'mortgages' => [['principal' => 500000]]]]], 200);

    $api = app(RegistryApi::class);

    expect($api->resolveAddressAnalysis('Søndergade 43A'))->toHaveKey('error');
    expect($api->resolveAddressAnalysis('Søndergade 43A'))->toHaveKey('property');
});

it('cacher stadig et AEGTE tomt svar — det er ikke en fejl', function () {
    // 200 uden property = ejendommen findes, men vi har ingen data.
    // Den tilstand maa gerne caches; det er kun fejl der ikke maa.
    Http::fakeSequence()
        ->push(['data' => ['property' => null]], 200)
        ->push(['data' => ['property' => ['mortgages' => [['principal' => 1]]]]], 200);

    $api = app(RegistryApi::class);

    expect($api->resolveAddressAnalysis('Tomvej 1, 1000 By'))->toBe([]);
    // Andet kald rammer cachen, ikke det nye svar.
    expect($api->resolveAddressAnalysis('Tomvej 1, 1000 By'))->toBe([]);
});

it('🚨 siger IKKE "ingen pantebreve" naar opslaget fejlede', function () {
    Http::fake(['*property/analysis*' => Http::response(['message' => 'Unprocessable'], 422)]);

    $html = Livewire::test(AddressMortgages::class, ['query' => 'Søndergade 43A'])->html();

    // Den falske benaegtelse maa vaere VAEK ...
    expect($html)->not->toContain('No mortgages found.')
        // ... og erstattet af noget der siger hvad der faktisk skete.
        ->and($html)->toContain('opslaget lykkedes ikke');
});

it('siger det praecist naar adressen er FLERTYDIG (422), ikke bare "noget gik galt"', function () {
    // Brugeren kan selv rette DEN fejl ved at tilfoeje postnummer — men kun
    // hvis beskeden siger det. Maalt: "Søndergade 43A" findes mindst 5 steder.
    Http::fake(['*property/analysis*' => Http::response(['message' => 'Unprocessable'], 422)]);

    $html = Livewire::test(AddressMortgages::class, ['query' => 'Søndergade 43A'])->html();

    expect($html)->toContain('prøv med postnummer');
});

it('viser stadig "No mortgages found." naar ejendommen ER undersoegt og gaeldfri', function () {
    // Guarden maa ikke goere den AEGTE tomme tilstand utilgaengelig — en
    // faktisk gaeldfri ejendom skal stadig kunne sige det.
    Http::fake(['*property/analysis*' => Http::response(['data' => ['property' => [
        'address' => 'Gaeldfri Alle 1, 1000 By',
        'mortgages' => [],
        'tinglysning_synced_at' => '2026-08-18T00:00:00Z',
    ]]], 200)]);

    $html = Livewire::test(AddressMortgages::class, ['query' => 'Gaeldfri Alle 1, 1000 By'])->html();

    expect($html)->toContain('No mortgages found.')
        ->and($html)->not->toContain('opslaget lykkedes ikke');
});

it('🚨 siger IKKE "ingen ejerdata" naar opslaget fejlede', function () {
    Http::fake(['*property/analysis*' => Http::response(['message' => 'Unprocessable'], 422)]);

    $html = Livewire::test(\TheFountainhead\Metis\Livewire\Sections\AddressOwners::class,
        ['query' => 'Søndergade 43A'])->html();

    expect($html)->not->toContain('No owner data found.')
        ->and($html)->toContain('opslaget lykkedes ikke');
});

it('🚨 siger IKKE "ingen vurderingsdata" naar opslaget fejlede', function () {
    Http::fake(['*property/analysis*' => Http::response(['message' => 'Unprocessable'], 422)]);

    $html = Livewire::test(\TheFountainhead\Metis\Livewire\Sections\AddressValuation::class,
        ['query' => 'Søndergade 43A'])->html();

    expect($html)->not->toContain('No valuation data found.')
        ->and($html)->toContain('opslaget lykkedes ikke');
});

it('viser stadig den AEGTE tomme tilstand for ejere naar kaldet lykkes', function () {
    // Guarden maa ikke goere "ingen ejere" utilgaengelig — kun uaerlig.
    Http::fake(['*property/analysis*' => Http::response(['data' => ['property' => [
        'address' => 'Tomvej 1, 1000 By', 'owners' => [],
    ]]], 200)]);

    $html = Livewire::test(\TheFountainhead\Metis\Livewire\Sections\AddressOwners::class,
        ['query' => 'Tomvej 1, 1000 By'])->html();

    expect($html)->toContain('No owner data found.')
        ->and($html)->not->toContain('opslaget lykkedes ikke');
});

it('🪤 den DELTE partial viser ogsaa postnummer-hintet ved 422', function () {
    // Review-fund: de to tests for 'prøv med postnummer' ramte begge
    // AddressMortgages, som havde sin EGEN inlinede kopi af grenen. Partialen
    // — den kode 11 af 12 sektioner faktisk koerer — var helt utestet:
    // @if(false) i dens 422-gren overlevede hele suiten groent.
    Http::fake(['*property/analysis*' => Http::response(['message' => 'Unprocessable'], 422)]);

    foreach ([
        \TheFountainhead\Metis\Livewire\Sections\AddressOwners::class,
        \TheFountainhead\Metis\Livewire\Sections\AddressValuation::class,
    ] as $sektion) {
        expect(Livewire::test($sektion, ['query' => 'Søndergade 43A'])->html())
            ->toContain('prøv med postnummer');
    }
});

it('🪤 en TRANSPORTFEJL faar den generiske besked, ikke postnummer-hintet', function () {
    // Den hyppigste prod-fejl er timeout, ikke 422. Uden dette kunne
    // errorMessage vaere hardkodet til 'address_ambiguous' og alle tests
    // stadig vaere groenne — og brugeren fik da et raad der ikke hjaelper.
    Http::fake(['*property/analysis*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout')]);

    $html = Livewire::test(\TheFountainhead\Metis\Livewire\Sections\AddressOwners::class,
        ['query' => 'Søndergade 43A, 4653 Karise'])->html();

    expect($html)->toContain('Vi kunne ikke få svar fra kilden')
        ->and($html)->not->toContain('prøv med postnummer');
});

it('🚨 et misdannet 200-svar er en FEJL, ikke "ingen data"', function () {
    // 🪤 Min foerste rettelse af post() brugte `?? []`, saa et svar vi ikke
    // forstaar blev til "ingen data" — den falske benaegtelse flyttet ét lag
    // ned. Nu bliver det en fejl, som resten af kaeden behandler korrekt.
    Http::fake(['*property/analysis*' => Http::response(['uventet' => 'form'], 200)]);

    $svar = app(RegistryApi::class)->resolveAddressAnalysis('Misdannetvej 1, 9999 Testby');

    expect($svar)->toHaveKey('error')
        ->and($svar)->not->toBe([]);
});

/**
 * 🚨 ALLE 12 SEKTIONER, IKKE ET UDVALG.
 *
 * Guarden blev indsat mekanisk i ni sektioner med et script, og testfilen
 * daekkede kun tre. En mutation der fjernede guarden i AddressBbr ville
 * ingen test have fanget — "helper coverage is not call-site coverage":
 * at mutere hjaelperen beviser at DEN er rigtig, ikke at den BRUGES.
 *
 * 🪤 Og hullet var ikke teoretisk: AddressSkraafoto fik guarden i
 * komponenten, men jeg glemte fejl-grenen i bladen. Den rendrede da et TOMT
 * <div> ved fejl — hele kortet forsvandt sporloest, hvilket laeses som en
 * oedelagt side. Fanget af netop denne test, ikke af laesning.
 */
dataset('address-sektioner', [
    'bbr' => [\TheFountainhead\Metis\Livewire\Sections\AddressBbr::class],
    'ejere' => [\TheFountainhead\Metis\Livewire\Sections\AddressOwners::class],
    'vurdering' => [\TheFountainhead\Metis\Livewire\Sections\AddressValuation::class],
    'pantebreve' => [\TheFountainhead\Metis\Livewire\Sections\AddressMortgages::class],
    'handler' => [\TheFountainhead\Metis\Livewire\Sections\AddressTransactions::class],
    'lignende handler' => [\TheFountainhead\Metis\Livewire\Sections\AddressSimilarTrades::class],
    'markedsanalyse' => [\TheFountainhead\Metis\Livewire\Sections\AddressComparison::class],
    'virksomheder' => [\TheFountainhead\Metis\Livewire\Sections\AddressCompanies::class],
    'lokalplaner' => [\TheFountainhead\Metis\Livewire\Sections\AddressPlanning::class],
    'energimaerke' => [\TheFountainhead\Metis\Livewire\Sections\AddressEnergy::class],
    'fredning' => [\TheFountainhead\Metis\Livewire\Sections\AddressHeritage::class],
    'skraafoto' => [\TheFountainhead\Metis\Livewire\Sections\AddressSkraafoto::class],
]);

it('🚨 siger ALDRIG "ingen data" naar opslaget fejlede', function (string $sektion) {
    Http::fake(['*property/analysis*' => Http::response(['message' => 'Unprocessable'], 422)]);

    // 🪤 EGEN ADRESSE PR. SEKTION. resolveAddressAnalysis cacher paa
    // md5(adresse), saa 12 sektioner mod SAMME query deler cache-post — og
    // beforeEach(Cache::flush()) hjaelper ikke, for forureningen sker INDE i
    // samme test. Foerste koersel cachede fejlen, de elleve naeste laeste den
    // cachede vaerdi og fik et andet resultat end deres eget fake.
    $test = Livewire::test($sektion, ['query' => 'Søndergade '.class_basename($sektion).' 1']);

    // 1) Komponenten skal VIDE at det fejlede.
    expect($test->get('hasError'))->toBeTrue();

    // 2) Og bladen skal SIGE det — et tomt kort er lige saa uaerligt som en
    //    falsk benaegtelse: brugeren kan ikke se forskel paa "fejl" og
    //    "siden er i stykker".
    expect($test->html())->toContain('opslaget lykkedes ikke');

    // 🪤 REVIEW FANDT ET HUL JEG IKKE KUNNE LUKKE HER. Guarden blev flyttet
    //    til EFTER feltudtraekket, og hele suiten var groen (754): begge
    //    assertions ovenfor er sande uanset raekkefoelge — hasError saettes
    //    stadig, og bladen gater paa hasError. Ingen test observerer FELTET.
    //
    //    Jeg proevede at assertere at alle public properties staar paa deres
    //    default efter en fejl. Det virker ikke: AddressSimilarTrades har
    //    `limit`, `radiusKm`, `areaPct`, `monthsBack` — soegeparametre med
    //    defaults der SKAL baere vaerdi, ogsaa ved fejl. En generisk regel kan
    //    ikke skelne "felt fra svaret" fra "indstilling", og en allowlist pr.
    //    sektion ville vaere ren duplikering af koden den tester.
    //
    //    Konsekvensen i praksis er lille: en fejl-payload har ingen
    //    'property'-noegle, saa `?? <default>` giver samme resultat som
    //    guarden. Men kontrakten i MetisSection's docblock ("brug FOER
    //    felterne udtraekkes") er UHAANDHAEVET, og AddressBbr skriver
    //    `$this->address = $analysis['property']['address'] ?? $query`, hvor
    //    raekkefoelgen kunne faa betydning. ⏰ Aaben: metis-package #175.
})->with('address-sektioner');

it('🪤 cacher ALDRIG en malformet STRUKTUR — samme regel som adresse-opslaget', function () {
    // 🚨 REGRESSION FUNDET AF REVIEW. `cacheStructure()` gatede paa
    // `! empty($structure)` — men et fejl-array ER non-empty, saa fejlen blev
    // cachet i 5 minutter. Metodens egen docblock lovede det modsatte.
    //
    // Blev foerst farligt da post() holdt op med at kaste paa misdannede svar:
    // foer kastede en TypeError, saa der var intet at cache. Reglen jeg
    // indfoerte for adresse-opslaget blev altsaa brudt ét hus laengere nede
    // ad gaden i samme PR.
    Cache::flush();

    Http::fakeSequence()
        ->push(['uventet' => 'form'], 200)
        ->push(['data' => ['company' => ['name' => 'RIGTIG A/S']]], 200);

    $api = app(RegistryApi::class);

    expect($api->fetchCompanyStructureCached('99887766'))->toHaveKey('error')
        ->and($api->fetchCompanyStructureCached('99887766'))->not->toHaveKey('error');
});

it('🚨 searchByName() giver fejlen VIDERE — en tom liste er ikke et svar', function () {
    // Regression: `$result['companies'] ?? []` destruerede fejlen
    // uigenkaldeligt. Search.php:576 gater paa isset($result['error']) og saa
    // derfor intet — brugeren fik "ingen resultater" for et opslag der aldrig
    // lykkedes. Samme falske benaegtelse, bare paa selskabssoegningen.
    Http::fake(['*cvr/search-by-name*' => Http::response(['uventet' => 'form'], 200)]);

    expect(app(RegistryApi::class)->searchByName('Malformet Struktur Test ApS'))
        ->toHaveKey('error')
        ->and(app(RegistryApi::class)->searchByName('Malformet Struktur Test ApS'))->not->toBe([]);
});
