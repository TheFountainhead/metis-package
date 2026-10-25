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
