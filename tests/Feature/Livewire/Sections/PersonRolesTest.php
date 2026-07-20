<?php
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\Sections\PersonRoles;

beforeEach(function () {
    if (! Route::has('metis.lookup')) {
        Route::get('/lookup/{type}/{query}', fn () => null)->name('metis.lookup')->where('query', '.*');
    }
});

it('renders EJERREGISTER as a readable role label in the visible role cell', function () {
    Http::fake(['*cvr/person-roles*' => Http::response(['data' => [
        'person_name' => 'Mikkel Duif',
        'companies' => [[
            'name' => 'Duif Holding ApS', 'cvr' => '41519843', 'company_type' => 'APS', 'status' => 'NORMAL',
            'roles' => [
                ['role_label' => 'Direktion', 'is_current' => true],
                ['role_label' => 'EJERREGISTER', 'is_current' => true],
                ['role_label' => 'Reelle ejere', 'is_current' => true],
            ],
        ]],
    ]])]);

    $html = Livewire::test(PersonRoles::class, ['query' => 'Mikkel Duif'])->html();

    // Den synlige rolle-celle viser den samlede, pænede label-streng.
    // (EJERREGISTER kan stadig ligge i Livewire's hydration-snapshot, men vises ikke.)
    expect($html)->toContain('Direktion, Legal ejer, Reelle ejere');
});
