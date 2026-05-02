# Portfolio-Tinglysning-tab Implementation Design

**Status:** Draft v1.1 (2026-05-02) — applies multi-agent review feedback
**Trigger:** Rasmus Hornhaver demo at Draupnir Invest 2026-04-29 + Rasmus' bug-feedback 2026-05-02
**Owner:** Frederik
**Estimat:** 11-13 dage Sprint 1 (median 12,0)

---

## v1.1 Changelog

Applied review feedback from 6 parallel agents (spec-flow, architecture, data-integrity, simplicity, performance, Laravel-conventions):

- **Tree-index strategy switched** fra nightly TRUNCATE+rebuild til incremental observer-maintenance + nightly reconciliation pass (sidesteps F1 staleness + rebuild-lock issues)
- **Watchlist backfill column** `expanded_to_ancestors_at` tilføjet for at undgå retroactive alert-storm til eksisterende F1-watchere
- **Resolver dedup** eksplicit defineret: `(user_id, mortgage_id, change_kind)` uniqueness, ikke watchlist-id
- **Multi-parent CVR** håndteret: composite UNIQUE inkluderer `descendant_company_id`, allow multiple ancestry paths
- **Symbolic 1-DKK transaktioner** filtreres i LTV (familieoverdragelse-types ekskluderet)
- **`include_inactive` boolean → `status=active|inactive|all` enum** (Taylor's anti-pattern killed)
- **`TinglysningOverviewService` → `BuildTinglysningOverview` Action**
- **Drop standalone `MortgageDetailDrawer` Livewire-component → `<flux:modal variant="flyout">`**
- **Drop standalone `PriceIndexer` → metode på `PropertyValueResolver`**
- **Defer PDF til Sprint 2**, XLSX-only Sprint 1 (åbne branding-spørgsmål blokerer ikke ship)
- **JsonResource** for API-shape stabilitet
- **Stable enum for `ltv.method`**: `skoede_price | sale_price | public_valuation | unavailable` (ikke registry-api intern tabelnavn)
- **Polling 2000ms + stop ved complete + Cache::remember** på tier_meta (60s) og Boligsiden indeks (24h)
- **Delta-payloads** (`mortgages_added: [...]`) som `CompanyProperties::loadMore()` allerede gør
- **PropertyValueResolver::resolveBatch()** eksplicit metode for at undgå N+1
- **PDF queued via Horizon** når den shippes Sprint 2 (sync timeout-risk)
- **Tier 4 user override** cleanly fjernet (var halvt-deferred — ren Sprint 2)
- **Drawer URL namespaced** `?ting_mortgage=` for at undgå cross-tab kollision
- **Error-state + a11y subsections** tilføjet
- **Cross-tab drawer collision policy** dokumenteret
- **Multi-parent CVR + diamond-paths** edge-case eksplicit

---

## Goal

Build a Tinglysning-tab on the company page that shows **all pantebreve across a holding's entire koncerntræ** in one flat-list with portfolio-level metrics (samlet hovedstol, total LTV, antal pantebreve), filterable, with batch-watchlist support and Linear-style flyout drilldown.

This is the killer view from spec v1.2's F-NEW. It's the feature that makes Resight customers reconsider switching, and it lays the foundation for the broader Lender Intelligence product line (Vej B / `frankston-lender.dk`).

## Architecture (one-paragraph)

Backend: registry-api gets a new `/api/v1/companies/{cvr}/tinglysning-overview` endpoint that delegates to a `BuildTinglysningOverview` Action. The Action joins property_owners → properties → mortgages → property_transactions/property_sales/valuations using a recursive CTE for koncerntræ traversal. F1's `watchlists.watch_type='company'` mekanisme genbruges; `DetectMortgageChange::resolveWatchlists()` udvides til ancestor-traversal via et nyt `company_property_tree_index` der vedligeholdes **incrementelt af observers** med nightly reconciliation som safety-net (ikke source-of-truth). Frontend: metis-package gets a new `CompanyTinglysning` Livewire section using `wire:poll.2s` for delta-streaming, plus a `<flux:modal variant="flyout">` for shareable-URL drilldown. LTV is computed server-side via `PropertyValueResolver::resolveBatch()` (3-trins fallback chain, batch-loaded to avoid N+1).

## Tech Stack

- **Backend**: Laravel 12, PHP 8.4, PostgreSQL 16 (registry-api repo)
- **Frontend**: Livewire 3, Tailwind, Flux UI (`flux:modal variant="flyout"` for drawer)
- **Eksport Sprint 1**: `phpoffice/phpspreadsheet` (XLSX). PDF deferred til Sprint 2.
- **Streaming UX**: Livewire `wire:poll.2s` (sløvere end CompanyProperties' default for at undgå polling-overhead på 100+ samtidige brugere)
- **API shape**: Eloquent API Resources (`TinglysningOverviewResource`) for stabil contract

---

## File Structure

### registry-api repo

**Modify:**
- `app/Http/Controllers/Api/V1/CompanyController.php` — add `tinglysningOverview()` method (delegates til Action)
- `app/Jobs/DetectMortgageChange.php` — `resolveWatchlists()` udvides med ancestor-traversal + eksplicit user-level dedup
- `app/Observers/PropertyOwnerObserver.php` — incremental tree-index maintenance (NY observer)
- `app/Observers/CompanyRoleObserver.php` — incremental tree-index maintenance (NY observer)
- `app/Providers/AppServiceProvider.php` — register de to nye observers
- `routes/api.php` — register new endpoint

**Create:**
- `database/migrations/2026_05_02_*_create_company_property_tree_index.php`
- `database/migrations/2026_05_02_*_add_expanded_to_ancestors_at_to_watchlists.php` — backfill-marker for at undgå retroactive alert-storm
- `app/Actions/Companies/BuildTinglysningOverview.php` — main Action (replaces "Service")
- `app/Services/CompanyPortfolio/PropertyValueResolver.php` — 3-trins LTV fallback med `resolveBatch()` + Boligsiden-indeks som intern metode
- `app/Services/CompanyPortfolio/CompanyPropertyTreeBuilder.php` — recursive CTE wrapper (genbrug fra `deep_enrichment_pipeline` hvis muligt)
- `app/Console/Commands/ReconcileCompanyPropertyTreeIndex.php` — nightly reconciliation (NOT source-of-truth, kun safety-net)
- `app/Http/Resources/V1/TinglysningOverviewResource.php` — API shape
- `app/Http/Resources/V1/MortgageRowResource.php`
- `tests/Feature/Api/V1/CompanyTinglysningOverviewTest.php`
- `tests/Feature/Jobs/DetectMortgageChangeAncestorTest.php`
- `tests/Feature/Observers/CompanyPropertyTreeIndexMaintenanceTest.php`
- `tests/Unit/Services/PropertyValueResolverTest.php`

### metis-package repo

**Modify:**
- `src/Livewire/Metis.php` — register new `CompanyTinglysning` section
- `src/Services/RegistryApi.php` — add `fetchCompanyTinglysningOverview($cvr, $options)` med delta-poll-support
- `resources/views/livewire/sections/` — add tab to navigation

**Create:**
- `src/Livewire/Sections/CompanyTinglysning.php` — main tab component
- `resources/views/livewire/sections/company-tinglysning.blade.php` — inkluderer Flux flyout (NO separate Livewire-component for drawer)
- `resources/views/livewire/sections/partials/skeleton-row.blade.php` — streaming placeholder
- `resources/views/livewire/sections/partials/error-state.blade.php` — registry-api timeout/down UX
- `src/Exports/PortfolioTinglysningExport.php` — XLSX builder
- `tests/Feature/Livewire/Sections/CompanyTinglysningTest.php`

**Sprint 2 (deferred):**
- `src/Jobs/GeneratePortfolioTinglysningPdf.php` — queued PDF job
- `resources/views/exports/tinglysning-pdf.blade.php` — PDF template

---

## Data Model Changes

### 1. F1 watchlists — `expanded_to_ancestors_at` backfill column

Eksisterende F1-watchere med `watch_type='company'` matcher i dag kun direkte-ejende CVR. Når ancestor-traversal aktiveres efter deploy, vil disse watchere pludseligt begynde at matche pantebreve i hele koncerntræet, inklusive ejendomme der historisk var i træet — det er en retroactive alert-storm risiko.

**Mitigation**: Tilføj `expanded_to_ancestors_at` timestamp på watchlists. For eksisterende rows: sættes til deploy-time. For nye rows: defaulter til `created_at`. Resolver firer kun ancestor-match hvis `mortgage.dispatched_at >= watchlist.expanded_to_ancestors_at`.

```php
// Migration: add_expanded_to_ancestors_at_to_watchlists.php
Schema::table('watchlists', function (Blueprint $table) {
    $table->timestamp('expanded_to_ancestors_at')->nullable()->after('created_at');
});

// Backfill: alle eksisterende rows sættes til deploy-time
DB::statement("UPDATE watchlists SET expanded_to_ancestors_at = NOW() WHERE expanded_to_ancestors_at IS NULL");
```

Direkte-CVR-match og property-match påvirkes IKKE af denne kolonne — de bruger eksisterende `created_at < dispatchedAt` filter uændret.

### 2. Company-property tree index (incrementelt vedligeholdt)

```php
// Migration: create_company_property_tree_index.php
Schema::create('company_property_tree_index', function (Blueprint $table) {
    $table->id();
    $table->string('root_cvr', 8)->index();
    $table->foreignId('descendant_company_id')->constrained('companies');
    $table->foreignId('property_id')->constrained('properties');
    $table->integer('depth'); // 0 = direkte ejet, 1 = via 1 datterselskab, etc.
    $table->timestamps();

    // Inkluder descendant_company_id i UNIQUE for at understøtte multi-parent CVR'er
    // (samme property kan være i flere træer via diamond-paths). MIN(depth) tie-break
    // håndteres downstream i resolver via DISTINCT root_cvr.
    $table->unique(['root_cvr', 'descendant_company_id', 'property_id'], 'idx_tree_unique');
    $table->index(['property_id', 'root_cvr'], 'idx_tree_reverse_lookup');
});
```

**Vedligeholdelses-strategi (KRITISK ÆNDRING fra v1.0):**

- **Source-of-truth**: Observers på `PropertyOwner` og `CompanyRole` (ny `PropertyOwnerObserver` + `CompanyRoleObserver`) — ved enhver ownership-/koncern-ændring opdateres tree-index incrementelt indenfor samme transaction. Det betyder F1-alerts på nye koncern-kanter virker øjeblikkeligt, ikke 24h forsinket.
- **Safety-net**: Nightly `ReconcileCompanyPropertyTreeIndex`-command kører kl. 03:30 UTC med `withoutOverlapping()` + `onOneServer()`. Den bygger til shadow-tabel (`company_property_tree_index_new`), sammenligner med live-table, logger diff til Flare, og swap'er kun atomic hvis diff < 0,1% (hint om alvorlig observer-bug). Ved større diff: alerter Frederik, swapper IKKE.
- **Schedule registration**:
  ```php
  Schedule::command('tree-index:reconcile')
      ->dailyAt('03:30')
      ->withoutOverlapping(60)  // 60-min lock TTL
      ->onOneServer()
      ->runInBackground();
  ```

### 3. DetectMortgageChange resolver — ancestor + dedup

```php
// Udvidet resolver i DetectMortgageChange.php
private function resolveWatchlists(Mortgage $mortgage): Collection
{
    $dispatchedAt = Carbon::parse($this->dispatchedAt);

    // Path 1: direct property match (uændret)
    $propertyMatches = Watchlist::where('is_active', true)
        ->where('watch_type', 'property')
        ->where('watch_value', $mortgage->property->matrikel_id)
        ->where('created_at', '<', $dispatchedAt)
        ->get();

    // Path 2: direct CVR match (uændret — eksisterende F1-funktionalitet)
    $directCvrs = $mortgage->property->owners
        ->where('owner_type', Company::class)
        ->pluck('owner.cvr')->filter()->unique();

    $directCompanyMatches = Watchlist::where('is_active', true)
        ->where('watch_type', 'company')
        ->whereIn('watch_value', $directCvrs)
        ->where('created_at', '<', $dispatchedAt)
        ->get();

    // Path 3: NY — ancestor CVR via tree-index, gated på expanded_to_ancestors_at
    $ancestorCvrs = DB::table('company_property_tree_index')
        ->where('property_id', $mortgage->property_id)
        ->distinct()
        ->pluck('root_cvr');

    $ancestorMatches = $ancestorCvrs->isEmpty()
        ? collect()
        : Watchlist::where('is_active', true)
            ->where('watch_type', 'company')
            ->whereIn('watch_value', $ancestorCvrs)
            ->where('created_at', '<', $dispatchedAt)
            ->where(function ($q) use ($dispatchedAt) {
                $q->whereNull('expanded_to_ancestors_at')
                  ->orWhere('expanded_to_ancestors_at', '<', $dispatchedAt);
            })
            ->get();

    // Eksplicit user-level dedup: én alert per (user_id, mortgage_id, change_kind)
    // Hvis user har watch på både root CVR og direkte sub-CVR i samme træ, vælges
    // den ÆLDSTE watchlist (forrang til længst-løbende relation).
    return $propertyMatches
        ->concat($directCompanyMatches)
        ->concat($ancestorMatches)
        ->groupBy('user_id')
        ->map(fn ($watchlistsForUser) => $watchlistsForUser->sortBy('created_at')->first())
        ->values();
}
```

---

## API Contract

### `GET /api/v1/companies/{cvr}/tinglysning-overview`

**Query params:**
- `status=active` (default) | `inactive` | `all` — Q5 filtrering (replaces v1.0 boolean `include_inactive`)
- `mortgage_types[]=privatpantebrev,realkreditpantebrev,...` — Q8 filter
- `min_amount=0&max_amount=999999999999` — Q8 range
- `sort=principal_amount_desc` (default) | `tinglysning_date_desc` | `ltv_desc` | `address_asc`
- `tree_depth=1` (default, viser root + 1 niveau) | `full` (Q8 mega-koncern)
- `cursor=<opaque>` — for delta-poll continuation

**Response (TinglysningOverviewResource):**

```json
{
  "company": { "cvr": "28963610", "name": "Mimo Invest ApS" },
  "tree_meta": {
    "total_descendant_companies": 7,
    "total_properties": 18,
    "total_mortgages": 24,
    "total_principal_amount": 24730000000,
    "weighted_ltv": 0.77,
    "tree_depth": 3,
    "applied_tree_depth": 1
  },
  "tier_breakdown": [
    {
      "company_id": 12345,
      "cvr": "28963610",
      "name": "Mimo Invest ApS (root)",
      "depth": 0,
      "property_count": 4,
      "mortgage_count": 4,
      "principal_amount": 4700000000,
      "weighted_ltv": 0.74
    }
  ],
  "mortgages_added": [
    {
      "id": 9999,
      "property_id": 87654,
      "address": "Roligedsvej 12-14, 2400 København NV",
      "bfe": "1234567",
      "owner_company": { "cvr": "28963610", "name": "Mimo Invest ApS" },
      "tier_depth": 0,
      "mortgage_type": "ejerpantebrev",
      "creditor": "Mimo Hotel ApS",
      "debitor": "Mimo Hotel ApS",
      "priority": 6,
      "principal_amount": 820000000,
      "registration_date": "2024-08-15",
      "is_active": true,
      "is_sampant": false,
      "ltv": {
        "value": 0.76,
        "method": "skoede_price",
        "property_value_raw": 6200000000,
        "property_value_indexed_2026": 7100000000,
        "source_date": "2022-04-12"
      }
    }
  ],
  "streaming": {
    "complete": false,
    "cursor": "eyJhZnRlcl9pZCI6OTk5OX0=",
    "total_expected": 24,
    "delivered_so_far": 12
  }
}
```

**Stable enums** (ingen registry-api intern tabel-leakage):
- `ltv.method`: `skoede_price | sale_price | public_valuation | unavailable`

**Streaming-shape (P0 fix fra performance-review):**
- Frontend tracker `cursor` og sender det med næste poll
- Backend returnerer kun nye `mortgages_added` siden cursor; `tree_meta` + `tier_breakdown` er cached i Redis 60s og returneres uændret hvis ikke udløbet
- Polling stopper når `streaming.complete = true`

### `POST /api/v1/watchlists`

Eksisterende F1-endpoint, ingen ny route. Brug `watch_type='company'` + `watch_value=<cvr>`. Idempotency: server-side check på `(user_id, watch_type, watch_value)` returnerer eksisterende watchlist hvis duplicate.

### `POST /api/v1/companies/{cvr}/tinglysning-overview/export`

**Sprint 1 — XLSX kun:**
- Request: `{ "format": "xlsx", "filters": {...} }`
- Response: Binary XLSX stream, sync (forventet < 2s for 200 rows)

**Sprint 2 — PDF queued:**
- Request: `{ "format": "pdf", "filters": {...} }`
- Response: `{ "job_id": "abc...", "status_url": "/api/v1/jobs/abc.../status" }`
- Frontend poller status-url til `complete: true`, downloader derefter

---

## Frontend Components

### `CompanyTinglysning` (main tab — INGEN separat drawer-komponent)

```php
namespace TheFountainhead\Metis\Livewire\Sections;

use Livewire\Attributes\Url;

class CompanyTinglysning extends MetisSection
{
    public ?array $treeMeta = null;
    public ?array $tierBreakdown = null;
    public array $mortgages = [];
    public bool $streamingComplete = false;
    public ?string $streamingCursor = null;
    public ?string $errorState = null; // 'timeout' | 'unauthorized' | 'server_error'

    // Filters (Q5, Q8) — boolean replaced med enum
    public string $status = 'active'; // active | inactive | all
    public array $mortgageTypeFilter = [];
    public ?int $minAmount = null;
    public ?int $maxAmount = null;
    public string $sortBy = 'principal_amount_desc';
    public string $treeDepthState = '1'; // '1' | 'full'

    // Drawer state — URL-bound, namespaced for at undgå cross-tab kollision
    #[Url(as: 'ting_mortgage')]
    public ?int $openMortgageId = null;

    public function mount(string $query): void { /* initial fetch tree_meta + tier */ }
    public function pollForUpdates(): void { /* delta-fetch nye mortgages siden cursor */ }
    public function followKoncern(): void { /* POST /watchlists with watch_type=company */ }
    public function exportXlsx() { /* download */ }
    public function clearFilters() { /* reset til default */ }
    public function navigateDrawer(string $direction): void { /* prev/next mortgage in current list */ }
}
```

### Drawer — `<flux:modal variant="flyout">` direkte i Blade

Ingen separat Livewire-komponent. Alpine `x-data` håndterer keyboard-nav (pil-op/pil-ned) klient-side uden roundtrip per arrow-key.

```blade
<flux:modal variant="flyout" wire:model="openMortgageId" class="w-[600px]">
    <div x-data="{ navigate(dir) { $wire.navigateDrawer(dir) } }"
         x-on:keydown.up.window="navigate('prev')"
         x-on:keydown.down.window="navigate('next')">
        @if($openMortgageId)
            @include('metis::livewire.sections.partials.mortgage-detail', ['mortgageId' => $openMortgageId])
        @endif
    </div>
</flux:modal>
```

Drawer indhold:
1. Mortgage grunddata (creditor, debitor, hovedstol, rente, prioritet, tinglysningsdato)
2. Prioritets-stak på ejendommen — visual progress bar showing pant-belastning vs ejendomsværdi
3. Change-history — F1 events siden tracking startede (lazy-loaded ved drawer-åbning)
4. Link til ejendoms-detail-side (existing Metis property page)
5. PDF-link til pantebrev (hvis crawler har det)
6. "Kopiér link"-knap der kopierer den nuværende URL inkl. `?ting_mortgage=12345`

**Cross-tab drawer-policy**: URL-key er `ting_mortgage` (ikke `mortgage`) for at undgå kollision med fremtidige drawers i andre Metis-sektioner. Hvis bruger skifter til en anden tab, bevares URL-paramet IKKE — drawer-state er bundet til tab-konteksten.

**A11y (KRITISK gap fra v1.0):**
- `<flux:modal>` har built-in focus-trap + ESC-handling
- ARIA live-region annoncerer "Streaming completed, 24 mortgages loaded"
- Pil-op/pil-ned virker som arrow-keys, ikke kun via klik på knapper
- Skeleton-loaders har `aria-busy="true"`

**Post-logout drawer-state recovery:**
Hvis bruger åbner shared link `?ting_mortgage=12345` mens session er udløbet, redirectes til login med intended-URL gemt; efter login åbnes drawer'en automatisk hvis mortgage-id stadig er accessible (ellers vises "Du har ikke adgang til denne pantebrev"-toast).

---

## LTV Fallback Chain (Q2) — 3-trins (Tier 4 fjernet)

`PropertyValueResolver::resolveBatch(Collection $propertyIds): Collection` returnerer en Map af property_id → LTV-info. **Batch-loaded for at undgå N+1** — 3 queries i alt for 200 properties, ikke 600.

```php
public function resolveBatch(Collection $propertyIds): Collection
{
    // Step 1: tinglysning-skøder, ekskluderer symbolske + familieoverdragelser
    $skoeder = PropertyTransaction::whereIn('property_id', $propertyIds)
        ->whereNotNull('price')
        ->where('price', '>=', 100_000)  // 1.000 DKK i ører — filterer 1-DKK-symbolske
        ->whereNotIn('transaction_type', ['familieoverdragelse', 'gaveskøde', 'arvskifte'])
        ->where('transaction_date', '>=', now()->subYears(7))
        ->orderBy('transaction_date', 'desc')
        ->get()
        ->keyBy('property_id');

    // Step 2: Boliga sold for properties uden skøde
    $missingProperties = $propertyIds->diff($skoeder->keys());
    $boligaSales = $missingProperties->isEmpty()
        ? collect()
        : PropertySale::whereIn('property_id', $missingProperties)
            ->where('sale_date', '>=', now()->subYears(7))
            ->orderBy('sale_date', 'desc')
            ->get()
            ->keyBy('property_id');

    // Step 3: Off. vurd. for properties uden begge
    $stillMissing = $missingProperties->diff($boligaSales->keys());
    $valuations = $stillMissing->isEmpty()
        ? collect()
        : Valuation::whereIn('property_id', $stillMissing)
            ->orderBy('valuation_date', 'desc')
            ->get()
            ->keyBy('property_id');

    // Indeksering via Boligsiden — cached i Redis (24h TTL, hele indeks-tabellen warm-loaded)
    return $propertyIds->mapWithKeys(fn ($id) => [
        $id => $this->buildLtvInfo($id, $skoeder, $boligaSales, $valuations),
    ]);
}

private function buildLtvInfo(...): array
{
    // Returnerer: { value, method, property_value_raw, property_value_indexed_2026, source_date }
    // method-enum: skoede_price | sale_price | public_valuation | unavailable
    // Indeksering bruger Cache::remember("boliga_index:{$postal}:{$type}:{$year}", 86400, ...)
    // NULL/0-guard: hvis indeks returnerer 0 eller null, sættes property_value_indexed_2026 = null
    //   og method bevarer base-værdien; ingen exception.
}
```

**3-trins (ikke 4):** Tier 4 (user override) fjernet ren — flyttet til Out of Scope / Sprint 2.

**Disclaimer for off. vurd.**: API-response inkluderer `method: "public_valuation"`. UI viser "Off. vurd. (typisk 30-40% under markedspris for kommercielle)" ved hover/tooltip.

---

## Edge Cases (Q8 + review-fund)

| Case | Handling |
|------|----------|
| Selskab uden ejendomme (direkte) | Tab vises med "Selskabet ejer ikke ejendomme direkte. Tjek datterselskaber via Selskabsstruktur-tab." Link til Selskabsstruktur. |
| Selskab uden pantebreve men med ejendomme | Vis ejendomsliste + samlet vurdering. Tom mortgages-array, banner "Ingen tinglyste pantebreve". |
| Sampant (én pantebrev → flere ejendomme i koncernen) | Vis én række pr. (mortgage_id, property_id) med `is_sampant: true` badge. **Centraliseret `computeTotals()`** sikrer DISTINCT mortgage_id på tværs af UI/XLSX/PDF. |
| Multi-parent CVR (50/50-ejerskab eller diamond-path) | Tree-index gemmer multiple paths via `(root_cvr, descendant_company_id, property_id)`-UNIQUE. Resolver bruger `DISTINCT root_cvr` for at sikre én row per ancestor i UI. Tier-breakdown viser begge paths separat. |
| Koncern-cykler (A → B → A) | `CompanyPropertyTreeBuilder` bruger visited-set i recursive CTE. Test-fixture covers cykel og diamond. |
| Mega-koncerner (≥200 datterselskaber) | `tree_depth=1` default. CTA "Vis hele træet" loader resten. Hard cap depth=7. |
| Property uden `property_owners`-record | Skip pantebrevet i tab'en (vi kan ikke koble til selskab). Logges som data-gap til Flare. |
| User uden adgang til CVR-watches (gamle Metis-plan) | "Følg ændringer på alle"-knap viser "Opgrader plan" CTA (gating-hook for Lender Intelligence). |
| **API timeout / registry-api 500/504/429** | Frontend viser `error-state.blade.php` med retry-knap + Flare correlation-ID. Polling stopper. |
| **Bruger filtrerer til 0 resultater** | "Ingen pantebreve matcher dine filtre" + "Nulstil filtre"-CTA — distinct fra "ingen pantebreve i koncernen". |
| **Bruger navigerer væk under streaming** | Livewire `wire:offline` + `unmount` hooks — server-side abort token. Polling-cap: 30s før graceful giveup med retry-CTA. |
| **`Følg`-knap klikkes 2x** | Server-side dedupe på `(user_id, watch_type, watch_value)`. UI viser "Du følger allerede Mimo-koncernen" + "Stop med at følge"-CTA hvis aktiv watchlist findes. |
| **Drawer-link åbnes efter logout** | Login-redirect med intended-URL session; efter login åbnes drawer hvis mortgage-id stadig accessible, ellers 403-toast. |
| **Symbolic 1-DKK familietransaktion** | Filtreret ud i `PropertyValueResolver` (`price >= 100_000` ører + ekskluderer `familieoverdragelse`/`gaveskøde`/`arvskifte` types). LTV falder til Boliga eller off. vurd. |
| **Boligsiden indeks NULL/0** | Guard i `buildLtvInfo`. Hvis indeks 0/null: `property_value_indexed_2026 = null`, base-værdi bevares, ingen exception. |
| **Eksport med aktive filtre** | UI viser "Eksportér 18 filtrerede / 24 i alt"-toggle inden download — bruger vælger eksplicit. |

---

## Testing Strategy

### Backend (registry-api)

**Unit tests** (`PropertyValueResolverTest`):
- 3-trins fallback chain for hver kombination (skøde / Boliga / vurd. / nothing)
- 7-år cutoff: skøde fra 8 år siden falder igennem til Boliga
- Indeksering: 2018-pris × Boligsiden-indeks giver forventet 2026-pris
- Symbolic 1-DKK skøde filtreret ud
- familieoverdragelse-type filtreret ud
- Boligsiden NULL/0 guard: ingen exception, indexed_2026=null
- `resolveBatch()` med 200 properties bruger 3 queries (assertion på query count)
- Edge: ingen handler + ingen vurdering → returnerer null + method='unavailable'

**Feature tests** (`CompanyTinglysningOverviewTest`):
- Mimo-fixture (root + 7 datterselskaber + 18 ejendomme + 24 pantebreve) → forventet response shape
- Filter `status=active` → kun aktive
- Filter `status=inactive` → kun aflyste
- Filter `status=all` → alle
- Filter `mortgage_types=[ejerpantebrev]` → kun matchende
- Sort `principal_amount_desc` → høj-til-lav
- Cycle-fixture (A→B→A) → ingen infinite loop, max 1 forekomst per company i tier_breakdown
- Diamond-fixture (A→B,C; B,C→D) → property D vises med begge ancestor-paths i tree-index, men én row i tier_breakdown via DISTINCT
- Multi-parent CVR (50/50) → property vises med begge roots i tree-index
- Mega-koncern-fixture (200 datterselskaber) → `tree_depth=1` returnerer kun depth ≤1
- Sampant-fixture → DISTINCT mortgage_id i total via `computeTotals()`
- Sampant-fixture med filter → totals matcher filtrerede rows på tværs af UI/XLSX

**Watchlist-resolver tests** (`DetectMortgageChangeAncestorTest`):
- Eksisterende direkte-CVR-match path: uændret opførsel
- Direkte-property-match path: uændret opførsel
- Ny ancestor-CVR-match: pantebrev på sub-datterselskabs ejendom matcher CVR-watch på root
- Backfill-marker: pre-deploy watchlist (`expanded_to_ancestors_at = deploy_time`) får IKKE retroactive ancestor-match for events før deploy_time
- Dedup: bruger med både direkte- + ancestor-watch på samme træ → ÉN alert (eldest-watchlist-wins)
- Forwards-only filter (`created_at < dispatchedAt`) bevares for alle 3 paths

**Tree-index maintenance tests** (`CompanyPropertyTreeIndexMaintenanceTest`):
- `PropertyOwnerObserver`: ny ownership-row trigger tree-index insert
- `PropertyOwnerObserver`: deleted ownership-row trigger tree-index delete
- `CompanyRoleObserver`: ny parent-relation trigger ancestor-paths insert
- `CompanyRoleObserver`: removed parent-relation trigger ancestor-paths cleanup
- Reconciliation-command: bygger shadow-tabel + sammenligner med live + alerter ved diff > 0,1%
- Reconciliation-command: ingen swap hvis diff for stor (manuel review)

### Frontend (metis-package)

**Livewire tests** (`CompanyTinglysningTest`):
- Initial mount henter `treeMeta` + `tierBreakdown` synkront
- `pollForUpdates` opdaterer `mortgages` progressivt med delta-cursor
- `pollForUpdates` stopper når `streamingComplete=true`
- `followKoncern()` POST'er til `/watchlists` med `watch_type=company` + valgt CVR
- Allerede-følger detection: viser unfollow-CTA i stedet for follow
- Filter-changes triggerer ny fetch + reset cursor
- Sort-changes ikke-flickering
- Drawer URL-binding: `$set('openMortgageId', 123)` opdaterer `?ting_mortgage=123`
- Drawer-navigation: `navigateDrawer('next')` skifter til næste mortgage i samme liste
- Error-state: simuleret 504 viser error-state-partial med retry-knap
- 0-results filter: "Ingen pantebreve matcher" vises (distinct fra empty-koncern)

### Manual QA

- **Browser-test** via existing `tools/browser-test.mjs`:
  - Login demo, navigér til CVR med portfolio (Mimo eller Inova)
  - Klik Tinglysning-tab — verificér streaming UX (skeletons → fyldt) + delta-loading
  - Filtrer status=active/inactive/all
  - Klik en row → flyout åbner + URL ændres til `?ting_mortgage=`
  - Pil-op/pil-ned i flyout (keyboard arrow-keys, ikke kun klik)
  - ESC lukker flyout + clearer URL
  - Eksportér XLSX, åbn i Excel, verificér totals matches UI
  - Test allerede-følger detection (klik "Følg" 2x)
- **iPad QA**: Safari iPad, drawer fungerer som flyout
- **Performance**: Telescope/Flare instrumentering måler p95 load-tid for første 1-2 ugers brug

---

## Implementation Sprint Breakdown

**Sprint 1 — Foundation (11-13 dage estimated, median 12,0)**

| Dage | Komponent |
|------|-----------|
| 1,0 | `company_property_tree_index` migration + observer-based maintenance |
| 1,0 | `ReconcileCompanyPropertyTreeIndex` command + shadow-swap pattern |
| 1,5 | `BuildTinglysningOverview` Action + recursive CTE koncerntræ-query |
| 1,0 | `PropertyValueResolver::resolveBatch()` + 3-trins fallback + Boligsiden integration |
| 0,5 | Boligsiden indeks Redis-cache warm-up (24h TTL) |
| 1,0 | API endpoint + delta-streaming + JsonResource |
| 1,0 | `DetectMortgageChange` ancestor-traversal + dedup + `expanded_to_ancestors_at` migration + tests |
| 1,5 | `CompanyTinglysning` Livewire section + tabel-UI |
| 1,0 | Streaming/skeleton-loaders + sort-stability + error-state UX |
| 1,0 | Flux flyout drawer + URL-state + a11y |
| 1,0 | Filter-bar + sort options |
| 1,0 | XLSX export med centraliseret `computeTotals()` |
| (parallel) | Test-skrivning (TDD per task) |

**Total: 12,5 dage**

**Out of Sprint 1 (deferred til Sprint 2):**
- PDF export (queued via Horizon, branding-spørgsmål afklares først)
- User override of property values (Tier 4 LTV)
- Mobile-optimering (`<768px`)
- Avancerede filtre (prioritet, kreditor, dato-range)

---

## Out of Scope (Documented for Future)

1. **Lender Intelligence / "Frankston Risk"-produkt** (Vej B / `frankston-lender.dk`)
   - F-NEW + F1 + portfolio-import + concentration-analytics + audit-trail
   - 6-12 måneder build-out
   - Separate brainstorm + spec efter F-NEW shipped (~2026-05-22)
   - Pilot-kunder: Draupnir Invest (Rasmus + Ulrik), Nordic Bloom mfl.
   - Memory: `~/.claude/projects/-Users-Frederik/memory/project_lender_intelligence.md`

2. **PDF eksport** (Sprint 2) — kræver brand-beslutning (Metis vs Frankston-only) + queued generation via Horizon

3. **User-rating overlay (LTV Tier 4)** — fundament for Lender Intelligence's portfolio-import

4. **CSV-eksport** — droppet til favør af XLSX; vil indgå i Lender Intelligence's API-tier

5. **Risk-portfolio-import** — stort produktspor, tilhører Lender Intelligence

6. **Visuel prioritets-stak** som standalone Lag 3 (droppet under Q8 — vil findes i flyout alligevel)

---

## Risks

| Risk | Severity | Mitigation |
|------|----------|-----------|
| Observer-baseret tree-index maintenance har bug → silent index-staleness | HIGH | Nightly reconciliation-command bygger shadow-tabel + diff'er mod live. Diff > 0,1% alerter Frederik via Flare. Swap kun ved diff < 0,1%. |
| Ancestor-traversal i resolver giver alert-storm til CVR-watchere | HIGH | `expanded_to_ancestors_at` backfill-marker forhindrer retroactive storm. Eksplicit user-level dedup på `(user_id, mortgage_id, change_kind)`. Telemetry måler watchlist→alert ratio før prod-kritisk brug. Hvis storm: feature-flag rollback. |
| Streaming UX feels broken if numbers pop in jarringly | MEDIUM | Skeleton-loaders + fade-in transitions. Sort lockes indtil load complete. Visual review i Sprint 1 review. |
| Mega-koncern (Mærsk-skala) tager > 5s | MEDIUM | `tree_depth=1` default + max-depth=7. Streaming + delta-payloads. Cache::remember på tree_meta (60s TTL). Telemetry catches outliers. |
| Boligsiden indeks ikke tilgængelig | LOW | Fallback til "vis kun rå pris + dato" hvis indeks-API fejler. NULL/0-guard. Logges til Flare. |
| Sampant inconsistent totals (UI vs eksport) | LOW | Single `computeTotals()` source kaldes fra UI + XLSX (+ Sprint 2 PDF). Test-fixture med sampant + filter dækker. |
| Cycle / diamond i koncerntræ | LOW | Visited-set i `CompanyPropertyTreeBuilder` recursive CTE. Test covers cykel + diamond. Tree-index UNIQUE på `(root_cvr, descendant_company_id, property_id)` tillader multi-path uden konflikt. |
| Polling overload på registry-api ved 100+ samtidige brugere | MEDIUM | 2000ms interval + stop ved complete + Cache::remember tree_meta 60s. Hvis stadig overload: switch til server-sent events (Sprint 2). |

---

## Open Questions for Engineer

(Flagget under implementation hvis opdaget)

1. **Boligsiden-indeks API/feed** — `boligsiden.dk/api/prisindeks` eller Danmarks Statistik? Verify med Frederik før integration.
2. **`is_sampant` detection** — bruges `tinglysning_data->>'Sampant'` JSON felt eller separat `mortgage_property_links` tabel? Tjek crawler-konvention.
3. **Reconciliation-diff threshold** — 0,1% sat arbitrarily; kalibrér efter første reconciliation-runs.

---

## References

- **Triggering meeting:** Draupnir Invest 2026-04-29 — Rasmus Hornhaver demoed Resight
- **Triggering bug:** Rasmus' email 2026-05-02 — F1 fired alert for 2007-pantebrev (fixed in PR #31, #32)
- **Parent spec:** `docs/superpowers/specs/2026-05-01-metis-resight-parity-design.md` (v1.2) F-NEW section
- **Multi-agent review** (2026-05-02): 6 reviewers (spec-flow, architecture, data-integrity, simplicity, performance, Laravel-conventions). 5 kritiske + 11 vigtige fix'es applied i v1.1.
- **Lender Intelligence**: `~/.claude/projects/-Users-Frederik/memory/project_lender_intelligence.md`
- **Compound:** `~/.claude/projects/-Users-Frederik/memory/compound_silent_failures_recovery_registry_api_2026_05_02.md`
- **Related architecture:** `app/Services/Tinglysning/TinglysningSync.php`, `app/Observers/MortgageObserver.php`, `app/Pipelines/DeepEnrichment*` (registry-api), `src/Livewire/Sections/CompanyProperties.php` (metis-package)
