<?php

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
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

// Portefølje-kaldet bruger ikke ->throw(), så de to fejltyper rammer hver sin
// gren: en 500 giver json()===null (fanges af ?? 0), mens en ConnectionException
// kastes og skal fanges af catch-blokken. Begge skal degradere til 0.
it('falder tilbage til 0 ejendomme når portefølje-kaldet timer ud', function () {
    Http::fake(function ($request) {
        if (str_contains($request->url(), 'property-portfolio')) {
            throw new ConnectionException('Connection timed out');
        }

        return Http::response(['data' => [
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
        ]]);
    });

    $api = new RegistryApi;
    $result = $api->searchPersonByName('Anders Hansen');

    expect($result[0]['total_properties'])->toBe(0)
        ->and($result[0]['owned_companies'][0]['ownership'])->toBe(100);
});

it('rapporterer timeout på portefølje-kaldet', function () {
    // rescue() kaldte report() automatisk. Den observability skal bevares,
    // ellers bliver en registry-api-nedetid til et tavst "0 ejendomme".
    $reported = [];
    app()->bind(ExceptionHandler::class, function () use (&$reported) {
        return new class($reported) implements ExceptionHandler
        {
            public function __construct(private array &$reported) {}

            public function report(Throwable $e): void
            {
                $this->reported[] = $e::class;
            }

            public function shouldReport(Throwable $e): bool
            {
                return true;
            }

            public function render($request, Throwable $e)
            {
                return null;
            }

            public function renderForConsole($output, Throwable $e): void {}
        };
    });

    Http::fake(function ($request) {
        if (str_contains($request->url(), 'property-portfolio')) {
            throw new ConnectionException('Connection timed out');
        }

        return Http::response(['data' => [
            'person_name' => 'Anders Hansen',
            'companies' => [[
                'cvr' => '12345678',
                'name' => 'Acme ApS',
                'status' => 'NORMAL',
                'roles' => [['role_label' => 'Reelle ejere', 'is_current' => true]],
            ]],
        ]]);
    });

    (new RegistryApi)->searchPersonByName('Anders Hansen');

    expect($reported)->toBe([ConnectionException::class]);
});

it('falder tilbage til 0 ejendomme når portefølje-kaldet svarer 500', function () {
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
        '*/property-portfolio*' => Http::response('Server error', 500),
    ]);

    $api = new RegistryApi;
    $result = $api->searchPersonByName('Anders Hansen');

    expect($result[0]['total_properties'])->toBe(0)
        ->and($result[0]['owned_companies'][0]['ownership'])->toBe(100);
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

it('chunker matrikel_ids i grupper à 200 og fladgør data-listerne', function () {
    // 250 ids skal splittes i to POST-kald (200 + 50) — assert på antal kald
    // og payload-størrelse pr. kald, ikke kun det samlede flade resultat.
    $ids = array_map(fn ($i) => "matrikel-{$i}", range(1, 250));

    Http::fake([
        '*/v1/properties/batch' => Http::sequence()
            ->push(['data' => array_fill(0, 200, ['matrikel_id' => 'x', 'bbr' => ['buildings' => []]])])
            ->push(['data' => array_fill(0, 50, ['matrikel_id' => 'y', 'bbr' => ['buildings' => []]])]),
    ]);

    $api = new RegistryApi;
    $result = $api->fetchPropertiesBatch($ids);

    Http::assertSentCount(2);
    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/v1/properties/batch')
            && count($request->data()['matrikel_ids']) <= 200;
    });
    expect($result)->toHaveCount(250);
});

it('returnerer tomt array uden kald ved tom input til fetchPropertiesBatch', function () {
    Http::fake();

    $api = new RegistryApi;
    $result = $api->fetchPropertiesBatch([]);

    expect($result)->toBe([]);
    Http::assertNothingSent();
});

it('cacher company-structure men kun ved ikke-tomt svar', function () {
    Http::fake([
        '*/v1/cvr/company-structure' => Http::sequence()
            ->push(['data' => ['root' => ['cvr' => '12345678', 'name' => 'Frankston ApS']]])
            ->push(['data' => ['root' => ['cvr' => '12345678', 'name' => 'STALE — bør aldrig ses']]]),
    ]);

    $api = new RegistryApi;
    $first = $api->fetchCompanyStructureCached('12345678');
    $second = $api->fetchCompanyStructureCached('12345678');

    Http::assertSentCount(1);
    expect($first)->toBe($second)
        ->and($second['root']['name'])->toBe('Frankston ApS');
});

it('cacher IKKE et tomt svar fra company-structure', function () {
    Http::fake([
        '*/v1/cvr/company-structure' => Http::response(['data' => []]),
    ]);

    $api = new RegistryApi;
    $api->fetchCompanyStructureCached('12345678');
    $api->fetchCompanyStructureCached('12345678');

    // Tomt svar må ikke cache — begge kald skal ramme API'et.
    Http::assertSentCount(2);
});

it('cacher company-info ved andet kald', function () {
    Http::fake([
        '*/v1/cvr/company/*' => Http::sequence()
            ->push(['data' => ['company' => ['cvr' => '12345678', 'name' => 'Frankston ApS']]])
            ->push(['data' => ['company' => ['cvr' => '12345678', 'name' => 'STALE — bør aldrig ses']]]),
    ]);

    $api = new RegistryApi;
    $first = $api->fetchCompanyInfo('12345678');
    $second = $api->fetchCompanyInfo('12345678');

    Http::assertSentCount(1);
    expect($first)->toBe($second)
        ->and($second['name'])->toBe('Frankston ApS');
});

it('cacher IKKE et null-svar fra company-info', function () {
    Http::fake([
        '*/v1/cvr/company/*' => Http::response('Server error', 500),
    ]);

    $api = new RegistryApi;
    $first = $api->fetchCompanyInfo('12345678');
    $second = $api->fetchCompanyInfo('12345678');

    expect($first)->toBeNull()->and($second)->toBeNull();
    Http::assertSentCount(2);
});
