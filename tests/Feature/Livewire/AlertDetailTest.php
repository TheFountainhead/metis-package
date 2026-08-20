<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\AlertDetail;

beforeEach(function () {
    session(['metis_user_token' => '13|fake-token-for-test']);
});

it('mounts with alert id and fetches alert via getAlert', function () {
    Http::fake([
        '*/v1/alerts/123' => Http::response([
            'data' => [
                'id' => 123,
                'title' => 'Nyt pantebrev tinglyst',
                'description' => 'Et nyt pantebrev er tinglyst på Bredgade 40, 1260 København K',
                'is_read' => false,
                'priority' => 'low',
                'created_at' => '2026-05-04T10:00:00Z',
                'metadata' => [
                    'bfe' => '12345',
                    'mortgage_id' => 'M-001',
                    'change_type' => 'new',
                    'priority' => 'low',
                    'before' => null,
                    'after' => [
                        'principal_amount' => 250000000, // 2.500.000 kr (in ører)
                        'interest_rate' => 4.5,
                        'creditor' => 'Nordea Kredit',
                        'mortgage_type' => 'realkredit',
                        'is_active' => true,
                    ],
                    'property_address' => 'Bredgade 40, 1260 København K',
                ],
                'watchlist' => [
                    'id' => 1,
                    'watch_type' => 'property',
                    'watch_value' => '12345',
                    'display_label' => 'Bredgade 40',
                ],
            ],
        ], 200),
    ]);

    Livewire::test(AlertDetail::class, ['id' => 123])
        ->assertSet('alertId', 123)
        ->assertSet('error', null)
        ->assertSet('loading', false)
        ->assertSee('Nyt pantebrev tinglyst')
        ->assertSee('Bredgade 40');
});

it('shows error when alert is not found (404)', function () {
    Http::fake([
        '*/v1/alerts/999' => Http::response(['message' => 'Not found'], 404),
    ]);

    Livewire::test(AlertDetail::class, ['id' => 999])
        ->assertSet('alert', null)
        ->assertSee('Alert ikke fundet eller ingen adgang.');
});

it('renders 2-column diff for principal_change', function () {
    Http::fake([
        '*/v1/alerts/200' => Http::response([
            'data' => [
                'id' => 200,
                'title' => 'Hovedstol ændret',
                'description' => 'Hovedstol ændret fra 2.000.000 til 2.500.000',
                'is_read' => false,
                'priority' => 'low',
                'created_at' => '2026-05-04T10:00:00Z',
                'metadata' => [
                    'bfe' => '12345',
                    'mortgage_id' => 'M-001',
                    'change_type' => 'principal_change',
                    'priority' => 'low',
                    'before' => [
                        'principal_amount' => 200000000,
                        'interest_rate' => 4.0,
                        'creditor' => 'Nordea Kredit',
                        'mortgage_type' => 'realkredit',
                        'is_active' => true,
                    ],
                    'after' => [
                        'principal_amount' => 250000000,
                        'interest_rate' => 4.0,
                        'creditor' => 'Nordea Kredit',
                        'mortgage_type' => 'realkredit',
                        'is_active' => true,
                    ],
                ],
                'watchlist' => ['id' => 1, 'watch_type' => 'property', 'watch_value' => '12345'],
            ],
        ], 200),
    ]);

    Livewire::test(AlertDetail::class, ['id' => 200])
        ->assertSet('error', null)
        ->assertSee('Hovedstol ændret')
        ->assertSee('Hovedstol')
        ->assertSee('Kreditor')
        ->assertSee('2.000.000') // before
        ->assertSee('2.500.000'); // after
});

it('shows only after-column for change_type=new', function () {
    Http::fake([
        '*/v1/alerts/300' => Http::response([
            'data' => [
                'id' => 300,
                'title' => 'Nyt pantebrev',
                'description' => 'Nyt pantebrev tinglyst',
                'is_read' => false,
                'priority' => 'low',
                'created_at' => '2026-05-04T10:00:00Z',
                'metadata' => [
                    'bfe' => '12345',
                    'mortgage_id' => 'M-002',
                    'change_type' => 'new',
                    'priority' => 'low',
                    'before' => null,
                    'after' => [
                        'principal_amount' => 100000000,
                        'interest_rate' => 5.0,
                        'creditor' => 'Realkredit Danmark',
                        'mortgage_type' => 'realkredit',
                        'is_active' => true,
                    ],
                ],
                'watchlist' => ['id' => 1, 'watch_type' => 'property', 'watch_value' => '12345'],
            ],
        ], 200),
    ]);

    Livewire::test(AlertDetail::class, ['id' => 300])
        ->assertSee('Ny tilstand')
        ->assertSee('1.000.000')
        ->assertSee('Realkredit Danmark');
});

it('shows only before-column for change_type=removed', function () {
    Http::fake([
        '*/v1/alerts/400' => Http::response([
            'data' => [
                'id' => 400,
                'title' => 'Pantebrev fjernet',
                'description' => 'Pantebrev fjernet',
                'is_read' => false,
                'priority' => 'low',
                'created_at' => '2026-05-04T10:00:00Z',
                'metadata' => [
                    'bfe' => '12345',
                    'mortgage_id' => 'M-003',
                    'change_type' => 'removed',
                    'priority' => 'low',
                    'before' => [
                        'principal_amount' => 50000000,
                        'interest_rate' => 3.5,
                        'creditor' => 'Jyske Realkredit',
                        'mortgage_type' => 'realkredit',
                        'is_active' => true,
                    ],
                    'after' => null,
                ],
                'watchlist' => ['id' => 1, 'watch_type' => 'property', 'watch_value' => '12345'],
            ],
        ], 200),
    ]);

    Livewire::test(AlertDetail::class, ['id' => 400])
        ->assertSee('Fjernet')
        ->assertSee('500.000')
        ->assertSee('Jyske Realkredit');
});

it('renders high-priority badge for new_lien (udlæg)', function () {
    Http::fake([
        '*/v1/alerts/500' => Http::response([
            'data' => [
                'id' => 500,
                'title' => 'Nyt udlæg tinglyst',
                'description' => 'Et nyt udlæg er tinglyst',
                'is_read' => false,
                'priority' => 'high',
                'created_at' => '2026-05-04T10:00:00Z',
                'metadata' => [
                    'bfe' => '12345',
                    'mortgage_id' => 'M-004',
                    'change_type' => 'new_lien',
                    'priority' => 'high',
                    'before' => null,
                    'after' => [
                        'principal_amount' => 30000000,
                        'interest_rate' => 0,
                        'creditor' => 'SKAT',
                        'mortgage_type' => 'lien',
                        'is_active' => true,
                    ],
                ],
                'watchlist' => ['id' => 1, 'watch_type' => 'property', 'watch_value' => '12345'],
            ],
        ], 200),
    ]);

    Livewire::test(AlertDetail::class, ['id' => 500])
        ->assertSee('Nyt udlæg (høj prioritet)');
});

it('markRead calls API and refetches alert', function () {
    Http::fake([
        '*/v1/alerts/123' => Http::response([
            'data' => [
                'id' => 123,
                'title' => 'Test',
                'description' => 'Test alert',
                'is_read' => false,
                'priority' => 'low',
                'created_at' => '2026-05-04T10:00:00Z',
                'metadata' => ['bfe' => '12345', 'change_type' => 'new', 'before' => null, 'after' => null],
                'watchlist' => ['id' => 1, 'watch_type' => 'property', 'watch_value' => '12345'],
            ],
        ], 200),
        '*/v1/alerts/123/read' => Http::response(['data' => ['id' => 123, 'is_read' => true]], 200),
    ]);

    Livewire::test(AlertDetail::class, ['id' => 123])
        ->call('markRead');

    Http::assertSent(fn ($req) =>
        str_contains($req->url(), '/v1/alerts/123/read') && $req->method() === 'PATCH'
    );
});

/*
 * Live observer-path schema (DetectMortgageChange::safeMetadata).
 *
 * Production alerts come from the MortgageObserver → DetectMortgageChange job,
 * which writes a DIFFERENT metadata shape than the snapshot-path tests above:
 *   - key `change_kind` (new|amount_changed|rate_changed|paid_off), not `change_type`
 *   - flat `address`, `creditor`, `principal_amount_kr` (KRONER), `mortgage_type`,
 *     `registered_date` — no `before`/`after` objects, no `property_address`
 * The detail view must render these correctly, not collapse them to "new".
 */

/** Build a realistic observer-path alert payload as returned by GET /v1/alerts/{id}. */
function observerAlert(array $overrides = []): array
{
    return array_replace_recursive([
        'id' => 700,
        'title' => 'Rente ændret: Bredgade 40, 1260 København K',
        'description' => 'Ny rente: 5% (var 4%).',
        'is_read' => false,
        'priority' => 'low',
        'created_at' => '2026-06-21T10:00:00Z',
        'metadata' => [
            'mortgage_id' => 9001,
            'property_id' => 555,
            'address' => 'Bredgade 40, 1260 København K',
            'postal_code' => '1260',
            'mortgage_type' => 'realkreditpantebrev',
            'change_kind' => 'rate_changed',
            'principal_amount_kr' => 2500000,
            'interest_rate' => 5.0,
            'creditor' => 'Nordea Kredit',
            'registered_date' => '2026-06-20',
        ],
        'watchlist' => [
            'id' => 1, 'watch_type' => 'property', 'watch_value' => '555',
            'display_label' => 'Bredgade 40',
        ],
    ], $overrides);
}

it('renders a live rate_changed alert with correct label, address and creditor', function () {
    Http::fake(['*/v1/alerts/700' => Http::response(['data' => observerAlert()], 200)]);

    Livewire::test(AlertDetail::class, ['id' => 700])
        ->assertSet('error', null)
        ->assertDontSee('Ukendt ejendom')   // address lives in metadata.address
        ->assertDontSee('Nyt pantebrev')    // it's a rate change, not a new mortgage
        ->assertSee('Rente ændret')
        ->assertSee('Nordea Kredit')        // flat metadata.creditor
        ->assertSee('5,00');                // interest_rate now surfaced in facts panel
});

it('renders a live amount_changed alert with hovedstol and creditor', function () {
    Http::fake(['*/v1/alerts/701' => Http::response(['data' => observerAlert([
        'id' => 701,
        'title' => 'Hovedstol ændret: Bredgade 40, 1260 København K',
        'description' => 'Ny hovedstol: 2.500.000 kr (var 2.000.000 kr).',
        'metadata' => ['change_kind' => 'amount_changed'],
    ])], 200)]);

    Livewire::test(AlertDetail::class, ['id' => 701])
        ->assertSet('error', null)
        ->assertDontSee('Ukendt ejendom')
        ->assertDontSee('Nyt pantebrev')
        ->assertSee('Hovedstol ændret')
        ->assertSee('Nordea Kredit')
        ->assertSee('2.500.000');           // principal_amount_kr rendered as kr
});

it('renders a live new udlæg as high-priority new_lien', function () {
    Http::fake(['*/v1/alerts/702' => Http::response(['data' => observerAlert([
        'id' => 702,
        'title' => 'Nyt udlaeg: Bredgade 40, 1260 København K',
        'description' => 'Hovedstol: 30.000 kr. Kreditor: SKAT.',
        'priority' => 'high',
        'metadata' => ['change_kind' => 'new', 'mortgage_type' => 'udlaeg', 'creditor' => 'SKAT'],
    ])], 200)]);

    Livewire::test(AlertDetail::class, ['id' => 702])
        ->assertSet('error', null)
        ->assertSee('Nyt udlæg (høj prioritet)')
        ->assertSee('SKAT');
});

it('renders a live paid_off alert as removed, not new', function () {
    Http::fake(['*/v1/alerts/703' => Http::response(['data' => observerAlert([
        'id' => 703,
        'title' => 'Pantebrev afløst: Bredgade 40, 1260 København K',
        'description' => 'Pantebrev er afløst (is_active=false).',
        'metadata' => ['change_kind' => 'paid_off'],
    ])], 200)]);

    Livewire::test(AlertDetail::class, ['id' => 703])
        ->assertSet('error', null)
        ->assertDontSee('Ukendt ejendom')
        ->assertDontSee('Nyt pantebrev')
        ->assertSee('Pantebrev fjernet');
});

it('does not masquerade an unknown change_kind as a new mortgage', function () {
    // A future producer kind the view does not know about must fail visibly —
    // show title/description, never fabricate a confident "Nyt pantebrev" panel.
    Http::fake(['*/v1/alerts/704' => Http::response(['data' => observerAlert([
        'id' => 704,
        'title' => 'Kreditor skiftet: Bredgade 40, 1260 København K',
        'description' => 'Kreditor skiftet fra Nordea Kredit til Jyske Realkredit.',
        'metadata' => ['change_kind' => 'creditor_swapped'],
    ])], 200)]);

    Livewire::test(AlertDetail::class, ['id' => 704])
        ->assertSet('error', null)
        ->assertDontSee('Nyt pantebrev')
        ->assertDontSee('Ny tilstand')
        ->assertSee('Kreditor skiftet fra Nordea Kredit'); // description still surfaced
});

/**
 * Traekker "Se ejendom"-linkets adresse ud og AFKODER den.
 *
 * 🔑 Asserter paa den streng modtageren faktisk faar, ikke paa én bestemt
 * stavemaade af encodingen: route() lader ',' staa (lovligt sub-delim i
 * RFC 3986), mens rawurlencode() skriver '%2C'. Begge afkoder ens, saa en
 * assertion paa raa-formen ville vaere skrøbelig — og i mit foerste udkast
 * faldt den, selv om koden var rigtig.
 */
function opslagsadresseFraLink(string $html): ?string
{
    if (! preg_match('#href="[^"]*/lookup/address/([^"]*)"#', $html, $m)) {
        return null;
    }

    return rawurldecode($m[1]);
}

/*
 * 🚨 "Se ejendom" sendte adressen UDEN postnummer — og ramte 422.
 *
 * Flare #9104992 (117 forekomster, prod). Linket byggede
 * `/lookup/address/{{ urlencode($address) }}` af metadata.address alene.
 *
 * Maalt paa prod 20/8 (registry-api, `properties`, 2.680.226 raekker med
 * adresse): kolonnen `address` er GADELINJEN alene — postnummeret ligger i
 * soesterkolonnen `postal_code`. Kun 278 raekker (0,01%) indeholder
 * overhovedet fire cifre, og de er husnumre. Alert-metadataen baerer
 * `postal_code` med (DetectMortgageChange::safeMetadata), men bladen laeste
 * den aldrig.
 *
 * Uden postnummer kan adressen ikke opløses til én matrikel — registry-api
 * svarer "The matrikel id field is required when street / number / zip is
 * not present". Maalt mod prod:
 *   parseAddress('Agernskrænten 33')        -> zip: ''      -> 422
 *   parseAddress('Agernskrænten 33, 2750')  -> zip: '2750'  -> oploest
 *
 * 🪤 DEN EKSISTERENDE FIXTUR KAN IKKE FANGE DET. `observerAlert()` bruger
 * 'Bredgade 40, 1260 København K' — postnummeret staar allerede i selve
 * adressestrengen, saa linket blev tilfaeldigvis gyldigt. En test paa den
 * fixtur ville vaere groen uden at bevise noget. Derfor bruges her den form
 * prod faktisk leverer.
 */
it('bygger "Se ejendom"-linket MED postnummer naar adressen er en ren gadelinje', function () {
    Http::fake(['*/v1/alerts/702' => Http::response(['data' => observerAlert([
        'id' => 702,
        'metadata' => [
            // Prod-formen: gadelinje i `address`, postnummer i egen noegle.
            'address' => 'Agernskrænten 33',
            'postal_code' => '2750',
        ],
    ])], 200)]);

    // Asserter paa selve HREF'en, ikke paa at siden naevner postnummeret:
    // adressen staar ogsaa i overskriften, saa assertSee('2750') ville vaere
    // groen uden at linket aendrede sig.
    // 🪤 REVIEW-FUND (P2): asserter IKKE med urlencode(). Foerste udkast
    // byggede forventningen med den SAMME funktion som bladen — altsaa
    // selv-konsistens, ikke at URL'en kan opløses. Den ville vaere groen
    // gennem hele urlencode/+-fejlen nedenfor. Nu gaar vi gennem route(),
    // som er den kanoniske form i resten af kodebasen.
    $html = Livewire::test(AlertDetail::class, ['id' => 702])->html();

    expect(opslagsadresseFraLink($html))->toBe('Agernskrænten 33, 2750');
});

/*
 * 🚨 REVIEW-FUND (P1): urlencode() giver "+" for mellemrum — og "+" betyder
 * mellemrum i en QUERY-streng, ALDRIG i et sti-segment.
 *
 * Ruten er `->where('query', '.*')`, saa segmentet naar frem raat:
 *   urlencode    -> …/Agernskr%C3%A6nten+33%2C+2750 => "Agernskrænten+33,+2750"
 *   rawurlencode -> …/Agernskr%C3%A6nten%2033%2C%202750 => "Agernskrænten 33, 2750"
 *
 * Og "+" oedelaegger praecis den parser rettelsen bygger paa (maalt paa prod):
 *   'Agernskrænten 33, 2750' => street=Agernskrænten     number=33  zip=2750  ✅
 *   'Agernskrænten+33,+2750' => street=Agernskrænten+33  number=''  zip=2750  🚨
 *
 * 🚨 DET VAERSTE: zip BLIVER fundet, saa den nye guard i Lookup::mount()
 * vinker den igennem — og saa fejler kaldet med 422 paa `number` i stedet,
 * forbi den kontrol der skulle fange det. Rettelsen ville have gjort fejlen
 * TAVSERE, ikke vaek.
 */
it('bruger route() saa mellemrum ikke bliver til "+" i sti-segmentet', function () {
    Http::fake(['*/v1/alerts/704' => Http::response(['data' => observerAlert([
        'id' => 704,
        'metadata' => ['address' => 'Agernskrænten 33', 'postal_code' => '2750'],
    ])], 200)]);

    $html = Livewire::test(AlertDetail::class, ['id' => 704])->html();

    // '+' maa ALDRIG optraede: i et sti-segment er det et bogstaveligt plus.
    expect($html)->not->toContain('Agernskr%C3%A6nten+33');
    expect(opslagsadresseFraLink($html))->toBe('Agernskrænten 33, 2750');
});

/*
 * 🚨 REVIEW-FUND (P2): adressen baerer ALLEREDE et postnummer.
 *
 * Repoets egen fixtur har 'Bredgade 40, 1260 København K' MED postal_code
 * '1260' — en naiv sammensaetning gav "…København K, 1260". trim() rydder
 * kun ENDERNE og kan ikke hjaelpe med et postnummer inde i strengen.
 */
it('dublerer ikke postnummeret naar adressen allerede har et', function () {
    Http::fake(['*/v1/alerts/705' => Http::response(['data' => observerAlert([
        'id' => 705,
        'metadata' => ['address' => 'Bredgade 40, 1260 København K', 'postal_code' => '1260'],
    ])], 200)]);

    $html = Livewire::test(AlertDetail::class, ['id' => 705])->html();

    expect(opslagsadresseFraLink($html))->toBe('Bredgade 40, 1260 København K');
});

/*
 * 🚨 REVIEW-FUND (P3): postal_code er ekstern JSON og kan vaere hvad som
 * helst. Et array gav "Array to string conversion" -> ViewException -> HELE
 * alert-siden nede. Lav blast radius i sandsynlighed, hoej i konsekvens.
 */
it('overlever et postal_code der ikke er skalarr', function () {
    Http::fake(['*/v1/alerts/706' => Http::response(['data' => observerAlert([
        'id' => 706,
        'metadata' => ['address' => 'Agernskrænten 33', 'postal_code' => ['2750']],
    ])], 200)]);

    $html = Livewire::test(AlertDetail::class, ['id' => 706])->html();

    expect(opslagsadresseFraLink($html))->toBe('Agernskrænten 33');
});

/*
 * Postnummeret mangler i payloaden (fx ownership_change fra
 * MonitoringService, der kun sender `property_address`). Saa er en 422 ikke
 * til at undgaa — men linket maa ikke faa en efterhaengende ", ".
 */
it('efterlader ingen haengende komma naar postnummeret mangler', function () {
    Http::fake(['*/v1/alerts/703' => Http::response(['data' => observerAlert([
        'id' => 703,
        'metadata' => [
            'address' => 'Agernskrænten 33',
            'postal_code' => null,
        ],
    ])], 200)]);

    $html = Livewire::test(AlertDetail::class, ['id' => 703])->html();

    expect(opslagsadresseFraLink($html))->toBe('Agernskrænten 33');
});

/*
 * 🪤 MIT EGET REVIEW-FUND: et bart route()-kald tager siden ned.
 *
 * Pakken registrerer ruterne BETINGET (embedded vs standalone), saa
 * `route('metis.lookup')` kaster RouteNotFoundException naar ruten ikke er
 * registreret — og en ViewException i en blade tager HELE alert-siden med
 * sig. `debt-search.blade.php:273` og `MetisLink.php:57` guarder allerede
 * med Route::has(); jeg kopierede sammensaetningen derfra, men ikke guarden.
 * Samme "halvdelen af kaldestederne"-fejl som hele denne PR handler om.
 */
it('tager ikke siden ned naar lookup-ruten ikke er registreret', function () {
    // Fjern KUN metis.lookup — som i en host-app der ikke registrerer
    // opslagsruten. 🪤 Foerste udkast nulstillede HELE RouteCollection og
    // fejlede paa `default.livewire.update`: et falsk roedt der beviste noget
    // helt andet end guarden.
    $routes = app('router')->getRoutes();
    $beholdte = new Illuminate\Routing\RouteCollection;
    foreach ($routes as $r) {
        if ($r->getName() !== 'metis.lookup') {
            $beholdte->add($r);
        }
    }
    app('router')->setRoutes($beholdte);

    expect(Route::has('metis.lookup'))->toBeFalse();

    Http::fake(['*/v1/alerts/707' => Http::response(['data' => observerAlert([
        'id' => 707,
        'metadata' => ['address' => 'Agernskrænten 33', 'postal_code' => '2750'],
    ])], 200)]);

    // Siden skal stadig rendere — bare uden "Se ejendom"-linket.
    Livewire::test(AlertDetail::class, ['id' => 707])
        ->assertSet('error', null)
        ->assertDontSee('Se ejendom');
});

/*
 * 🚨 REVIEW-FUND (P1): route() encoder IKKE '/'.
 * `$meta['address']` er ekstern alert-JSON. En raekke som '../../../admin'
 * gav /lookup/address/../../../admin — browseren normaliserer '../' vaek
 * FOER afsendelse, saa linket navigerer et andet sted hen i appen.
 */
it('encoder skraastreger i "Se ejendom"-linket', function () {
    Http::fake(['*/v1/alerts/708' => Http::response(['data' => observerAlert([
        'id' => 708,
        'metadata' => ['address' => '../../../admin', 'postal_code' => '2750'],
    ])], 200)]);

    $html = Livewire::test(AlertDetail::class, ['id' => 708])->html();

    expect($html)->not->toContain('/lookup/address/../../../admin');
    expect(opslagsadresseFraLink($html))->toBe('../../../admin, 2750');
});

/*
 * 🚨 REVIEW-FUND (P1): en TOM adresse byggede et link til et bart postnummer.
 *
 * Guarden skiftede fra `@if($address)` (truthy) til `$address !== null`, saa
 * '' faldt igennem: trim('', ', ') = '' og saa ', '.$postnr =&gt; ", 2750".
 * `@if($opslagsUrl)` er sand for den streng, saa linket RENDEREDE — og
 * parseAddress(', 2750') finder zip, saa den ville slippe forbi guarden og
 * fejle paa `number` bagved. Praecis den "tavsere fejl" PR #179 advarede mod.
 */
it('bygger intet link naar adressen er tom', function () {
    Http::fake(['*/v1/alerts/709' => Http::response(['data' => observerAlert([
        'id' => 709,
        'metadata' => ['address' => '', 'postal_code' => '2750'],
    ])], 200)]);

    $html = Livewire::test(AlertDetail::class, ['id' => 709])->html();

    expect(opslagsadresseFraLink($html))->toBeNull();
    expect($html)->not->toContain('Se ejendom');
});

/*
 * 🪤 Samme is_scalar-hul som `postal_code` fik lukket — men paa `address`.
 * Et array gav "Array to string conversion" og tog hele alert-siden ned.
 */
it('overlever en address der ikke er skalarr', function () {
    Http::fake(['*/v1/alerts/710' => Http::response(['data' => observerAlert([
        'id' => 710,
        'metadata' => ['address' => ['ikke', 'en', 'streng'], 'postal_code' => '2750'],
    ])], 200)]);

    Livewire::test(AlertDetail::class, ['id' => 710])
        ->assertSet('error', null);
});
