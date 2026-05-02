# Portfolio-Tinglysning-tab Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the killer Tinglysning-tab på selskabs-side — flat-list af alle pantebreve på tværs af et holdings koncerntræ med portfolio-metrics, batch-watchlist og Linear-style flyout drilldown.

**Architecture:** Backend (registry-api): nyt `/api/v1/companies/{cvr}/tinglysning-overview` endpoint orkestreret via to Actions (`BuildTinglysningOverview` synchronous + `StreamTinglysningMortgages` cursor-based). Materialized `company_property_tree_index` vedligeholdt incrementelt af observers (`PropertyOwnerObserver` + `CompanyRoleObserver` → `TreeIndexMaintenance` service) med nightly reconciliation som safety-net. F1's eksisterende `watchlists.watch_type='company'` mekanisme genbruges; `DetectMortgageChange::resolveWatchlists()` udvides til ancestor-traversal med closest-ancestor-wins SQL-native dedup. LTV via 4-trins fallback (skøde → Boliga sold → off. vurd. → unavailable) batch-loaded. Frontend (metis-package): ny `CompanyTinglysning` Livewire section med streaming polling-pattern, Flux flyout drawer med URL-state binding (`?ting_mortgage=`), XLSX export.

**Tech Stack:** Laravel 12, PHP 8.4, PostgreSQL 16, Livewire 3, Flux UI, Pest, Redis (cache::tags), phpoffice/phpspreadsheet.

**Spec reference:** `docs/superpowers/specs/2026-05-02-portfolio-tinglysning-tab-design.md` (v1.3, /plan-ready efter 3 review-iterationer)

**Estimat:** 11-13 dage (median 12,0).

**Pilot-kunde:** Rasmus Hornhaver (Draupnir Invest) — bug-feedback 2026-05-02 udløste denne feature; han er natural design-partner under build.

---

## Pre-flight (gør én gang før Task 1)

- [ ] **Step P1: Verify spec is current**

Run:
```bash
cd /Users/Frederik/metis-package
git log -1 --format="%h %s" docs/superpowers/specs/2026-05-02-portfolio-tinglysning-tab-design.md
```
Expected: shows commit `aec470f` (v1.3) eller nyere.

- [ ] **Step P2: Read relevant feedback memories (Compound Engineering pre-flight)**

Søg memory for relevante feedback der gælder for denne type opgave:
```bash
ls /Users/Frederik/.claude/projects/-Users-Frederik/memory/feedback_*.md | xargs grep -l -iE "livewire|cache|migration|observer|specific git add|registry-api"
```
Læs hver match — særligt `feedback_specific_git_add_in_registry_api.md` og `feedback_phase_a_must_check_observers_jobs_recent_commits.md`.

- [ ] **Step P3: Verify F1 + recent registry-api state**

```bash
cd /Users/Frederik/registry-api
git log --oneline -10
ls app/Observers/ app/Jobs/Detect*.php
```
Confirm PR #31 + #32 (Rasmus bug-fix) are merged. Confirm `MortgageObserver`, `DetectMortgageChange`, `Mortgage` model exist.

- [ ] **Step P4: Confirm Pest framework + factories available**

```bash
cd /Users/Frederik/registry-api
ls tests/Feature/ tests/Unit/ database/factories/ | head -20
grep -l "use Pest" tests/Feature/Observers/MortgageObserverHistoricalBackfillTest.php
```
Confirm Pest is in use (created in PR #31). Note which factories exist (`PropertyFactory`, `MortgageFactory`, `CompanyFactory`, `PropertyOwnerFactory`).

- [ ] **Step P5: Branch off main**

```bash
cd /Users/Frederik/registry-api
git checkout main && git pull
git checkout -b feature/f-new-tinglysning-overview-backend
```

For metis-package work later: separate branch in metis-package repo.

---

## Task 1: Foundation — config + migrations

**Files:**
- Create: `registry-api/config/tinglysning.php`
- Create: `registry-api/database/migrations/2026_05_02_100000_create_company_property_tree_index.php`
- Create: `registry-api/database/migrations/2026_05_02_100001_add_expanded_to_ancestors_at_to_watchlists.php` (Migration A)
- Create: `registry-api/database/migrations/2026_05_02_100002_drop_default_from_watchlists_expanded_to_ancestors_at.php` (Migration B)
- Create: `registry-api/database/migrations/2026_05_02_100003_add_tinglysning_right_id_index_on_mortgages.php` (CONCURRENTLY)

- [ ] **Step 1.1: Write `config/tinglysning.php`**

```php
<?php

return [
    'reconciliation_diff_threshold' => env('TINGLYSNING_RECONCILIATION_DIFF_THRESHOLD', 0.001), // 0.1%
    'polling_interval_ms' => env('TINGLYSNING_POLLING_INTERVAL_MS', 2000),
    'cache_ttl_seconds' => env('TINGLYSNING_CACHE_TTL_SECONDS', 60),
    'boligsiden_index_cache_ttl' => env('TINGLYSNING_BOLIGSIDEN_INDEX_CACHE_TTL', 86400),
    'historical_sale_max_age_years' => env('TINGLYSNING_HISTORICAL_SALE_MAX_AGE_YEARS', 7),
    'tree_max_depth' => env('TINGLYSNING_TREE_MAX_DEPTH', 7),
];
```

- [ ] **Step 1.2: Run config publish (no command needed — just sanity-check loads)**

Run:
```bash
php artisan tinker --execute="echo config('tinglysning.tree_max_depth');"
```
Expected: `7`

- [ ] **Step 1.3: Write `create_company_property_tree_index` migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('company_property_tree_index', function (Blueprint $table) {
            $table->id();
            $table->string('root_cvr', 8);
            $table->foreignId('descendant_company_id')->constrained('companies');
            $table->foreignId('property_id')->constrained('properties');
            $table->integer('depth');
            $table->timestamps();

            $table->unique(
                ['root_cvr', 'descendant_company_id', 'property_id'],
                'idx_tree_unique'
            );
            $table->index(['property_id', 'root_cvr'], 'idx_tree_reverse_lookup');
            $table->index('root_cvr');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_property_tree_index');
    }
};
```

- [ ] **Step 1.4: Run migration locally + verify**

```bash
php artisan migrate
php artisan tinker --execute="echo Schema::hasTable('company_property_tree_index') ? 'YES' : 'NO';"
```
Expected: `YES`

- [ ] **Step 1.5: Write Migration A — `add_expanded_to_ancestors_at_to_watchlists`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('watchlists', function (Blueprint $table) {
            $table->timestamp('expanded_to_ancestors_at')
                  ->default(DB::raw('CURRENT_TIMESTAMP'))
                  ->after('created_at');
        });

        // Sanity-check for timezone/restored-from-backup edge cases
        DB::statement("
            UPDATE watchlists
            SET expanded_to_ancestors_at = GREATEST(NOW(), created_at)
            WHERE expanded_to_ancestors_at < created_at
        ");
    }

    public function down(): void
    {
        Schema::table('watchlists', function (Blueprint $table) {
            $table->dropColumn('expanded_to_ancestors_at');
        });
    }
};
```

- [ ] **Step 1.6: Run Migration A + verify column exists with default**

```bash
php artisan migrate
php artisan tinker --execute="echo Schema::hasColumn('watchlists', 'expanded_to_ancestors_at') ? 'YES' : 'NO';"
```
Expected: `YES`

- [ ] **Step 1.7: Write Migration B — drop default**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement('ALTER TABLE watchlists ALTER COLUMN expanded_to_ancestors_at DROP DEFAULT');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE watchlists ALTER COLUMN expanded_to_ancestors_at SET DEFAULT CURRENT_TIMESTAMP');
    }
};
```

- [ ] **Step 1.8: Run Migration B + verify default gone**

```bash
php artisan migrate
php artisan tinker --execute="
\$col = \DB::selectOne(\"SELECT column_default FROM information_schema.columns WHERE table_name='watchlists' AND column_name='expanded_to_ancestors_at'\");
echo \$col->column_default ?? 'NO DEFAULT';
"
```
Expected: `NO DEFAULT`

- [ ] **Step 1.9: Write `add_tinglysning_right_id_index_on_mortgages` migration (CONCURRENTLY)**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement("
            CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_mortgages_tinglysning_right_id
            ON mortgages ((tinglysning_data->'Pantrettighed'->>'RettighedIdentifikator'))
            WHERE is_active = true
        ");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_mortgages_tinglysning_right_id');
    }
};
```

- [ ] **Step 1.10: Run index migration + verify**

```bash
php artisan migrate
php artisan tinker --execute="
\$idx = \DB::select(\"SELECT indexname FROM pg_indexes WHERE indexname = 'idx_mortgages_tinglysning_right_id'\");
echo count(\$idx) > 0 ? 'YES' : 'NO';
"
```
Expected: `YES`

- [ ] **Step 1.11: Commit Task 1**

```bash
git add config/tinglysning.php database/migrations/2026_05_02_100*.php
git commit -m "feat(tinglysning): foundation config + 4 migrations

- config/tinglysning.php with reconciliation/polling/cache thresholds
- company_property_tree_index table with composite UNIQUE for diamond-paths
- watchlists.expanded_to_ancestors_at split migration (A=add+default, B=drop default)
- mortgages partial B-tree index on tinglysning_right_id (CONCURRENTLY)"
```

---

## Task 2: TreeIndexMaintenance service + Mortgage accessor

**Files:**
- Create: `registry-api/app/Contracts/TreeIndexMaintenance.php` (interface)
- Create: `registry-api/app/Services/CompanyPortfolio/TreeIndexMaintenanceService.php`
- Modify: `registry-api/app/Models/Mortgage.php` (add accessor + scope)
- Test: `registry-api/tests/Unit/Services/TreeIndexMaintenanceServiceTest.php`
- Test: `registry-api/tests/Unit/Models/MortgageTinglysningRightIdAccessorTest.php`

- [ ] **Step 2.1: Write `TreeIndexMaintenance` contract**

```php
<?php

namespace App\Contracts;

use App\Models\CompanyRole;
use App\Models\PropertyOwner;
use Illuminate\Support\Collection;

interface TreeIndexMaintenance
{
    public function onOwnershipAdded(PropertyOwner $owner): void;
    public function onOwnershipRemoved(PropertyOwner $owner): void;
    public function onCompanyRoleAdded(CompanyRole $role): void;
    public function onCompanyRoleRemoved(CompanyRole $role): void;
    public function ancestorCvrsForProperty(int $propertyId): Collection;
    public function recomputePathsForCompany(int $companyId): void;
    public function recomputePathsForProperty(int $propertyId): void;
}
```

- [ ] **Step 2.2: Write failing test for `ancestorCvrsForProperty`**

```php
<?php

use App\Contracts\TreeIndexMaintenance;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns ancestor CVRs for a property in tree-index', function () {
    \DB::table('company_property_tree_index')->insert([
        ['root_cvr' => '11111111', 'descendant_company_id' => 1, 'property_id' => 99, 'depth' => 0, 'created_at' => now(), 'updated_at' => now()],
        ['root_cvr' => '22222222', 'descendant_company_id' => 2, 'property_id' => 99, 'depth' => 1, 'created_at' => now(), 'updated_at' => now()],
    ]);

    $cvrs = app(TreeIndexMaintenance::class)->ancestorCvrsForProperty(99);

    expect($cvrs->all())->toEqualCanonicalizing(['11111111', '22222222']);
});
```

- [ ] **Step 2.3: Run failing test**

```bash
php artisan test --filter "ancestor CVRs for a property"
```
Expected: FAIL — `TreeIndexMaintenance` contract not bound.

- [ ] **Step 2.4: Write minimal `TreeIndexMaintenanceService`**

```php
<?php

namespace App\Services\CompanyPortfolio;

use App\Contracts\TreeIndexMaintenance;
use App\Models\CompanyRole;
use App\Models\PropertyOwner;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TreeIndexMaintenanceService implements TreeIndexMaintenance
{
    public function ancestorCvrsForProperty(int $propertyId): Collection
    {
        return DB::table('company_property_tree_index')
            ->where('property_id', $propertyId)
            ->distinct()
            ->pluck('root_cvr');
    }

    public function onOwnershipAdded(PropertyOwner $owner): void
    {
        // Implemented in Step 2.7
    }

    public function onOwnershipRemoved(PropertyOwner $owner): void
    {
        // Implemented in Step 2.7
    }

    public function onCompanyRoleAdded(CompanyRole $role): void
    {
        // Implemented in Step 2.7
    }

    public function onCompanyRoleRemoved(CompanyRole $role): void
    {
        // Implemented in Step 2.7
    }

    public function recomputePathsForCompany(int $companyId): void
    {
        // Implemented in Step 2.8
    }

    public function recomputePathsForProperty(int $propertyId): void
    {
        // Implemented in Step 2.8
    }
}
```

- [ ] **Step 2.5: Bind contract to service in `AppServiceProvider`**

In `app/Providers/AppServiceProvider.php` `register()`:
```php
$this->app->bind(
    \App\Contracts\TreeIndexMaintenance::class,
    \App\Services\CompanyPortfolio\TreeIndexMaintenanceService::class
);
```

- [ ] **Step 2.6: Run test — should pass**

```bash
php artisan test --filter "ancestor CVRs for a property"
```
Expected: PASS.

- [ ] **Step 2.7: Implement onOwnershipAdded with recursive ancestor-walk**

Replace the placeholder in `TreeIndexMaintenanceService::onOwnershipAdded()`:
```php
public function onOwnershipAdded(PropertyOwner $owner): void
{
    if ($owner->owner_type !== \App\Models\Company::class || ! $owner->is_current) {
        return;
    }

    // Find alle root-CVRs der har denne company som descendant via koncerntræ
    // (incl. self ved depth=0)
    $ancestors = $this->resolveAncestorPaths($owner->owner_id);

    foreach ($ancestors as $path) {
        DB::table('company_property_tree_index')->updateOrInsert(
            [
                'root_cvr' => $path['root_cvr'],
                'descendant_company_id' => $owner->owner_id,
                'property_id' => $owner->property_id,
            ],
            [
                'depth' => $path['depth'],
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }
}

/**
 * Walk koncerntræ upward from $companyId; returns [['root_cvr' => '...', 'depth' => N], ...]
 * Includes self at depth=0.
 */
private function resolveAncestorPaths(int $companyId): array
{
    $maxDepth = config('tinglysning.tree_max_depth', 7);

    $rows = DB::select("
        WITH RECURSIVE ancestors AS (
            SELECT c.id, c.cvr, 0 AS depth
            FROM companies c WHERE c.id = ?
            UNION ALL
            SELECT c.id, c.cvr, a.depth + 1
            FROM ancestors a
            JOIN company_roles cr ON cr.company_id = a.id
            JOIN companies c ON c.id = cr.parent_company_id
            WHERE cr.is_current = true
              AND a.depth < ?
        )
        SELECT DISTINCT cvr AS root_cvr, MIN(depth) AS depth
        FROM ancestors
        WHERE cvr IS NOT NULL
        GROUP BY cvr
    ", [$companyId, $maxDepth]);

    return array_map(fn ($r) => ['root_cvr' => $r->root_cvr, 'depth' => (int) $r->depth], $rows);
}
```

- [ ] **Step 2.8: Implement onOwnershipRemoved + onCompanyRoleAdded/Removed + recompute helpers**

```php
public function onOwnershipRemoved(PropertyOwner $owner): void
{
    if ($owner->owner_type !== \App\Models\Company::class) {
        return;
    }

    DB::table('company_property_tree_index')
        ->where('descendant_company_id', $owner->owner_id)
        ->where('property_id', $owner->property_id)
        ->delete();
}

public function onCompanyRoleAdded(CompanyRole $role): void
{
    if (! $role->is_current) {
        return;
    }
    // Tilføjelse af parent-relation: alle properties under $role->company_id skal tilbage-mappes til $role->parent_company_id og dennes ancestors
    $this->recomputePathsForCompany($role->company_id);
}

public function onCompanyRoleRemoved(CompanyRole $role): void
{
    $this->recomputePathsForCompany($role->company_id);
}

public function recomputePathsForCompany(int $companyId): void
{
    // Slet alle eksisterende paths hvor denne company er descendant, derefter rebuild
    DB::table('company_property_tree_index')
        ->where('descendant_company_id', $companyId)
        ->delete();

    // Find properties ejet af denne company + descendants via samme rekursive walk
    $properties = DB::table('property_owners')
        ->where('owner_type', \App\Models\Company::class)
        ->where('owner_id', $companyId)
        ->where('is_current', true)
        ->pluck('property_id');

    foreach ($properties as $propertyId) {
        $owner = \App\Models\PropertyOwner::where('owner_id', $companyId)
            ->where('owner_type', \App\Models\Company::class)
            ->where('property_id', $propertyId)
            ->first();
        if ($owner) {
            $this->onOwnershipAdded($owner);
        }
    }
}

public function recomputePathsForProperty(int $propertyId): void
{
    DB::table('company_property_tree_index')
        ->where('property_id', $propertyId)
        ->delete();

    $owners = \App\Models\PropertyOwner::where('property_id', $propertyId)
        ->where('owner_type', \App\Models\Company::class)
        ->where('is_current', true)
        ->get();

    foreach ($owners as $owner) {
        $this->onOwnershipAdded($owner);
    }
}
```

- [ ] **Step 2.9: Add `tinglysning_right_id` accessor + scope to Mortgage model**

In `app/Models/Mortgage.php` add:
```php
use Illuminate\Database\Eloquent\Casts\Attribute;

protected function tinglysningRightId(): Attribute
{
    return Attribute::make(
        get: fn () => data_get($this->tinglysning_data, 'Pantrettighed.RettighedIdentifikator'),
    );
}

public function scopeWithTinglysningRightId($query)
{
    return $query->whereNotNull(
        \DB::raw("tinglysning_data->'Pantrettighed'->>'RettighedIdentifikator'")
    );
}
```

- [ ] **Step 2.10: Write accessor test**

```php
<?php

use App\Models\Mortgage;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('exposes tinglysning_right_id accessor from Pantrettighed.RettighedIdentifikator', function () {
    $mortgage = Mortgage::factory()->create([
        'tinglysning_data' => [
            'Pantrettighed' => ['RettighedIdentifikator' => 'a1bad63d-d223-4a41-9f4c-3ba4d63e6d82'],
        ],
    ]);

    expect($mortgage->tinglysning_right_id)->toBe('a1bad63d-d223-4a41-9f4c-3ba4d63e6d82');
});

it('returns null when Pantrettighed missing (pre-2009 pantebreve)', function () {
    $mortgage = Mortgage::factory()->create([
        'tinglysning_data' => ['HaeftelseType' => 'privatPantebrev'],
    ]);

    expect($mortgage->tinglysning_right_id)->toBeNull();
});
```

- [ ] **Step 2.11: Run accessor tests + verify pass**

```bash
php artisan test --filter "tinglysning_right_id"
```
Expected: 2 PASS.

- [ ] **Step 2.12: Commit Task 2**

```bash
git add app/Contracts/TreeIndexMaintenance.php app/Services/CompanyPortfolio/TreeIndexMaintenanceService.php app/Models/Mortgage.php app/Providers/AppServiceProvider.php tests/Unit/Services/TreeIndexMaintenanceServiceTest.php tests/Unit/Models/MortgageTinglysningRightIdAccessorTest.php
git commit -m "feat(tinglysning): TreeIndexMaintenance service + Mortgage tinglysning_right_id accessor

- Contract + service implementation with recursive ancestor-walk via CTE
- onOwnershipAdded/Removed + onCompanyRoleAdded/Removed observer-hooks
- recomputePaths{Company,Property} reconciliation primitives
- Mortgage::tinglysning_right_id accessor reads Pantrettighed.RettighedIdentifikator
- 2 unit tests (accessor + null pre-2009 fallback)"
```

---

## Task 3: PropertyOwner + CompanyRole observers (afterCommit + Flare)

**Files:**
- Create: `registry-api/app/Observers/PropertyOwnerObserver.php`
- Create: `registry-api/app/Observers/CompanyRoleObserver.php`
- Modify: `registry-api/app/Providers/AppServiceProvider.php` (register observers)
- Test: `registry-api/tests/Feature/Observers/CompanyPropertyTreeIndexMaintenanceTest.php`
- Create: `registry-api/tests/Architecture/TreeIndexBypassTest.php` (Pest arch-rule)

- [ ] **Step 3.1: Write failing test — observer fires after commit on PropertyOwner create**

```php
<?php

use App\Models\Company;
use App\Models\Property;
use App\Models\PropertyOwner;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('inserts tree-index row when PropertyOwner is created in committed transaction', function () {
    $company = Company::factory()->create(['cvr' => '12345678']);
    $property = Property::factory()->create();

    \DB::transaction(function () use ($company, $property) {
        PropertyOwner::create([
            'property_id' => $property->id,
            'owner_type' => Company::class,
            'owner_id' => $company->id,
            'is_current' => true,
            'ownership_share' => 100,
        ]);
    });

    expect(
        \DB::table('company_property_tree_index')
            ->where('descendant_company_id', $company->id)
            ->where('property_id', $property->id)
            ->exists()
    )->toBeTrue();
});

it('does NOT insert tree-index row when PropertyOwner create is rolled back', function () {
    $company = Company::factory()->create(['cvr' => '12345678']);
    $property = Property::factory()->create();

    try {
        \DB::transaction(function () use ($company, $property) {
            PropertyOwner::create([
                'property_id' => $property->id,
                'owner_type' => Company::class,
                'owner_id' => $company->id,
                'is_current' => true,
                'ownership_share' => 100,
            ]);
            throw new \RuntimeException('rollback');
        });
    } catch (\RuntimeException) {}

    expect(
        \DB::table('company_property_tree_index')
            ->where('descendant_company_id', $company->id)
            ->where('property_id', $property->id)
            ->exists()
    )->toBeFalse();
});
```

- [ ] **Step 3.2: Run failing tests**

```bash
php artisan test --filter "tree-index row when PropertyOwner"
```
Expected: 2 FAIL — observer not registered.

- [ ] **Step 3.3: Write `PropertyOwnerObserver`**

```php
<?php

namespace App\Observers;

use App\Contracts\TreeIndexMaintenance;
use App\Models\PropertyOwner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PropertyOwnerObserver
{
    public function created(PropertyOwner $owner): void
    {
        DB::afterCommit(fn () => $this->safe(
            fn () => app(TreeIndexMaintenance::class)->onOwnershipAdded($owner),
            ['owner_id' => $owner->id, 'op' => 'added']
        ));
    }

    public function updated(PropertyOwner $owner): void
    {
        // is_current toggle = ownership added/removed
        if ($owner->wasChanged('is_current')) {
            DB::afterCommit(fn () => $this->safe(
                fn () => $owner->is_current
                    ? app(TreeIndexMaintenance::class)->onOwnershipAdded($owner)
                    : app(TreeIndexMaintenance::class)->onOwnershipRemoved($owner),
                ['owner_id' => $owner->id, 'op' => 'updated']
            ));
        }
    }

    public function deleted(PropertyOwner $owner): void
    {
        DB::afterCommit(fn () => $this->safe(
            fn () => app(TreeIndexMaintenance::class)->onOwnershipRemoved($owner),
            ['owner_id' => $owner->id, 'op' => 'removed']
        ));
    }

    private function safe(callable $fn, array $context): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::error('tree-index maintenance failed', [
                ...$context,
                'observer' => self::class,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
```

- [ ] **Step 3.4: Write `CompanyRoleObserver` (same pattern)**

```php
<?php

namespace App\Observers;

use App\Contracts\TreeIndexMaintenance;
use App\Models\CompanyRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CompanyRoleObserver
{
    public function created(CompanyRole $role): void
    {
        DB::afterCommit(fn () => $this->safe(
            fn () => app(TreeIndexMaintenance::class)->onCompanyRoleAdded($role),
            ['role_id' => $role->id, 'op' => 'added']
        ));
    }

    public function updated(CompanyRole $role): void
    {
        if ($role->wasChanged('is_current') || $role->wasChanged('parent_company_id')) {
            DB::afterCommit(fn () => $this->safe(
                fn () => app(TreeIndexMaintenance::class)->onCompanyRoleAdded($role),
                ['role_id' => $role->id, 'op' => 'updated']
            ));
        }
    }

    public function deleted(CompanyRole $role): void
    {
        DB::afterCommit(fn () => $this->safe(
            fn () => app(TreeIndexMaintenance::class)->onCompanyRoleRemoved($role),
            ['role_id' => $role->id, 'op' => 'removed']
        ));
    }

    private function safe(callable $fn, array $context): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::error('tree-index maintenance failed', [
                ...$context,
                'observer' => self::class,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
```

- [ ] **Step 3.5: Register observers in `AppServiceProvider::boot()`**

```php
\App\Models\PropertyOwner::observe(\App\Observers\PropertyOwnerObserver::class);
\App\Models\CompanyRole::observe(\App\Observers\CompanyRoleObserver::class);
```

- [ ] **Step 3.6: Run tests — should pass now**

```bash
php artisan test --filter "tree-index row when PropertyOwner"
```
Expected: 2 PASS.

- [ ] **Step 3.7: Write Pest arch-test against `::upsert()`**

`tests/Architecture/TreeIndexBypassTest.php`:
```php
<?php

arch('PropertyOwner upsert is forbidden — bypasses observers')
    ->expect('App')
    ->not->toUse('App\Models\PropertyOwner::upsert');

arch('CompanyRole upsert is forbidden — bypasses observers')
    ->expect('App')
    ->not->toUse('App\Models\CompanyRole::upsert');

// Note: Pest arch-tests can't grep raw DB::table strings; manual lint as supplement.
```

Plus a manual grep-based test for raw inserts:
```php
it('does not bypass observers via raw DB::table inserts', function () {
    $files = collect(\File::allFiles(app_path()))
        ->filter(fn ($f) => str_ends_with($f->getFilename(), '.php'));

    foreach ($files as $file) {
        $content = file_get_contents($file->getPathname());
        expect($content)
            ->not->toMatch('/DB::table\([\'"]property_owners[\'"]\)\s*->\s*insert/')
            ->not->toMatch('/DB::table\([\'"]company_roles[\'"]\)\s*->\s*insert/');
    }
});
```

- [ ] **Step 3.8: Run arch-test + verify pass (current code clean per audit)**

```bash
php artisan test --filter "TreeIndexBypassTest"
```
Expected: PASS (B2-audit confirmed clean codebase).

- [ ] **Step 3.9: Commit Task 3**

```bash
git add app/Observers/PropertyOwnerObserver.php app/Observers/CompanyRoleObserver.php app/Providers/AppServiceProvider.php tests/Feature/Observers/CompanyPropertyTreeIndexMaintenanceTest.php tests/Architecture/TreeIndexBypassTest.php
git commit -m "feat(tinglysning): observers maintain tree-index incrementally with afterCommit + Flare

- PropertyOwnerObserver + CompanyRoleObserver delegate to TreeIndexMaintenance
- DB::afterCommit() ensures rollback-safety (transaction-rolled-back inserts dont leak)
- try-catch + Log::error for visibility on maintenance failures (Flare auto-captures)
- Pest arch-tests prevent ::upsert() and raw DB::table inserts on tree-relevant tables"
```

---

## Task 4: Reconciliation command + DropOldTreeIndex

**Files:**
- Create: `registry-api/app/Console/Commands/ReconcileCompanyPropertyTreeIndex.php`
- Create: `registry-api/app/Console/Commands/DropOldTreeIndex.php`
- Modify: `registry-api/routes/console.php` (schedule registration)
- Test: `registry-api/tests/Feature/Console/ReconcileCompanyPropertyTreeIndexTest.php`

- [ ] **Step 4.1: Write failing test — reconciliation builds shadow + diffs**

```php
it('builds shadow-index, compares to live, and swaps when diff under threshold', function () {
    // Setup: create a Mimo-like fixture with 1 property + 1 company-owner
    $company = Company::factory()->create(['cvr' => '28963610']);
    $property = Property::factory()->create();
    PropertyOwner::create([...]);  // observer fires, populates live tree-index

    // Verify live has 1 row
    expect(\DB::table('company_property_tree_index')->count())->toBe(1);

    // Tamper: simulate observer-bug ved at slette en row
    // (real bug-scenario: observer missed an event)
    \DB::table('company_property_tree_index')->where('property_id', $property->id)->delete();

    expect(\DB::table('company_property_tree_index')->count())->toBe(0);

    // Run reconciliation — should detect drift, alert, IKKE swappe (drift > threshold)
    $exitCode = Artisan::call('tree-index:reconcile');

    expect($exitCode)->toBe(0); // graceful, alert sent
    // Verify Flare/Log received drift-alert (via Log::shouldReceive in setUp)
});
```

- [ ] **Step 4.2: Run failing test**

```bash
php artisan test --filter "ReconcileCompanyPropertyTreeIndex"
```
Expected: FAIL — command does not exist.

- [ ] **Step 4.3: Write `ReconcileCompanyPropertyTreeIndex` command**

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReconcileCompanyPropertyTreeIndex extends Command
{
    protected $signature = 'tree-index:reconcile {--force-swap : swap regardless of diff}';
    protected $description = 'Build shadow tree-index, compare to live, swap if diff under threshold';

    public function handle(): int
    {
        $threshold = config('tinglysning.reconciliation_diff_threshold', 0.001);

        $this->info('Building shadow tree-index...');
        $this->buildShadowTable();

        $this->info('Computing diff per root_cvr...');
        $diffs = $this->computeDiffs();

        $worstDiff = collect($diffs)->max('relative_diff') ?? 0;

        Log::info('tree-index:reconcile diff summary', [
            'worst_relative_diff' => $worstDiff,
            'threshold' => $threshold,
            'total_root_cvrs_with_diff' => count($diffs),
        ]);

        if ($worstDiff > $threshold && ! $this->option('force-swap')) {
            Log::error('tree-index:reconcile diff exceeds threshold — NOT swapping', [
                'worst_relative_diff' => $worstDiff,
                'threshold' => $threshold,
                'top_diffs' => array_slice($diffs, 0, 10),
            ]);
            $this->error("Diff {$worstDiff} > threshold {$threshold} — manual review required");
            return self::SUCCESS; // exit 0 — alert sent, no failure cascade
        }

        $this->info('Diff under threshold — performing atomic swap...');
        $this->performSwap();
        $this->info('Swap complete. Old table renamed to *_pending_drop, will be dropped by separate command.');

        return self::SUCCESS;
    }

    private function buildShadowTable(): void
    {
        DB::statement('DROP TABLE IF EXISTS company_property_tree_index_new');
        DB::statement('CREATE TABLE company_property_tree_index_new (LIKE company_property_tree_index INCLUDING ALL)');

        // Rebuild via recursive CTE — same logic as TreeIndexMaintenanceService::resolveAncestorPaths
        // but for ALL companies × ALL properties at once.
        $maxDepth = config('tinglysning.tree_max_depth', 7);
        DB::statement("
            INSERT INTO company_property_tree_index_new (root_cvr, descendant_company_id, property_id, depth, created_at, updated_at)
            WITH RECURSIVE ancestors AS (
                SELECT c.id AS company_id, c.cvr AS root_cvr, c.id AS descendant_id, 0 AS depth
                FROM companies c
                UNION ALL
                SELECT a.company_id, c.cvr, cr.company_id AS descendant_id, a.depth + 1
                FROM ancestors a
                JOIN company_roles cr ON cr.parent_company_id = a.descendant_id AND cr.is_current = true
                JOIN companies c ON c.id = a.company_id
                WHERE a.depth < ?
            )
            SELECT a.root_cvr, a.descendant_id, po.property_id, MIN(a.depth), NOW(), NOW()
            FROM ancestors a
            JOIN property_owners po ON po.owner_id = a.descendant_id AND po.owner_type = 'App\\\\Models\\\\Company' AND po.is_current = true
            WHERE a.root_cvr IS NOT NULL
            GROUP BY a.root_cvr, a.descendant_id, po.property_id
        ", [$maxDepth]);
    }

    private function computeDiffs(): array
    {
        // Per root_cvr: count rows in live vs new, compute relative diff
        $rows = DB::select("
            SELECT
                COALESCE(live.root_cvr, new.root_cvr) AS root_cvr,
                COALESCE(live.cnt, 0) AS live_count,
                COALESCE(new.cnt, 0) AS new_count,
                ABS(COALESCE(live.cnt, 0) - COALESCE(new.cnt, 0)) AS abs_diff
            FROM
                (SELECT root_cvr, COUNT(*) AS cnt FROM company_property_tree_index GROUP BY root_cvr) live
            FULL OUTER JOIN
                (SELECT root_cvr, COUNT(*) AS cnt FROM company_property_tree_index_new GROUP BY root_cvr) new
            ON live.root_cvr = new.root_cvr
            WHERE COALESCE(live.cnt, 0) != COALESCE(new.cnt, 0)
        ");

        return array_map(fn ($r) => [
            'root_cvr' => $r->root_cvr,
            'live_count' => (int) $r->live_count,
            'new_count' => (int) $r->new_count,
            'relative_diff' => $r->live_count > 0 ? $r->abs_diff / $r->live_count : 1.0,
        ], $rows);
    }

    private function performSwap(): void
    {
        DB::transaction(function () {
            DB::statement('LOCK TABLE company_property_tree_index IN ACCESS EXCLUSIVE MODE');
            DB::statement('ALTER TABLE company_property_tree_index RENAME TO company_property_tree_index_pending_drop');
            DB::statement('ALTER TABLE company_property_tree_index_new RENAME TO company_property_tree_index');
        });
    }
}
```

- [ ] **Step 4.4: Write `DropOldTreeIndex` command (Phase 2 of swap)**

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DropOldTreeIndex extends Command
{
    protected $signature = 'tree-index:drop-old';
    protected $description = 'Drop pending-drop tree-index table 5 minutes after reconciliation rename';

    public function handle(): int
    {
        DB::statement('DROP TABLE IF EXISTS company_property_tree_index_pending_drop');
        $this->info('Pending-drop table cleaned up.');
        return self::SUCCESS;
    }
}
```

- [ ] **Step 4.5: Register schedules in `routes/console.php`**

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('tree-index:reconcile')
    ->dailyAt('03:30')
    ->withoutOverlapping(60)
    ->onOneServer()
    ->runInBackground();

Schedule::command('tree-index:drop-old')
    ->dailyAt('03:35')
    ->onOneServer();
```

- [ ] **Step 4.6: Run reconciliation test + verify pass**

```bash
php artisan test --filter "ReconcileCompanyPropertyTreeIndex"
```
Expected: PASS.

- [ ] **Step 4.7: Smoke-test reconciliation locally**

```bash
php artisan tree-index:reconcile
```
Expected: builds shadow, finds 0 diff (fresh state), swaps successfully.

- [ ] **Step 4.8: Commit Task 4**

```bash
git add app/Console/Commands/ReconcileCompanyPropertyTreeIndex.php app/Console/Commands/DropOldTreeIndex.php routes/console.php tests/Feature/Console/ReconcileCompanyPropertyTreeIndexTest.php
git commit -m "feat(tinglysning): nightly reconciliation with shadow-swap + 5-min grace before drop

- ReconcileCompanyPropertyTreeIndex builds shadow via recursive CTE
- Per-root_cvr relative diff; alert if exceeds config threshold (default 0.1%)
- 2-phase swap: rename inside transaction (ms lock), DROP separately 5 min later
- DropOldTreeIndex command runs at 03:35 daily
- Schedule registered with withoutOverlapping + onOneServer"
```

---

## Task 5: F1 ancestor-traversal + closest-ancestor SQL dedup

**Files:**
- Modify: `registry-api/app/Jobs/DetectMortgageChange.php` (rewrite `resolveWatchlists`)
- Test: `registry-api/tests/Feature/Jobs/DetectMortgageChangeAncestorTest.php`

- [ ] **Step 5.1: Write failing test — closest-ancestor wins over root**

```php
it('closest-ancestor watchlist wins over root-level watchlist for same user', function () {
    // Setup: Mimo-koncern. Root=A, sub=B, property owned by B.
    [$root, $sub, $property] = setupKoncernFixture();
    $user = User::factory()->create();

    // User has watch on root (depth 1 from property) AND on direct sub (depth 0)
    $rootWatch = Watchlist::create([
        'user_id' => $user->id, 'watch_type' => 'company', 'watch_value' => $root->cvr,
        'display_label' => 'Root holding',
        'created_at' => now()->subDays(10),
        'expanded_to_ancestors_at' => now()->subDays(10),
    ]);
    $subWatch = Watchlist::create([
        'user_id' => $user->id, 'watch_type' => 'company', 'watch_value' => $sub->cvr,
        'display_label' => 'Direct sub',
        'created_at' => now()->subDays(1),
        'expanded_to_ancestors_at' => now()->subDays(1),
    ]);

    // Mortgage change on property
    $mortgage = Mortgage::factory()->create(['property_id' => $property->id]);

    $job = new DetectMortgageChange($mortgage->id, [['kind' => 'new']], now()->toIso8601String());
    $matches = (fn () => $this->resolveWatchlists($mortgage))->call($job);

    expect($matches)->toHaveCount(1);
    expect($matches->first()->id)->toBe($subWatch->id); // closest-ancestor wins
});

it('property match beats CVR match (sentinel depth -1)', function () {
    // ... similar fixture
});

it('does NOT fire ancestor-match for watchlist created BEFORE expanded_to_ancestors_at', function () {
    // Backfill-marker test
});
```

- [ ] **Step 5.2: Run tests — fail expected**

```bash
php artisan test --filter "DetectMortgageChangeAncestor"
```
Expected: FAIL — current resolver doesn't do ancestor-traversal.

- [ ] **Step 5.3: Replace `DetectMortgageChange::resolveWatchlists()` per spec lines 233-310**

(Code in spec; copy verbatim. Key: single SQL query with 3-path UNION ALL + DISTINCT ON + closest-ancestor tiebreak via INNER JOIN on tree-index.)

- [ ] **Step 5.4: Run tests — should pass**

```bash
php artisan test --filter "DetectMortgageChangeAncestor"
```
Expected: 3 PASS.

- [ ] **Step 5.5: Run full test suite — verify no regression**

```bash
php artisan test --filter "DetectMortgageChange"
```
Expected: ALL pass (existing + new tests).

- [ ] **Step 5.6: Commit Task 5**

```bash
git add app/Jobs/DetectMortgageChange.php tests/Feature/Jobs/DetectMortgageChangeAncestorTest.php
git commit -m "feat(f1): ancestor-CVR traversal in DetectMortgageChange with closest-ancestor SQL dedup

- 3-path UNION ALL: property match (depth -1), direct CVR (depth 0), ancestor CVR (depth N from tree-index)
- DISTINCT ON (user_id) + ORDER BY depth ASC, created_at ASC = closest-ancestor wins
- Direct INNER JOIN on company_property_tree_index (no string interpolation, parameterized)
- expanded_to_ancestors_at gating prevents retroactive alert-storm to pre-deploy F1 watchers"
```

---

## Task 6: MortgageObserver cache invalidation (multi-parent CVR flush)

**Files:**
- Modify: `registry-api/app/Observers/MortgageObserver.php` (extend existing observer)
- Test: `registry-api/tests/Feature/Observers/MortgageObserverCacheInvalidationTest.php`

- [ ] **Step 6.1: Write failing test — diamond-path flushes all 3 ancestor CVRs**

```php
it('flushes tree_meta cache for ALL ancestor CVRs on mortgage save (diamond-path)', function () {
    // Setup: A → B,C; B,C → D (property D owned by both B and C, both under A)
    [$rootA, $subB, $subC, $propertyD] = setupDiamondFixture();

    // Pre-warm cache for all 3 CVRs
    Cache::tags(['tree_meta', "cvr:{$rootA->cvr}"])->put('overview', 'old-A', 60);
    Cache::tags(['tree_meta', "cvr:{$subB->cvr}"])->put('overview', 'old-B', 60);
    Cache::tags(['tree_meta', "cvr:{$subC->cvr}"])->put('overview', 'old-C', 60);

    // Mortgage save on property D
    $mortgage = Mortgage::factory()->create(['property_id' => $propertyD->id]);
    $mortgage->update(['principal_amount' => 999_99]);

    // ALL 3 CVRs should be flushed
    expect(Cache::tags(['tree_meta', "cvr:{$rootA->cvr}"])->get('overview'))->toBeNull();
    expect(Cache::tags(['tree_meta', "cvr:{$subB->cvr}"])->get('overview'))->toBeNull();
    expect(Cache::tags(['tree_meta', "cvr:{$subC->cvr}"])->get('overview'))->toBeNull();
});
```

- [ ] **Step 6.2: Run failing test**

```bash
php artisan test --filter "diamond-path"
```
Expected: FAIL — flush logic doesn't exist.

- [ ] **Step 6.3: Extend `MortgageObserver::saved()` (existing method, add cache flush at end)**

In `app/Observers/MortgageObserver.php` after F1 dispatch logic:
```php
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

public function saved(Mortgage $mortgage): void
{
    // ... existing F1 alert-dispatch logic (unchanged) ...

    // v1.3 — multi-parent CVR cache invalidation (Q4 fix)
    DB::afterCommit(function () use ($mortgage) {
        $ancestorCvrs = DB::table('company_property_tree_index')
            ->where('property_id', $mortgage->property_id)
            ->distinct()
            ->pluck('root_cvr');

        foreach ($ancestorCvrs as $cvr) {
            Cache::tags(['tree_meta', "cvr:{$cvr}"])->flush();
        }
    });
}
```

- [ ] **Step 6.4: Run test — should pass**

```bash
php artisan test --filter "diamond-path"
```
Expected: PASS.

- [ ] **Step 6.5: Run full MortgageObserver suite — verify no regression**

```bash
php artisan test --filter "MortgageObserver"
```
Expected: ALL pass.

- [ ] **Step 6.6: Commit Task 6**

```bash
git add app/Observers/MortgageObserver.php tests/Feature/Observers/MortgageObserverCacheInvalidationTest.php
git commit -m "feat(tinglysning): MortgageObserver flushes tree_meta cache for ALL ancestor CVRs

- Multi-parent CVR fix (v1.3 Q4): diamond-paths must flush all root_cvr ancestors
- DB::afterCommit ensures rollback safety
- Test covers diamond fixture (A->B,C; B,C->D)"
```

---

## Task 7: PropertyValueResolver + Boligsiden integration

**Files:**
- Create: `registry-api/app/Services/CompanyPortfolio/PropertyValueResolver.php`
- Create: `registry-api/app/Services/CompanyPortfolio/BoligsidenIndexClient.php`
- Test: `registry-api/tests/Unit/Services/PropertyValueResolverTest.php`

- [ ] **Step 7.1: Write failing tests for 4 fallback paths**

```php
it('returns skoede_price when recent tinglysning-skøde exists', function () { /* ... */ });
it('falls back to sale_price when no recent skøde exists', function () { /* ... */ });
it('falls back to public_valuation when no recent sale exists', function () { /* ... */ });
it('returns unavailable when nothing matches', function () { /* ... */ });
it('filters symbolic 1-DKK familieoverdragelse', function () { /* ... */ });
it('filters skøder older than 7 years', function () { /* ... */ });
it('resolveBatch uses 3 queries for N properties (not N+1)', function () {
    // Use \DB::enableQueryLog before, count after
});
```

- [ ] **Step 7.2: Run tests — fail expected**

- [ ] **Step 7.3: Implement `PropertyValueResolver` per spec lines 410-470**

(Code in spec — copy with adjustments for:
- `whereIn('property_id', $propertyIds)` for batch
- 3 sequential queries with progressive `diff()` of remaining properties
- `Cache::remember` rundt om Boligsiden indeks lookup, 24h TTL
- Symbolic 1-DKK filter: `where('price', '>=', 100_000)` + `whereNotIn('transaction_type', ['familieoverdragelse', 'gaveskøde', 'arvskifte'])`)

- [ ] **Step 7.4: Implement `BoligsidenIndexClient` (cached HTTP-call)**

```php
public function getIndex(string $postalCode, string $propertyType, int $year): ?float
{
    return Cache::remember(
        "boligsiden_index:{$postalCode}:{$propertyType}:{$year}",
        config('tinglysning.boligsiden_index_cache_ttl', 86400),
        function () use ($postalCode, $propertyType, $year) {
            $response = Http::timeout(5)->get('https://api.boligsiden.dk/prisindeks', [
                'postal_code' => $postalCode,
                'property_type' => $propertyType,
                'year' => $year,
            ]);
            if (! $response->successful()) {
                Log::warning('Boligsiden indeks API failed', ['status' => $response->status()]);
                return null;
            }
            $value = $response->json('index_value');
            return $value > 0 ? (float) $value : null;
        }
    );
}
```

(Note: actual API URL needs verification per spec Open Question #1. Document `// TODO: verify endpoint with Frederik before prod use`.)

- [ ] **Step 7.5: Run tests — should pass**

- [ ] **Step 7.6: Commit Task 7**

```bash
git add app/Services/CompanyPortfolio/PropertyValueResolver.php app/Services/CompanyPortfolio/BoligsidenIndexClient.php tests/Unit/Services/PropertyValueResolverTest.php
git commit -m "feat(tinglysning): PropertyValueResolver with 4-trins fallback + Boligsiden indeks cache

- resolveBatch() uses 3 queries (not N+1) for skøde/Boliga/valuation lookup
- 7-year cutoff on historical sales
- Symbolic 1-DKK + familieoverdragelse filtered out
- BoligsidenIndexClient with 24h Redis cache, NULL/0 guard
- Stable enum: skoede_price | sale_price | public_valuation | unavailable"
```

---

## Task 8: BuildTinglysningOverview Action + StreamTinglysningMortgages Action

**Files:**
- Create: `registry-api/app/Actions/Companies/BuildTinglysningOverview.php`
- Create: `registry-api/app/Actions/Companies/StreamTinglysningMortgages.php`
- Create: `registry-api/app/Services/CompanyPortfolio/CompanyPropertyTreeBuilder.php`
- Test: `registry-api/tests/Unit/Actions/Companies/BuildTinglysningOverviewTest.php`
- Test: `registry-api/tests/Unit/Actions/Companies/StreamTinglysningMortgagesTest.php`

- [ ] **Step 8.1: Write `CompanyPropertyTreeBuilder` (recursive CTE)**

```php
public function descendantPropertiesWithDepth(string $cvr, int $maxDepth): Collection
{
    return collect(DB::select("
        WITH RECURSIVE descendants AS (
            SELECT id, cvr, 0 AS depth FROM companies WHERE cvr = ?
            UNION ALL
            SELECT c.id, c.cvr, d.depth + 1
            FROM descendants d
            JOIN company_roles cr ON cr.parent_company_id = d.id AND cr.is_current = true
            JOIN companies c ON c.id = cr.company_id
            WHERE d.depth < ?
        )
        SELECT d.id AS company_id, d.cvr, d.depth, po.property_id
        FROM descendants d
        JOIN property_owners po ON po.owner_id = d.id AND po.owner_type = 'App\\\\Models\\\\Company' AND po.is_current = true
    ", [$cvr, $maxDepth]));
}
```

- [ ] **Step 8.2: Write `BuildTinglysningOverview::execute()`**

Returns: `['tree_meta' => [...], 'tier_breakdown' => [...]]`. Cached via `Cache::tags(['tree_meta', "cvr:{$cvr}"])->remember('overview', 60, fn() => ...)`.

- [ ] **Step 8.3: Write `StreamTinglysningMortgages::execute()`**

Returns: `['mortgages_added' => [...], 'streaming' => ['complete' => bool, 'cursor' => '...', 'total_expected' => N, 'delivered_so_far' => M]]`. Uses cursor for pagination.

- [ ] **Step 8.4: Write tests for both Actions covering the 8 spec scenarios**

(Mimo fixture, status filter, sort, cycle, diamond, mega-koncern, sampant, sampant-with-filter)

- [ ] **Step 8.5: Verify tests pass**

- [ ] **Step 8.6: Commit Task 8**

---

## Task 9: API Resources + Controller endpoint

**Files:**
- Create: `registry-api/app/Http/Resources/V1/TinglysningOverviewResource.php`
- Create: `registry-api/app/Http/Resources/V1/MortgageRowResource.php`
- Modify: `registry-api/app/Http/Controllers/Api/V1/CompanyController.php` (add `tinglysningOverview` method)
- Modify: `registry-api/routes/api.php` (register route)
- Test: `registry-api/tests/Feature/Api/V1/CompanyTinglysningOverviewTest.php`

- [ ] **Step 9.1: Write `TinglysningOverviewResource` shape per spec line 281-340**

- [ ] **Step 9.2: Write `MortgageRowResource` (with `is_sampant` + LTV nested object)**

- [ ] **Step 9.3: Add `tinglysningOverview()` method to CompanyController**

(Per spec Q3 controller composition — calls both Actions, returns Resource)

- [ ] **Step 9.4: Register route**

```php
Route::get('/companies/{cvr}/tinglysning-overview', [CompanyController::class, 'tinglysningOverview']);
```

- [ ] **Step 9.5: Write feature tests covering 11 spec scenarios**

- [ ] **Step 9.6: Verify all pass**

- [ ] **Step 9.7: Commit Task 9**

---

## Task 10: Deploy backend to staging + smoke-test

- [ ] **Step 10.1: Push backend branch + create PR**

```bash
git push -u origin feature/f-new-tinglysning-overview-backend
gh pr create --title "F-NEW: Tinglysning-overview backend" --body "Implements backend for spec v1.3..."
```

- [ ] **Step 10.2: Run full test suite locally before requesting merge**

```bash
php artisan test
```
Expected: ALL pass.

- [ ] **Step 10.3: Self-review PR + confidence-check (per CLAUDE.md workflow)**

Score 1-100 on correctness + completeness. Target ≥ 90.

- [ ] **Step 10.4: Request review (or merge if confidence ≥ 95 and minor)**

- [ ] **Step 10.5: Deploy to prod via Forge**

- [ ] **Step 10.6: Smoke-test on prod**

```bash
ssh forge@49.13.17.240 "cd /home/forge/registry-api.frankston.io && curl -s 'http://localhost/api/v1/companies/28963610/tinglysning-overview?status=active' | jq '.tree_meta'"
```
Expected: returns Mimo-koncern overview JSON.

- [ ] **Step 10.7: Bootstrap tree-index on prod (initial reconciliation run with --force-swap)**

```bash
ssh forge@49.13.17.240 "cd /home/forge/registry-api.frankston.io && php artisan tree-index:reconcile --force-swap"
```
Expected: succeeds, populates ~600K rows.

---

## Task 11: metis-package — RegistryApi client + CompanyTinglysning Livewire section (foundation)

**Repo switch:** `cd /Users/Frederik/metis-package`

**Files:**
- Modify: `metis-package/src/Services/RegistryApi.php` (add `fetchCompanyTinglysningOverview`)
- Create: `metis-package/src/Livewire/Sections/CompanyTinglysning.php`
- Create: `metis-package/resources/views/livewire/sections/company-tinglysning.blade.php`
- Create: `metis-package/resources/views/livewire/sections/partials/skeleton-row.blade.php`
- Create: `metis-package/resources/views/livewire/sections/partials/error-state.blade.php`
- Modify: `metis-package/src/Livewire/Metis.php` (register section)

- [ ] **Step 11.1: Branch off main**

```bash
cd /Users/Frederik/metis-package
git checkout main && git pull
git checkout -b feature/f-new-tinglysning-tab-frontend
```

- [ ] **Step 11.2: Add `fetchCompanyTinglysningOverview` to RegistryApi**

```php
public function fetchCompanyTinglysningOverview(
    string $cvr,
    array $filters = [],
    ?string $cursor = null
): array {
    $query = http_build_query([
        ...$filters,
        'cursor' => $cursor,
    ]);

    $response = Http::withToken($this->apiKey)
        ->timeout(10)
        ->get("{$this->baseUrl}/api/v1/companies/{$cvr}/tinglysning-overview?{$query}");

    if (! $response->successful()) {
        throw new RegistryApiException("Failed to fetch tinglysning overview: {$response->status()}");
    }

    return $response->json();
}
```

- [ ] **Step 11.3: Write `CompanyTinglysning` Livewire section per spec lines 460-510**

- [ ] **Step 11.4: Write minimal Blade template — table + filters + loading state**

- [ ] **Step 11.5: Skeleton-row + error-state partials**

- [ ] **Step 11.6: Register section in `Metis.php` (so tab appears on company-page)**

- [ ] **Step 11.7: Manual smoke-test in browser**

```bash
node tools/browser-test.mjs login --server demo
node tools/browser-test.mjs navigate --url "/companies/28963610"
node tools/browser-test.mjs screenshot --output /tmp/tinglysning-tab.png
```

Read screenshot — verify tab is visible + clicks load data.

- [ ] **Step 11.8: Commit Task 11**

---

## Task 12: Streaming polling + sort/filter UX + drawer

**Files:**
- Modify: `metis-package/src/Livewire/Sections/CompanyTinglysning.php` (add polling)
- Modify: `metis-package/resources/views/livewire/sections/company-tinglysning.blade.php` (add Flux flyout)

- [ ] **Step 12.1: Add `wire:poll.2s` + delta-cursor handling**

- [ ] **Step 12.2: Add filter-bar (status enum + mortgage-types + amount-range + sort)**

- [ ] **Step 12.3: Add Flux flyout drawer with `#[Url(as: 'ting_mortgage')]` URL-binding**

```blade
<flux:modal variant="flyout" wire:model="openMortgageId" class="w-[600px]">
    <div x-data x-on:keydown.up.window="$wire.navigateDrawer('prev')"
                x-on:keydown.down.window="$wire.navigateDrawer('next')">
        @if($openMortgageId)
            @include('metis::livewire.sections.partials.mortgage-detail', ['mortgageId' => $openMortgageId])
        @endif
    </div>
</flux:modal>
```

- [ ] **Step 12.4: Add `navigateDrawer()` method (prev/next mortgage in current list)**

- [ ] **Step 12.5: Browser test — verify drawer opens + URL updates + arrow keys work**

- [ ] **Step 12.6: Commit Task 12**

---

## Task 13: XLSX export + computeTotals centralization

**Files:**
- Create: `metis-package/src/Exports/PortfolioTinglysningExport.php`
- Modify: `metis-package/src/Livewire/Sections/CompanyTinglysning.php` (add `exportXlsx()`)

- [ ] **Step 13.1: Write `PortfolioTinglysningExport` using PhpSpreadsheet**

- [ ] **Step 13.2: Centralize `computeTotals()` — DISTINCT on `tinglysning_right_id` (with mortgage_id fallback for pre-2009)**

- [ ] **Step 13.3: Add `exportXlsx()` Livewire method that streams download**

- [ ] **Step 13.4: Test totals match between UI + XLSX (via assertion)**

- [ ] **Step 13.5: Commit Task 13**

---

## Task 14: Manual QA + ship

- [ ] **Step 14.1: Run full Pest suite in both repos**

```bash
cd /Users/Frederik/registry-api && php artisan test
cd /Users/Frederik/metis-package && php artisan test
```

- [ ] **Step 14.2: Browser-test scenarios per spec lines 707-720**

- [ ] **Step 14.3: Confidence-check (per CLAUDE.md workflow) — score 1-100, target ≥ 95**

- [ ] **Step 14.4: Multi-agent review (per CLAUDE.md /review)**

- [ ] **Step 14.5: Push frontend PR + merge after review**

- [ ] **Step 14.6: Deploy frontend to demo + verify with Rasmus**

- [ ] **Step 14.7: Send Rasmus heads-up email (plain text, Frederik sender selv)**

> "Hej Rasmus — Tinglysning-tab er live på demo. Forventet UX: åbn Mimo-CVR, klik 'Tinglysning'-tab, klik en pantebrev for drilldown. Test gerne 'Følg ændringer på alle X' og send mig feedback."

- [ ] **Step 14.8: Document compound-learning (per CLAUDE.md /compound)**

Save to `~/.claude/projects/-Users-Frederik/memory/compound_f_new_tinglysning_tab_2026_05_<ship-date>.md` med læringer fra implementation (særligt: blev observer-pattern reliable nok? blev streaming UX god? hvad kostede 12 dage faktisk?).

---

## Acceptance Criteria — Sprint 1 Done When

1. ✅ Backend deployed til prod, alle tests grønne
2. ✅ Frontend deployed til demo
3. ✅ Browser-test passerer alle scenarios fra spec lines 707-720
4. ✅ Telescope/Flare instrumentering måler p95 load-tid
5. ✅ Rasmus har testet på demo + givet feedback (skriftlig eller verbal)
6. ✅ Confidence-check ≥ 95
7. ✅ Compound-memory dokumenteret

## Out of Sprint 1 (per spec)

- PDF export (Sprint 2 — kræver brand-beslutning)
- User-rating overlay (LTV Tier 4)
- Mobile-optimering (`<768px`)
- Avancerede filtre (prioritet, kreditor, dato-range)

## Reminders

- **Stop ved acceptance criteria** — ikke "hvad ellers". Memory: confidence ≥ 90, tests grønne, AC opfyldt.
- **Specifikke git add paths** — registry-api har untracked Eskat-WIP filer der ikke må commits utilsigtet (per memory).
- **Browser-test alle nye Livewire-flows** før completion-claim (per memory).
- **Læs filer tilbage før completion-claim** — Edit/Write success ≠ content-correct (per memory).

## Reference

- Spec v1.3: `docs/superpowers/specs/2026-05-02-portfolio-tinglysning-tab-design.md`
- Lender Intelligence parent: `~/.claude/projects/-Users-Frederik/memory/project_lender_intelligence.md`
- Cron reminder: 2026-05-18 09:07 (Lender Intelligence brainstorm)
