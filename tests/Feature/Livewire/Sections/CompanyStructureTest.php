<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\Sections\CompanyStructure;

beforeEach(function () {
    if (! Route::has('metis.lookup')) {
        Route::get('/lookup/{type}/{query}', fn () => null)
            ->name('metis.lookup')
            ->where('query', '.*');
    }
});

it('does not present the owners subsidiaries as the searched companys own', function () {
    // Scenariet fra JEUDAN-buggen: søgt selskab har ejere men (transient) tomme
    // subsidiaries; ejerens struktur har en portefølje af andre selskaber.
    Http::fake([
        '*cvr/company-structure*' => Http::sequence()
            ->push(['data' => ['owners' => [
                ['person_name' => 'MODERSELSKAB A/S', 'is_company' => true, 'cvr' => '11111111', 'ownership_share' => 33.33, 'role_label' => 'EJERREGISTER', 'is_current' => true],
            ], 'subsidiaries' => []]])
            ->push(['data' => ['owners' => [], 'subsidiaries' => [
                ['name' => 'FREMMED DATTER A/S', 'cvr' => '22222222', 'ownership_share' => 100],
            ]]]),
        '*cvr/company/*' => Http::response(['data' => ['company' => ['name' => 'SØGT SELSKAB A/S', 'owners' => []]]]),
        '*enrichment*' => Http::response(['data' => ['status' => 'completed']]),
    ]);

    Livewire::test(CompanyStructure::class, ['query' => '99999999'])
        ->assertSet('subsidiaries', [])
        ->assertDontSee('FREMMED DATTER');
});

it('renders the companys own subsidiaries when present', function () {
    Http::fake([
        '*cvr/company-structure*' => Http::response(['data' => ['owners' => [
            ['person_name' => 'EJER A/S', 'is_company' => true, 'cvr' => '11111111', 'ownership_share' => 50.0, 'role_label' => 'EJERREGISTER', 'is_current' => true],
        ], 'subsidiaries' => [
            ['name' => 'EGEN DATTER ApS', 'cvr' => '33333333', 'ownership_share' => 100],
        ]]]),
        '*cvr/company/*' => Http::response(['data' => ['company' => ['name' => 'SØGT SELSKAB A/S', 'owners' => []]]]),
        '*enrichment*' => Http::response(['data' => ['status' => 'completed']]),
    ]);

    Livewire::test(CompanyStructure::class, ['query' => '99999999'])
        ->assertSee('EGEN DATTER ApS')
        ->assertSee('EJER A/S');
});

it('splits owners by owner_kind into reel, legal and other rows', function () {
    Http::fake([
        '*cvr/company-structure*' => Http::response(['data' => ['owners' => [
            ['person_name' => 'UBO PERSON', 'is_company' => false, 'ownership_share' => 27.43, 'owner_kind' => 'reel', 'is_current' => true],
            ['person_name' => 'LEGAL HOLDING ApS', 'is_company' => true, 'cvr' => '11111111', 'ownership_share' => 50.0, 'owner_kind' => 'legal', 'is_current' => true],
            ['person_name' => 'DIREKTØR MED ANDEL', 'is_company' => false, 'ownership_share' => 5.0, 'owner_kind' => 'other', 'is_current' => true],
        ], 'subsidiaries' => []]]),
        '*cvr/company/*' => Http::response(['data' => ['company' => ['name' => 'SØGT SELSKAB A/S', 'owners' => []]]]),
        '*enrichment*' => Http::response(['data' => ['status' => 'completed']]),
    ]);

    Livewire::test(CompanyStructure::class, ['query' => '99999999'])
        ->assertSeeInOrder(['Ultimate beneficial owner', 'UBO PERSON', 'Legal owner', 'LEGAL HOLDING', 'Other with ownership share', 'DIREKTØR MED ANDEL']);
});

it('never renders an other-kind participant under the legal owner label (regression)', function () {
    Http::fake([
        '*cvr/company-structure*' => Http::response(['data' => ['owners' => [
            ['person_name' => 'DIREKTØR MED ANDEL', 'is_company' => false, 'ownership_share' => 5.0, 'owner_kind' => 'other', 'is_current' => true],
        ], 'subsidiaries' => []]]),
        '*cvr/company/*' => Http::response(['data' => ['company' => ['name' => 'SØGT SELSKAB A/S', 'owners' => []]]]),
        '*enrichment*' => Http::response(['data' => ['status' => 'completed']]),
    ]);

    Livewire::test(CompanyStructure::class, ['query' => '99999999'])
        ->assertSee('Other with ownership share')
        ->assertSee('DIREKTØR MED ANDEL')
        ->assertDontSee('Legal owner')
        ->assertDontSee('Owners</'); // the fallback label used when no reel owners exist
});

it('falls back to role_label when owner_kind is absent (stale cached payload)', function () {
    Http::fake([
        '*cvr/company-structure*' => Http::response(['data' => ['owners' => [
            ['person_name' => 'OLD REEL', 'is_company' => false, 'ownership_share' => 27.0, 'role_label' => 'Reelle ejere', 'is_current' => true],
            ['person_name' => 'OLD LEGAL ApS', 'is_company' => true, 'cvr' => '11111111', 'ownership_share' => 50.0, 'role_label' => 'EJERREGISTER', 'is_current' => true],
        ], 'subsidiaries' => []]]),
        '*cvr/company/*' => Http::response(['data' => ['company' => ['name' => 'SØGT SELSKAB A/S', 'owners' => []]]]),
        '*enrichment*' => Http::response(['data' => ['status' => 'completed']]),
    ]);

    Livewire::test(CompanyStructure::class, ['query' => '99999999'])
        ->assertSeeInOrder(['Ultimate beneficial owner', 'OLD REEL', 'Legal owner', 'OLD LEGAL']);
});

it('exposes ancestors from the structure payload', function () {
    Http::fake([
        '*cvr/company-structure*' => Http::response(['data' => [
            'name' => 'OpCo',
            'owners' => [],
            'subsidiaries' => [],
            'ancestors' => [
                ['person_name' => 'HoldCo ApS', 'cvr' => '70000002', 'is_company' => true,
                    'ownership_share' => 100.0, 'owner_kind' => 'legal', 'depth' => 1,
                    'parent_of_cvr' => null, 'enriching' => false, 'capped' => false, 'cycle' => false, 'foreign' => false],
            ],
        ]]),
        '*cvr/company/*' => Http::response(['data' => ['company' => ['name' => 'OpCo', 'owners' => []]]]),
        '*enrichment*' => Http::response(['data' => ['status' => 'completed']]),
    ]);

    Livewire::test(CompanyStructure::class, ['query' => '70000001'])
        ->assertSet('ancestors', fn ($a) => count($a) === 1 && $a[0]['person_name'] === 'HoldCo ApS');
});

it('renders ancestors above the searched company, deepest at top', function () {
    Http::fake([
        '*cvr/company-structure*' => Http::response(['data' => [
            'name' => 'OpCo', 'owners' => [], 'subsidiaries' => [],
            'ancestors' => [
                ['person_name' => 'BidCo ApS', 'cvr' => '20000002', 'is_company' => true, 'ownership_share' => 100.0, 'owner_kind' => 'legal', 'depth' => 1, 'parent_of_cvr' => null, 'enriching' => false, 'capped' => false, 'cycle' => false, 'foreign' => false],
                ['person_name' => 'Top Ejer', 'cvr' => null, 'is_company' => false, 'ownership_share' => 100.0, 'owner_kind' => 'reel', 'depth' => 2, 'parent_of_cvr' => '20000002', 'enriching' => false, 'capped' => false, 'cycle' => false, 'foreign' => false],
            ],
        ]]),
        '*cvr/company/*' => Http::response(['data' => ['company' => ['name' => 'OpCo', 'owners' => []]]]),
        '*enrichment*' => Http::response(['data' => ['status' => 'completed']]),
    ]);

    Livewire::test(CompanyStructure::class, ['query' => '70000001'])
        ->assertSeeInOrder(['Top Ejer', 'BidCo ApS', 'OpCo']); // deepest UBO first, then down to searched company
});
