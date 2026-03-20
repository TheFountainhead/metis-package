<?php

use Illuminate\Support\Facades\Route;
use TheFountainhead\Metis\Http\Controllers\MetisPdfController;
use TheFountainhead\Metis\Livewire\Index;
use TheFountainhead\Metis\Livewire\Lookup;

Route::prefix('metis')->middleware('auth')->group(function () {
    Route::get('/', Index::class)->name('metis.index');
    Route::get('/{type}/{query}', Lookup::class)->name('metis.lookup')->where('query', '.*');
    Route::get('/{type}/{query}/pdf', [MetisPdfController::class, 'download'])->name('metis.pdf')->where('query', '.*');
});
