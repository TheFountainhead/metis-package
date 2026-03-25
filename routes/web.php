<?php

use Illuminate\Support\Facades\Route;
use TheFountainhead\Metis\Http\Controllers\AdminAuthController;
use TheFountainhead\Metis\Http\Controllers\MetisPdfController;
use TheFountainhead\Metis\Http\Middleware\MetisAdminAuth;
use TheFountainhead\Metis\Livewire\Admin\Dashboard;
use TheFountainhead\Metis\Livewire\Admin\Leads;
use TheFountainhead\Metis\Livewire\Admin\Logs;
use TheFountainhead\Metis\Livewire\Lookup;
use TheFountainhead\Metis\Livewire\Search;

// Public routes
Route::get('/', Search::class)->name('metis.home')->middleware('throttle:20,1');
Route::get('/lookup/{type}/{query}', Lookup::class)->name('metis.lookup')->where('query', '.*')->middleware('throttle:20,1');
Route::get('/robots.txt', fn () => response("User-agent: *\nDisallow: /admin\n", 200, ['Content-Type' => 'text/plain']));

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
