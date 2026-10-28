<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use TheFountainhead\Metis\Livewire\CompanySegmentation;
use TheFountainhead\Metis\Livewire\MetodeOgVejledning;

/**
 * Metode og vejledning: de skrevne regler bag segmenteringen (ERST-udbuddet).
 *
 * Samme adgang som segmenteringen. Siden kalder intet API, men den hoerer til
 * licensen og skal aabne og lukke sammen med det, den beskriver.
 */
beforeEach(function () {
    $this->withoutVite();
    config()->set('metis.mode', 'standalone');
    config()->set('metis.gating.enabled', true);
    Cache::flush();
    Http::preventStrayRequests();
});

dataset('metode-ikke-piloter', [
    'anonym' => [[]],
    'kun verificeret mail' => [['metis_verified_email' => 'kunde@firma.dk']],
    'uproevet token' => [['metis_user_token' => '1|x']],
]);

it('viser reglerne for en bekraeftet pilot', function () {
    $this->withSession(['metis_user_token' => '1|aegte', 'metis_pilot_account_id' => 7]);

    $this->get('/metode')->assertOk()
        ->assertDontSee('Kun for brugere med konto')
        ->assertSee('data-metode', false)
        ->assertSee('Iværksættervirksomheder')
        ->assertSee('Uden dansk region')
        ->assertSee('62.10.00')
        ->assertSee('EIFO-definitionen')
        ->assertSee('§ 138, stk. 6');
});

it('🚨 en ikke-pilot ser login-beskeden og ingen regler', function (array $session) {
    if ($session !== []) {
        $this->withSession($session);
    }

    $this->get('/metode')->assertOk()
        ->assertSee('Kun for brugere med konto')
        ->assertDontSee('data-metode', false)
        ->assertDontSee('EIFO-definitionen');
})->with('metode-ikke-piloter');

it('modstykke: med gaten slaaet fra (embedded bag vaertens login) vises reglerne', function () {
    config()->set('metis.gating.enabled', false);

    $this->get('/metode')->assertOk()->assertSee('data-metode', false);
});

it('🪤 hver vertikal i segmenteringen har sine branchekoder beskrevet', function () {
    // En ny vertikal i dropdownen uden en raekke her ville staa med tom
    // forklaring paa metodesiden.
    expect(array_keys(MetodeOgVejledning::VERTIKAL_BRANCHER))
        ->toEqualCanonicalizing(array_keys(CompanySegmentation::VERTIKALER));
});

it('segmenteringssiden linker til metoden', function () {
    $this->withSession(['metis_user_token' => '1|aegte', 'metis_pilot_account_id' => 7]);
    Http::fake(['*/company-segmentation' => Http::response(['data' => [], 'meta' => ['total' => 0]])]);

    $this->get('/segmentering')->assertOk()->assertSee('data-metode-link', false)->assertSee('/metode', false);
});

/**
 * 🪤 TestCase loader kun routes/web.php. Uden at loade embedded.php her ville
 * en test af /metis/metode ramme 404 og aldrig maale ruten (lektionen i
 * MetisPdfControllerTest).
 */
function loadEmbeddedRoutes(): void
{
    Route::middleware('web')->group(__DIR__.'/../../routes/embedded.php');
    app('router')->getRoutes()->refreshNameLookups();
}

it('embedded: /metis/metode viser reglerne bag vaertens login', function () {
    config()->set('metis.mode', 'embedded');
    config()->set('metis.gating.enabled', false);
    config()->set('livewire.layout', 'metis::layouts.embedded');
    loadEmbeddedRoutes();

    $this->actingAs(new GenericUser(['id' => 1]))
        ->get('/metis/metode')->assertOk()
        ->assertSee('data-metode', false)
        ->assertSee('EIFO-definitionen');
});

it('🚨 embedded: /metis/metode er lukket uden login', function () {
    config()->set('metis.mode', 'embedded');
    config()->set('metis.gating.enabled', false);
    config()->set('livewire.layout', 'metis::layouts.embedded');
    loadEmbeddedRoutes();
    // Vaertens login-rute; `auth` sender en gaest dertil.
    Route::get('/login', fn () => 'login')->name('login');
    app('router')->getRoutes()->refreshNameLookups();

    $this->get('/metis/metode')->assertRedirect('/login');
});

it('beskriver kapitalfiltrene, selskabslisten og udenlandske ejere', function () {
    config()->set('metis.gating.enabled', false);

    $this->get('/metode')->assertOk()
        // Kapital (udviklingsstadie)
        ->assertSee('id="udviklingsstadie"', false)
        ->assertSee('Fondsforhøjelser, fusioner og spaltninger tæller ikke')
        // Investortyper efter de officielle definitioner (registry-api #399)
        ->assertSee('EuVECA-forordningen', false)
        ->assertSee('kapitalfond', false)
        ->assertDontSee('64.30', false)
        ->assertSee('Rejst kapital er derfor en nedre grænse')
        ->assertSee('Gældskonvertering, apportindskud og kapital fra et moderselskab')
        ->assertSee('Kun selskaber med hentet kapitalhistorik')
        ->assertSee('Kapitalhistorik ikke hentet')
        ->assertSee('<em>Højst</em> tæller kun selskaber, hvor alle forhøjelser har et kendt beløb i kroner', false)
        ->assertDontSee('seed')
        // Selskabslisten (#215)
        ->assertSee('Hent selskabslisten (CSV)')
        ->assertSee('-afkortet.csv')
        ->assertSee('højst 10.000 selskaber')
        // EIFO: ikke laengere "ikke samlet paa tvaers"
        ->assertDontSee('ikke er samlet på tværs af selskaber')
        ->assertSee('Det led er ikke en del af investortype-filteret')
        // Udenlandske ejere (#214)
        ->assertSee('Sverige · selskab/fond')
        ->assertSee('Udenlandsk ejer');
});

it('beskriver koncernreglen som den er bygget', function () {
    config()->set('metis.gating.enabled', false);

    $this->get('/metode')->assertOk()
        ->assertSee('Selskaber og koncerner')
        // Stemmerne afgoer kontrol (registry-api Koncern, coalesce(voting_share, ownership_share)).
        ->assertSee('har mindst 66,67 % af stemmerne i selskabet')
        ->assertSee('har 50 % af stemmerne og ingen anden ejer, hverken et selskab, en person eller en udenlandsk ejer, har en kendt andel på 50 % eller mere.')
        ->assertSee('Er stemmeandelen ikke registreret i CVR, bruges ejerandelen.')
        ->assertDontSee('ejer mindst 66,67 % af selskabet')
        ->assertSee('Er det øverste moderselskab udenlandsk eller en person, stopper kæden ved det øverste danske selskab.')
        ->assertSee('kontrol gennem flere mindre ejerandele')
        ->assertSee('højst 10 led');
});

it('følger faktatjekket 28/10-2026 (registry-api main efter #399)', function () {
    config()->set('metis.gating.enabled', false);

    $this->get('/metode')->assertOk()
        // F1: kun venturefond filtreres paa under 50 % (ByggInvestortyperCommand)
        ->assertDontSee('Under begge typer tæller kun selskaber')
        ->assertSee('En kapitalfond tæller i alle selskaber, den ejer, også hvor den har 50 % eller mere af stemmerne.')
        ->assertSee('Fonde, der er afregistreret hos ESMA, tæller med.')
        ->assertSee('§ 3, stk. 1, nr. 1', false)
        ->assertSee('(februar 2021, s. 17)', false)
        ->assertDontSee('s. 16', false)
        // F2: listen har tre punkter
        ->assertSee('når alle tre krav er opfyldt')
        ->assertDontSee('alle fire krav')
        // F3: EIFO kraever ikke under 50 %
        ->assertSee('Kravet om under 50 % er vores tilføjelse. EIFO kræver det ikke.')
        // F7 + F8: rapportens rigtige titel og lønkravet
        ->assertSee('Nye virksomheder i danske kommuner og erhvervshusområder')
        ->assertDontSee('<em>Nye virksomheder i Danmark</em>', false)
        ->assertSee('svarende til mindst et halvt års løn i samme branche')
        // Regioner: Region Østdanmark fra 1/1-2027
        ->assertSee('Region Østdanmark (lov nr. 699 af 20. juni 2025)')
        // F14: kun 2 eller 4 cifre
        ->assertSee('I filteret skriver du 2 cifre')
        ->assertDontSee('skrive starten af en kode')
        // F16: ÅRL § 32
        ->assertSee('årsregnskabsloven § 32')
        // F18: kapitalhistorik for alle
        ->assertDontSee('hentes for iværksætterpopulationen')
        // F20: kilder
        ->assertSee('Danmarks Statistik (nøglen fra DB07 til DB25');
});

it('🪤 kapitalfond-forklaringen i dropdownen lover ikke en 50 %-grænse', function () {
    // F1: koden filtrerer kun venture paa under 50 %.
    expect(CompanySegmentation::INVESTORTYPE_FORKLARING['kapitalfond'])
        ->not->toContain('under 50 %')
        ->toContain('50 % eller mere af stemmerne');
});

it('dropdownens angel-forklaringer siger at 50 %-grænsen er vores og at holdingselskabet ikke tæller', function () {
    $f = CompanySegmentation::INVESTORTYPE_FORKLARING;
    expect($f['angel_eifo'])->toContain('vores tilføjelse, ikke EIFO')
        ->and($f['angel_person'])->toContain('gennem eget holdingselskab, også stifterne')
        ->and($f['angel_person'])->toContain('holdingselskab tæller ikke selv med')
        ->and($f['venture'])->toContain('under 50 % af stemmerne');
});
