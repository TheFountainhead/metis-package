<?php

use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\Sections\AddressBbr;
use TheFountainhead\Metis\Services\RegistryApi;

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

/*
 * 🔴 MetisPdfController er IKKE testet — og det skal staa her, ikke skjules.
 *
 * To udkast paastod at daekke den, begge falsk groenne:
 *   1. kaldte `resolveAddressAnalysis()` direkte (byte-identisk med testen
 *      ovenfor). Mutation: controlleren kaster i sin constructor => GROEN.
 *   2. gik gennem ruten. Men `tests/TestCase.php:37` loader ruterne, og
 *      opslaget svarer 500 fordi Browsershot/Puppeteer ikke koerer i CI.
 *      `assertNotSent` blev derfor trivielt sand: INTET naaede at koere.
 *      Mutation: controlleren kaster => stadig GROEN.
 *
 * ⇒ Controlleren har NUL daekning. Guarden i `fetchProperty()` og
 * `resolveAddressAnalysis()` beskytter den kodesti — det er pinnet af
 * `naar ingen af de kendte indgange ud...` ovenfor, som kalder de samme
 * metoder controlleren kalder. Men at PDF'en OPFOERER sig rigtigt er
 * utestet, og PDF'en kan fortsat ikke UDTRYKKE en fejl: 422, aegte tom og
 * ingen-data giver byte-identisk dokument uden ét udsagn om ejendommen.
 *
 * At skrive en test der ikke kan fejle ville vaere vaerre end ingen test.
 * Sagen er noteret i PR-beskrivelsen som aabent punkt.
 */



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
        // 🪤 ÉT argument. `toHaveKey('error', $besked)` laeser andet argument
        // som forventet VAERDI, ikke som besked — assertionen blev "ingen
        // error-noegle hvis vaerdi er lig den danske tekst", altsaa trivielt
        // sand. Maalt: en mutation der afviste 2 af 3 adresser forblev groen.
        // Samme Pest-faelde som kommentaren om `toContain` ovenfor advarer mod;
        // jeg gentog den ti linjer laengere nede.
        expect($api->resolveAddressAnalysis($adresse))->not->toHaveKey('error');
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
 * 🚨 EN VAGT PAA VAGTEN — MEN EN DER MAALER ADFAERD, IKKE TEKST.
 *
 * Foerste udkast var to kildekode-scannere: "der skal staa
 * `address_ambiguous` inden for 15 linjer af hvert post()" og "kun
 * RegistryApi maa naevne endpointet". Begge var falsk groenne. Maalt:
 *
 *   - guard-body slettet, kun ordet tilbage i en KOMMENTAR  => GROEN 🚨
 *   - guard intakt, 16 kommentarlinjer indsat foran         => ROED 🚨
 *   - ny fil med `self::BASE.'/analysis'` (streng delt op)  => GROEN 🚨
 *
 * Forkert i BEGGE retninger: groen naar guarden er vaek, roed naar koden er
 * rigtig. Og en grep-baseret vagt mod doer 16 gentager praecis den
 * maalefejl der skjulte doer 15.
 *
 * ⇒ Vi pinner i stedet ADFAERDEN paa hver kendt indgang. En ny doer fanges
 * ikke af en scanner, men af at nogen skriver en test for den — og af at
 * `fetchProperty()` er det eneste sted der poster.
 */
it('naar ingen af de kendte indgange ud med en uoploeselig adresse', function () {
    $ufuldstaendig = 'Søndergade 43A';

    $indgange = [
        'resolveAddressAnalysis' => fn () => app(RegistryApi::class)->resolveAddressAnalysis($ufuldstaendig),
        'fetchPropertyByAddress' => fn () => app(RegistryApi::class)->fetchPropertyByAddress($ufuldstaendig),
        'fetchProperty' => fn () => app(RegistryApi::class)->fetchProperty(
            app(RegistryApi::class)->parseAddress($ufuldstaendig)
        ),
    ];

    foreach ($indgange as $navn => $kald) {
        Http::fake(['*' => Http::response(['data' => ['property' => []]], 200)]);

        $svar = $kald();

        expect($svar)->toHaveKey('error');
        expect($svar['error'])->toBe('address_ambiguous');
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/v1/property/analysis'));
    }
});

/*
 * Den ENE strukturelle invariant der er vaerd at beholde: ingen anden fil
 * end RegistryApi maa poste til endpointet. Den kan stadig omgaas af en delt
 * streng — men den maaler et reelt arkitektur-krav, ikke naerhed af tekst,
 * og en overtraedelse er en bevidst handling frem for et uheld.
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

/*
 * 🚨 DEN FJERDE GUARD HAVDE NUL DAEKNING.
 *
 * `resolvePropertyComparison()` rammer et ANDET endpoint (`property/compare`)
 * og havde sin egen haandrullede kopi af reglen. Maalt af review: guarden
 * kunne SLETTES HELT uden at én af 804 tests blev roed — en guard ingen test
 * bevogter er bare en kommentar.
 *
 * Den er i praksis uopnaaelig i dag, fordi `AddressComparison::mount()` kalder
 * `resolveAddressAnalysis()` foerst og returnerer ved fejl. Men den er
 * beskyttet af en RAEKKEFOELGE i en kalder, ikke af sin egen kontrakt —
 * praecis saadan doer 15 og 16 opstod.
 */
it('naar ikke ud til property/compare med en uoploeselig adresse', function () {
    Http::fake(['*' => Http::response(['data' => ['comparison' => []]], 200)]);

    expect(app(RegistryApi::class)->resolvePropertyComparison('Søndergade 43A'))->toBeNull();

    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'property/compare'));
});

it('naar UD til property/compare med en komplet adresse', function () {
    Http::fake(['*' => Http::response(['data' => ['snit' => 42]], 200)]);

    // Positiv kontrol: beviser at guarden blev EVALUERET og gav lov.
    expect(app(RegistryApi::class)->resolvePropertyComparison('Søndergade 43A, 4653'))
        ->toBe(['snit' => 42]);

    Http::assertSent(fn ($r) => str_contains($r->url(), 'property/compare'));
});
