# Metis Sprint 0a — Backend Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Lock F1 (debt alerts) + F2 (omvendt søgning) end-to-end ved at lukke route- og schema-mismatch mellem `metis-package` og `registry-api`, og bygge `mortgage_change`-detektor (Rasmus' sticky-feature).

**Architecture:** `/v2/*`-rebrand i registry-api parallelt med eksisterende `/v1/monitoring/*` (alias 30 dage med Sunset-header). Hybrid snapshot+events-pattern for delta-detektion (snapshot = current state cache for hurtig diff, events = permanent audit-log). Person watch_type tilføjes både backend og frontend.

**Tech Stack:** Laravel 12 + PostgreSQL 16 + PostGIS (registry-api), Laravel 12 + Livewire 3 + Flux UI Pro (metis-package), Pest tests.

**Foregående artefakter (læs først):**
- Spec v1.2: `docs/superpowers/specs/2026-05-01-metis-resight-parity-design.md`
- Audit: `docs/user-research/2026-04-29-draupnir-resight/audit-monitoring-consumers.md` — bekræfter at kun metis-package konsumerer disse endpoints, så cross-repo PR-koordinering er IKKE nødvendig
- Phase A findings: `docs/user-research/2026-04-29-draupnir-resight/phase-A-findings.md` — current state af `mortgages`-skema

**Scope-grænse:** Kun backend (registry-api) + metis-package. UI-arbejde i metis-app (standalone) sker via composer-update mod ny pakke-version (separat Sprint 0a-trail). Embedded mode i Frankston-master rør vi IKKE i denne sprint (separat sprint efter pilot-validering).

**Estimat:** 1 uge (5-7 arbejdsdage), Kristian som primær owner. Jens kan parallelisere på Task 5 (commercial-rents-aktivering) hvis tilgængelig.

---

## File Structure

### registry-api (feature-branch: `feature/sprint-0a-watchlists-rebrand`)

**Database migrations (create new):**
- `database/migrations/2026_05_01_000001_extend_mortgages_for_pant_type.php` — udvid `mortgages` med `priority` (smallinteger nullable) og indexér `mortgage_type`
- `database/migrations/2026_05_01_000002_create_mortgage_snapshots_table.php` — snapshot-tabel med `row_hash`, partitioneret monthly
- `database/migrations/2026_05_01_000003_create_mortgage_events_table.php` — event-log tabel
- `database/migrations/2026_05_01_000004_extend_watchlists_for_person_type.php` — modify `watch_type` validator (i request, ikke schema), tilføj `display_label` kolonne hvis ikke der
- `database/migrations/2026_05_01_000005_create_alerts_priority_column.php` — tilføj `priority` enum til `alerts` (low|medium|high)

**Routes (modify):**
- `routes/api/v1.php:106-110` — bevar eksisterende `monitoring/*` routes; tilføj `Sunset` middleware
- `routes/api/v2.php` (create new) — full `/v2/*` route-fil for watchlists/alerts/debt-search/mortgages

**Controllers (modify + new):**
- `app/Http/Controllers/Api/V1/MonitoringController.php:18-24` — udvid validator: tilføj `person` til watch_type enum, tilføj `mortgage_change|new_lien|ownership_change` til alert_types enum
- `app/Http/Controllers/Api/V1/MonitoringController.php` — tilføj `checkBatch` method
- `app/Http/Controllers/Api/V2/WatchlistController.php` (create new) — alias of MonitoringController med samme logic, men `/v2/*` paths
- `app/Http/Controllers/Api/V2/AlertController.php` (create new) — alias for alerts-routes
- `app/Http/Controllers/Api/V2/DebtSearchController.php` (create new) — wrapper omkring MortgageSearchController med owner_type+debt_type+CSV-eksport

**Services (new):**
- `app/Services/Monitoring/MortgageDeltaDetector.php` (create new) — kerne-engine for `checkMortgageDelta`
- `app/Services/Monitoring/MortgageSnapshotter.php` (create new) — daily snapshot-job
- `app/Services/Monitoring/MortgageEventLogger.php` (create new) — event-log writer

**MonitoringService (modify):**
- `app/Services/Monitoring/MonitoringService.php:20-32` — tilføj `match`-arm for `person`, integrér `MortgageDeltaDetector`

**Models (new + modify):**
- `app/Models/MortgageSnapshot.php` (create new)
- `app/Models/MortgageEvent.php` (create new)
- `app/Models/Watchlist.php:13-15` — udvid casts hvis nødvendigt

**Console commands (new):**
- `app/Console/Commands/MortgageSnapshotCommand.php` (create new) — `mortgages:snapshot` schedule daily 02:00
- `app/Console/Commands/Kernel.php:??` — registrér ny scheduled job

**Tests (new):**
- `tests/Feature/Api/V2/WatchlistControllerTest.php`
- `tests/Feature/Api/V2/AlertControllerTest.php`
- `tests/Feature/Api/V2/DebtSearchControllerTest.php`
- `tests/Feature/Services/Monitoring/MortgageDeltaDetectorTest.php`
- `tests/Feature/Services/Monitoring/MortgageSnapshotterTest.php`
- `tests/Feature/Console/MortgageSnapshotCommandTest.php`

### metis-package (feature-branch: `feature/sprint-0a-v2-routes`)

**Service (modify):**
- `src/Services/RegistryApi.php:421-510` — opdatér alle endpoint-paths fra `/v1/*` til `/v2/*`

**Livewire components (modify):**
- `src/Livewire/FollowButton.php:38` — tilføj support for watch_type=person
- `src/Livewire/AlertsInbox.php` — opdatér priority-rendering til at understøtte 3 levels

**Tests (modify):**
- `tests/Feature/DebtSearchTest.php` — opdatér mock URLs til `/v2/*`
- `tests/Feature/FollowButtonTest.php` — verificér person-watch_type roundtrip

---

## Bite-Sized Tasks

### Task 1: Verificér mortgage-skema og dokumentér eksisterende kolonner

**Files:**
- Read-only: `registry-api/database/migrations/2026_02_24_000007_create_mortgages_table.php`
- Create: `registry-api/docs/internal/mortgage-schema-2026-05-01.md`

- [ ] **Step 1: Læs eksisterende mortgages-migration**

```bash
cat database/migrations/2026_02_24_000007_create_mortgages_table.php
```

Forventet output: `mortgages` har kolonner `creditor`, `principal_amount`, `currency`, `mortgage_type`, `interest_rate`, `registration_date`, `maturity_date`, `is_active`, `tinglysning_data` (jsonb), timestamps.

- [ ] **Step 2: Tjek hvilke værdier `mortgage_type` faktisk indeholder i prod-DB**

```bash
# Kør lokalt mod udviklings-DB (eller spørg Frederik om en mandag-morgen prod-query)
php artisan tinker --execute="echo App\Models\Mortgage::select('mortgage_type')->distinct()->pluck('mortgage_type');"
```

Forventet: liste af unique mortgage_type-værdier (forventet: `ejerpantebrev`, `realkredit_pantebrev`, `afgiftspantebrev`, eller lignende dansk-tinglysning-terminologi). Hvis listen er kaotisk eller tom: notér det og adressér i Task 2.

- [ ] **Step 3: Tjek om `tinglysning_data`-jsonb indeholder priority-felt**

```bash
php artisan tinker --execute="
echo App\Models\Mortgage::whereNotNull('tinglysning_data')
    ->limit(5)
    ->pluck('tinglysning_data')
    ->map(fn(\$d) => array_keys((array) \$d))
    ->unique()
    ->flatten()
    ->unique();
"
```

Forventet: hvis `priority` (eller lignende felt-navn som `prioritet` eller `pant_priority`) er i jsonb-keys, kan Task 2's `priority`-kolonne udfyldes via backfill.

- [ ] **Step 4: Skriv resultat til docs**

Create `docs/internal/mortgage-schema-2026-05-01.md` med:
- Eksisterende kolonner og typer
- Unique mortgage_type-værdier observeret
- Hvor priority-data findes (kolonne vs jsonb)
- Anbefal: hvilke nye kolonner Sprint 0a faktisk har brug for

- [ ] **Step 5: Commit**

```bash
git checkout -b feature/sprint-0a-watchlists-rebrand
git add docs/internal/mortgage-schema-2026-05-01.md
git commit -m "docs: document existing mortgages schema before Sprint 0a"
```

---

### Task 2: Migration: udvid `mortgages` med `priority`-kolonne (hvis ikke i tinglysning_data)

**Files:**
- Create: `registry-api/database/migrations/2026_05_01_000001_extend_mortgages_for_pant_type.php`

**Forudsætning:** Task 1 har bekræftet at `priority` ikke findes som kolonne. Hvis det allerede er der: skip denne task.

- [ ] **Step 1: Skriv migration**

Create `database/migrations/2026_05_01_000001_extend_mortgages_for_pant_type.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mortgages', function (Blueprint $table) {
            $table->smallInteger('priority')->nullable()->after('interest_rate');
            $table->index('mortgage_type');
        });
    }

    public function down(): void
    {
        Schema::table('mortgages', function (Blueprint $table) {
            $table->dropIndex(['mortgage_type']);
            $table->dropColumn('priority');
        });
    }
};
```

- [ ] **Step 2: Kør migration lokalt**

```bash
php artisan migrate
```

Forventet: `Migrated: 2026_05_01_000001_extend_mortgages_for_pant_type`

- [ ] **Step 3: Verificér kolonne**

```bash
php artisan tinker --execute="echo Schema::getColumnListing('mortgages');"
```

Forventet: liste indeholder `priority`.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_05_01_000001_extend_mortgages_for_pant_type.php
git commit -m "feat(db): add priority column to mortgages table"
```

---

### Task 3: Migration: opret `mortgage_snapshots` tabel med row_hash og partitionering

**Files:**
- Create: `registry-api/database/migrations/2026_05_01_000002_create_mortgage_snapshots_table.php`

- [ ] **Step 1: Skriv migration**

Create `database/migrations/2026_05_01_000002_create_mortgage_snapshots_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mortgage_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mortgage_id')->constrained()->cascadeOnDelete();
            $table->date('snapshot_date');
            $table->string('mortgage_type')->nullable();
            $table->smallInteger('priority')->nullable();
            $table->bigInteger('principal_amount')->nullable();
            $table->decimal('interest_rate', 5, 2)->nullable();
            $table->string('creditor')->nullable();
            $table->boolean('is_active');
            $table->char('row_hash', 64); // sha256 hex
            $table->timestamps();

            $table->unique(['property_id', 'mortgage_id', 'snapshot_date'], 'mortgage_snapshots_unique');
            $table->index(['property_id', 'snapshot_date']);
            $table->index('row_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mortgage_snapshots');
    }
};
```

**Note:** Postgres native partitioning er over-engineering for Sprint 0a. Tilføj month-partitionering Sprint 1 hvis volumen kræver det. Index dækker query-pattern.

- [ ] **Step 2: Kør migration**

```bash
php artisan migrate
```

- [ ] **Step 3: Verificér tabel**

```bash
php artisan tinker --execute="echo Schema::hasTable('mortgage_snapshots') ? 'YES' : 'NO';"
```

Forventet: `YES`

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_05_01_000002_create_mortgage_snapshots_table.php
git commit -m "feat(db): create mortgage_snapshots table with row_hash"
```

---

### Task 4: Migration: opret `mortgage_events` tabel (event-log for hybrid pattern)

**Files:**
- Create: `registry-api/database/migrations/2026_05_01_000003_create_mortgage_events_table.php`

- [ ] **Step 1: Skriv migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mortgage_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mortgage_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('event_type'); // 'created' | 'principal_changed' | 'creditor_changed' | 'priority_changed' | 'deactivated' | 'reactivated'
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->date('detected_on');
            $table->string('source')->default('snapshot_diff'); // future: 'webhook', 'manual'
            $table->timestamps();

            $table->index(['property_id', 'detected_on']);
            $table->index(['event_type', 'detected_on']);
            $table->index('mortgage_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mortgage_events');
    }
};
```

- [ ] **Step 2: Kør og commit**

```bash
php artisan migrate
git add database/migrations/2026_05_01_000003_create_mortgage_events_table.php
git commit -m "feat(db): create mortgage_events table for delta audit log"
```

---

### Task 5: Migration: udvid `alerts` med priority-kolonne

**Files:**
- Create: `registry-api/database/migrations/2026_05_01_000004_extend_alerts_for_priority.php`

- [ ] **Step 1: Tjek eksisterende `alerts`-tabel**

```bash
php artisan tinker --execute="echo Schema::getColumnListing('alerts');"
```

Hvis `priority` allerede findes: skip denne task.

- [ ] **Step 2: Skriv migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            if (! Schema::hasColumn('alerts', 'priority')) {
                $table->string('priority', 10)->default('medium')->after('alert_type');
                $table->index('priority');
            }
        });
    }

    public function down(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            if (Schema::hasColumn('alerts', 'priority')) {
                $table->dropIndex(['priority']);
                $table->dropColumn('priority');
            }
        });
    }
};
```

- [ ] **Step 3: Kør og commit**

```bash
php artisan migrate
git add database/migrations/2026_05_01_000004_extend_alerts_for_priority.php
git commit -m "feat(db): add priority column to alerts table"
```

---

### Task 6: Migration: tilføj display_label til watchlists

**Files:**
- Create: `registry-api/database/migrations/2026_05_01_000005_extend_watchlists.php`

- [ ] **Step 1: Tjek eksisterende `watchlists`-tabel**

```bash
php artisan tinker --execute="echo Schema::getColumnListing('watchlists');"
```

- [ ] **Step 2: Skriv migration (idempotent)**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('watchlists', function (Blueprint $table) {
            if (! Schema::hasColumn('watchlists', 'display_label')) {
                $table->string('display_label', 255)->nullable()->after('watch_value');
            }
            if (! Schema::hasColumn('watchlists', 'last_checked_at')) {
                $table->timestamp('last_checked_at')->nullable()->after('display_label');
                $table->index('last_checked_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('watchlists', function (Blueprint $table) {
            $table->dropColumn(['display_label', 'last_checked_at']);
        });
    }
};
```

- [ ] **Step 3: Kør og commit**

```bash
php artisan migrate
git add database/migrations/2026_05_01_000005_extend_watchlists.php
git commit -m "feat(db): add display_label and last_checked_at to watchlists"
```

---

### Task 7: Eloquent models for nye tabeller

**Files:**
- Create: `registry-api/app/Models/MortgageSnapshot.php`
- Create: `registry-api/app/Models/MortgageEvent.php`

- [ ] **Step 1: Skriv MortgageSnapshot model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MortgageSnapshot extends Model
{
    protected $fillable = [
        'property_id', 'mortgage_id', 'snapshot_date', 'mortgage_type',
        'priority', 'principal_amount', 'interest_rate', 'creditor',
        'is_active', 'row_hash',
    ];

    protected $casts = [
        'snapshot_date' => 'date',
        'principal_amount' => 'integer',
        'interest_rate' => 'decimal:2',
        'priority' => 'integer',
        'is_active' => 'boolean',
    ];

    public function mortgage()
    {
        return $this->belongsTo(Mortgage::class);
    }

    public function property()
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * Build a deterministic hash for the significant columns.
     * Used for fast diff-detection in MortgageDeltaDetector.
     */
    public static function hashFor(Mortgage $mortgage): string
    {
        return hash('sha256', implode('|', [
            $mortgage->mortgage_type ?? '',
            $mortgage->priority ?? '',
            $mortgage->principal_amount ?? '',
            $mortgage->interest_rate ?? '',
            $mortgage->creditor ?? '',
            $mortgage->is_active ? '1' : '0',
        ]));
    }
}
```

- [ ] **Step 2: Skriv MortgageEvent model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MortgageEvent extends Model
{
    protected $fillable = [
        'mortgage_id', 'property_id', 'event_type',
        'before', 'after', 'detected_on', 'source',
    ];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
        'detected_on' => 'date',
    ];

    public const TYPE_CREATED = 'created';
    public const TYPE_PRINCIPAL_CHANGED = 'principal_changed';
    public const TYPE_CREDITOR_CHANGED = 'creditor_changed';
    public const TYPE_PRIORITY_CHANGED = 'priority_changed';
    public const TYPE_DEACTIVATED = 'deactivated';
    public const TYPE_REACTIVATED = 'reactivated';
}
```

- [ ] **Step 3: Commit**

```bash
git add app/Models/MortgageSnapshot.php app/Models/MortgageEvent.php
git commit -m "feat(models): add MortgageSnapshot and MortgageEvent"
```

---

### Task 8: MortgageSnapshotter service (daily snapshot writer)

**Files:**
- Create: `registry-api/app/Services/Monitoring/MortgageSnapshotter.php`

- [ ] **Step 1: Skriv failing test**

Create `tests/Feature/Services/Monitoring/MortgageSnapshotterTest.php`:
```php
<?php

use App\Models\Mortgage;
use App\Models\MortgageSnapshot;
use App\Models\Property;
use App\Services\Monitoring\MortgageSnapshotter;

it('snapshots all active mortgages for given properties', function () {
    $property = Property::factory()->create();
    $mortgage = Mortgage::factory()->create([
        'property_id' => $property->id,
        'mortgage_type' => 'ejerpantebrev',
        'principal_amount' => 5_000_000_00,
        'is_active' => true,
    ]);

    app(MortgageSnapshotter::class)->snapshot([$property->id], today());

    expect(MortgageSnapshot::count())->toBe(1)
        ->and(MortgageSnapshot::first()->row_hash)->not->toBeEmpty();
});

it('is idempotent for same date', function () {
    $property = Property::factory()->create();
    Mortgage::factory()->create(['property_id' => $property->id]);

    app(MortgageSnapshotter::class)->snapshot([$property->id], today());
    app(MortgageSnapshotter::class)->snapshot([$property->id], today());

    expect(MortgageSnapshot::count())->toBe(1);
});
```

- [ ] **Step 2: Verificér det fejler**

```bash
./vendor/bin/pest tests/Feature/Services/Monitoring/MortgageSnapshotterTest.php
```

Expected: FAIL — class not found.

- [ ] **Step 3: Implementér service**

Create `app/Services/Monitoring/MortgageSnapshotter.php`:
```php
<?php

namespace App\Services\Monitoring;

use App\Models\Mortgage;
use App\Models\MortgageSnapshot;
use Carbon\CarbonInterface;

class MortgageSnapshotter
{
    /**
     * Write today's snapshot for all active mortgages on the given properties.
     * Idempotent: re-running same day = no-op (unique key on property+mortgage+date).
     */
    public function snapshot(array $propertyIds, CarbonInterface $date): int
    {
        $count = 0;

        Mortgage::whereIn('property_id', $propertyIds)
            ->where('is_active', true)
            ->chunkById(500, function ($mortgages) use ($date, &$count) {
                $rows = $mortgages->map(fn ($m) => [
                    'property_id' => $m->property_id,
                    'mortgage_id' => $m->id,
                    'snapshot_date' => $date,
                    'mortgage_type' => $m->mortgage_type,
                    'priority' => $m->priority,
                    'principal_amount' => $m->principal_amount,
                    'interest_rate' => $m->interest_rate,
                    'creditor' => $m->creditor,
                    'is_active' => $m->is_active,
                    'row_hash' => MortgageSnapshot::hashFor($m),
                    'created_at' => now(),
                    'updated_at' => now(),
                ])->all();

                MortgageSnapshot::insertOrIgnore($rows);
                $count += count($rows);
            });

        return $count;
    }

    /**
     * Snapshot ALL properties referenced by active watchlists.
     * Used by daily cron.
     */
    public function snapshotForActiveWatchlists(CarbonInterface $date): int
    {
        $propertyIds = \App\Models\Watchlist::where('is_active', true)
            ->where('watch_type', 'property')
            ->pluck('watch_value')
            ->map(fn ($v) => (int) $v)
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $this->snapshot($propertyIds, $date);
    }
}
```

- [ ] **Step 4: Kør test**

```bash
./vendor/bin/pest tests/Feature/Services/Monitoring/MortgageSnapshotterTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Monitoring/MortgageSnapshotter.php tests/Feature/Services/Monitoring/MortgageSnapshotterTest.php
git commit -m "feat(monitoring): add MortgageSnapshotter for daily snapshot capture"
```

---

### Task 9: MortgageDeltaDetector service (kerne diff-engine)

**Files:**
- Create: `registry-api/app/Services/Monitoring/MortgageDeltaDetector.php`

- [ ] **Step 1: Skriv failing tests**

Create `tests/Feature/Services/Monitoring/MortgageDeltaDetectorTest.php`:
```php
<?php

use App\Models\Mortgage;
use App\Models\MortgageEvent;
use App\Models\MortgageSnapshot;
use App\Models\Property;
use App\Services\Monitoring\MortgageDeltaDetector;
use App\Services\Monitoring\MortgageSnapshotter;

beforeEach(function () {
    $this->property = Property::factory()->create();
    $this->detector = app(MortgageDeltaDetector::class);
    $this->snapshotter = app(MortgageSnapshotter::class);
});

it('detects new mortgage as created event', function () {
    // Day 1: no mortgage. Day 2: new mortgage.
    $this->snapshotter->snapshot([$this->property->id], today()->subDay());
    
    $mortgage = Mortgage::factory()->create([
        'property_id' => $this->property->id,
        'mortgage_type' => 'ejerpantebrev',
        'principal_amount' => 5_000_000_00,
    ]);
    $this->snapshotter->snapshot([$this->property->id], today());

    $events = $this->detector->detectFor($this->property->id, today());

    expect($events)->toHaveCount(1)
        ->and($events[0]->event_type)->toBe(MortgageEvent::TYPE_CREATED);
});

it('detects principal_changed when amount changes', function () {
    $mortgage = Mortgage::factory()->create([
        'property_id' => $this->property->id,
        'principal_amount' => 5_000_000_00,
    ]);
    $this->snapshotter->snapshot([$this->property->id], today()->subDay());

    $mortgage->update(['principal_amount' => 7_000_000_00]);
    $this->snapshotter->snapshot([$this->property->id], today());

    $events = $this->detector->detectFor($this->property->id, today());

    expect($events)->toHaveCount(1)
        ->and($events[0]->event_type)->toBe(MortgageEvent::TYPE_PRINCIPAL_CHANGED);
});

it('detects deactivated when is_active flips false', function () {
    $mortgage = Mortgage::factory()->create([
        'property_id' => $this->property->id,
        'is_active' => true,
    ]);
    $this->snapshotter->snapshot([$this->property->id], today()->subDay());

    $mortgage->update(['is_active' => false]);
    $this->snapshotter->snapshot([$this->property->id], today());

    $events = $this->detector->detectFor($this->property->id, today());

    expect($events)->toHaveCount(1)
        ->and($events[0]->event_type)->toBe(MortgageEvent::TYPE_DEACTIVATED);
});

it('returns empty when nothing changed', function () {
    Mortgage::factory()->create(['property_id' => $this->property->id]);
    $this->snapshotter->snapshot([$this->property->id], today()->subDay());
    $this->snapshotter->snapshot([$this->property->id], today());

    $events = $this->detector->detectFor($this->property->id, today());

    expect($events)->toBeEmpty();
});

it('handles missed-day recovery via last_available_snapshot', function () {
    $mortgage = Mortgage::factory()->create([
        'property_id' => $this->property->id,
        'principal_amount' => 5_000_000_00,
    ]);
    // Snapshot Monday, not Tuesday, then Wednesday with change
    $this->snapshotter->snapshot([$this->property->id], today()->subDays(2));
    $mortgage->update(['principal_amount' => 8_000_000_00]);
    $this->snapshotter->snapshot([$this->property->id], today());

    $events = $this->detector->detectFor($this->property->id, today());

    expect($events)->toHaveCount(1)
        ->and($events[0]->event_type)->toBe(MortgageEvent::TYPE_PRINCIPAL_CHANGED);
});
```

- [ ] **Step 2: Verificér failing**

```bash
./vendor/bin/pest tests/Feature/Services/Monitoring/MortgageDeltaDetectorTest.php
```

Expected: FAIL — class not found.

- [ ] **Step 3: Implementér detector**

Create `app/Services/Monitoring/MortgageDeltaDetector.php`:
```php
<?php

namespace App\Services\Monitoring;

use App\Models\MortgageEvent;
use App\Models\MortgageSnapshot;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class MortgageDeltaDetector
{
    /**
     * Compare today's snapshot to last available previous snapshot for the property.
     * Append MortgageEvent rows for each detected delta.
     *
     * Returns the array of created MortgageEvent models.
     */
    public function detectFor(int $propertyId, CarbonInterface $today): array
    {
        $todaySnaps = MortgageSnapshot::where('property_id', $propertyId)
            ->where('snapshot_date', $today)
            ->get()
            ->keyBy('mortgage_id');

        $previousSnaps = $this->findLastSnapshotBefore($propertyId, $today);

        $events = [];

        // Detect new mortgages (in today, not in previous)
        foreach ($todaySnaps as $mortgageId => $today) {
            if (! $previousSnaps->has($mortgageId)) {
                $events[] = $this->createEvent(
                    $propertyId, $mortgageId, MortgageEvent::TYPE_CREATED,
                    null, $today->toArray(), $today->snapshot_date
                );
                continue;
            }

            $previous = $previousSnaps->get($mortgageId);

            if ($previous->row_hash === $today->row_hash) {
                continue; // No change
            }

            // Determine specific change type
            $eventType = $this->classifyChange($previous, $today);
            $events[] = $this->createEvent(
                $propertyId, $mortgageId, $eventType,
                $previous->toArray(), $today->toArray(), $today->snapshot_date
            );
        }

        // Detect deactivated mortgages (in previous, not in today as active)
        foreach ($previousSnaps as $mortgageId => $previous) {
            if (! $todaySnaps->has($mortgageId) && $previous->is_active) {
                $events[] = $this->createEvent(
                    $propertyId, $mortgageId, MortgageEvent::TYPE_DEACTIVATED,
                    $previous->toArray(), null, $today
                );
            }
        }

        return $events;
    }

    protected function findLastSnapshotBefore(int $propertyId, CarbonInterface $date): Collection
    {
        $lastDate = MortgageSnapshot::where('property_id', $propertyId)
            ->where('snapshot_date', '<', $date)
            ->max('snapshot_date');

        if (! $lastDate) {
            return collect();
        }

        return MortgageSnapshot::where('property_id', $propertyId)
            ->where('snapshot_date', $lastDate)
            ->get()
            ->keyBy('mortgage_id');
    }

    protected function classifyChange(MortgageSnapshot $before, MortgageSnapshot $after): string
    {
        if ($before->is_active && ! $after->is_active) {
            return MortgageEvent::TYPE_DEACTIVATED;
        }
        if (! $before->is_active && $after->is_active) {
            return MortgageEvent::TYPE_REACTIVATED;
        }
        if ($before->principal_amount !== $after->principal_amount) {
            return MortgageEvent::TYPE_PRINCIPAL_CHANGED;
        }
        if ($before->creditor !== $after->creditor) {
            return MortgageEvent::TYPE_CREDITOR_CHANGED;
        }
        if ($before->priority !== $after->priority) {
            return MortgageEvent::TYPE_PRIORITY_CHANGED;
        }
        // Fallback: generic change
        return MortgageEvent::TYPE_PRINCIPAL_CHANGED;
    }

    protected function createEvent(
        int $propertyId,
        int $mortgageId,
        string $type,
        ?array $before,
        ?array $after,
        $detectedOn,
    ): MortgageEvent {
        return MortgageEvent::create([
            'property_id' => $propertyId,
            'mortgage_id' => $mortgageId,
            'event_type' => $type,
            'before' => $before,
            'after' => $after,
            'detected_on' => $detectedOn,
            'source' => 'snapshot_diff',
        ]);
    }
}
```

- [ ] **Step 4: Kør test**

```bash
./vendor/bin/pest tests/Feature/Services/Monitoring/MortgageDeltaDetectorTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Monitoring/MortgageDeltaDetector.php tests/Feature/Services/Monitoring/MortgageDeltaDetectorTest.php
git commit -m "feat(monitoring): add MortgageDeltaDetector with hash-fast diff"
```

---

### Task 10: Integrér delta-detection i MonitoringService

**Files:**
- Modify: `registry-api/app/Services/Monitoring/MonitoringService.php:24-29`

- [ ] **Step 1: Tilføj test**

Append til `tests/Feature/Services/Monitoring/MonitoringServiceTest.php` (eller create):
```php
it('fires mortgage_change alert via runChecks', function () {
    $property = Property::factory()->create();
    $watchlist = Watchlist::factory()->create([
        'watch_type' => 'property',
        'watch_value' => $property->id,
        'alert_types' => ['mortgage_change', 'new_lien'],
        'is_active' => true,
    ]);

    Mortgage::factory()->create([
        'property_id' => $property->id,
        'mortgage_type' => 'udlaeg',
        'principal_amount' => 3_500_000_00,
    ]);
    
    // Snapshot yesterday (no mortgage)
    app(MortgageSnapshotter::class)->snapshot([$property->id], today()->subDay());
    // Add mortgage today, snapshot today
    app(MortgageSnapshotter::class)->snapshot([$property->id], today());

    $count = app(MonitoringService::class)->runChecks();

    expect($count)->toBeGreaterThan(0)
        ->and(Alert::where('watchlist_id', $watchlist->id)->count())->toBeGreaterThan(0);
});
```

- [ ] **Step 2: Modify MonitoringService::runChecks**

Edit `app/Services/Monitoring/MonitoringService.php:20-32`:
```php
public function runChecks(): int
{
    $this->checkBoligaStaleness();

    $alertCount = 0;
    $watchlists = Watchlist::where('is_active', true)->get();

    foreach ($watchlists as $watchlist) {
        $alertCount += match ($watchlist->watch_type) {
            'property' => $this->checkProperty($watchlist),
            'postal_code' => $this->checkPostalCode($watchlist),
            'company' => $this->checkCompany($watchlist),
            'person' => $this->checkPerson($watchlist),
            default => 0,
        };
    }

    return $alertCount;
}
```

- [ ] **Step 3: Udvid `checkProperty` med delta-detection**

Append til `checkProperty()` (efter eksisterende `transaction` + `ownership_change` checks, før `$watchlist->touch()`):
```php
// Mortgage delta detection (F1 sticky-feature)
$alertTypes = $watchlist->alert_types ?? [];
if (in_array('mortgage_change', $alertTypes) || in_array('new_lien', $alertTypes)) {
    $events = app(MortgageDeltaDetector::class)
        ->detectFor((int) $watchlist->watch_value, now()->startOfDay());
    
    foreach ($events as $event) {
        $isLien = in_array($event->after['mortgage_type'] ?? null, ['udlaeg', 'retsanmaerkning']);
        $alertType = $isLien ? 'new_lien' : 'mortgage_change';
        $priority = $isLien ? 'high' : 'medium';
        
        if (! in_array($alertType, $alertTypes)) {
            continue; // User opted out of this alert type
        }
        
        Alert::create([
            'watchlist_id' => $watchlist->id,
            'alert_type' => $alertType,
            'priority' => $priority,
            'title' => "{$this->humanizeEventType($event->event_type)}: {$property->address}",
            'description' => $this->describeEvent($event),
            'metadata' => [
                'property_id' => $property->id,
                'mortgage_event_id' => $event->id,
                'mortgage_id' => $event->mortgage_id,
                'before' => $event->before,
                'after' => $event->after,
            ],
        ]);
        $alertCount++;
    }
}
```

Plus implementér `humanizeEventType()` og `describeEvent()` private helpers + `checkPerson()` stub:
```php
protected function humanizeEventType(string $type): string
{
    return match ($type) {
        MortgageEvent::TYPE_CREATED => 'Ny tinglysning',
        MortgageEvent::TYPE_DEACTIVATED => 'Tinglysning aflyst',
        MortgageEvent::TYPE_REACTIVATED => 'Tinglysning genaktiveret',
        MortgageEvent::TYPE_PRINCIPAL_CHANGED => 'Hovedstol ændret',
        MortgageEvent::TYPE_CREDITOR_CHANGED => 'Kreditor ændret',
        MortgageEvent::TYPE_PRIORITY_CHANGED => 'Prioritet ændret',
        default => 'Tinglysning ændret',
    };
}

protected function describeEvent(MortgageEvent $event): string
{
    $after = $event->after ?? [];
    $before = $event->before ?? [];
    
    return match ($event->event_type) {
        MortgageEvent::TYPE_CREATED => sprintf(
            '%s på %s DKK fra %s, prioritet %s',
            $after['mortgage_type'] ?? 'pantebrev',
            number_format(($after['principal_amount'] ?? 0) / 100, 0, ',', '.'),
            $after['creditor'] ?? 'ukendt',
            $after['priority'] ?? '-'
        ),
        MortgageEvent::TYPE_PRINCIPAL_CHANGED => sprintf(
            'Hovedstol ændret fra %s til %s DKK',
            number_format(($before['principal_amount'] ?? 0) / 100, 0, ',', '.'),
            number_format(($after['principal_amount'] ?? 0) / 100, 0, ',', '.')
        ),
        default => 'Detaljer i metadata',
    };
}

protected function checkPerson(Watchlist $watchlist): int
{
    // Stub for Sprint 0a; full implementation Sprint 2
    // For now: track CVR-roles for a person
    return 0;
}
```

- [ ] **Step 4: Test**

```bash
./vendor/bin/pest tests/Feature/Services/Monitoring/MonitoringServiceTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Monitoring/MonitoringService.php tests/Feature/Services/Monitoring/MonitoringServiceTest.php
git commit -m "feat(monitoring): integrate MortgageDeltaDetector into runChecks"
```

---

### Task 11: Console-command `mortgages:snapshot` + scheduler-registration

**Files:**
- Create: `registry-api/app/Console/Commands/MortgageSnapshotCommand.php`
- Modify: `registry-api/app/Console/Kernel.php` (eller routes/console.php)

- [ ] **Step 1: Skriv command**

```php
<?php

namespace App\Console\Commands;

use App\Services\Monitoring\MortgageSnapshotter;
use Illuminate\Console\Command;

class MortgageSnapshotCommand extends Command
{
    protected $signature = 'mortgages:snapshot {--date= : ISO date, default today}';
    protected $description = 'Snapshot active mortgages for all properties referenced by active watchlists';

    public function handle(MortgageSnapshotter $snapshotter): int
    {
        $date = $this->option('date') 
            ? \Carbon\Carbon::parse($this->option('date'))
            : now()->startOfDay();
        
        $this->info("Snapshotting mortgages for {$date->toDateString()}...");
        $count = $snapshotter->snapshotForActiveWatchlists($date);
        $this->info("Wrote {$count} snapshot rows.");
        
        return self::SUCCESS;
    }
}
```

- [ ] **Step 2: Registrér scheduler**

Tilføj til `routes/console.php` (eller `app/Console/Kernel.php` schedule()-metode):
```php
Schedule::command('mortgages:snapshot')
    ->dailyAt('02:00')
    ->onOneServer()
    ->withoutOverlapping(60)
    ->onFailure(fn () => app(\App\Services\SchedulerFailureNotifier::class)?->notify('mortgages:snapshot failed'));
```

- [ ] **Step 3: Test**

Create `tests/Feature/Console/MortgageSnapshotCommandTest.php`:
```php
it('runs snapshot command', function () {
    $property = \App\Models\Property::factory()->create();
    \App\Models\Watchlist::factory()->create([
        'watch_type' => 'property',
        'watch_value' => $property->id,
        'is_active' => true,
    ]);
    \App\Models\Mortgage::factory()->create(['property_id' => $property->id]);

    $this->artisan('mortgages:snapshot')->assertSuccessful();

    expect(\App\Models\MortgageSnapshot::count())->toBeGreaterThan(0);
});
```

Run:
```bash
./vendor/bin/pest tests/Feature/Console/MortgageSnapshotCommandTest.php
```

Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add app/Console/Commands/MortgageSnapshotCommand.php routes/console.php tests/Feature/Console/MortgageSnapshotCommandTest.php
git commit -m "feat(monitoring): add mortgages:snapshot command + daily 02:00 schedule"
```

---

### Task 12: Udvid MonitoringController validators + tilføj checkBatch endpoint

**Files:**
- Modify: `registry-api/app/Http/Controllers/Api/V1/MonitoringController.php:18-24`

- [ ] **Step 1: Skriv test**

Create `tests/Feature/Api/V1/MonitoringControllerTest.php` (eller append):
```php
it('accepts person watch_type', function () {
    $user = \App\Models\User::factory()->create();
    
    $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/monitoring/watchlists', [
        'watch_type' => 'person',
        'watch_value' => 'Brian Nielsen',
        'alert_types' => ['ownership_change'],
    ]);
    
    $response->assertCreated();
});

it('accepts mortgage_change alert_type', function () {
    $user = \App\Models\User::factory()->create();
    $property = \App\Models\Property::factory()->create();
    
    $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/monitoring/watchlists', [
        'watch_type' => 'property',
        'watch_value' => (string) $property->id,
        'alert_types' => ['mortgage_change', 'new_lien'],
    ]);
    
    $response->assertCreated();
});

it('check-batch returns is_followed array', function () {
    $user = \App\Models\User::factory()->create();
    $property = \App\Models\Property::factory()->create();
    \App\Models\Watchlist::factory()->create([
        'user_id' => $user->id,
        'watch_type' => 'property',
        'watch_value' => (string) $property->id,
        'is_active' => true,
    ]);
    
    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/monitoring/watchlists/check-batch', [
            'items' => [
                ['type' => 'property', 'value' => (string) $property->id],
                ['type' => 'company', 'value' => '12345678'],
            ],
        ]);
    
    $response->assertOk()
        ->assertJsonPath('data.0.is_followed', true)
        ->assertJsonPath('data.1.is_followed', false);
});
```

- [ ] **Step 2: Modify validator i `store()`**

Edit `app/Http/Controllers/Api/V1/MonitoringController.php:18-24`:
```php
$request->validate([
    'watch_type' => 'required|string|in:property,postal_code,company,person',
    'watch_value' => 'required|string',
    'display_label' => 'nullable|string|max:255',
    'alert_types' => 'required|array|min:1',
    'alert_types.*' => 'string|in:transaction,ownership_change,valuation,new_listing,mortgage_change,new_lien,creditor_change,principal_change,annual_report',
]);
```

- [ ] **Step 3: Tilføj `checkBatch` method**

Append til `MonitoringController`:
```php
public function checkBatch(Request $request): JsonResponse
{
    $request->validate([
        'items' => 'required|array|max:100',
        'items.*.type' => 'required|string|in:property,postal_code,company,person',
        'items.*.value' => 'required|string',
    ]);

    $userId = $request->user()?->id;
    $items = $request->input('items');

    // Pull all matching watchlists in one query
    $followed = Watchlist::where('user_id', $userId)
        ->where('is_active', true)
        ->where(function ($q) use ($items) {
            foreach ($items as $item) {
                $q->orWhere(function ($qq) use ($item) {
                    $qq->where('watch_type', $item['type'])
                       ->where('watch_value', $item['value']);
                });
            }
        })
        ->get()
        ->keyBy(fn ($w) => "{$w->watch_type}:{$w->watch_value}");

    $result = collect($items)->map(fn ($item) => [
        'type' => $item['type'],
        'value' => $item['value'],
        'is_followed' => $followed->has("{$item['type']}:{$item['value']}"),
        'watchlist_id' => $followed->get("{$item['type']}:{$item['value']}")?->id,
    ]);

    return $this->success($result);
}
```

- [ ] **Step 4: Registrér route**

Edit `routes/api/v1.php` linje 106-110, tilføj:
```php
Route::post('monitoring/watchlists/check-batch', [MonitoringController::class, 'checkBatch']);
```

- [ ] **Step 5: Test**

```bash
./vendor/bin/pest tests/Feature/Api/V1/MonitoringControllerTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/V1/MonitoringController.php routes/api/v1.php tests/Feature/Api/V1/MonitoringControllerTest.php
git commit -m "feat(api): expand watchlist validators + add check-batch endpoint"
```

---

### Task 13: V2-routes + V2-controller alias

**Files:**
- Create: `registry-api/routes/api/v2.php`
- Create: `registry-api/app/Http/Controllers/Api/V2/WatchlistController.php`
- Create: `registry-api/app/Http/Controllers/Api/V2/AlertController.php`
- Modify: `registry-api/bootstrap/app.php` eller `RouteServiceProvider` for at loade v2.php

- [ ] **Step 1: Lav v2.php route-fil**

Create `routes/api/v2.php`:
```php
<?php

use App\Http\Controllers\Api\V2\AlertController;
use App\Http\Controllers\Api\V2\WatchlistController;
use App\Http\Controllers\Api\V1\MortgageSearchController;
use Illuminate\Support\Facades\Route;

Route::prefix('v2')->middleware('auth:sanctum')->group(function () {
    // Watchlists
    Route::get('watchlists', [WatchlistController::class, 'index']);
    Route::post('watchlists', [WatchlistController::class, 'store']);
    Route::delete('watchlists/{id}', [WatchlistController::class, 'destroy']);
    Route::post('watchlists/check-batch', [WatchlistController::class, 'checkBatch']);
    
    // Alerts
    Route::get('alerts', [AlertController::class, 'index']);
    Route::patch('alerts/{id}/read', [AlertController::class, 'markRead']);
    
    // Debt search (alias for mortgages/search with extended schema)
    Route::get('debt-search', [MortgageSearchController::class, 'search']);
});
```

- [ ] **Step 2: Tilføj v2 til app-bootstrap**

Edit `bootstrap/app.php` (Laravel 11+) eller `RouteServiceProvider::boot`:
```php
->withRouting(
    api: __DIR__.'/../routes/api/v1.php',
    apiPrefix: 'api',
    // ...
    using: function () {
        Route::middleware('api')->prefix('api')->group(__DIR__.'/../routes/api/v1.php');
        Route::middleware('api')->prefix('api')->group(__DIR__.'/../routes/api/v2.php');
    },
)
```

- [ ] **Step 3: Skriv V2 controllers (extend V1)**

Create `app/Http/Controllers/Api/V2/WatchlistController.php`:
```php
<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Api\V1\MonitoringController;

/**
 * V2-alias for MonitoringController.
 * Same logic, different URL prefix.
 *
 * Sunset planned for V1: 2026-06-01.
 */
class WatchlistController extends MonitoringController
{
    // Inherits index(), store(), destroy(), checkBatch().
}
```

Create `app/Http/Controllers/Api/V2/AlertController.php`:
```php
<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Api\V1\MonitoringController;

class AlertController extends MonitoringController
{
    // Inherits alerts() and markRead().
    // Aliased via routes/api/v2.php to GET /alerts and PATCH /alerts/{id}/read.
}
```

- [ ] **Step 4: Tilføj Sunset middleware på v1 monitoring/* routes**

Create `app/Http/Middleware/SunsetHeader.php`:
```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SunsetHeader
{
    public function handle(Request $request, Closure $next, string $sunsetDate)
    {
        $response = $next($request);
        $response->headers->set('Sunset', $sunsetDate);
        $response->headers->set('Deprecation', 'true');
        $response->headers->set('Link', '<https://docs.frankston.io/registry-api/v2-migration>; rel="deprecation"');
        return $response;
    }
}
```

Edit `routes/api/v1.php` linje 106-110:
```php
Route::middleware('sunset:Sat, 31 May 2026 23:59:59 GMT')->group(function () {
    Route::post('monitoring/watchlists', [MonitoringController::class, 'store']);
    Route::get('monitoring/watchlists', [MonitoringController::class, 'index']);
    Route::post('monitoring/watchlists/check-batch', [MonitoringController::class, 'checkBatch']);
    Route::delete('monitoring/watchlists/{id}', [MonitoringController::class, 'destroy']);
    Route::get('monitoring/alerts', [MonitoringController::class, 'alerts']);
    Route::patch('monitoring/alerts/{id}/read', [MonitoringController::class, 'markRead']);
});
```

Registrér middleware-alias i `bootstrap/app.php`:
```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->alias([
        'sunset' => \App\Http\Middleware\SunsetHeader::class,
    ]);
})
```

- [ ] **Step 5: Test V2 endpoints**

Append til V2 test:
```php
it('v2 watchlists endpoint mirrors v1', function () {
    $user = \App\Models\User::factory()->create();
    
    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v2/watchlists');
    
    $response->assertOk();
});

it('v1 monitoring routes return Sunset header', function () {
    $user = \App\Models\User::factory()->create();
    
    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/monitoring/watchlists');
    
    expect($response->headers->get('Sunset'))->not->toBeNull()
        ->and($response->headers->get('Deprecation'))->toBe('true');
});
```

Run:
```bash
./vendor/bin/pest tests/Feature/Api/V2/WatchlistControllerTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add routes/api/v2.php app/Http/Controllers/Api/V2/ app/Http/Middleware/SunsetHeader.php routes/api/v1.php bootstrap/app.php tests/Feature/Api/V2/
git commit -m "feat(api): add /v2 routes for watchlists/alerts/debt-search + Sunset header on v1"
```

---

### Task 14: V2 DebtSearch med owner_type + debt_type (forlænget Mortgages/search)

**Files:**
- Modify: `registry-api/app/Http/Controllers/Api/V1/MortgageSearchController.php:15-78`

- [ ] **Step 1: Skriv test**

Create `tests/Feature/Api/V2/DebtSearchTest.php`:
```php
it('filters by mortgage_type', function () {
    $user = \App\Models\User::factory()->create();
    $property = \App\Models\Property::factory()->create();
    
    \App\Models\Mortgage::factory()->create([
        'property_id' => $property->id,
        'mortgage_type' => 'ejerpantebrev',
        'principal_amount' => 5_000_000_00,
    ]);
    \App\Models\Mortgage::factory()->create([
        'property_id' => $property->id,
        'mortgage_type' => 'realkredit_pantebrev',
        'principal_amount' => 3_000_000_00,
    ]);
    
    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v2/debt-search?mortgage_type=ejerpantebrev');
    
    $response->assertOk()
        ->assertJsonCount(1, 'data');
});

it('filters by owner_type=company', function () {
    $user = \App\Models\User::factory()->create();
    
    // Create one company-owned property and one person-owned
    $companyProperty = \App\Models\Property::factory()->create([
        'primary_owner_type' => 'company',  // assume schema has this
    ]);
    $personProperty = \App\Models\Property::factory()->create([
        'primary_owner_type' => 'person',
    ]);
    
    \App\Models\Mortgage::factory()->create(['property_id' => $companyProperty->id]);
    \App\Models\Mortgage::factory()->create(['property_id' => $personProperty->id]);
    
    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v2/debt-search?owner_type=company');
    
    $response->assertOk()
        ->assertJsonCount(1, 'data');
});
```

- [ ] **Step 2: Modify validator + query i MortgageSearchController.search**

Edit `app/Http/Controllers/Api/V1/MortgageSearchController.php:17-33`:
```php
$validated = $request->validate([
    'creditor' => 'nullable|string',
    'min_amount' => 'nullable|numeric|min:0',
    'max_amount' => 'nullable|numeric|min:0',
    'min_rate' => 'nullable|numeric',
    'max_rate' => 'nullable|numeric',
    'postal_codes' => 'nullable|array',
    'postal_codes.*' => 'string|size:4',
    'postal_code_from' => 'nullable|string|size:4',
    'postal_code_to' => 'nullable|string|size:4',
    'mortgage_type' => 'nullable|string',
    'owner_type' => 'nullable|string|in:company,person',
    'limit' => 'nullable|integer|min:1|max:100',
    'offset' => 'nullable|integer|min:0',
]);
```

Tilføj efter eksisterende filtre (omkring linje 67):
```php
if ($validated['mortgage_type'] ?? null) {
    $query->where('mortgage_type', $validated['mortgage_type']);
}

if ($validated['owner_type'] ?? null) {
    $query->whereHas('property', fn ($q) => 
        $q->where('primary_owner_type', $validated['owner_type'])
    );
}
```

**OBS for Kristian:** `properties.primary_owner_type` kolonne skal verificeres. Hvis ikke der: enten (a) tilføj migration, eller (b) udled via join til `property_owners` med owner_type-flag. Beslutning afhænger af eksisterende skema. Spørg Frederik mandag om data findes i én af de to.

- [ ] **Step 3: Kør tests**

```bash
./vendor/bin/pest tests/Feature/Api/V2/DebtSearchTest.php
```

Expected: PASS (eller fail med tydelig "primary_owner_type-kolonne mangler" — tilføj som blocker til mandag-status).

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/Api/V1/MortgageSearchController.php tests/Feature/Api/V2/DebtSearchTest.php
git commit -m "feat(api): add mortgage_type + owner_type filters to debt-search"
```

---

### Task 15: metis-package — opdatér RegistryApi.php til /v2/* paths

**Files:**
- Modify: `metis-package/src/Services/RegistryApi.php:421-510`

(Skift fra registry-api til metis-package for denne task)

- [ ] **Step 1: Lav feature-branch**

```bash
cd /Users/Frederik/metis-package
git checkout -b feature/sprint-0a-v2-routes
```

- [ ] **Step 2: Opdatér alle endpoint-paths fra v1 til v2**

Edit `src/Services/RegistryApi.php`:

```php
// Linje 421 - debt-search
public function debtSearch(array $filters, ?string $source = null): array
{
    $request = $this->client();
    if ($source !== null) {
        $request = $request->withHeaders(['X-Search-Source' => $source]);
    }
    
    try {
        return $request->get('/v2/debt-search', $filters)->throw()->json();
        //                    ^^^ changed from /v1/debt-search
    } // ...
}

// Linje 449 - listWatchlists
public function listWatchlists(): array
{
    try {
        return $this->client()->get('/v2/watchlists')->throw()->json();
        //                              ^^^ changed
    } // ...
}

// Linje 458 - checkBatch
public function checkBatch(array $items): array
{
    try {
        return $this->client()
            ->post('/v2/watchlists/check-batch', ['items' => $items])
            //      ^^^ changed
            ->throw()
            ->json();
    } // ...
}

// Linje 470 - createWatchlist
public function createWatchlist(string $type, string $value, ?string $label, array $alertTypes): array
{
    try {
        return $this->client()->post('/v2/watchlists', [
            //                          ^^^ changed
            'watch_type' => $type,
            'watch_value' => $value,
            'display_label' => $label,
            'alert_types' => $alertTypes,
        ])->throw()->json();
    } // ...
}

// Linje 484 - deleteWatchlist
public function deleteWatchlist(int $id): array
{
    try {
        return $this->client()->delete("/v2/watchlists/{$id}")->throw()->json();
        //                                ^^^ changed
    } // ...
}

// Linje 493 - listAlerts
public function listAlerts(bool $unreadOnly = false, ?string $priority = null, int $page = 1): array
{
    try {
        return $this->client()->get('/v2/alerts', array_filter([
            //                          ^^^ changed
            'unread_only' => $unreadOnly ? 1 : 0,
            'priority' => $priority,
            'page' => $page,
        ], fn ($v) => $v !== null))->throw()->json();
    } // ...
}

// Linje 506 - markAlertRead
public function markAlertRead(int $alertId): array
{
    try {
        return $this->client()->patch("/v2/alerts/{$alertId}/read")->throw()->json();
        //                              ^^^ changed
    } // ...
}
```

- [ ] **Step 3: Opdatér eksisterende DebtSearchTest mocks**

Edit `tests/Feature/DebtSearchTest.php` — alle Http::fake() / Http::expects() der referer `/v1/debt-search` skal opdateres til `/v2/debt-search`.

- [ ] **Step 4: Kør tests**

```bash
./vendor/bin/pest
```

Expected: PASS (alle tests). Hvis nogen mock-paths fejler, fix dem.

- [ ] **Step 5: Commit**

```bash
git add src/Services/RegistryApi.php tests/
git commit -m "feat(api): migrate RegistryApi to /v2/* paths"
```

---

### Task 16: metis-package — udvid FollowButton og AlertsInbox til person + 3-priority

**Files:**
- Modify: `metis-package/src/Livewire/FollowButton.php:14-44`
- Modify: `metis-package/src/Livewire/AlertsInbox.php`

- [ ] **Step 1: Skriv test for FollowButton med person**

Append til `tests/Feature/FollowButtonTest.php`:
```php
it('mounts with watch_type=person', function () {
    Http::fake([
        '*/v2/watchlists/check-batch' => Http::response([
            'data' => [['is_followed' => false]],
        ]),
    ]);
    
    \Livewire\Livewire::test(\TheFountainhead\Metis\Livewire\FollowButton::class, [
        'watchType' => 'person',
        'watchValue' => 'Brian Nielsen',
        'displayLabel' => 'Brian Nielsen',
    ])->assertSet('isFollowed', false);
});
```

- [ ] **Step 2: Tjek FollowButton tager person allerede**

Læs `src/Livewire/FollowButton.php:14-44`:
- `$watchType` er allerede string-typed, ingen enum
- `mount()` accepterer den
- `checkBatch` bruges i mount

Det er allerede agnostisk over for type. Ingen kode-ændring nødvendig.

- [ ] **Step 3: Verificér via test**

```bash
./vendor/bin/pest tests/Feature/FollowButtonTest.php
```

Expected: PASS.

- [ ] **Step 4: Opdatér AlertsInbox til 3-priority rendering**

Edit `src/Livewire/AlertsInbox.php` — find priority-rendering og ensure 3-level support: `low`/`medium`/`high`.

Edit relevant blade-template `resources/views/livewire/alerts-inbox.blade.php` — tilføj badge per priority:
```blade
@php
    $priorityClass = match($alert['priority'] ?? 'medium') {
        'high' => 'bg-red-100 text-red-800',
        'medium' => 'bg-yellow-100 text-yellow-800',
        'low' => 'bg-gray-100 text-gray-700',
        default => 'bg-gray-100',
    };
@endphp
<span class="inline-block px-2 py-0.5 text-xs rounded {{ $priorityClass }}">
    {{ ucfirst($alert['priority'] ?? 'medium') }}
</span>
```

- [ ] **Step 5: Tests og commit**

```bash
./vendor/bin/pest
git add src/Livewire/AlertsInbox.php resources/views/livewire/alerts-inbox.blade.php tests/Feature/FollowButtonTest.php
git commit -m "feat(ui): add 3-level priority badge in AlertsInbox + verify person-watch_type"
```

---

### Task 17: Push begge feature-branches som draft-PRs

- [ ] **Step 1: Push registry-api**

```bash
cd /Users/Frederik/registry-api
git push -u origin feature/sprint-0a-watchlists-rebrand
gh pr create --draft --title "Sprint 0a: backend foundation for F1+F2 (mortgage-delta + /v2 routes)" \
  --body-file <(cat <<EOF
## Summary

Sprint 0a foundation per docs/superpowers/specs/2026-05-01-metis-resight-parity-design.md.

## Changes

- DB: 5 migrations (priority, mortgage_snapshots, mortgage_events, alerts.priority, watchlists.display_label)
- Models: MortgageSnapshot, MortgageEvent
- Services: MortgageSnapshotter (daily snapshot), MortgageDeltaDetector (hash-fast diff)
- MonitoringService: integrated mortgage-delta in checkProperty + person watch_type stub
- Routes: /v2/* parallel to /v1/monitoring/*; v1 with Sunset header (Sat 31 May 2026)
- API: validator extension for person watch_type + 3 new alert_types; check-batch endpoint
- Tests: ~30 new Pest tests covering snapshot, delta-detector, controllers

## Migration order

Run migrations in order. None destructive.

## Sunset plan

v1/monitoring/* deprecated as of merge. v2/* canonical. Plan to delete v1 paths
after 30 days of zero traffic confirmed via Forge logs.

## Test plan

- [ ] All existing tests pass
- [ ] New Pest tests pass
- [ ] Smoke-test against metis-package PR (separate)
- [ ] Manual: Forge logs show v2 traffic from metis-package after deploy

## Related

- Spec: docs/superpowers/specs/2026-05-01-metis-resight-parity-design.md
- Audit: metis-package/docs/user-research/2026-04-29-draupnir-resight/audit-monitoring-consumers.md
- metis-package PR: see companion in metis-package repo

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)
```

- [ ] **Step 2: Push metis-package**

```bash
cd /Users/Frederik/metis-package
git push -u origin feature/sprint-0a-v2-routes
gh pr create --draft --title "Sprint 0a: migrate RegistryApi to /v2/* paths + 3-priority alerts" \
  --body "Companion to registry-api/feature/sprint-0a-watchlists-rebrand. See spec docs/superpowers/specs/2026-05-01-metis-resight-parity-design.md."
```

- [ ] **Step 3: Verificér PRs**

```bash
gh pr list --repo TheFountainhead/registry-api --state open
gh pr list --repo TheFountainhead/metis-package --state open
```

---

## Self-Review Checklist

Efter alle tasks er done, tjek:

1. **Spec coverage:**
   - [ ] Alle Sprint 0a-tasks fra spec v1.2 Sprint-tabel adresseret? (route-rebrand + validator + audit + delta-engine)
   - [ ] Alert-type-reduktion fra 5 til 3 reflekteret? (faktisk 4-5 tilladt i validator for backward-compat — forenkles i UI)

2. **Placeholder scan:**
   - [ ] Ingen "TBD", "TODO", "implement later"
   - [ ] Alle code-blocks komplette
   - [ ] Alle commands eksakt-formaterede

3. **Type consistency:**
   - [ ] `MortgageEvent::TYPE_*`-konstanter bruges konsistent på tværs af tasks
   - [ ] `mortgage_change` vs `mortgage_event` — bruges `mortgage_change` som alert_type (UI), `MortgageEvent` som model (backend)
   - [ ] Method-signatures på MortgageDeltaDetector matcher mellem tests og implementation

## Risici og open questions

1. **Properties.primary_owner_type-kolonne** (Task 14) — kan være missing. Hvis så: enten migration eller property_owners-join. Spørg Frederik mandag.
2. **Mortgage_type-værdier** i prod-DB (Task 1) — hvis ikke standardiserede strings ('ejerpantebrev'/'realkredit_pantebrev'/etc.), kræver Task 14's filter case-mapping eller backfill.
3. **Forge cron-config** for `mortgages:snapshot` — kræver Frederik-godkendelse til Forge før prod-deploy.
4. **Rate-limiting på debt-search** — eksisterende `throttle:20,1` på metis-routes kan ramme kreditor-pilot bruger med høj-volume. Verificér og evt. bump.
5. **Hvad sker hvis snapshot-cron fejler én dag** — der er ingen backfill-logic. Skal designes Sprint 1 hvis verificeret som problem.

## Execution Handoff

Plan complete and saved to `docs/superpowers/plans/2026-05-01-metis-sprint-0a-plan.md`. To execute:

**Subagent-driven** (recommended):
- Use superpowers:subagent-driven-development skill
- Fresh subagent per task + two-stage review
- Allows parallel work between tasks 1-7 (DB + models) and 8-11 (services)

**Inline execution:**
- Use superpowers:executing-plans skill
- Sequentially with checkpoints
- Better for one-developer (Kristian) workflow

**Recommendation for Sprint 0a:** Inline execution (Kristian alone for backend), since tasks have heavy sequential dependencies (migrations → models → services → controllers → routes → tests).
