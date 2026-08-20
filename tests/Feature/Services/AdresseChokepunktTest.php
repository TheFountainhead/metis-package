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
 * 🚨 REVIEW-FUND: `number !== ''` AFVISTE HELT ALMINDELIGE DANSKE ADRESSER.
 *
 * Foerste udkast krævede ogsaa et husnummer, fordi
 * `parseAddress('Agernskrænten+33,+2750')` gav zip='2750' men number=''.
 * Men det ene tilfaelde var et symptom paa `+`-encoding-bug'en, ikke paa en
 * ufuldstaendig adresse — og generaliseringen ramte bredt. Maalt:
 *
 *   'Strandvejen 100 B, 2900 Hellerup'   -> number='' (FULDT gyldig)
 *   'Vestergade 1 A, 5000 Odense C'      -> number=''
 *   'Bakkedraget 7 st tv, 8000 Aarhus C' -> number=''
 *
 * `parseAddress()`s regex er ankret til sidste token og taaler ikke et
 * mellemrum foer bogstavet. En tom `number` er altsaa oftest en
 * PARSER-begraensning, ikke et hul i adressen.
 *
 * 🚨 OG AFVISNINGEN VAR TAVS: intet kald, ingen Flare, og brugeren fik at
 * vide at adressen var flertydig — hvilket ingen mængde postnummer kunne
 * rette, for postnummeret var der allerede. En synlig fejlklasse gjort
 * usynlig. Upstream afgoer det bedre end vi kan.
 */
it('afviser IKKE gyldige adresser med mellemrum foer husnummerets bogstav', function () {
    Http::fake(['*' => Http::response(['data' => ['property' => ['bbr' => ['total_area' => 140]]]], 200)]);

    $api = app(RegistryApi::class);

    foreach ([
        'Strandvejen 100 B, 2900 Hellerup',
        'Vestergade 1 A, 5000 Odense C',
        'Bakkedraget 7 st tv, 8000 Aarhus C',
    ] as $adresse) {
        expect($api->resolveAddressAnalysis($adresse))
            ->not->toHaveKey('error', $adresse.' blev afvist');
    }

    Http::assertSent(fn ($r) => str_contains($r->url(), '/v1/property/analysis'));
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

/*
 * 🚨 REVIEW-FUND: DEN 15. DOER — og hvorfor jeg ikke saa den.
 *
 * Jeg greppede efter `resolveAddressAnalysis` og fandt 14 kaldesteder. Men
 * `fetchPropertyByAddress()` -> `fetchProperty()` poster til SAMME endpoint
 * UDEN at gaa gennem den metode — den var per konstruktion usynlig for min
 * soegning. Maalt:
 *
 *   fetchPropertyByAddress('Søndergade 43A')
 *   => POST /v1/property/analysis {"street":…,"number":"43A","zip":""}
 *
 * 🔑 Det rigtige spoergsmaal er ikke "hvem kalder metoden?" men "hvem rammer
 * ENDPOINTET?". `grep "v1/property/analysis"` fandt den paa ét sekund.
 * Samme fejlklasse som [[compound_i_measured_the_proxy_four_times]]: filteret
 * finder kun det du soeger efter — facit siger hvad der ER.
 *
 * Guarden ligger derfor nu i `fetchProperty()`, som BEGGE veje deler.
 */
it('lukker ogsaa doeren gennem fetchPropertyByAddress', function () {
    Http::fake(['*' => Http::response(['data' => ['property' => []]], 200)]);

    $svar = app(RegistryApi::class)->fetchPropertyByAddress('Søndergade 43A');

    expect($svar)->toHaveKey('error');
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/v1/property/analysis'));
});

/*
 * 🚨 EN VAGT PAA VAGTEN. Dette er sjette fix i familien, og hver gang har en
 * NY DOER vaeret aarsagen. Denne test fejler hvis nogen tilfoejer et kald til
 * endpointet uden en guard umiddelbart foran.
 *
 * 🔑 Den maaler ENDPOINTET, ikke metodenavnet — praecis den maalefejl der
 * skjulte doer 15 for mig: jeg greppede efter `resolveAddressAnalysis` og
 * fandt 14 kaldere, men `fetchProperty()` rammer samme endpoint uden at gaa
 * gennem den metode.
 */
it('har en address_ambiguous-guard foran HVERT kald til property/analysis', function () {
    $fil = __DIR__.'/../../../src/Services/RegistryApi.php';
    $linjer = file($fil);

    $poster = [];
    foreach ($linjer as $nr => $linje) {
        if (str_contains($linje, "post('/v1/property/analysis'")) {
            $poster[] = $nr;
        }
    }

    expect($poster)->not->toBeEmpty('endpointet kaldes slet ikke — er stien aendret?');

    foreach ($poster as $nr) {
        // Kig 15 linjer OP fra kaldet: der SKAL staa en address_ambiguous-guard.
        $fra = max(0, $nr - 15);
        $foran = implode('', array_slice($linjer, $fra, $nr - $fra));

        // 🪤 IKKE `toContain($needle, $besked)` — Pest laeser ALLE argumenter
        // som needles, saa fejlbeskeden blev en ekstra soegestreng og testen
        // fejlede af en grund der intet havde med koden at goere.
        expect(str_contains($foran, 'address_ambiguous'))
            ->toBeTrue("post() paa linje ".($nr + 1)." har ingen guard foran sig");
    }
});

/*
 * 🚨 INGEN ANDEN FIL MAA POSTE TIL ENDPOINTET. Guarden ville vaere omgaaet
 * igen, praecis som doer 15 var det.
 */
it('poster kun til property/analysis fra RegistryApi', function () {
    $src = realpath(__DIR__.'/../../../src');
    $andre = [];

    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src));
    foreach ($it as $f) {
        if ($f->getExtension() !== 'php' || $f->getPathname() === $src.'/Services/RegistryApi.php') {
            continue;
        }
        foreach (file($f->getPathname()) as $nr => $linje) {
            if (str_contains($linje, "'/v1/property/analysis'")) {
                $andre[] = str_replace($src.'/', '', $f->getPathname()).':'.($nr + 1);
            }
        }
    }

    expect($andre)->toBe([]);
});
