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

it('hides companies without a current role by default, shows them via toggle', function () {
    Http::fake(['*cvr/person-roles*' => Http::response(['data' => [
        'person_name' => 'Test Person',
        'companies' => [
            [
                'name' => 'Aktiv Holding ApS', 'cvr' => '11111111', 'company_type' => 'APS', 'status' => 'NORMAL',
                'roles' => [['role_label' => 'Direktion', 'is_current' => true]],
            ],
            [
                'name' => 'Ophørt Gammel ApS', 'cvr' => '22222222', 'company_type' => 'APS', 'status' => 'OPHOERT',
                'roles' => [['role_label' => 'Direktion', 'is_current' => false]],
            ],
        ],
    ]])]);

    $component = Livewire::test(PersonRoles::class, ['query' => 'Test Person']);

    // Default: kun selskabet med aktuel rolle vises; det ophørte er skjult + toggle tilbudt.
    expect($component->html())->toContain('Aktiv Holding ApS')
        ->not->toContain('Ophørt Gammel ApS');
    $component->assertSee('Vis også tidligere roller');

    // Slå toggle til → begge vises.
    $component->set('showAllRoles', true);
    expect($component->html())->toContain('Aktiv Holding ApS')
        ->toContain('Ophørt Gammel ApS');
});
