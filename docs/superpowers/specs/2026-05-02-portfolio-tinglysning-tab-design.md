# Portfolio-Tinglysning-tab Implementation Design

**Status:** Draft v1.0 (2026-05-02)
**Trigger:** Rasmus Hornhaver demo at Draupnir Invest 2026-04-29 (Resight competitive analysis) + Rasmus' bug-feedback 2026-05-02
**Owner:** Frederik
**Estimat:** 10-13 dage Sprint 1 (median 12,0)

---

## Goal

Build a Tinglysning-tab on the company page that shows **all pantebreve across a holding's entire koncerntræ** in one flat-list with portfolio-level metrics (samlet hovedstol, total LTV, antal pantebreve), filterable, with batch-watchlist support and Linear-style drawer drilldown.

This is the killer view from spec v1.2's F-NEW. It's the feature that makes Resight customers reconsider switching, and it lays the foundation for the broader Lender Intelligence product line (Vej B / `frankston-lender.dk`).

## Architecture (one-paragraph)

Backend: registry-api gets a new `/api/v1/companies/{cvr}/tinglysning-overview` endpoint that does recursive koncerntræ traversal (via existing `deep_enrichment_pipeline`), joins property_owners → properties → mortgages → property_transactions/property_sales/valuations, and returns a streaming-friendly payload. F1's eksisterende `watchlists.watch_type='company'`-mekanisme genbruges — INGEN F1 schema-ændring; `DetectMortgageChange::resolveWatchlists()` udvides til ancestor-traversal via et nyt materialized tree-index. Frontend: metis-package gets a new `CompanyTinglysning` Livewire section that uses `pollForUpdates` pattern to stream rendering, plus a reusable `MortgageDetailDrawer` component with URL-bound state for shareable links. LTV is computed server-side using 4-trins fallback chain. Mega-koncerner are protected by max-depth=7 + cycle-detection.

## Tech Stack

- **Backend**: Laravel 12, PHP 8.4, PostgreSQL 16 (registry-api repo)
- **Frontend**: Livewire 3, Tailwind, Flux UI (metis-package repo)
- **Eksport**: `phpoffice/phpspreadsheet` (XLSX), `barryvdh/laravel-dompdf` (PDF)
- **Streaming UX**: Livewire `wire:poll.500ms` pattern (matches existing `CompanyProperties`)
- **Drawer**: Livewire 3 `#[Url]` attribute for query-string state binding

---

## File Structure

### registry-api repo

**Modify:**
- `app/Http/Controllers/Api/V1/CompanyController.php` — add `tinglysningOverview()` method
- `app/Services/Tinglysning/TinglysningSync.php` — already populates `registration_date` (PR #31)
- `app/Jobs/DetectMortgageChange.php` — `resolveWatchlists()` udvides med rekursiv ancestor-traversal via materialized tree-index (NO watchlists schema-change)
- `routes/api.php` — register new endpoint

**Create:**
- `database/migrations/2026_05_02_*_create_company_property_tree_index.php` — materialized index
- `app/Services/CompanyPortfolio/TinglysningOverviewService.php` — main orchestrator
- `app/Services/CompanyPortfolio/PropertyValueResolver.php` — 4-trins LTV fallback
- `app/Services/CompanyPortfolio/PriceIndexer.php` — historical sale → 2026-pris (Boligsiden indeks)
- `app/Console/Commands/RebuildCompanyPropertyTreeIndex.php` — nightly materialization
- `tests/Feature/Api/V1/CompanyTinglysningOverviewTest.php`
- `tests/Unit/Services/PropertyValueResolverTest.php`

### metis-package repo

**Modify:**
- `src/Livewire/Metis.php` — register new `CompanyTinglysning` section
- `src/Services/RegistryApi.php` — add `fetchCompanyTinglysningOverview($cvr, $options)`
- `resources/views/livewire/sections/` — add tab to navigation
- `routes/web.php` — drawer URL pattern

**Create:**
- `src/Livewire/Sections/CompanyTinglysning.php` — main tab component
- `src/Livewire/Components/MortgageDetailDrawer.php` — reusable drawer
- `src/Livewire/Components/PortfolioExportButton.php` — XLSX + PDF
- `resources/views/livewire/sections/company-tinglysning.blade.php`
- `resources/views/livewire/components/mortgage-detail-drawer.blade.php`
- `resources/views/livewire/components/skeleton-row.blade.php` — streaming placeholder
- `src/Exports/PortfolioTinglysningExport.php` — XLSX builder
- `src/Exports/PortfolioTinglysningPdf.php` — PDF builder
- `resources/views/exports/tinglysning-pdf.blade.php` — PDF template
- `tests/Feature/Livewire/Sections/CompanyTinglysningTest.php`
- `tests/Feature/Livewire/Components/MortgageDetailDrawerTest.php`

---

## Data Model Changes

### 1. F1 watchlists — INGEN schema-ændring

F1's eksisterende skema understøtter allerede CVR-watches via `watch_type='company'` + `watch_value=<cvr>` (verificeret i `database/migrations/2026_03_01_400002_create_watchlists_table.php`). `DetectMortgageChange::resolveWatchlists()` matcher i dag på direkte CVR-ejere af ejendommen.

**Ændring**: `resolveWatchlists()` udvides til at hente **alle ancestor-CVR'er** i koncerntræet via det nye `company_property_tree_index` (se nedenfor), så et CVR-watch på Mimo Invest fanger pantebrev-ændringer på alle ejendomme i hele træet — ikke kun direkte-ejede.

```php
// Nyt resolver-snippet (illustrativt):
$ancestorCvrs = DB::table('company_property_tree_index')
    ->where('property_id', $mortgage->property_id)
    ->pluck('root_cvr')
    ->unique();

$companyMatches = Watchlist::where('is_active', true)
    ->where('watch_type', 'company')
    ->whereIn('watch_value', $ancestorCvrs)
    ->where('created_at', '<', $dispatchedAt)
    ->get();
```

Eksisterende F1-funktionalitet uændret — tilføjelsen er rent additiv: hvor vi før kun fangede direkte-ejende CVR'er, fanger vi nu også ancestor-CVR'er. Brugere med direkte-ejer-watches bemærker ingen forskel.

### 2. Company-property tree index (materialized)

```php
// Migration: create_company_property_tree_index.php
Schema::create('company_property_tree_index', function (Blueprint $table) {
    $table->id();
    $table->string('root_cvr', 8)->index();
    $table->foreignId('descendant_company_id')->constrained('companies');
    $table->foreignId('property_id')->constrained('properties');
    $table->integer('depth'); // 0 = direkte ejet, 1 = via 1 datterselskab, etc.
    $table->timestamps();

    $table->unique(['root_cvr', 'property_id']);
    $table->index(['property_id', 'root_cvr']); // reverse-lookup for DetectMortgageChange
});
```

Rebuilt nightly by `RebuildCompanyPropertyTreeIndex` command. For 50K active companies × ~12 properties each = ~600K rows, indexed for sub-ms lookups in both directions.

---

## API Contract

### `GET /api/v1/companies/{cvr}/tinglysning-overview`

**Query params:**
- `include_inactive=false` (default) — Q5 toggle
- `mortgage_types[]=privatpantebrev,realkreditpantebrev,...` — Q8 filter
- `min_amount=0&max_amount=999999999999` — Q8 range
- `sort=principal_amount_desc` (default) | `tinglysning_date_desc` | `ltv_desc` | `address_asc`
- `expand_tree=root_plus_one` (default) | `full` — Q8 mega-koncern handling

**Response:**

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
    "expand_state": "root_plus_one"
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
    },
    { "company_id": 12346, "name": "Mimo Hotel ApS", "depth": 1, ... }
  ],
  "mortgages": [
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
        "method": "tinglysning_skoede",
        "property_value_raw": 6200000000,
        "property_value_indexed_2026": 7100000000,
        "source_date": "2022-04-12",
        "fallback_chain_used": ["tinglysning_skoede"]
      }
    }
  ]
}
```

Streaming-friendly: `tier_breakdown` returns instantly, `mortgages` array can be partial with `_streaming: { complete: false, eta_ms: 800 }`. Frontend polls until `complete: true`.

### `POST /api/v1/watchlists`

Used af "Følg ændringer på alle 18"-knappen. Matcher F1's eksisterende endpoint (ikke nyt API):

**Request:**
```json
{
  "watch_type": "company",
  "watch_value": "28963610",
  "display_label": "Mimo Invest-koncernen",
  "alert_types": ["mortgage_new", "mortgage_amount_changed", "mortgage_paid_off"]
}
```

**Response:** `{ "watchlist_id": 555 }`

Når denne watchlist evalueres af `DetectMortgageChange`, traverser resolveren `company_property_tree_index` for at fange pantebrev-ændringer på hele koncerntræet — ikke kun direkte-ejede ejendomme.

For per-BFE watches bruges samme endpoint med `watch_type='property'` + `watch_value=<matrikel_id>` (eksisterende F1-pattern uændret).

### `POST /api/v1/companies/{cvr}/tinglysning-overview/export`

**Request:** `{ "format": "xlsx" | "pdf", "filters": {...} }`
**Response:** Binary file stream with appropriate Content-Type.

---

## Frontend Components

### `CompanyTinglysning` (main tab)

```php
namespace TheFountainhead\Metis\Livewire\Sections;

class CompanyTinglysning extends MetisSection
{
    public ?array $treeMeta = null;
    public ?array $tierBreakdown = null;
    public array $mortgages = [];
    public bool $streamingComplete = false;

    // Filters (Q5, Q8)
    public bool $includeInactive = false;
    public array $mortgageTypeFilter = [];
    public ?int $minAmount = null;
    public ?int $maxAmount = null;
    public string $sortBy = 'principal_amount_desc';
    public string $expandState = 'root_plus_one';

    // Drawer state (Q7) — URL-bound
    #[Url(as: 'mortgage')]
    public ?int $openMortgageId = null;

    public function mount(string $query): void { /* initial fetch tree_meta + tier */ }
    public function pollForUpdates(): void { /* streaming partial fills */ }
    public function followKoncern(): void { /* POST /watchlists with watch_type=company */ }
    public function exportXlsx() { /* download */ }
    public function exportPdf() { /* download */ }
}
```

### `MortgageDetailDrawer` (reusable drawer)

Triggered by `wire:click="$set('openMortgageId', {{ id }})"` from any row in `CompanyTinglysning`. URL automatically updates to `?mortgage=12345` via `#[Url]` attribute. Clicking outside or pressing Escape sets to `null`, which closes drawer + clears URL.

Drawer content:
1. Mortgage grunddata (creditor, debitor, hovedstol, rente, prioritet, tinglysningsdato)
2. Prioritets-stak på ejendommen — visual progress bar showing current pant-belastning vs ejendomsværdi
3. Change-history — F1 events siden tracking startede (lazy-loaded on drawer open)
4. Link til ejendoms-detail-side (existing Metis property page)
5. PDF-link til pantebrev (hvis crawler har det)
6. Navigation: pil-op/pil-ned mellem mortgages i samme tab without closing drawer
7. "Kopiér link"-knap der kopierer den nuværende URL inkl. `?mortgage=12345`

---

## LTV Fallback Chain (Q2)

`PropertyValueResolver` resolves property value in this order, with `method` field tracking which step was used:

1. **`tinglysning_skoede`** — `property_transactions.price` where `transaction_date >= now() - 7 years` and price IS NOT NULL. Uses most recent.
2. **`boliga_sold`** — `property_sales.sale_price` where `sale_date >= now() - 7 years`. Uses most recent.
3. **`offentlig_vurdering`** — `valuations.value` (most recent VUR). Tagged with disclaimer "off. vurd. — typisk 30-40% under markedspris for kommercielle".
4. **`user_override`** — Per-user override stored on `user_property_value_overrides` (Sprint 1 stretch goal — defer to Sprint 2 if scope tight).

For sources 1+2, `PriceIndexer` computes `indexed_2026` value using Boligsiden's price index (postnummer + ejendomstype). Both `value_raw` + `value_indexed_2026` shown to user.

If all fail (no transaction, no Boliga, no valuation): `ltv: null` with `method: "unavailable"`. UI shows "—" for LTV with tooltip explaining missing data.

---

## Edge Cases (Q8)

| Case | Handling |
|------|----------|
| Selskab uden ejendomme (direkte) | Tab vises med "Selskabet ejer ikke ejendomme direkte. Tjek datterselskaber via Selskabsstruktur-tab." Link til Selskabsstruktur. |
| Selskab uden pantebreve men med ejendomme | Vis ejendomsliste + samlet vurdering. Tom mortgages-array, banner "Ingen tinglyste pantebreve". |
| Sampant (én pantebrev → flere ejendomme i koncernen) | Vis én række pr. (mortgage_id, property_id) med `is_sampant: true` badge. Total `principal_amount` summeret med `DISTINCT mortgage_id` for ikke at dobbelt-tælle. |
| Koncern-cykler (A → B → A) | BFS i `RebuildCompanyPropertyTreeIndex` bruger visited-set. Dokumenteret i kommentar med eksempel. |
| Mega-koncerner (≥200 datterselskaber) | `expand_state=root_plus_one` default. CTA "Vis hele træet" loader resten. Hard cap depth=7. |
| Property uden `property_owners`-record | Skip pantebrevet i tinglysning-tab'en (vi kan ikke koble den til selskabet). Logges som data-gap til Flare. |
| User uden adgang til CVR-watches (gamle Metis-plan) | Knappen "Følg ændringer på alle"-knap viser "Opgrader plan" CTA (gating-hook for Lender Intelligence). |

---

## Testing Strategy

### Backend (registry-api)

**Unit tests** (`PropertyValueResolverTest`):
- 4-trins fallback chain for hver kombination (skøde / Boliga / vurd. / nothing)
- 7-år cutoff: skøde fra 8 år siden falder igennem til Boliga
- Indeksering: 2018-pris × Boligsiden-indeks giver forventet 2026-pris
- Edge: ingen handler + ingen vurdering → returnerer null + method='unavailable'

**Feature tests** (`CompanyTinglysningOverviewTest`):
- Mimo-fixture (root + 7 datterselskaber + 18 ejendomme + 24 pantebreve) → forventet response shape
- Filter `include_inactive=false` → kun aktive
- Filter `mortgage_types=[ejerpantebrev]` → kun matchende
- Sort `principal_amount_desc` → høj-til-lav
- Cycle-fixture (A→B→A) → ingen infinite loop, max 1 forekomst per company
- Mega-koncern-fixture (200 datterselskaber) → `expand_state=root_plus_one` returnerer kun depth ≤1
- Sampant-fixture → DISTINCT mortgage_id i total

**Watchlist-resolver tests** (`DetectMortgageChangeTest` udvidet):
- Eksisterende direkte-CVR-match path: uændret opførsel
- Ny ancestor-CVR-match: pantebrev på sub-datterselskabs ejendom matcher CVR-watch på root
- Ingen dobbelt-alert hvis user har både direkte- og ancestor-watch på samme træ
- Forwards-only filter (`created_at < dispatchedAt`) bevares for begge paths

**Index-build test** (`RebuildCompanyPropertyTreeIndexTest`):
- 100K-fixture rebuilds < 60s
- Cycle-detection
- Re-run idempotent

### Frontend (metis-package)

**Livewire tests** (`CompanyTinglysningTest`):
- Initial mount henter `treeMeta` + `tierBreakdown` synkront
- `pollForUpdates` opdaterer `mortgages` progressivt
- `followKoncern()` POST'er til `/watchlists` med `watch_type=company` + valgt CVR
- Filter-changes triggerer ny fetch
- Sort-changes ikke-flickering
- Drawer URL-binding: `$set('openMortgageId', 123)` opdaterer `?mortgage=123`

**Component tests** (`MortgageDetailDrawerTest`):
- Åbner ved `openMortgageId != null`, lukker ved `null`
- Navigation pil-op/pil-ned mellem mortgages
- Kopiér-link-knap kopierer fuld URL

### Manual QA

- **Browser-test** via existing `tools/browser-test.mjs`:
  - Login demo, navigér til CVR med portfolio (Mimo eller Inova)
  - Klik Tinglysning-tab — verificér streaming UX (skeletons → fyldt)
  - Filtrer aktiv/inaktiv toggle
  - Klik en row → drawer åbner + URL ændres
  - Pil-op/pil-ned navigation
  - Eksportér XLSX, åbn i Excel
  - Eksportér PDF
- **Mobile**: iPad i Safari på Frederik's enhed
- **Performance**: Telescope/Flare instrumentering måler p95 load-tid for første 1-2 ugers brug

---

## Implementation Sprint Breakdown

**Sprint 1 — Foundation (10-13 dage estimated, median 12,0)**

| Dage | Komponent |
|------|-----------|
| 1,5 | `company_property_tree_index` migration + nightly rebuild command |
| 1,5 | `TinglysningOverviewService` + recursive koncerntræ query |
| 1,0 | `PropertyValueResolver` + 4-trins fallback |
| 0,5 | `PriceIndexer` Boligsiden integration |
| 1,0 | API endpoint + streaming response |
| 0,5 | `DetectMortgageChange::resolveWatchlists()` ancestor-traversal udvidelse + tests |
| 1,5 | `CompanyTinglysning` Livewire section + tabel-UI |
| 1,0 | Streaming/skeleton-loaders + sort-stability + race-handling |
| 1,5 | `MortgageDetailDrawer` + URL-state binding |
| 1,0 | Filter-bar + sort options |
| 1,0 | XLSX export |
| 1,5 | PDF export + branding |
| (parallel) | Test-skrivning (TDD per task) |

**Total: 12,0 dage** (median af 10-13 range — let reduceret pga. ingen F1 schema-migration)

**Out of Sprint 1 (deferred):**
- User override of property values (LTV step 4) — Sprint 2 hvis efterspurgt
- Mobile-optimering (`<768px`) — Sprint 2
- Avancerede filtre (prioritet, kreditor, dato-range)
- Cache-laget (kun hvis p95 > 1500ms efter måling)

---

## Out of Scope (Documented for Future)

These came up in brainstorm and are tracked here for follow-up:

1. **Lender Intelligence / "Frankston Risk"-produkt** (Vej B / `frankston-lender.dk`)
   - F-NEW + F1 + portfolio-import + concentration-analytics + audit-trail
   - 6-12 måneder build-out
   - Separate brainstorm + spec efter F-NEW shipped
   - Pilot-kunder: Draupnir Invest (Rasmus + Ulrik), Nordic Bloom mfl.

2. **Visuel prioritets-stak** som standalone Lag 3 (droppet under Q8 — vil findes i drawer alligevel)

3. **CSV-eksport** — droppet til favør af XLSX + PDF; vil indgå i Lender Intelligence's API-tier

4. **Risk-portfolio-import** — stort produktspor, tilhører Lender Intelligence

---

## Risks

| Risk | Mitigation |
|------|-----------|
| Ancestor-traversal i `resolveWatchlists` giver alert-storm til CVR-watchere | Tests dækker både direkte- og ancestor-match-paths. Telemetry måler watchlist→alert ratio inden vi bruger det i prod-kritiske flows. Hvis storm ses, gating'es ancestor-traversal bag feature-flag. |
| Streaming UX feels broken if numbers pop in jarringly | Skeleton-loaders + fade-in transitions. Sort lockes indtil load complete. Visual review in Sprint 1 review. |
| Mega-koncern (Mærsk-skala) tager > 5s | `expand_state=root_plus_one` default + max-depth=7. Pre-built tree-index. Streaming. Telemetry catches outliers. |
| Boligsiden indeks ikke tilgængelig | Fallback til "vis kun rå pris + dato" hvis indeks-API fejler. Logges til Flare. |
| Sampant dobbelt-tælling | DISTINCT mortgage_id i totals, badge i UI. Test-fixture covers dette. |
| Cycle i koncerntræ → infinite BFS | Visited-set i `RebuildCompanyPropertyTreeIndex`. Test covers cycle-fixture. |

---

## Open Questions for Engineer

(None blocking — but flag during implementation if discovered)

- Which Boligsiden-indeks API/feed should we use? `boligsiden.dk/api/prisindeks` or Danmarks Statistik? Verify with Frederik before integration.
- For `is_sampant` detection, do we use `tinglysning_data->>'Sampant'` JSON field or a separate `mortgage_property_links` table? Check existing crawler conventions.
- PDF branding: Is Metis-logo + Frankston-footer the right combo, or skal det være Frankston-only for Lender Intelligence-fremtid?

---

## References

- **Triggering meeting:** Draupnir Invest 2026-04-29 — Rasmus Hornhaver demoed Resight
- **Triggering bug:** Rasmus' email 2026-05-02 — F1 fired alert for 2007-pantebrev (fixed in PR #31, #32)
- **Parent spec:** `docs/superpowers/specs/2026-05-01-metis-resight-parity-design.md` (v1.2) F-NEW section
- **Compound:** `~/.claude/projects/-Users-Frederik/memory/compound_silent_failures_recovery_registry_api_2026_05_02.md`
- **Related architecture:** `app/Services/Tinglysning/TinglysningSync.php`, `app/Observers/MortgageObserver.php`, `app/Pipelines/DeepEnrichment*` (registry-api)
