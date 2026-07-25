<?php

use Illuminate\Support\Facades\Http;
use TheFountainhead\Metis\Services\RegistryApi;

it('fetches company by CVR', function () {
    Http::fake([
        // get()/post() unwrapper svaret via ->json('data'), så fixturen
        // skal wrappes i en data-nøgle for at nå frem til koden.
        '*/v1/cvr/company/*' => Http::response([
            'data' => [
                'company' => [
                    'cvr' => '12345678',
                    'name' => 'Frankston ApS',
                    // Koden foretrækker long_company_type og falder tilbage til
                    // company_type — pin den primære gren, ikke kun fallbacken.
                    'long_company_type' => 'Anpartsselskab',
                    'company_type' => 'ApS',
                    'roles' => [[
                        'person_name' => 'Frederik Larnæs',
                        'role_label' => 'Direktør',
                        'is_current' => true,
                    ]],
                ],
            ],
        ]),
    ]);

    $api = new RegistryApi;
    $result = $api->fetchCompany('12345678');

    expect($result['company']['name'])->toBe('Frankston ApS')
        ->and($result['company']['cvr'])->toBe('12345678')
        ->and($result['company']['type'])->toBe('Anpartsselskab')
        ->and($result['persons'])->toBe([
            ['name' => 'Frederik Larnæs', 'role' => 'Direktør'],
        ]);
});

it('searches companies by name', function () {
    Http::fake([
        '*/v1/cvr/search-by-name' => Http::response([
            'data' => ['companies' => [['name' => 'Carlsberg A/S']]],
        ]),
    ]);

    $api = new RegistryApi;
    $result = $api->searchByName('Carlsberg');

    expect($result)->toHaveCount(1);
});

it('fetches property analysis by address', function () {
    Http::fake([
        '*/v1/property/analysis' => Http::response([
            'data' => [
                'property' => [
                    'address' => 'Bredgade',
                    'postal_code' => '1260',
                    'city' => 'København K',
                    'matrikel_id' => '123abc',
                    'bbr' => ['usage_code' => 'Etageejendom', 'total_area' => 200],
                    'valuation' => null,
                    'owners' => [],
                    'companies_at_address' => [],
                ],
            ],
        ]),
    ]);

    $api = new RegistryApi;
    $result = $api->fetchProperty(['street' => 'Bredgade', 'number' => '40', 'zip' => '1260']);

    expect($result)->toHaveKey('property');
});

it('returns error structure on API failure', function () {
    Http::fake([
        '*/v1/cvr/company/*' => Http::response('Server error', 500),
    ]);

    $api = new RegistryApi;
    $result = $api->fetchCompany('12345678');

    expect($result)->toHaveKey('error');
});

it('returns empty array when person is not found', function () {
    // registry-api svarer 404 ved intet match (CvrController::personRolesByName) —
    // ikke et tomt data-objekt, som en fake ellers let kommer til at antage.
    Http::fake([
        '*/v1/cvr/person-roles' => Http::response([
            'error' => ['code' => 'not_found', 'message' => 'Person not found'],
        ], 404),
    ]);

    $api = new RegistryApi;
    $result = $api->searchPersonByName('Anders Hansen');

    expect($result)->toBe([]);
});

it('maps person roles, ownership and property count', function () {
    Http::fake([
        '*/v1/cvr/person-roles' => Http::response(['data' => [
            'person_name' => 'Anders Hansen',
            'companies' => [[
                'cvr' => '12345678',
                'name' => 'Acme ApS',
                'status' => 'NORMAL',
                'roles' => [[
                    'role_label' => 'Reelle ejere',
                    'is_current' => true,
                    'ownership_share' => 100,
                ]],
            ]],
        ]]),
        '*/property-portfolio*' => Http::response(['data' => [
            'portfolio' => ['total_count' => 3],
        ]]),
    ]);

    $api = new RegistryApi;
    $result = $api->searchPersonByName('Anders Hansen');

    expect($result[0]['name'])->toBe('Anders Hansen')
        ->and($result[0]['owned_companies'][0]['ownership'])->toBe(100)
        ->and($result[0]['total_properties'])->toBe(3);
});

it('fetches map layers', function () {
    Http::fake([
        '*/v1/map/layers' => Http::response([
            'data' => [
                ['id' => 'cadastral', 'type' => 'vector'],
                ['id' => 'bluespot', 'type' => 'raster'],
            ],
        ]),
    ]);

    $api = new RegistryApi;
    $result = $api->getMapLayers();

    expect($result)->toHaveCount(2);
    expect($result[0]['id'])->toBe('cadastral');
});

it('fetches cross ownership', function () {
    Http::fake([
        '*/v1/cvr/cross-ownership' => Http::response([
            'data' => [
                'relations' => [
                    ['parent_cvr' => '11111111', 'child_cvr' => '22222222'],
                ],
            ],
        ]),
    ]);

    $api = new RegistryApi;
    $result = $api->fetchCrossOwnership(['11111111', '22222222']);

    expect($result)->toHaveKey('relations');
});

it('fetches roles by multiple CVRs', function () {
    Http::fake([
        '*/v1/cvr/roles-by-cvr' => Http::response([
            'data' => [
                'companies' => [
                    ['cvr' => '11111111', 'name' => 'Company A'],
                    ['cvr' => '22222222', 'name' => 'Company B'],
                ],
            ],
        ]),
    ]);

    $api = new RegistryApi;
    $result = $api->fetchRolesByCvr(['11111111', '22222222']);

    expect($result['companies'])->toHaveCount(2);
});

it('parses Danish address correctly', function () {
    $api = new RegistryApi;
    $result = $api->parseAddress('Bredgade 40, 1260 København K');

    expect($result['street'])->toBe('Bredgade');
    expect($result['number'])->toBe('40');
    expect($result['zip'])->toBe('1260');
});
