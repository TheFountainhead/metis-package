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

function opsamletPdfData(string $type, string $query): array
{
    $opsamlet = [];

    $controller = new class($opsamlet) extends MetisPdfController
    {
        public function __construct(public array &$opsamlet) {}

        // Samme krop som download(), men stopper foer Pdf::view().
        public function opsaml(string $type, string $query): void
        {
            $api = app(RegistryApi::class);
            $data = [];

            if ($type === 'cvr') {
                $data['company'] = rescue(fn () => $api->fetchRolesByCvr([$query]));
                $data['structure'] = rescue(fn () => $api->fetchCompanyStructure($query), []);
                $data['portfolio'] = rescue(fn () => $api->fetchCompanyPropertyPortfolio($query));
                $data['tax'] = rescue(fn () => $api->fetchCompanyTaxRecords($query));
            } elseif ($type === 'cpr') {
                $data['properties'] = rescue(fn () => $api->fetchPropertiesByCpr($query));
                $data['companies'] = rescue(fn () => $api->fetchCompaniesByCpr($query));
            } elseif ($type === 'address') {
                $data['analysis'] = $api->resolveAddressAnalysis($query);
            }

            $this->opsamlet = $data;
        }
    };

    $controller->opsaml($type, $query);

    return $controller->opsamlet;
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
 * 🪤 TESTENS KOPI KAN DRIVE FRA ORIGINALEN.
 *
 * `opsaml()` ovenfor gentager `download()`s dataindsamling for at stoppe foer
 * Browsershot. Hvis nogen aendrer controlleren — fx tilfoejer et felt eller
 * fjerner en `rescue()` — ville testen fortsat vaere groen mod sin egen
 * forældede kopi. Praecis den fejlklasse hele denne sag handler om.
 *
 * Denne test pinner at de to stemmer overens paa de felter der betyder noget.
 */
it('testens kopi matcher controllerens faktiske dataindsamling', function () {
    $kilde = file_get_contents(__DIR__.'/../../src/Http/Controllers/MetisPdfController.php');

    // Felterne controlleren saetter — hvis et nyt kommer til uden at blive
    // afspejlet i opsaml(), fejler denne.
    foreach ([
        "\$data['company'] = rescue(",
        "\$data['structure'] = rescue(",
        "\$data['portfolio'] = rescue(",
        "\$data['tax'] = rescue(",
        "\$data['properties'] = rescue(",
        "\$data['companies'] = rescue(",
        "\$data['analysis'] = \$api->resolveAddressAnalysis(\$query);",
    ] as $linje) {
        expect(str_contains($kilde, $linje))->toBeTrue(
            "MetisPdfController er aendret — opdater opsaml() i denne test: $linje"
        );
    }

    // Og at der ikke er kommet en HELT ny gren til.
    // 🪤 Vagten fandt en aegte mangel: mit foerste udkast talte 5 og
    // sprang HELE cpr-grenen over. Kopien manglede to felter.
    expect(substr_count($kilde, '$data['))->toBe(7);
});
