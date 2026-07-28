# Persongraf på navne-siden — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Navne-siden (`/lookup/person/{navn}`) får samme Selskabsstruktur-graf som CPR-siden — uden "Private ejendomme"-chippen — så en bruger der kun kender et navn kan se hele selskabs- og ejendomsstrukturen.

**Architecture:** Nyt registry-api-endpoint bygger en search-by-cpr-**kompatibel** companies-liste live fra CVR-ES' deltager-dokument. `PersonStructure` får en eksplicit `$source`-prop der vælger endpoint; alt andet (chips, faser, enrichment, graf-builder) genbruges uændret fordi det er cvr-nøglet og ligeglad med hvor skelettet kom fra.

**Tech Stack:** Laravel 12 / PHP 8.4 (registry-api), Livewire 3 + Flux (metis-package), Pest.

**Spec:** `metis-package/docs/superpowers/specs/2026-07-28-person-graph-name-page-design.md`
**Handover:** `metis-package/docs/superpowers/specs/2026-07-28-person-graph-name-page-HANDOVER.md`

## Global Constraints

### 🚨 Branches — verificér FØR første commit

| Repo | Absolut sti | Din branch |
|---|---|---|
| registry-api | `/Users/Frederik/Herd/registry-api` | `feat/person-companies-by-name` |
| metis-package | `/Users/Frederik/Code/metis-package` | `feat/person-graph-name-page` |
| metis (host) | `/Users/Frederik/Herd/metis` | `chore/bump-metis-person-graph` |

Kør `git branch --show-current` som første handling i hver task der committer. Matcher den ikke, **STOP** og opret fra trunk. `cwd nulstilles mellem bash-kald` — brug absolut sti i **hvert** kald.

### Kontrakt og disciplin

- **Ejerskabsreglen:** `has_direct_ownership` = mindst én **aktuel** rolle hvis *mappede* type er `LegalOwner` eller `Shareholder` **og** som har en andel. 🔑 Brug den MAPPEDE type (`mapRoleType`), ikke org-navnet: `EJERREGISTER` og `Interessenter` → `LegalOwner`, `Stiftere` → `Shareholder` (`CvrService.php:1947-1949`). **"Reelle ejere" → `BeneficialOwner` og tæller ALDRIG** — at bryde den regel tegner falske ejerskabskanter.
- **`financials` udelades bevidst** fra det nye endpoint — enrichment-fasen henter selv nøgletal, og feltet er det dyreste i CPR-payloaden.
- **null ≠ tom:** 404 når `searchDeltager` intet finder, ALDRIG tom liste. Fejlet kald må aldrig rendere som "ingen selskaber".
- **CPR må ALDRIG** optræde i cache-nøgler, URL'er, node-id'er, log-beskeder eller Flare-payloads — gælder også her, fordi kodestierne deles.
- Eksterne HTTP-kald i registry-api: eksplicit `timeout()` **og** `connectTimeout()`. Statiske log-*beskeder* (varierende detaljer i context, aldrig i message — Flare-bucket-splitting).
- `retry($times)` tæller **totale** forsøg — `retry(1, …)` er en no-op.
- **`Http::assertNotSent` + `Http::pool` er INERT.** Håndhæv "endpoint kaldes aldrig" via `Http::fake` UDEN mønstret + `Http::preventStrayRequests()`.
- 🚨 **`Http::fake`-rækkefølge i metis-tests:** person-mønstre SKAL registreres FØR den generiske `*/property-portfolio*`-wildcard (insertion-order-match). Se `fakePersonPrivate` i `PersonStructureTest.php`.
- Fang ALDRIG `\Throwable` omkring HTTP i registry-api-tests (sluger `StrayRequestException`). Ingen globale `function`-helpers i Pest-filer med kollisionsrisiko.
- Testkommandoer: registry-api `cd /Users/Frederik/Herd/registry-api && php artisan test --filter=<navn>`; metis-package `cd /Users/Frederik/Code/metis-package && vendor/bin/pest --filter=<navn>` (metis-package har **ingen** composer-scripts).
- 🖥️ ÉN `--parallel`-testkørsel ad gangen på 8GB-Mac'en.

### Sekvens

**registry-api FØRST.** metis-testene faker endpointet, men prod-verifikation kræver det live. registry-api **auto-deployer ikke** — manuel Forge-trigger (server 1167658, site 3058307).

---

### Task 1: registry-api — `personCompaniesByName` i CvrService

**Repo:** registry-api — `/Users/Frederik/Herd/registry-api`

**Files:**
- Modify: `app/Services/Cvr/CvrService.php` (ny metode efter `searchPersonRolesByName`, som slutter ~:1180)
- Test: `tests/Feature/Services/CvrPersonCompaniesByNameTest.php` (ny)

**Interfaces:**
- Consumes: `searchDeltager(string $name, ?string $postalCode): ?array` (`:937`), `mapRoleType(string $orgNavn): ?CompanyRoleType` (`:1941`)
- Produces: `CvrService::personCompaniesByName(string $name): ?array` — returnerer `['person_name' => string, 'companies' => array]` eller `null` når deltageren ikke findes. Hver company-række: `cvr, name, company_type, status, is_active, has_direct_ownership, person_name, roles[{role, title, ownership_share, is_current, start_date, end_date}]`. **Ingen `financials`.**

- [ ] **Step 1: Write the failing tests**

```php
// tests/Feature/Services/CvrPersonCompaniesByNameTest.php
<?php

use App\Services\Cvr\CvrService;
use Illuminate\Support\Facades\Http;

function cvrNameDeltagerDoc(array $organisationer, ?string $gyldigTil = null): array
{
    return ['hits' => ['hits' => [['_source' => ['Vrdeltagerperson' => [
        'navne' => [['navn' => 'Test Person', 'periode' => ['gyldigFra' => '2020-01-01', 'gyldigTil' => null]]],
        'virksomhedSummariskRelation' => [[
            'virksomhed' => [
                'cvrNummer' => 12345678,
                'navne' => [['navn' => 'Test Holding ApS', 'periode' => ['gyldigTil' => null]]],
                'virksomhedsform' => [['kortBeskrivelse' => 'APS', 'periode' => ['gyldigTil' => null]]],
                'virksomhedsstatus' => [['status' => 'NORMAL', 'periode' => ['gyldigTil' => null]]],
            ],
            'organisationer' => $organisationer,
        ]],
    ]]]]];
}

function cvrNameOrg(string $orgNavn, ?float $andel, ?string $gyldigTil = null): array
{
    $vaerdier = $andel === null ? [] : [[
        'vaerdi' => (string) $andel,
        'periode' => ['gyldigFra' => '2021-03-01', 'gyldigTil' => $gyldigTil],
    ]];

    return [
        'organisationsNavn' => [['navn' => $orgNavn, 'periode' => ['gyldigFra' => '2021-03-01', 'gyldigTil' => null]]],
        'medlemsData' => [['attributter' => [[
            'type' => 'EJERANDEL_PROCENT',
            'vaerdier' => $vaerdier ?: [['vaerdi' => '1', 'periode' => ['gyldigFra' => '2021-03-01', 'gyldigTil' => $gyldigTil]]],
        ]]]],
    ];
}

it('extracts the current ownership share as a percentage', function () {
    Http::fake(['*' => Http::response(cvrNameDeltagerDoc([cvrNameOrg('EJERREGISTER', 0.5)]))]);

    $result = app(CvrService::class)->personCompaniesByName('Test Person');

    expect($result['companies'][0]['roles'][0]['ownership_share'])->toBe(50.0)
        ->and($result['companies'][0]['has_direct_ownership'])->toBeTrue();
});

it('prefers the current period over an expired one', function () {
    $org = cvrNameOrg('EJERREGISTER', null);
    $org['medlemsData'][0]['attributter'][0]['vaerdier'] = [
        ['vaerdi' => '0.9', 'periode' => ['gyldigFra' => '2019-01-01', 'gyldigTil' => '2020-12-31']],
        ['vaerdi' => '0.25', 'periode' => ['gyldigFra' => '2021-01-01', 'gyldigTil' => null]],
    ];
    Http::fake(['*' => Http::response(cvrNameDeltagerDoc([$org]))]);

    $result = app(CvrService::class)->personCompaniesByName('Test Person');

    expect($result['companies'][0]['roles'][0]['ownership_share'])->toBe(25.0);
});

it('marks the company inactive when every role period is closed', function () {
    Http::fake(['*' => Http::response(cvrNameDeltagerDoc([cvrNameOrg('EJERREGISTER', 0.5, '2023-06-30')]))]);

    $result = app(CvrService::class)->personCompaniesByName('Test Person');

    expect($result['companies'][0]['is_active'])->toBeFalse();
});

it('never counts a beneficial-owner relation as direct ownership', function () {
    // Regel-pin: "Reelle ejere" er INDIREKTE. Brydes den, tegnes falske ejerskabskanter.
    Http::fake(['*' => Http::response(cvrNameDeltagerDoc([cvrNameOrg('Reelle ejere', 0.75)]))]);

    $result = app(CvrService::class)->personCompaniesByName('Test Person');

    expect($result['companies'][0]['has_direct_ownership'])->toBeFalse();
});

it('counts Interessenter as direct ownership (maps to LegalOwner)', function () {
    Http::fake(['*' => Http::response(cvrNameDeltagerDoc([cvrNameOrg('Interessenter', 0.5)]))]);

    $result = app(CvrService::class)->personCompaniesByName('Test Person');

    expect($result['companies'][0]['has_direct_ownership'])->toBeTrue();
});

it('returns null when the participant is not found', function () {
    Http::fake(['*' => Http::response(['hits' => ['hits' => []]])]);

    expect(app(CvrService::class)->personCompaniesByName('Ukendt Navn'))->toBeNull();
});

it('carries person_name on every company row', function () {
    Http::fake(['*' => Http::response(cvrNameDeltagerDoc([cvrNameOrg('Direktion', null)]))]);

    $result = app(CvrService::class)->personCompaniesByName('Test Person');

    expect($result['person_name'])->toBe('Test Person')
        ->and($result['companies'][0]['person_name'])->toBe('Test Person');
});

it('omits financials from the payload', function () {
    Http::fake(['*' => Http::response(cvrNameDeltagerDoc([cvrNameOrg('Direktion', null)]))]);

    $result = app(CvrService::class)->personCompaniesByName('Test Person');

    expect($result['companies'][0])->not->toHaveKey('financials');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd /Users/Frederik/Herd/registry-api && php artisan test --filter=CvrPersonCompaniesByNameTest`
Expected: FAIL — `Call to undefined method … personCompaniesByName()`

- [ ] **Step 3: Implement the method**

Indsæt efter `searchPersonRolesByName` (som slutter ~:1180). Mønstret for felt-udtræk følger den metode; andel-udtrækket følger `fetchForeignOwnerLeaves` (~:3026).

```php
/**
 * Build a search-by-cpr-COMPATIBLE companies list live from the CVR-ES
 * participant document, so the name page can render the same ownership graph
 * as the CPR page without a local DB row for the person.
 *
 * Deliberately omits `financials`: the graph's enrichment phase fetches key
 * figures itself, and financials are the most expensive field in the CPR
 * payload.
 */
public function personCompaniesByName(string $name): ?array
{
    $deltager = $this->searchDeltager($name, null);

    if (! $deltager) {
        return null;
    }

    $personNames = $deltager['navne'] ?? [];
    $latestPersonName = collect($personNames)->first(fn ($n) => data_get($n, 'periode.gyldigTil') === null);
    $personName = $latestPersonName['navn'] ?? ($personNames[0]['navn'] ?? $name);

    $companies = [];

    foreach ($deltager['virksomhedSummariskRelation'] ?? [] as $relation) {
        $virksomhed = $relation['virksomhed'] ?? [];
        $cvr = (string) ($virksomhed['cvrNummer'] ?? '');

        if (! $cvr) {
            continue;
        }

        $names = $virksomhed['navne'] ?? [];
        $latestName = collect($names)->first(fn ($n) => data_get($n, 'periode.gyldigTil') === null);

        $forms = $virksomhed['virksomhedsform'] ?? [];
        $latestForm = collect($forms)->first(fn ($f) => data_get($f, 'periode.gyldigTil') === null);

        $statuses = $virksomhed['virksomhedsstatus'] ?? [];
        $latestStatus = collect($statuses)->first(fn ($s) => data_get($s, 'periode.gyldigTil') === null);

        $roles = [];

        foreach ($relation['organisationer'] ?? [] as $org) {
            $orgNames = $org['organisationsNavn'] ?? [];
            $latestOrgName = collect($orgNames)->first(fn ($n) => data_get($n, 'periode.gyldigTil') === null);
            $orgNavn = $latestOrgName['navn'] ?? ($orgNames[0]['navn'] ?? null);

            if (! $orgNavn) {
                continue;
            }

            // A vaerdier list can hold several EJERANDEL_PROCENT periods (an expired
            // historical share alongside the current one). Prefer the period whose
            // gyldigTil is null — the same rule the rest of CvrService uses.
            $values = collect($org['medlemsData'] ?? [])
                ->flatMap(fn ($medlem) => $medlem['attributter'] ?? [])
                ->filter(fn ($attr) => ($attr['type'] ?? null) === 'EJERANDEL_PROCENT')
                ->flatMap(fn ($attr) => $attr['vaerdier'] ?? [])
                ->filter(fn ($val) => is_numeric($val['vaerdi'] ?? null) && (float) $val['vaerdi'] <= 1.0);

            $chosen = $values->first(fn ($val) => data_get($val, 'periode.gyldigTil') === null)
                ?? $values->sortByDesc(fn ($val) => data_get($val, 'periode.gyldigFra'))->first();

            $ownershipShare = $chosen ? round((float) $chosen['vaerdi'] * 100, 2) : null;

            // The role is current when ANY membership period is still open.
            $isCurrent = collect($org['medlemsData'] ?? [])
                ->flatMap(fn ($medlem) => $medlem['attributter'] ?? [])
                ->flatMap(fn ($attr) => $attr['vaerdier'] ?? [])
                ->contains(fn ($val) => data_get($val, 'periode.gyldigTil') === null);

            $startDate = collect($org['medlemsData'] ?? [])
                ->flatMap(fn ($medlem) => $medlem['attributter'] ?? [])
                ->flatMap(fn ($attr) => $attr['vaerdier'] ?? [])
                ->map(fn ($val) => data_get($val, 'periode.gyldigFra'))
                ->filter()
                ->sort()
                ->first();

            $endDate = $isCurrent
                ? null
                : collect($org['medlemsData'] ?? [])
                    ->flatMap(fn ($medlem) => $medlem['attributter'] ?? [])
                    ->flatMap(fn ($attr) => $attr['vaerdier'] ?? [])
                    ->map(fn ($val) => data_get($val, 'periode.gyldigTil'))
                    ->filter()
                    ->sortDesc()
                    ->first();

            $roles[] = [
                'role' => $this->mapRoleType($orgNavn)?->value,
                'title' => $orgNavn,
                'ownership_share' => $ownershipShare,
                'is_current' => $isCurrent,
                'start_date' => $startDate,
                'end_date' => $endDate,
            ];
        }

        // Direct ownership = LegalOwner or Shareholder with a share, on a CURRENT role.
        // BeneficialOwner ("Reelle ejere") is INDIRECT and must never count — it would
        // draw ownership edges that do not exist. Same rule as formatPersonCompanies().
        $directTypes = [CompanyRoleType::LegalOwner->value, CompanyRoleType::Shareholder->value];

        $hasDirectOwnership = collect($roles)->contains(
            fn ($r) => $r['is_current']
                && ! empty($r['ownership_share'])
                && in_array($r['role'], $directTypes, true)
        );

        $companies[] = [
            'cvr' => $cvr,
            'name' => $latestName['navn'] ?? ($names[0]['navn'] ?? ''),
            'company_type' => $latestForm['kortBeskrivelse'] ?? null,
            'status' => $latestStatus['status'] ?? null,
            'is_active' => collect($roles)->contains(fn ($r) => $r['is_current']),
            'has_direct_ownership' => $hasDirectOwnership,
            'person_name' => $personName,
            'roles' => $roles,
        ];
    }

    return [
        'person_name' => $personName,
        'companies' => $companies,
    ];
}
```

Tilføj `use App\Enums\CompanyRoleType;` hvis den ikke allerede er importeret — verificér øverst i filen.

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd /Users/Frederik/Herd/registry-api && php artisan test --filter=CvrPersonCompaniesByNameTest`
Expected: PASS (8 tests)

- [ ] **Step 5: Mutation-check the ownership rule**

Skift `$directTypes` midlertidigt til at inkludere `CompanyRoleType::BeneficialOwner->value`, kør testene igen, og bekræft at *"never counts a beneficial-owner relation"* bliver **rød**. Gendan derefter og bekræft grøn. Uden dette tjek ved vi ikke om regel-pinnen virker.

- [ ] **Step 6: Commit**

```bash
cd /Users/Frederik/Herd/registry-api
git add app/Services/Cvr/CvrService.php tests/Feature/Services/CvrPersonCompaniesByNameTest.php
git commit -m "feat(cvr): build cpr-compatible companies list from participant doc by name"
```

---

### Task 2: registry-api — endpoint, cache og rute

**Repo:** registry-api — `/Users/Frederik/Herd/registry-api`

**Files:**
- Modify: `app/Http/Controllers/Api/V1/CvrController.php` (ny metode efter `personRolesByName`, `:146-159`)
- Modify: `routes/api/v1.php:134` (ny rute ved siden af `cvr/person-roles`)
- Test: `tests/Feature/Api/PersonCompaniesByNameTest.php` (ny)

**Interfaces:**
- Consumes: `CvrService::personCompaniesByName(string $name): ?array` fra Task 1
- Produces: `POST /api/v1/cvr/person-companies-by-name` med body `{"name": string}` → `200` med `{"data": {"person_name": string, "companies": [...]}}`, eller `404` når deltageren ikke findes

- [ ] **Step 1: Write the failing tests**

```php
// tests/Feature/Api/PersonCompaniesByNameTest.php
<?php

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function personCompaniesApiDoc(): array
{
    return ['hits' => ['hits' => [['_source' => ['Vrdeltagerperson' => [
        'navne' => [['navn' => 'Test Person', 'periode' => ['gyldigTil' => null]]],
        'virksomhedSummariskRelation' => [[
            'virksomhed' => [
                'cvrNummer' => 12345678,
                'navne' => [['navn' => 'Test Holding ApS', 'periode' => ['gyldigTil' => null]]],
                'virksomhedsform' => [['kortBeskrivelse' => 'APS', 'periode' => ['gyldigTil' => null]]],
                'virksomhedsstatus' => [['status' => 'NORMAL', 'periode' => ['gyldigTil' => null]]],
            ],
            'organisationer' => [[
                'organisationsNavn' => [['navn' => 'EJERREGISTER', 'periode' => ['gyldigTil' => null]]],
                'medlemsData' => [['attributter' => [[
                    'type' => 'EJERANDEL_PROCENT',
                    'vaerdier' => [['vaerdi' => '0.5', 'periode' => ['gyldigFra' => '2021-03-01', 'gyldigTil' => null]]],
                ]]]],
            ]],
        ]],
    ]]]]];
}

it('returns the companies payload for a known name', function () {
    Http::fake(['*' => Http::response(personCompaniesApiDoc())]);

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->postJson('/api/v1/cvr/person-companies-by-name', ['name' => 'Test Person'])
        ->assertOk()
        ->assertJsonPath('data.person_name', 'Test Person')
        ->assertJsonPath('data.companies.0.cvr', '12345678')
        ->assertJsonPath('data.companies.0.has_direct_ownership', true);
});

it('returns 404 when the participant is unknown — never an empty list', function () {
    // null ≠ tom: en tom liste ville rendere som "ingen selskaber" i klienten.
    Http::fake(['*' => Http::response(['hits' => ['hits' => []]])]);

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->postJson('/api/v1/cvr/person-companies-by-name', ['name' => 'Ukendt Navn'])
        ->assertStatus(404);
});

it('requires a name', function () {
    $this->actingAs(User::factory()->create(), 'sanctum')
        ->postJson('/api/v1/cvr/person-companies-by-name', [])
        ->assertStatus(422);
});

it('caches the result for an hour', function () {
    Http::fake(['*' => Http::response(personCompaniesApiDoc())]);
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/cvr/person-companies-by-name', ['name' => 'Test Person'])->assertOk();
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/cvr/person-companies-by-name', ['name' => 'Test Person'])->assertOk();

    // Positiv kontrol: uden caching ville der være to udgående kald.
    Http::assertSentCount(1);
});

it('does not cache a miss', function () {
    Http::fake(['*' => Http::response(['hits' => ['hits' => []]])]);
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/cvr/person-companies-by-name', ['name' => 'Ukendt'])->assertStatus(404);

    expect(Cache::has('cvr:person_companies_by_name:'.sha1(mb_strtolower('Ukendt'))))->toBeFalse();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd /Users/Frederik/Herd/registry-api && php artisan test --filter=PersonCompaniesByNameTest`
Expected: FAIL — ruten findes ikke (404 på alle, inkl. validerings-testen)

- [ ] **Step 3: Add the controller method**

Indsæt efter `personRolesByName` (`:146-159`):

```php
public function personCompaniesByName(Request $request): JsonResponse
{
    $request->validate([
        'name' => 'required|string|max:255',
    ]);

    $name = $request->input('name');
    // Navne-nøgle, ikke CPR — men hashet alligevel, så personnavne ikke ligger
    // i klartekst i cache-nøgler eller i eventuelle cache-dumps.
    $cacheKey = 'cvr:person_companies_by_name:'.sha1(mb_strtolower($name));

    if (! is_null($cached = Cache::get($cacheKey))) {
        return $this->success($cached);
    }

    $result = $this->cvr->personCompaniesByName($name);

    // Et miss caches ALDRIG: deltageren kan dukke op i CVR i morgen, og et
    // cachet 404 ville skjule den i en time.
    if (! $result) {
        return $this->error('Person not found', 404);
    }

    Cache::put($cacheKey, $result, 3600);

    return $this->success($result);
}
```

Tilføj `use Illuminate\Support\Facades\Cache;` øverst hvis den mangler.

- [ ] **Step 4: Register the route**

I `routes/api/v1.php`, umiddelbart efter linje 134 (`cvr/person-roles`):

```php
Route::post('cvr/person-companies-by-name', [CvrController::class, 'personCompaniesByName']);
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `cd /Users/Frederik/Herd/registry-api && php artisan test --filter=PersonCompaniesByNameTest`
Expected: PASS (5 tests)

- [ ] **Step 6: Run the full suite**

Run: `cd /Users/Frederik/Herd/registry-api && composer test`
Expected: PASS. En ny rute kan ikke brække eksisterende tests, men kør den for at være sikker.

- [ ] **Step 7: Commit**

```bash
cd /Users/Frederik/Herd/registry-api
git add app/Http/Controllers/Api/V1/CvrController.php routes/api/v1.php tests/Feature/Api/PersonCompaniesByNameTest.php
git commit -m "feat(api): add person-companies-by-name endpoint with 1h cache"
```

---

### Task 3: metis-package — RegistryApi-klientmetode

**Repo:** metis-package — `/Users/Frederik/Code/metis-package`

**Files:**
- Modify: `src/Services/RegistryApi.php` (ny metode ved siden af de øvrige person-metoder)
- Test: `tests/Feature/Services/RegistryApiPersonCompaniesByNameTest.php` (ny)

**Interfaces:**
- Consumes: `POST /v1/cvr/person-companies-by-name` fra Task 2
- Produces: `RegistryApi::fetchCompaniesByName(string $name): ?array` — returnerer aggregatets `data` (altså `['person_name' => …, 'companies' => [...]]`), `null` ved 404, og `['error' => 'upstream_error', 'status' => int]` ved transportfejl (arves fra `post()`)

- [ ] **Step 1: Write the failing tests**

```php
// tests/Feature/Services/RegistryApiPersonCompaniesByNameTest.php
<?php

use Illuminate\Support\Facades\Http;
use TheFountainhead\Metis\Services\RegistryApi;

it('returns the companies payload', function () {
    Http::fake(['*person-companies-by-name*' => Http::response([
        'data' => ['person_name' => 'Test Person', 'companies' => [['cvr' => '12345678']]],
    ])]);

    $result = app(RegistryApi::class)->fetchCompaniesByName('Test Person');

    expect($result['person_name'])->toBe('Test Person')
        ->and($result['companies'])->toHaveCount(1);
});

it('returns null on a 404 so the caller can show a genuine empty state', function () {
    Http::fake(['*person-companies-by-name*' => Http::response(['error' => 'Person not found'], 404)]);

    expect(app(RegistryApi::class)->fetchCompaniesByName('Ukendt'))->toBeNull();
});

it('surfaces a transport failure as an error shape, not as null', function () {
    // null ≠ tom: en fejl må ALDRIG kunne læses som "ingen selskaber".
    Http::fake(['*person-companies-by-name*' => Http::failedConnection('cURL error 28')]);

    $result = app(RegistryApi::class)->fetchCompaniesByName('Test Person');

    expect($result['error'])->toBe('upstream_error');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd /Users/Frederik/Code/metis-package && vendor/bin/pest --filter=RegistryApiPersonCompaniesByNameTest`
Expected: FAIL — `Call to undefined method … fetchCompaniesByName()`

- [ ] **Step 3: Implement the method**

Læs først en nabometode (fx `fetchCompaniesByCpr`) og følg dens form præcist — `post()` bærer transport-hærdningen (timeout, retry, `['error' => 'upstream_error']`-shape), så den skal bruges frem for `Http::` direkte.

```php
/**
 * Companies for a person looked up by NAME (no CPR). Returns the same shape
 * as the CPR path so PersonStructure can classify() it unchanged.
 *
 * null = participant genuinely not found (404). An error array = transport
 * failure — the caller must not render that as "no companies".
 */
public function fetchCompaniesByName(string $name): ?array
{
    $result = $this->post('/v1/cvr/person-companies-by-name', ['name' => $name]);

    if (is_null($result)) {
        return null;
    }

    if (isset($result['error'])) {
        return $result;
    }

    return $result;
}
```

⚠️ Verificér hvad `post()` returnerer ved 404 i dette repo — hvis den giver en fejl-shape frem for `null`, så map 404 eksplicit til `null` her. Læs `post()` før du skriver metoden.

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd /Users/Frederik/Code/metis-package && vendor/bin/pest --filter=RegistryApiPersonCompaniesByNameTest`
Expected: PASS (3 tests)

- [ ] **Step 5: Commit**

```bash
cd /Users/Frederik/Code/metis-package
git add src/Services/RegistryApi.php tests/Feature/Services/RegistryApiPersonCompaniesByNameTest.php
git commit -m "feat(registry-api): add fetchCompaniesByName client method"
```

---

### Task 4: metis-package — `$source`-mode i PersonStructure

**Repo:** metis-package — `/Users/Frederik/Code/metis-package`

**Files:**
- Modify: `src/Livewire/Sections/PersonStructure.php` (ny prop; `fetchCompanies()` ~:440; mount-logik omkring `:286`)
- Test: `tests/Feature/Livewire/Sections/PersonStructureNameModeTest.php` (ny)

**Interfaces:**
- Consumes: `RegistryApi::fetchCompaniesByName(string $name): ?array` fra Task 3
- Produces: `PersonStructure` med `public string $source = 'cpr'`. I name-mode: `$layers = ['ownership', 'roles']`, `privatePropertiesStatus = 'empty'` fra mount, og `fetchCompanies()` kalder navne-endpointet.

- [ ] **Step 1: Write the failing tests**

🚨 Registrér person-mønstre FØR den generiske `*/property-portfolio*`-wildcard — insertion-order-match afgør ellers testens udfald. Se `fakePersonPrivate` i `PersonStructureTest.php`.

```php
// tests/Feature/Livewire/Sections/PersonStructureNameModeTest.php
<?php

use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\Sections\PersonStructure;

function nameModeCompaniesPayload(): array
{
    return ['data' => ['person_name' => 'Test Person', 'companies' => [[
        'cvr' => '12345678',
        'name' => 'Test Holding ApS',
        'company_type' => 'APS',
        'status' => 'NORMAL',
        'is_active' => true,
        'has_direct_ownership' => true,
        'person_name' => 'Test Person',
        'roles' => [[
            'role' => 'legal_owner', 'title' => 'EJERREGISTER', 'ownership_share' => 50.0,
            'is_current' => true, 'start_date' => '2021-03-01', 'end_date' => null,
        ]],
    ]]]];
}

it('never asks for private properties in name mode', function () {
    // Håndhævet ved at UDELADE person-portfolio-mønstret + preventStrayRequests.
    // 🚨 Http::assertNotSent er INERT sammen med Http::pool — brug ikke den.
    Http::fake(['*person-companies-by-name*' => Http::response(nameModeCompaniesPayload())]);

    $test = Livewire::test(PersonStructure::class, ['query' => 'Test Person', 'source' => 'name']);

    expect($test->get('layers'))->not->toContain('private_properties')
        ->and($test->get('privatePropertiesStatus'))->toBe('empty');
});

it('builds an ownership edge with a percentage label from the name payload', function () {
    Http::fake(['*person-companies-by-name*' => Http::response(nameModeCompaniesPayload())]);

    Livewire::test(PersonStructure::class, ['query' => 'Test Person', 'source' => 'name'])
        ->assertSet('skeletonStatus', 'loaded')
        ->assertSee('Test Holding ApS');
});

it('shows the empty state immediately when the person has no active companies', function () {
    // I name-mode er 'empty' settled fra mount — ingen shimmer, ingen poll-vent.
    Http::fake(['*person-companies-by-name*' => Http::response([
        'data' => ['person_name' => 'Test Person', 'companies' => []],
    ])]);

    Livewire::test(PersonStructure::class, ['query' => 'Test Person', 'source' => 'name'])
        ->assertSet('privatePropertiesStatus', 'empty');
});

it('sets failed, not empty, when the fetch fails', function () {
    Http::fake(['*person-companies-by-name*' => Http::failedConnection('cURL error 28')]);

    Livewire::test(PersonStructure::class, ['query' => 'Test Person', 'source' => 'name'])
        ->assertSet('skeletonStatus', 'failed');
});

it('defaults to cpr mode so existing behaviour is unchanged', function () {
    expect((new PersonStructure)->source)->toBe('cpr');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd /Users/Frederik/Code/metis-package && vendor/bin/pest --filter=PersonStructureNameModeTest`
Expected: FAIL — `$source` findes ikke

- [ ] **Step 3: Add the property and mount branch**

Læs først `mount()` og linje `:286` (`privatePropertiesStatus = 'pending'`) samt `:116` for at se hvordan props initialiseres. Tilføj:

```php
/**
 * Where the company skeleton comes from. Set explicitly from the blade —
 * NEVER derived from the query's shape, since a 10-character name must not
 * be misclassified as a CPR number.
 */
public string $source = 'cpr';
```

I `mount()`, hvor lagene og statusserne sættes:

```php
if ($this->source === 'name') {
    // The private-properties layer is CPR-exclusive. It is not "empty" here —
    // the chip does not exist at all, so the layer never enters $layers.
    //
    // privatePropertiesStatus is settled from mount so #128's provisional-empty
    // mechanic behaves: tick()'s empty branch no-ops (status is not 'pending'),
    // the poll gate does not wait for the phase, and the empty message renders
    // directly instead of shimmering forever.
    $this->layers = ['ownership', 'roles'];
    $this->privatePropertiesStatus = 'empty';
} else {
    // …eksisterende cpr-initialisering, uændret…
}
```

- [ ] **Step 4: Branch `fetchCompanies()` on source**

I `fetchCompanies()` (~:440):

```php
protected function fetchCompanies(): ?array
{
    if ($this->source === 'name') {
        return app(RegistryApi::class)->fetchCompaniesByName($this->query);
    }

    // …eksisterende cpr-sti, uændret…
}
```

⚠️ Verificér hvad den eksisterende cpr-sti returnerer — hvis den giver `companies`-arrayet direkte frem for hele aggregatet, så udpak tilsvarende her (`$result['companies'] ?? null`), så `classify()` får samme form i begge modes.

- [ ] **Step 5: Run tests to verify they pass**

Run: `cd /Users/Frederik/Code/metis-package && vendor/bin/pest --filter=PersonStructureNameModeTest`
Expected: PASS (5 tests)

- [ ] **Step 6: Verify no CPR regression**

Run: `cd /Users/Frederik/Code/metis-package && vendor/bin/pest --filter=PersonStructureTest`
Expected: PASS. `$source` defaulter til `'cpr'`, så alle eksisterende tests skal være uændret grønne. Fejler én, er default-grenen brudt.

- [ ] **Step 7: Commit**

```bash
cd /Users/Frederik/Code/metis-package
git add src/Livewire/Sections/PersonStructure.php tests/Feature/Livewire/Sections/PersonStructureNameModeTest.php
git commit -m "feat(person-structure): add explicit source mode for name-based lookups"
```

---

### Task 5: metis-package — blade og CPR-note

**Repo:** metis-package — `/Users/Frederik/Code/metis-package`

**Files:**
- Modify: `resources/views/livewire/lookup.blade.php:48-49` (person-grenen)
- Modify: `resources/views/livewire/sections/person-structure.blade.php` (noten)
- Test: udvid `tests/Feature/Livewire/Sections/PersonStructureNameModeTest.php` fra Task 4

**Interfaces:**
- Consumes: `PersonStructure` med `$source`-prop fra Task 4
- Produces: navne-siden renderer grafen full-bleed over rollerne, med CPR-noten synlig i begge tilstande (graf og tom)

- [ ] **Step 1: Write the failing tests**

Tilføj til `PersonStructureNameModeTest.php`:

```php
it('shows the cpr note in name mode', function () {
    Http::fake(['*person-companies-by-name*' => Http::response(nameModeCompaniesPayload())]);

    Livewire::test(PersonStructure::class, ['query' => 'Test Person', 'source' => 'name'])
        ->assertSee('Søg med CPR-nummer');
});

it('shows the cpr note even when there are no companies', function () {
    // Særligt vigtigt her: personen KAN have private ejendomme vi ikke kan se.
    Http::fake(['*person-companies-by-name*' => Http::response([
        'data' => ['person_name' => 'Test Person', 'companies' => []],
    ])]);

    Livewire::test(PersonStructure::class, ['query' => 'Test Person', 'source' => 'name'])
        ->assertSee('Søg med CPR-nummer');
});

it('does not show the cpr note in cpr mode', function () {
    Http::fake(['*search-by-cpr*' => Http::response(['data' => ['companies' => []]])]);

    Livewire::test(PersonStructure::class, ['query' => '0101011234'])
        ->assertDontSee('Søg med CPR-nummer');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd /Users/Frederik/Code/metis-package && vendor/bin/pest --filter=PersonStructureNameModeTest`
Expected: FAIL på de tre nye — noten findes ikke

- [ ] **Step 3: Add the note to the section blade**

I `person-structure.blade.php`, både ved graf-visningen og ved tom-tilstanden. Følg `mgraph-note`-stilen der allerede bruges i filen; **ingen farvede callout-bokse**:

```blade
@if($source === 'name')
    <p class="mgraph-note">
        {{ __('Søg med CPR-nummer for også at se personens private ejendomme.') }}
    </p>
@endif
```

Verificér den præcise klasse ved at læse filen — brug den eksisterende note-stil frem for at opfinde en ny.

- [ ] **Step 4: Wire the blade into the person branch**

I `lookup.blade.php`, erstat person-grenen (linje 48-49):

```blade
@elseif($type === 'person')
    <livewire:metis-person-structure :query="$query" source="name" lazy />
    <livewire:metis-person-roles :query="$query" lazy />
```

Grafen er bevidst **uden for** `max-w-7xl`-wrapperen — full-bleed, som i cpr-grenen på linje 41.

- [ ] **Step 5: Run tests to verify they pass**

Run: `cd /Users/Frederik/Code/metis-package && vendor/bin/pest --filter=PersonStructureNameModeTest`
Expected: PASS (8 tests)

- [ ] **Step 6: Run the whole suite**

Run: `cd /Users/Frederik/Code/metis-package && vendor/bin/pest`
Expected: PASS. Blade-ændringen rører den delte lookup-side, så en regression ville vise sig her.

- [ ] **Step 7: Commit**

```bash
cd /Users/Frederik/Code/metis-package
git add resources/views/livewire/lookup.blade.php resources/views/livewire/sections/person-structure.blade.php tests/Feature/Livewire/Sections/PersonStructureNameModeTest.php
git commit -m "feat(lookup): render the ownership graph on the name page"
```

---

## Deploy-runbook (menneskestyret — IKKE en agent-task)

Udføres når Task 1-5 er merget. Kræver Forge-adgang og browser.

**Forge-ID'er (verificeret):**

| Site | Server | Site-ID | Deploy |
|---|---|---|---|
| `registry-api.frankston.io` | 1167658 | 3058307 | **manuel trigger** |
| `metis.frankston.io` | 1167658 | 3093070 | auto ved merge til main |

1. **registry-api først.** Notér prod-SHA før deploy. Merge PR, udløs manuel Forge-deploy. Uden dette svarer navne-endpointet 404 i prod, og grafen viser fejltilstand.
2. **🚨 Tjek åbne PR'er i registry-api før deploy** — deploy aldrig en anden agents u-mergede arbejde. `git log origin/main` og `gh pr list`.
3. **metis-package:** merge PR → `composer update thefountainhead/metis` i `/Users/Frederik/Herd/metis` → bump-PR → merge = auto-deploy.
4. **`php artisan view:clear`** på metis-prod via Forge command-API. Blade-ændringer slår ikke igennem uden.
5. **Browser-verifikation med åben konsol.** Søg på **"frederik larnæs"** (Frederiks egen testcase 28/7: person med roller/ejerskab i bl.a. Trygve Ejendomme A/S og FDL-Invest ApS, 7 selskaber med 35 ejendomme).

   Forvent: graf med ejerskabskanter og procent-labels hvor EJERREGISTER har andel · chips "Ejerskab"/"Roller" med badges · datterselskabs- og ejendomsfaser der loader progressivt · CPR-noten synlig · **INGEN "Private ejendomme"-chip** · konsollen ren.

   ⚠️ Metis' lazy-sektioner fyrer først intersects ved **scroll** i automatiserede browser-sessioner — scroll før du konkluderer at noget hænger.
6. **Regression:** CPR-siden (admin-CPR) uændret, inklusive privat-ejendoms-laget.
7. **Flare-watch** 30 min på begge sites.

**Rollback:** registry-api → Forge-deploy fra noteret SHA. metis → revert bump-commit → auto-deploy → `view:clear`. Ingen migrationer.

## Non-goals

Disambiguation-picker (`personDisambiguate`, F5 — egen runde) · private ejendomme via navn · enhedsnummer-baserede URL'er · ændringer af `person-roles`-endpointet eller `PersonRoles`-sektionen · finansdata i navne-payloaden.
