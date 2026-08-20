<?php

use Illuminate\Support\Facades\Http;
use TheFountainhead\Metis\Http\Controllers\MetisPdfController;
use TheFountainhead\Metis\Services\RegistryApi;

/*
 * 🚨 CONTROLLEREN HAVDE NUL DAEKNING — og to tidligere forsoeg var FALSK GROENNE.
 *
 * Forsoeg 1 kaldte `resolveAddressAnalysis()` direkte og roerte aldrig
 * controlleren: en mutation der fik den til at kaste i sin constructor lod
 * testen vaere groen.
 *
 * Forsoeg 2 gik gennem ruten — men `tests/TestCase.php:35` loader kun
 * `routes/web.php`, og PDF-ruten findes KUN i `routes/embedded.php`. Ruten
 * fandtes altsaa ikke i testen, requesten gav 500, og `assertNotSent` blev
 * trivielt sand: INTET naaede at koere.
 *
 * 🔑 Vi tester nu controllerens DATAINDSAMLING direkte. Selve PDF-genereringen
 * kraever Browsershot/Puppeteer, som ikke koerer i CI — men det er ogsaa ikke
 * det interessante: det farlige var HVILKE DATA der naaede viewet, og om et
 * fejlet opslag udloeste et kald.
 *
 * 🪤 `download()` returnerer et Pdf-objekt hvis rendering fejler i CI. Vi
 * kalder derfor gennem en subclass der fanger `$data` foer renderingen —
 * ikke gennem HTTP, hvor Browsershot ville doe foer assertionen.
 */

/*
 * 🚨 KALDER CONTROLLERENS EGEN METODE — ingen kopi.
 *
 * Foerste udkast duplikerede `download()`s krop i en subclass for at stoppe
 * foer Browsershot, plus en "vagt paa vagten" der tjekkede at kopien matchede
 * originalen paa tekst-niveau.
 *
 * 🪤 Review defeatede den: en mutation af `if ($type === 'cvr')` til
 * `'XXcvr'` overlevede HELE suiten (836/836 groenne), fordi tekst-vagten
 * ikke saa forskellen — og hver CVR-PDF ville da vaere tom.
 *
 * ⇒ `gatherData()` er nu ekstraheret paa controlleren, saa testen kalder den
 * RIGTIGE kode. En tekst-proxy kan ikke erstatte at koere tingen selv.
 */
function opsamletPdfData(string $type, string $query): array
{
    return app(MetisPdfController::class)->gatherData($type, $query);
}

it('🚨 udloeser INTET opslag for en adresse uden postnummer', function () {
    Http::fake(['*' => Http::response(['data' => ['property' => ['bbr' => ['total_area' => 1]]]], 200)]);

    $data = opsamletPdfData('address', 'Søndergade 43A');

    // SELVE TINGEN: kaldet maa ikke ske.
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/v1/property/analysis'));
    expect($data['analysis'])->toBe(['error' => 'address_ambiguous', 'status' => 422]);
});

it('udloeser opslaget for en KOMPLET adresse', function () {
    Http::fake(['*' => Http::response(['data' => ['property' => ['bbr' => ['total_area' => 140]]]], 200)]);

    $data = opsamletPdfData('address', 'Søndergade 43A, 4653');

    // Positiv kontrol: beviser at guarden blev EVALUERET og gav lov.
    Http::assertSent(fn ($r) => str_contains($r->url(), '/v1/property/analysis'));
    expect($data['analysis']['property']['bbr']['total_area'])->toBe(140);
});

it('🚨 renderer PDF-viewet med controllerens EGNE data — fejl-tilstand', function () {
    Http::fake(['*' => Http::response(['data' => ['property' => []]], 200)]);

    $data = opsamletPdfData('address', 'Søndergade 43A');

    // Foer denne PR gav dette et TAVST dokument. Nu siger det hvad der skete.
    $html = view('metis::livewire.pdf', [
        'type' => 'address', 'query' => 'Søndergade 43A', 'data' => $data,
    ])->render();

    expect(strip_tags($html))->toContain('Opslaget kunne ikke udføres');
});

it('🚨 en CVR-fejl bliver til rescue()-null og renderer IKKE en falsk benaegtelse', function () {
    // 🪤 rescue() svaelger exceptions til null — netop den form der foer gav
    // "No roles found." som en POSITIV paastand om selskabet.
    Http::fake(fn () => throw new RuntimeException('upstream nede'));

    $data = opsamletPdfData('cvr', '12345678');

    expect($data['company'])->toBeNull();

    $html = view('metis::livewire.pdf', [
        'type' => 'cvr', 'query' => '12345678', 'data' => $data,
    ])->render();

    $tekst = strip_tags($html);
    expect($tekst)->toContain('Opslaget kunne ikke udføres')
        ->and($tekst)->not->toContain('No roles found');
});

/*
 * 🚨 REVIEW-FUND: kun `company` var daekket.
 *
 * Maalt: en mutation hvor `portfolio` kaldte `fetchCompanyTaxRecords()` i
 * stedet for `fetchCompanyPropertyPortfolio()` overlevede — PDF'en ville da
 * vise skattetal under "ejendomsportefoelje". Tre af fire CVR-felter og
 * begge CPR-felter var utestede.
 *
 * Vi pinner nu HVILKET endpoint hvert felt henter fra.
 */
it('henter hvert CVR-felt fra sit EGET endpoint', function () {
    Http::fake([
        '*roles*' => Http::response(['data' => ['companies' => [['name' => 'RolleFirma']]]], 200),
        '*structure*' => Http::response(['data' => ['struktur' => 'S']], 200),
        '*portfolio*' => Http::response(['data' => ['portfolio' => ['P']]], 200),
        '*tax*' => Http::response(['data' => ['records' => [['income_year' => 2024]]]], 200),
        '*' => Http::response(['data' => []], 200),
    ]);

    $data = opsamletPdfData('cvr', '12345678');

    expect($data['company']['companies'][0]['name'])->toBe('RolleFirma')
        ->and($data['portfolio']['portfolio'])->toBe(['P'])
        ->and($data['tax']['records'][0]['income_year'])->toBe(2024);

    // 🪤 `assertSent` betyder MINDST én gang, ikke praecis én. Kommentaren
    // paastod kardinalitet, koden maalte eksistens: maalt overlevede en
    // mutation med TRE portfolio-kald testen. Vi taeller nu.
    $kald = [];
    Http::assertSent(function ($r) use (&$kald) {
        foreach (['roles', 'structure', 'portfolio', 'tax'] as $e) {
            if (str_contains($r->url(), $e)) {
                $kald[$e] = ($kald[$e] ?? 0) + 1;
            }
        }

        return true;
    });

    expect($kald)->toBe(['roles' => 1, 'structure' => 1, 'portfolio' => 1, 'tax' => 1]);
});

it('henter begge CPR-felter fra hver sit endpoint', function () {
    // 🪤 Begge stier indeholder 'search-by-cpr', saa et moenster paa
    // '*properties*' ramte den forkerte. Pin paa den FULDE sti.
    Http::fake([
        '*property-tinglysning/search-by-cpr*' => Http::response(['data' => ['properties' => [['id' => 1]]]], 200),
        '*cvr/search-by-cpr*' => Http::response(['data' => ['companies' => [['cvr' => '111']]]], 200),
        '*' => Http::response(['data' => []], 200),
    ]);

    $data = opsamletPdfData('cpr', '1234567890');

    expect($data['properties']['properties'][0]['id'])->toBe(1)
        ->and($data['companies']['companies'][0]['cvr'])->toBe('111');
});
