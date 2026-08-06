<?php

use Illuminate\Support\Facades\Route;
use TheFountainhead\Metis\Http\Controllers\AdminAuthController;
use TheFountainhead\Metis\Http\Controllers\MetisPdfController;
use TheFountainhead\Metis\Http\Middleware\MetisAdminAuth;
use TheFountainhead\Metis\Livewire\Admin\Dashboard;
use TheFountainhead\Metis\Livewire\Admin\Leads;
use TheFountainhead\Metis\Livewire\Admin\Logs;
use TheFountainhead\Metis\Livewire\AlertDetail;
use TheFountainhead\Metis\Livewire\AlertsInbox;
use TheFountainhead\Metis\Livewire\DebtSearch;
use TheFountainhead\Metis\Livewire\LenderExposure;
use TheFountainhead\Metis\Livewire\Lookup;
use TheFountainhead\Metis\Livewire\PropertyExplore;
use TheFountainhead\Metis\Livewire\Search;

// Public routes
Route::get('/', Search::class)->name('metis.home')->middleware('throttle:20,1');
Route::get('/lookup/{type}/{query}', Lookup::class)->name('metis.lookup')->where('query', '.*')->middleware('throttle:20,1');
Route::get('/soeg', DebtSearch::class)->name('metis.debt-search')->middleware('throttle:20,1');
Route::get('/udforsk', PropertyExplore::class)->name('metis.property-explore')->middleware('throttle:20,1');
Route::get('/laangiver', LenderExposure::class)->name('metis.lender-exposure')->middleware('throttle:20,1');
Route::get('/alerts', AlertsInbox::class)->name('metis.alerts')->middleware('throttle:60,1');
Route::get('/alerts/{id}', AlertDetail::class)->name('metis.alert.detail')->middleware('throttle:60,1')->whereNumber('id');
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
 */
Route::get('/robots.txt', fn () => response(
    "User-agent: *\nAllow: /\nDisallow: /admin\nDisallow: /*?q=\n",
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
