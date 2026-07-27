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

it('returnerer null (alt-eller-intet) når ét chunk fejler i fetchPropertiesBatch', function () {
    // 250 ids → 2 chunks. Chunk 1 (200 ids) svarer OK, chunk 2 (50 ids) fejler
    // med HTTP 500. post()-hjælperen fanger RequestException og returnerer
    // ['error' => ..., 'status' => 500] — den værdi må ALDRIG flatMap'es ind
    // blandt gyldige properties. Hele metoden skal degradere til null, uden
    // at kaste en exception.
    $ids = array_map(fn ($i) => "matrikel-{$i}", range(1, 250));

    Http::fake([
        '*/v1/properties/batch' => Http::sequence()
            ->push(['data' => array_fill(0, 200, ['matrikel_id' => 'x', 'bbr' => ['buildings' => []]])])
            ->push('Server error', 500),
    ]);

    $api = new RegistryApi;
    $result = $api->fetchPropertiesBatch($ids);

    expect($result)->toBeNull();
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

it('cacher company-info under den nye nøgle-konvention', function () {
    Http::fake([
        '*/v1/cvr/company/*' => Http::response(['data' => ['company' => ['cvr' => '12345678', 'name' => 'Frankston ApS']]]),
    ]);

    $api = new RegistryApi;
    $api->fetchCompanyInfo('12345678');

    expect(Cache::get('metis:company_info:12345678'))->not->toBeNull()
        ->and(Cache::get('metis.company-info.12345678'))->toBeNull();
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

it('cacher company-structure under den nye nøgle-konvention', function () {
    Http::fake([
        '*/v1/cvr/company-structure' => Http::response(['data' => ['root' => ['cvr' => '12345678', 'name' => 'Frankston ApS']]]),
    ]);

    $api = new RegistryApi;
    $api->fetchCompanyStructureCached('12345678');

    expect(Cache::get('metis:company_structure:12345678'))->not->toBeNull()
        ->and(Cache::get('metis.company-structure.12345678'))->toBeNull();
});

it('fetchCompanyInfosPooled: springer allerede cachede cvr\'er over og henter kun manglende via pool', function () {
    // 12345678 er allerede cachet — skal IKKE udløse et HTTP-kald.
    Cache::put('metis:company_info:12345678', ['cvr' => '12345678', 'name' => 'Cached ApS'], 86400);

    Http::fake([
        '*/v1/cvr/company/22222222' => Http::response(['data' => ['company' => ['cvr' => '22222222', 'name' => 'B ApS']]]),
        '*/v1/cvr/company/33333333' => Http::response(['data' => ['company' => ['cvr' => '33333333', 'name' => 'C ApS']]]),
    ]);

    $api = new RegistryApi;
    $result = $api->fetchCompanyInfosPooled(['12345678', '22222222', '33333333']);

    Http::assertSentCount(2);
    expect($result['12345678']['name'])->toBe('Cached ApS')
        ->and($result['22222222']['name'])->toBe('B ApS')
        ->and($result['33333333']['name'])->toBe('C ApS');
});

it('fetchCompanyInfosPooled: ét fejlet kald giver kun null for det cvr, ikke for de andre', function () {
    Http::fake([
        '*/v1/cvr/company/11111111' => Http::response(['data' => ['company' => ['cvr' => '11111111', 'name' => 'OK ApS']]]),
        '*/v1/cvr/company/22222222' => Http::response('Server error', 500),
    ]);

    $api = new RegistryApi;
    $result = $api->fetchCompanyInfosPooled(['11111111', '22222222']);

    expect($result['11111111']['name'])->toBe('OK ApS')
        ->and($result['22222222'])->toBeNull();
});

it('fetchCompanyInfosPooled: cacher succesfulde svar 24t så et efterfølgende kald ikke rammer HTTP', function () {
    Http::fake([
        '*/v1/cvr/company/44444444' => Http::response(['data' => ['company' => ['cvr' => '44444444', 'name' => 'D ApS']]]),
    ]);

    $api = new RegistryApi;
    $api->fetchCompanyInfosPooled(['44444444']);

    Http::assertSentCount(1);
    expect(Cache::get('metis:company_info:44444444')['name'])->toBe('D ApS');

    // Andet kald (fx via almindelig fetchCompanyInfo) skal ramme cachen, ikke HTTP.
    $second = $api->fetchCompanyInfo('44444444');

    Http::assertSentCount(1);
    expect($second['name'])->toBe('D ApS');
});

it('fetchCompanyInfosPooled: cacher ALDRIG et null/tomt svar', function () {
    Http::fake([
        '*/v1/cvr/company/55555555' => Http::response('Server error', 500),
    ]);

    $api = new RegistryApi;
    $result = $api->fetchCompanyInfosPooled(['55555555']);

    expect($result['55555555'])->toBeNull()
        ->and(Cache::get('metis:company_info:55555555'))->toBeNull();
});

it('fetchCompanyInfosPooled: tomt input giver tomt array uden HTTP-kald', function () {
    Http::fake();

    $api = new RegistryApi;
    $result = $api->fetchCompanyInfosPooled([]);

    expect($result)->toBe([]);
    Http::assertNothingSent();
});

it('fetchCompanyInfosPooled: dupliceret ucachet cvr deduperes til ét HTTP-kald', function () {
    Http::fake([
        '*/v1/cvr/company/66666666' => Http::response(['data' => ['company' => ['cvr' => '66666666', 'name' => 'E ApS']]]),
    ]);

    $api = new RegistryApi;
    $result = $api->fetchCompanyInfosPooled(['66666666', '66666666']);

    Http::assertSentCount(1);
    expect($result)->toHaveCount(1)
        ->and($result['66666666']['name'])->toBe('E ApS');
});

/**
 * Http::fake() can't observe concurrency — it fakes the underlying transfer,
 * not the pool's throttling, and Guzzle's EachPromise offers no hook to spy
 * on how many requests were in flight at once. A functional HTTP-count
 * assertion (as used by the tests above) would pass identically whether
 * concurrency is 6 or unbounded — the regression this guards against is
 * literally "someone deletes the named `concurrency: 6` argument", which
 * only an assertion on the actual call to Http::pool() can catch.
 *
 * Http::spy() is the clean way to see that argument: it swaps in a Mockery
 * spy for the Http facade, so pool() never executes for real (no network,
 * no timeout) but every call is recorded. A partialMock()+passthru() would
 * be preferable (real pool() semantics + argument assertion in one test),
 * but Laravel's Http::pool() only exists via Factory::__call() forwarding
 * to a fresh PendingRequest — Mockery's passthru() invokes that via a
 * *static* call to the parent class's __call, which drops the mock's own
 * $this and therefore its faked stubCallbacks, so it fires a real HTTP
 * request (observed: ~10s hang before this was caught). Spy avoids that
 * entirely since it never delegates to the real implementation.
 */
it('fetchCompanyInfosPooled: bounds Http::pool() to a concurrency of 6, never unlimited', function () {
    $spy = Http::spy();

    $api = new RegistryApi;
    $api->fetchCompanyInfosPooled(['77777777']);

    $spy->shouldHaveReceived('pool')
        ->withArgs(fn ($callback, $concurrency) => is_callable($callback) && $concurrency === 6);
});

it('caches companies-by-cpr on a hashed key and never caches null', function () {
    // fetchCompaniesByCpr() bruger post()-hjælperen, som unwrapper via
    // ->json('data') — fixturen skal derfor wrappes i 'data' for at nå frem.
    Http::fake(['*/v1/cvr/search-by-cpr' => Http::response(['data' => ['companies' => [['cvr' => '1']]]])]);

    $api = app(RegistryApi::class);
    $api->fetchCompaniesByCprCached('0101011234');
    $api->fetchCompaniesByCprCached('0101011234');

    Http::assertSentCount(1);
    expect(Cache::has('metis:companies_by_cpr:'.sha1('0101011234')))->toBeTrue();
});

it('cacher IKKE et fejlet svar fra companies-by-cpr', function () {
    // post()-hjælperen returnerer aldrig null ved en RequestException — den
    // giver ['error' => ..., 'status' => 500]. Den fejlform må ikke caches.
    Http::fake(['*/v1/cvr/search-by-cpr' => Http::response('Server error', 500)]);

    $api = app(RegistryApi::class);
    $first = $api->fetchCompaniesByCprCached('0101011234');
    $second = $api->fetchCompaniesByCprCached('0101011234');

    expect($first)->toHaveKey('error')->and($second)->toHaveKey('error');
    Http::assertSentCount(2);
    expect(Cache::has('metis:companies_by_cpr:'.sha1('0101011234')))->toBeFalse();
});

it('companies-by-cpr cache key never contains the raw cpr', function () {
    Http::fake(['*/v1/cvr/search-by-cpr' => Http::response(['data' => ['companies' => []]])]);

    app(RegistryApi::class)->fetchCompaniesByCprCached('0101011234');

    // sha1-nøglen er deterministisk — selve asserten er at ingen key med rå CPR findes.
    expect(Cache::has('metis:companies_by_cpr:0101011234'))->toBeFalse();
});

it('pools structure fetches with per-cvr null on failure', function () {
    Http::fake([
        '*/v1/cvr/company-structure' => Http::sequence()
            ->push(['data' => ['owners' => [], 'subsidiaries' => []]])
            ->push(['error' => 'x'], 500),
    ]);

    $r = app(RegistryApi::class)->fetchCompanyStructuresPooled(['11111111', '22222222']);

    expect($r['11111111'])->toBeArray()->and($r['22222222'])->toBeNull();
});

it('fetchCompanyStructuresPooled: dedupes input to one HTTP call per unique cvr', function () {
    Http::fake([
        '*/v1/cvr/company-structure' => Http::response(['data' => ['owners' => []]]),
    ]);

    $r = app(RegistryApi::class)->fetchCompanyStructuresPooled(['11111111', '11111111']);

    Http::assertSentCount(1);
    expect($r)->toHaveCount(1);
});

it('fetchCompanyStructuresPooled: empty input gives empty array without HTTP call', function () {
    Http::fake();

    $r = app(RegistryApi::class)->fetchCompanyStructuresPooled([]);

    expect($r)->toBe([]);
    Http::assertNothingSent();
});

it('fetchCompanyStructuresPooled: never READS the cache — refetches every call', function () {
    Http::fake([
        '*/v1/cvr/company-structure' => Http::sequence()
            ->push(['data' => ['owners' => [], 'v' => 1]])
            ->push(['data' => ['owners' => [], 'v' => 2]]),
    ]);

    $api = app(RegistryApi::class);
    $first = $api->fetchCompanyStructuresPooled(['11111111']);
    $second = $api->fetchCompanyStructuresPooled(['11111111']);

    // Fase 2 must fetch each cvr fresh — a 5-minute-old answer is exactly what
    // this endpoint exists to avoid. Note it WRITES the cache (see below); the
    // contract is read-free, not write-free.
    Http::assertSentCount(2);
    expect($first['11111111']['v'])->toBe(1)
        ->and($second['11111111']['v'])->toBe(2);
});

it('fetchCompanyStructuresPooled: WARMS the cache key fetchCompanyStructureCached reads', function () {
    Http::fake(['*/v1/cvr/company-structure' => Http::response(['data' => ['owners' => [], 'subsidiaries' => []]])]);

    $api = app(RegistryApi::class);
    $api->fetchCompanyStructuresPooled(['11111111']);

    // Without this, PersonStructure's per-tick recovery (protected state does
    // not survive a request) re-POSTs every already-loaded cvr on EVERY tick —
    // ~20 extra POSTs per 2s poll at the first-level cap.
    expect(Cache::has('metis:company_structure:11111111'))->toBeTrue();

    $api->fetchCompanyStructureCached('11111111');

    Http::assertSentCount(1); // the recovery read is a cache HIT, not a request
});

it('fetchCompanyStructuresPooled: does not cache a per-cvr failure', function () {
    Http::fake(['*/v1/cvr/company-structure' => Http::response('Server error', 500)]);

    app(RegistryApi::class)->fetchCompanyStructuresPooled(['11111111']);

    // Same rule as fetchCompanyStructureCached's own write: an error must never
    // shadow fresh data for 5 minutes.
    expect(Cache::has('metis:company_structure:11111111'))->toBeFalse();
});

it('fetchCompanyStructuresPooled: bounds Http::pool() to a concurrency of 3', function () {
    $spy = Http::spy();

    $api = app(RegistryApi::class);
    $api->fetchCompanyStructuresPooled(['77777777']);

    $spy->shouldHaveReceived('pool')
        ->withArgs(fn ($callback, $concurrency) => is_callable($callback) && $concurrency === 3);
});

/*
|--------------------------------------------------------------------------
| Fejlbesked-sanering (security P1) — upstream body må aldrig videreføres
|--------------------------------------------------------------------------
*/

it('never puts the upstream response body in the error value', function () {
    // RequestException::getMessage() APPENDS the response body (truncated at
    // 120 chars) to "HTTP request returned status code N". registry-api echoes
    // the request payload into some of its own error bodies — on the CPR path
    // that payload IS the CPR — so returning getMessage() handed the CPR to
    // every caller of this array, and onward into whatever logged or rendered
    // it. Callers only ever isset()-check this key (verified by grep across
    // src/ and tests/), so a constant loses nothing.
    Http::fake([
        '*/v1/cvr/search-by-cpr' => Http::response('validation failed for cpr 0101011234', 422),
    ]);

    $result = (new RegistryApi)->fetchCompaniesByCpr('0101011234');

    expect($result['error'])->toBe('upstream_error')
        ->and($result['status'])->toBe(422)
        ->and(json_encode($result))->not->toContain('0101011234');
});

it('reports the original exception so Flare still gets the detail', function () {
    // The detail is not thrown away, it is REROUTED: report() sends the real
    // exception down the exception channel, where the host-side Flare scrubber
    // censors it — instead of riding along in a return value that gets handed
    // around, logged and rendered with no scrubbing anywhere.
    Http::fake(['*/v1/cvr/cross-ownership' => Http::response('boom', 500)]);

    $reported = [];
    app()->bind(\Illuminate\Contracts\Debug\ExceptionHandler::class, function () use (&$reported) {
        return new class($reported) implements \Illuminate\Contracts\Debug\ExceptionHandler
        {
            public function __construct(public &$reported) {}

            public function report(\Throwable $e): void
            {
                $this->reported[] = $e;
            }

            public function shouldReport(\Throwable $e): bool
            {
                return true;
            }

            public function render($request, \Throwable $e)
            {
                return null;
            }

            public function renderForConsole($output, \Throwable $e): void {}
        };
    });

    (new RegistryApi)->fetchCrossOwnership(['11111111', '22222222']);

    expect($reported)->toHaveCount(1)
        ->and($reported[0])->toBeInstanceOf(\Illuminate\Http\Client\RequestException::class);
});

/*
|--------------------------------------------------------------------------
| Cross-ownership cache (perf P1)
|--------------------------------------------------------------------------
*/

it('caches cross-ownership so a poll tick does not refire it', function () {
    // PersonStructure calls this on mount AND on every rehydrate — which means
    // every poll tick (2s) plus every chip toggle and expand. Uncached, a page
    // open for half a minute fired ~10-12 identical POSTs at registry-api for a
    // relationship set that cannot change between them.
    Http::fake(['*/v1/cvr/cross-ownership' => Http::response(['data' => ['relationships' => [['parent' => '11111111', 'child' => '22222222']]]])]);

    $api = new RegistryApi;

    $first = $api->fetchCrossOwnershipCached(['11111111', '22222222']);
    $second = $api->fetchCrossOwnershipCached(['11111111', '22222222']);

    expect($second)->toBe($first);
    Http::assertSentCount(1);
});

it('keys the cross-ownership cache on the SET of cvrs, not their order', function () {
    // The caller derives the list from graph order, which changes as the graph
    // grows — an order-sensitive key would miss on every reordering and make
    // the cache useless exactly when the page is busiest.
    Http::fake(['*/v1/cvr/cross-ownership' => Http::response(['data' => ['relationships' => []]])]);

    $api = new RegistryApi;
    $api->fetchCrossOwnershipCached(['22222222', '11111111']);
    $api->fetchCrossOwnershipCached(['11111111', '22222222']);

    Http::assertSentCount(1);
});

it('never caches a failed cross-ownership call', function () {
    // post() turns a 500 into ['error' => …] rather than throwing. Caching that
    // would freeze the failure for 5 minutes — and in this component a
    // cross-ownership failure fails the WHOLE skeleton, so the retry button
    // would be dead for the entire TTL.
    Http::fake(['*/v1/cvr/cross-ownership' => Http::sequence()
        ->push('Server error', 500)
        ->push(['data' => ['relationships' => []]]),
    ]);

    $api = new RegistryApi;

    expect($api->fetchCrossOwnershipCached(['11111111', '22222222']))->toHaveKey('error');

    // The retry genuinely re-requests rather than replaying the cached failure.
    expect($api->fetchCrossOwnershipCached(['11111111', '22222222']))->not->toHaveKey('error');
    Http::assertSentCount(2);
});

/*
|--------------------------------------------------------------------------
| Cache tenancy — a DECISION, pinned so changing it is a conversation
|--------------------------------------------------------------------------
*/

it('company caches are deliberately tenant-neutral — revisit if registry-api ever returns token-scoped fields', function () {
    // NOT a bug being locked in. Company-info and company-structure are CVR
    // REGISTER data: public, identical for every caller, independent of who
    // asked. The token is a quota identity (billing, rate limits), not an
    // authorisation scope, so two tokens are entitled to the same bytes and a
    // shared entry leaks nothing. Namespacing per token would multiply the
    // cache by the tenant count and turn one warm cache into N cold ones.
    //
    // This test exists so the day that stops being true is NOTICED. If
    // registry-api starts returning anything token-scoped on these endpoints —
    // a per-customer note, an entitlement flag, a field one tenant sees and
    // another does not — the shared entry becomes a cross-tenant leak and the
    // key MUST gain a token component. Then this test fails, and its name
    // tells the next person that the fix is a decision, not a rename.
    Http::fake([
        '*/v1/cvr/company/*' => Http::response(['data' => ['company' => ['name' => 'Delt ApS']]]),
        '*/v1/cvr/company-structure*' => Http::response(['data' => ['subsidiaries' => []]]),
    ]);

    $api = new RegistryApi;

    session(['metis_user_token' => 'token-tenant-a']);
    $api->fetchCompanyInfo('11111111');
    $api->fetchCompanyStructureCached('11111111');

    // A DIFFERENT tenant hits the SAME entries — no second round-trip.
    session(['metis_user_token' => 'token-tenant-b']);
    $api->fetchCompanyInfo('11111111');
    $api->fetchCompanyStructureCached('11111111');

    Http::assertSentCount(2);

    // And the keys themselves carry no tenant component.
    expect(Cache::has('metis:company_info:11111111'))->toBeTrue()
        ->and(Cache::has('metis:company_structure:11111111'))->toBeTrue();
});
