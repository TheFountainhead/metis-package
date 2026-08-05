<?php

use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\LenderExposure;

/**
 * Baglaens laangiver-opslag i UI'et.
 *
 * 🎯 Draupnir-demoens anden halvdel: "hvor er DENNE laangiver eksponeret".
 * Fremad viser selskabssiden hvem der har laant penge TIL et selskab.
 *
 * 🚨 Kan ikke loeses med en kreditor-soegning: ved et ejerpantebrev er
 * selskabet SELV anfoert som kreditor. Laangiveren staar kun i
 * UnderpantrettighedSamling (offentligt, tinglysningslovens § 1 a, stk. 1).
 */
function fakeExposureResponse(int $totalKr = 70_000_000, bool $truncated = false): array
{
    return [
        'data' => [
            'lender_name' => 'DRAUPNIR INVESTMENT ADVISORS A/S',
            'cvr' => '35050027',
            'documents' => 2,
            'properties' => 2,
            'total_kr' => $totalKr,
            'truncated' => $truncated,
            'rows' => [
                ['address' => 'Akacietorvet 1', 'postal_code' => '4000', 'bfe' => '900100',
                    'amount_kr' => 45_000_000, 'document' => 'doc-a'],
                ['address' => 'Akacietorvet 1', 'postal_code' => '4000', 'bfe' => '900100',
                    'amount_kr' => 15_000_000, 'document' => 'doc-b'],
                ['address' => 'Vestergade 2', 'postal_code' => '8000', 'bfe' => '900200',
                    'amount_kr' => 10_000_000, 'document' => 'doc-c'],
            ],
        ],
        'meta' => [
            'disclaimer' => 'Beloebet er hvad laangiveren staar anfoert for i Tinglysningen. '
                .'En panthaver kan optraede paa vegne af andre kreditorer.',
            'source' => 'Tinglysningen, UnderpantrettighedSamling (§ 1 a, stk. 1)',
        ],
    ];
}

it('viser laangiverens samlede eksponering', function () {
    Http::fake(['*/v1/lender-exposure/*' => Http::response(fakeExposureResponse())]);

    Livewire::test(LenderExposure::class)
        ->set('cvr', '35050027')
        ->call('search')
        ->assertSet('exposure.total_kr', 70_000_000)
        ->assertSet('exposure.documents', 2)
        // Navnet formateres til laesbar form (LegalName), ikke raat versaler.
        ->assertSee('Draupnir Investment Advisors A/S')
        ->assertSee('70.000.000');
});

it('🚨 viser ALTID forbeholdet sammen med beloebet', function () {
    // ⚠️ Summen er hvad panthaveren staar ANFOERT for. De kan optraede paa
    // vegne af andre kreditorer, saa tallet er ikke noedvendigvis egen
    // finansiering. Uden forbeholdet laeses det som en paastand om deres
    // balance — og det kan vi ikke staa inde for.
    Http::fake(['*/v1/lender-exposure/*' => Http::response(fakeExposureResponse())]);

    Livewire::test(LenderExposure::class)
        ->set('cvr', '35050027')
        ->call('search')
        ->assertSee('paa vegne af andre kreditorer')
        ->assertSee('§ 1 a');
});

it('folder flere pantebreve paa SAMME ejendom til én raekke', function () {
    // Raa raekker er pr. (dokument, panthaver), saa Akacietorvet 1 optraeder
    // to gange. For spoergsmaalet "hvilke ejendomme er stillet som sikkerhed"
    // er det uigennemskueligt — de foldes, og beloebene laegges sammen.
    Http::fake(['*/v1/lender-exposure/*' => Http::response(fakeExposureResponse())]);

    $rows = Livewire::test(LenderExposure::class)
        ->set('cvr', '35050027')
        ->call('search')
        ->instance()
        ->byProperty;

    expect($rows)->toHaveCount(2)
        // Sorteret faldende: Akacietorvet (45+15=60 mio.) foer Vestergade (10 mio.).
        ->and($rows[0]['address'])->toBe('Akacietorvet 1')
        ->and($rows[0]['amount_kr'])->toBe(60_000_000)
        ->and($rows[0]['documents'])->toBe(2)
        ->and($rows[1]['amount_kr'])->toBe(10_000_000);
});

it('afviser et CVR der ikke er 8 cifre UDEN at kalde API-et', function () {
    // Formkravet ligger ogsaa i UI'et: et 7-cifret CVR ville ellers give et
    // tomt svar der er umuligt at skelne fra "laangiveren har intet pant".
    Http::fake();

    Livewire::test(LenderExposure::class)
        ->set('cvr', '3505002')
        ->call('search')
        ->assertSet('exposure', null)
        ->assertSee('præcis 8 cifre');

    Http::assertNothingSent();
});

it('renser mellemrum og landekode fra CVR', function () {
    Http::fake(['*/v1/lender-exposure/*' => Http::response(fakeExposureResponse())]);

    Livewire::test(LenderExposure::class)
        ->set('cvr', 'DK 35 05 00 27')
        ->call('search')
        ->assertSet('cvr', '35050027')
        ->assertSet('exposure.total_kr', 70_000_000);
});

it('viser "ingen pant" som et gyldigt svar, ikke en fejl', function () {
    Http::fake(['*/v1/lender-exposure/*' => Http::response([
        'data' => ['lender_name' => null, 'cvr' => '12345678', 'documents' => 0,
            'properties' => 0, 'total_kr' => 0, 'rows' => [], 'truncated' => false],
        'meta' => ['disclaimer' => 'x', 'source' => 'y'],
    ])]);

    Livewire::test(LenderExposure::class)
        ->set('cvr', '12345678')
        ->call('search')
        ->assertSet('errorMessage', null)
        ->assertSee('Ingen tinglyst pant');
});

it('haandterer API-fejl uden at kaste', function () {
    // 🪤 RegistryApi returnerer ['error' => ...] ved BAADE HTTP-fejl og
    // transport-fejl (timeout/DNS). En ConnectionException er en Exception,
    // ikke en HttpClientException — den gik tidligere lige igennem helperne
    // og ramte 15 kaldsteder uhaandteret (Flare 9097433).
    Http::fake(['*/v1/lender-exposure/*' => Http::response(['message' => 'boom'], 500)]);

    Livewire::test(LenderExposure::class)
        ->set('cvr', '35050027')
        ->call('search')
        ->assertSet('exposure', null)
        ->assertSee('Kunne ikke hente data');
});

it('oplyser at listen er afkortet, saa totalen ikke ser forkert ud', function () {
    // Summen er ALTID fuld; kun raekkelisten har et loft. Uden linjen ville de
    // to se ud til at modsige hinanden.
    Http::fake(['*/v1/lender-exposure/*' => Http::response(fakeExposureResponse(70_000_000, true))]);

    Livewire::test(LenderExposure::class)
        ->set('cvr', '35050027')
        ->call('search')
        ->assertSee('Listen er afkortet');
});

it('🚨 REVIEW-FUND: folder IKKE forskellige ejendomme sammen naar BFE mangler', function () {
    // 🚨 Noeglen var `bfe|address` UDEN postnummer. To FORSKELLIGE ejendomme
    // paa samme vejnavn i hver sin by, begge uden BFE, foldede til ÉN raekke
    // med beloebene lagt sammen. Verificeret foer rettelsen: 2 ind -> 1 ud
    // med 20.000.000 kr.
    //
    // I en kreditvurdering er det en forkert paastand om hvilken sikkerhed
    // der findes.
    Http::fake(['*/v1/lender-exposure/*' => Http::response([
        'data' => [
            'lender_name' => 'Test A/S', 'cvr' => '35050027',
            'documents' => 2, 'properties' => 2, 'total_kr' => 20_000_000, 'truncated' => false,
            'rows' => [
                ['address' => 'Hovedgaden 1', 'postal_code' => '4000', 'bfe' => null,
                    'amount_kr' => 10_000_000, 'document' => 'doc-a'],
                ['address' => 'Hovedgaden 1', 'postal_code' => '9000', 'bfe' => null,
                    'amount_kr' => 10_000_000, 'document' => 'doc-b'],
            ],
        ],
        'meta' => ['disclaimer' => 'x', 'source' => 'y'],
    ])]);

    $rows = Livewire::test(LenderExposure::class)
        ->set('cvr', '35050027')->call('search')
        ->instance()->byProperty;

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['amount_kr'])->toBe(10_000_000)
        ->and($rows[1]['amount_kr'])->toBe(10_000_000);
});

it('🚨 REVIEW-FUND: forbeholdet vises OGSAA naar backenden udelader meta', function () {
    // 🚨 Forbeholdet var betinget af `meta.disclaimer`. Uden den rendrede
    // beloebet og hele KPI-blokken UDEN forbehold. Klassens docblock kalder
    // det en invariant — men den var afhaengig af at en anden tjeneste
    // samarbejdede, og backendens form er endnu ikke frosset.
    Http::fake(['*/v1/lender-exposure/*' => Http::response([
        'data' => [
            'lender_name' => 'Test A/S', 'cvr' => '35050027',
            'documents' => 1, 'properties' => 1, 'total_kr' => 5_000_000, 'truncated' => false,
            'rows' => [['address' => 'Vej 1', 'postal_code' => '4000', 'bfe' => '900100',
                'amount_kr' => 5_000_000, 'document' => 'doc-a']],
        ],
        // INGEN meta.
    ])]);

    Livewire::test(LenderExposure::class)
        ->set('cvr', '35050027')->call('search')
        ->assertSee('5.000.000')
        ->assertSee('på vegne af andre kreditorer');
});

it('🚨 REVIEW-FUND: wire:key er indholds-baseret, ikke positionel', function () {
    // 🚨 Noeglen var `exposure-{{ bfe ?? loop->index }}`, altsaa POSITIONEL
    // naar BFE mangler. To problemer:
    //
    //   1. Kollision: en raekke UDEN bfe paa plads 0 fik `exposure-0`; en
    //      raekke MED bfe='0' ville faa det samme.
    //      (🪤 Reviewet paastod at bfe='0' ALENE kolliderede. Det er forkert:
    //      `??` reagerer paa null, ikke falsy, saa '0' bruges som noegle.
    //      Verificeret. Kollisionen kraever at bfe er NULL paa plads 0.)
    //   2. Vaerre — den var IDENTISK paa tvaers af soegninger: laangiver A og
    //      B gav begge [exposure-0, exposure-1] for helt forskellige
    //      ejendomme, saa morph genbrugte DOM-raekkerne.
    //
    // Livewire::test() koerer ingen morph, saa vi asserter paa den renderede
    // HTML: noeglerne skal vaere unikke OG afhaenge af indholdet.
    Http::fake(['*/v1/lender-exposure/*' => Http::response([
        'data' => [
            'lender_name' => 'Test A/S', 'cvr' => '35050027',
            'documents' => 2, 'properties' => 2, 'total_kr' => 2_000_000, 'truncated' => false,
            'rows' => [
                // Uden bfe paa plads 0 => gammel noegle blev 'exposure-0'.
                ['address' => 'Nulvej 1', 'postal_code' => '4000', 'bfe' => null,
                    'amount_kr' => 1_000_000, 'document' => 'doc-a'],
                // ...og denne har bfe='0' => ogsaa 'exposure-0'. Kollision.
                ['address' => 'Etvej 2', 'postal_code' => '5000', 'bfe' => '0',
                    'amount_kr' => 1_000_000, 'document' => 'doc-b'],
            ],
        ],
        'meta' => ['disclaimer' => 'x', 'source' => 'y'],
    ])]);

    $html = Livewire::test(LenderExposure::class)
        ->set('cvr', '35050027')->call('search')->html();

    preg_match_all('/wire:key="(exposure-[^"]+)"/', $html, $m);

    expect($m[1])->toHaveCount(2)
        ->and(array_unique($m[1]))->toHaveCount(2);
});
