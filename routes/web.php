<?php

use Illuminate\Support\Facades\Route;
use TheFountainhead\Metis\Http\Controllers\AdminAuthController;
use TheFountainhead\Metis\Http\Controllers\MetisPdfController;
use TheFountainhead\Metis\Http\Middleware\MetisAdminAuth;
use TheFountainhead\Metis\Http\Middleware\NoIndex;
use TheFountainhead\Metis\Livewire\Admin\Dashboard;
use TheFountainhead\Metis\Livewire\Admin\Leads;
use TheFountainhead\Metis\Livewire\Admin\Logs;
use TheFountainhead\Metis\Livewire\AlertDetail;
use TheFountainhead\Metis\Livewire\Analytics;
use TheFountainhead\Metis\Livewire\AlertsInbox;
use TheFountainhead\Metis\Livewire\DebtSearch;
use TheFountainhead\Metis\Livewire\LenderExposure;
use TheFountainhead\Metis\Http\Controllers\GrantQuotaController;
use TheFountainhead\Metis\Livewire\Lookup;
use TheFountainhead\Metis\Livewire\PropertyExplore;
use TheFountainhead\Metis\Livewire\Search;

/*
 * Public routes
 *
 * 🚨 `NoIndex` ligger paa GRUPPEN, ikke i service-provideren. Maalt 9/8:
 * `tests/TestCase.php:37` inkluderer DENNE fil direkte og springer
 * provideren over — middleware registreret dér ville altsaa aldrig koere i
 * test, mens den koerte i prod. Testmiljoeet ville ikke ligne prod, og
 * praecis dén afstand er grunden til at CPR-hullet kunne overleve en groen
 * testsuite. Paa gruppen foelger beskyttelsen ruterne uanset hvem der
 * indlaeser filen.
 */
Route::middleware(NoIndex::class)->group(function () {
    Route::get('/', Search::class)->name('metis.home')->middleware('throttle:20,1');
    Route::get('/lookup/{type}/{query}', Lookup::class)->name('metis.lookup')->where('query', '.*')->middleware('throttle:20,1');
    Route::get('/soeg', DebtSearch::class)->name('metis.debt-search')->middleware('throttle:20,1');
    Route::get('/udforsk', PropertyExplore::class)->name('metis.property-explore')->middleware('throttle:20,1');
    Route::get('/laangiver', LenderExposure::class)->name('metis.lender-exposure')->middleware('throttle:20,1');

    // 🔑 "Spoerg om noget" — aggregerede spoergsmaal om en POPULATION.
    // Soegefeltet finder én ting man kender navnet paa; det her afgraenser en
    // maengde og taeller. Samme kvote-gate som opslagene: et analyse-svar kan
    // afdaekke en hel population og maa ikke vaere vejen udenom betalingen.
    Route::get('/spoerg', Analytics::class)->name('metis.analytics')->middleware('throttle:20,1');
    Route::get('/alerts', AlertsInbox::class)->name('metis.alerts')->middleware('throttle:60,1');
    Route::get('/alerts/{id}', AlertDetail::class)->name('metis.alert.detail')->middleware('throttle:60,1')->whereNumber('id');

    // 🔑 Godkendelses-link i kvote-mailen: ét klik fra telefonen frem for en
    // SSH-session med `metis:grant-quota`. Friktionen dér betoed at en
    // anmodning kunne blive liggende, og en ventende testbruger er tabt.
    //
    // 🚨 AUTH-FRI — beskyttet af `signed` alene, saa den KAN aabnes uden login.
    // Derfor to ting der begge skal holde: signaturen daekker hele URL'en inkl.
    // `quota` (tallet kan ikke skrues op bagefter), og ruten ligger INDE i
    // NoIndex-gruppen. Praecis den kombination manglede paa CPR-ruterne 9/8,
    // hvor beskyttelsen sad paa den ene af to veje ind.
    Route::get('/godkend-kvote/{lead}/{quota}', GrantQuotaController::class)
        ->name('metis.grant-quota')
        ->middleware(['signed', 'throttle:10,1'])
        ->whereNumber(['lead', 'quota']);
});
/*
 * 🚨 DER ER TO robots.txt, og de var IKKE enige.
 *
 * Denne rute gav kun `Disallow: /admin`. Host-appen har desuden en STATISK
 * `public/robots.txt` med `Disallow: /*?q=` — og nginx serverer statiske
 * filer FOER PHP, saa filen vinder i prod mens denne rute vinder i tests.
 *
 * 🪤 Konsekvensen: en test af opslags-beskyttelsen saa den SVAGESTE af de to.
 * Forsvandt `public/robots.txt` i et deploy, ville `?q=`-beskyttelsen gaa
 * lydloest tabt og testen stadig vaere groen. Samme fejlklasse som noindex,
 * der laa i en layout med nul konsumenter (#149): beskyttelsen findes to
 * steder, den svageste vinder dér hvor vi kigger.
 *
 * Ruten baerer nu det fulde saet, saa pakken er sikker uden host-filen.
 * `?cvr=` og oevrige opslags-parametre daekkes af meta-robots i
 * standalone-layoutet — robots.txt kan ikke moenstermatche dem alle.
 *
 * 🚨 MAALT 9/8: `Disallow: /*?q=` daekker query-strenge. `/lookup/cpr/<nr>`
 * baerer opslaget i STIEN og var daekket af INGEN af linjerne. Googlebot
 * hentede 35 unikke CPR-URL'er.
 *
 * 🪤 REKKEFOELGEN ER VIGTIG, og `Disallow` alene goer skade uden noindex:
 * en `Disallow`'et URL kan ikke crawles, saa Google ser aldrig et `noindex`
 * — og en URL der ALLEREDE er indekseret kan blive staaende. Derfor er
 * noindex-headeren (`NoIndex`-middleware) det primaere lag; denne linje er
 * det andet, og den maa foerst faa lov at virke naar siderne er ude af
 * indekset.
 */
Route::get('/robots.txt', fn () => response(
    "User-agent: *\nAllow: /\nDisallow: /admin\nDisallow: /*?q=\nDisallow: /lookup/\n",
    200,
    ['Content-Type' => 'text/plain']
));

// Admin routes
Route::prefix('admin')->group(function () {
    Route::get('login', [AdminAuthController::class, 'login'])->name('metis.admin.login');
    Route::get('auth/redirect', [AdminAuthController::class, 'redirect'])->name('metis.admin.redirect');
    Route::get('auth/callback', [AdminAuthController::class, 'callback'])->name('metis.admin.callback');

    Route::middleware(MetisAdminAuth::class)->group(function () {
        Route::get('/', Dashboard::class)->name('metis.admin.dashboard');
        Route::get('leads', Leads::class)->name('metis.admin.leads');
        Route::get('logs', Logs::class)->name('metis.admin.logs');
        Route::post('logout', [AdminAuthController::class, 'logout'])->name('metis.admin.logout');
    });
});
