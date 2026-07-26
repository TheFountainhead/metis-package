# Ejer-relations-graf 2a.2 (berigelse) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Grafen beriges til "bedre end Resights": hover-/tap-kort pr. node (billede + nøgletal + links), værdi-aggregat på selskabs-noder og signal-ikoner — oven på 2a.1's deklarative builder uden at ændre dens API (enrichment-parameteren er reserveret).

**Architecture:** Berigelses-data hentes async EFTER ejendoms-trinnet (pooled `fetchCompanyInfo` + fuld `properties/batch`-map) og flettes via builder-rebuild som et cvr/bfe-nøglet `enrichmentData`-input. Kortet er ét singleton-element uden for canvas (host-JS positionerer m. transform+clamp). Signaler beregnes i builderen (ren funktion, `now` som parameter).

**Tech Stack:** Laravel/Livewire 3 (metis-package), Alpine + dagre (metis host-app), Pest.

**Spec:** `docs/superpowers/specs/2026-07-25-ownership-graph-phase2-design.md` (v3 + Task 12-afgørelsen 26/7)

## Global Constraints

- **TO repos:** metis-package (`feat/ownership-graph-2a2`) + host-app (`/Users/Frederik/Herd/metis`, ny branch `feat/ownership-graph-2a2-js` fra origin/main). Koordineret deploy (package → lock-bump → host); rollback = revert alle tre. registry-api ændres IKKE.
- **Builder-API'et fra 2a.1 må IKKE brydes:** `build(query, companyName, structure, properties, enrichment, expandedNodeIds, caps)` — 2a.2 UDFYLDER `enrichment` og tilføjer KUN en `now`-parameter med default (`?CarbonImmutable $now = null` sidst i signaturen), så alle eksisterende kald og tests forbliver gyldige.
- **null ≠ tom overalt:** fejlet berigelse må aldrig ligne "ingen data"; manglende regnskabsdata er en EKSPLICIT tredje tilstand ("ingen regnskabsdata" i kortet) — aldrig et fraværende ikon alene.
- **Alpine-lærdomme fra 2a.1 (bindende):** alle `x-text`-udtryk null-sikre (`?.` + `?? fallback`); `<template x-for>` ALDRIG i `<svg>`; status-elementer med timere får `wire:key`; per-node-affordances gates på felt-specifikke flags.
- Payload-hygiejne: signaler sendes som string-array (`signals: ['negative_equity']`), tal som tal, null-felter udelades af node-objekter.
- Selskabs-berigelse: ALDRIG serielle HTTP-kald, ALDRIG før first paint — `Http::pool` (samtidighed 6) + eksisterende 24 t-cache pr. CVR.
- `view:clear` efter Blade-ændringer på prod; browser-verifikation på ÆGTE side m. konsol åben.

## Verificerede prod-fakta (26/7 — brugt af planen)

- `GET /v1/cvr/company/{cvr}` → `data.company.{name, financials: list (period_start/period_end/currency/…equity/result-felter — spejl `CompanyOverview.php`'s eksisterende felt-læsninger), financial_ratios, employees (KAN være null), founded_date ("2017-05-18" ✓), industry (KAN være null), contact (website-feltet læses som eksisterende `CompanyInfo`/`CompanyOverview` gør), …}`.
- `POST /v1/properties/batch` → pr. ejendom: `latest_transaction` ✓ (transaction_date, price…), `valuation` ✓, `bbr.buildings[]` ✓ — men **`street_view_url` er null i praksis** (Google-nøglen ER sat i registry-api, men `Property->location`-relationen er tom i batch-stien; lat/lng er null her). **Portfolio-payloaden HAR derimod lat/lng pr. ejendom** (verificeret 25/7).
- **Konsekvens (beslutning):** metis bygger selv streetview-URL'en: `https://maps.googleapis.com/maps/api/streetview?size=600x400&location={lat},{lng}&key={config('metis.google_maps_api_key')}` NÅR portfolio-lat/lng findes OG nøglen er sat i metis-env (`GOOGLE_MAPS_API_KEY` — Frederik godkender/genbruger registry-api's nøgle ved deploy; er den ikke sat, udelades billedet og kortet viser kun "Se skråfoto ↗"-linket). Registry-api røres ikke.
- Task 12 afgjort: kant-labels forbliver eksakte — INGEN interval-arbejde i 2a.2.

## File Structure

**metis-package:**
- Modify: `src/Services/OwnershipGraphBuilder.php` — enrichment-lag, aggregat, signaler
- Modify: `src/Services/RegistryApi.php` — `fetchCompanyInfosPooled()`; cache-nøgle-rename (todo 003)
- Create: `src/Services/Utm32Projection.php` (udtræk fra `AddressSkraafoto`)
- Modify: `src/Livewire/Sections/CompanyStructure.php` — berigelses-trin + fuld batch-map (afløser usage-map)
- Modify: `resources/views/livewire/sections/partials/graph-node.blade.php` — aggregat-række + signal-ikoner
- Modify: `resources/views/livewire/sections/partials/ownership-graph.blade.php` — singleton-kort-markup + CSS
- Modify: `src/Livewire/Sections/AddressSkraafoto.php` — delegér til Utm32Projection
- Modify: `config/metis.php` — `google_maps_api_key` => env('GOOGLE_MAPS_API_KEY')
- Tests: `tests/Unit/OwnershipGraphBuilderTest.php`, `tests/Unit/Utm32ProjectionTest.php`, `tests/Unit/RegistryApiTest.php`, `tests/Feature/Livewire/Sections/CompanyStructureTest.php`

**metis host-app:**
- Modify: `resources/js/ownership-graph.js` — kort-state/positionering, hover-intent, tap-model, node-klik-navigation, touch-pan, dims

---

### Task 1: Builder — enrichment-lag, værdi-aggregat og signaler

**Files:** Modify `src/Services/OwnershipGraphBuilder.php`; Test `tests/Unit/OwnershipGraphBuilderTest.php`

**Interfaces:**
- Produces: `build(..., array $enrichment, array $expandedNodeIds, array $caps, ?\Carbon\CarbonImmutable $now = null)`.
  - `$enrichment = ['companies' => [cvr => ['equity'=>?float,'result'=>?float,'fiscal_year'=>?string,'employees'=>?int,'website'=>?string,'founded_date'=>?string,'industry'=>?string]], 'properties' => [matrikelId => ['usage'=>?string,'latest_sale_date'=>?string,'latest_sale_price'=>?int,'valuation'=>?int,'streetview_url'=>?string]]]` (usage-mappen fra 2a.1 flytter herind).
  - Selskabs-/searched-noder får (kun når data findes): `agg: ['count'=>int,'value'=>int,'valued'=>int]` (afledt af `$properties['list']` grupperet på owner — UAFHÆNGIGT af enrichment), `signals: list<string>` (∈ `negative_equity`, `newly_founded`, `no_financials`), `card: {...}` (hover-kort-felterne, null-felter udeladt).
  - Ejendoms-noder får `card: {usage, latest_sale_date, latest_sale_price, valuation, streetview_url, lat, lng}` (null-felter udeladt; lat/lng fra portfolio-rækken til skråfoto-linket).
- Signal-regler (i builderen, testbare): `negative_equity` = seneste financials-equity < 0; `newly_founded` = founded_date > now−12 mdr.; `no_financials` = selskab i enrichment-map MEN uden financials. Selskaber HELT uden enrichment-entry (ikke hentet endnu) får INGEN signals-nøgle (fravær ≠ sundt håndteres i kortet: "berigelse ikke hentet").

- [ ] **Step 1: Failing tests** (følg filens `buildGraph()`-hjælper; udvid den med `enrichment`- og `now`-args):

```php
it('derives value aggregate per owner from the property list with coverage', function () {
    $subs = [['cvr' => '44507781', 'name' => 'K', 'ownership_share' => 50.0, 'children' => []]];
    $props = [
        fdlProperty(['matrikel_id' => '1000001', 'valuation' => 500000]),
        fdlProperty(['matrikel_id' => '1000002', 'valuation' => null]),
    ];
    $g = buildGraph(['structure' => ['ancestors' => [], 'subsidiaries' => $subs], 'properties' => ['list' => $props, 'usage' => []]]);

    expect(collect($g['nodes'])->firstWhere('id', '44507781')['agg'])
        ->toMatchArray(['count' => 2, 'value' => 500000, 'valued' => 1]);
});

it('computes signals in the builder with a deterministic now', function () {
    $enrichment = ['companies' => ['44507781' => ['equity' => -12000.0, 'fiscal_year' => '2025', 'founded_date' => '2026-03-01']], 'properties' => []];
    $g = buildGraph([
        'structure' => ['ancestors' => [], 'subsidiaries' => [['cvr' => '44507781', 'name' => 'K', 'ownership_share' => 50.0, 'children' => []]]],
        'enrichment' => $enrichment,
        'now' => \Carbon\CarbonImmutable::parse('2026-07-26'),
    ]);

    expect(collect($g['nodes'])->firstWhere('id', '44507781')['signals'])
        ->toContain('negative_equity')->toContain('newly_founded');
});

it('marks enriched companies without financials explicitly and leaves unenriched nodes without signals key', function () {
    $enrichment = ['companies' => ['44507781' => ['founded_date' => '2010-01-01']], 'properties' => []];
    $g = buildGraph([
        'structure' => ['ancestors' => [], 'subsidiaries' => [
            ['cvr' => '44507781', 'name' => 'K', 'ownership_share' => 50.0, 'children' => []],
            ['cvr' => '45170209', 'name' => 'I', 'ownership_share' => 100.0, 'children' => []],
        ]],
        'enrichment' => $enrichment,
        'now' => \Carbon\CarbonImmutable::parse('2026-07-26'),
    ]);

    expect(collect($g['nodes'])->firstWhere('id', '44507781')['signals'])->toContain('no_financials')
        ->and(collect($g['nodes'])->firstWhere('id', '45170209'))->not->toHaveKey('signals');
});

it('attaches property card data from the enrichment property map', function () {
    $subs = [['cvr' => '44507781', 'name' => 'K', 'ownership_share' => 50.0, 'children' => []]];
    $g = buildGraph([
        'structure' => ['ancestors' => [], 'subsidiaries' => $subs],
        'properties' => ['list' => [fdlProperty(['latitude' => 55.25, 'longitude' => 12.17])], 'usage' => []],
        'enrichment' => ['companies' => [], 'properties' => ['2573669' => ['usage' => 'Fritliggende enfamiliehus', 'latest_sale_price' => 1200000, 'streetview_url' => 'https://example/sv']]],
    ]);

    $card = collect($g['nodes'])->firstWhere('id', 'bfe:2573669')['card'];
    expect($card)->toMatchArray(['usage' => 'Fritliggende enfamiliehus', 'latest_sale_price' => 1200000, 'streetview_url' => 'https://example/sv', 'lat' => 55.25, 'lng' => 12.17])
        ->and($card)->not->toHaveKey('valuation');   // null-felter udeladt
});

it('remains deterministic and idempotent with enrichment input', function () {
    $args = ['structure' => ['ancestors' => [], 'subsidiaries' => [['cvr' => '44507781', 'name' => 'K', 'ownership_share' => 50.0, 'children' => []]]],
        'enrichment' => ['companies' => ['44507781' => ['equity' => 5.0]], 'properties' => []],
        'now' => \Carbon\CarbonImmutable::parse('2026-07-26')];
    expect(buildGraph($args))->toEqual(buildGraph($args));
});
```

Bagudkompatibilitet: eksisterende `usage`-map-tests SKAL forblive grønne — builderen læser usage fra `enrichment['properties'][mid]['usage']` MED fallback til `properties['usage'][mid]` (2a.1-formen), så Task 3 kan migrere komponenten uden big-bang.

- [ ] **Step 2: Kør — FAIL.**
- [ ] **Step 3: Implementér** — ny `applyEnrichment(array &$nodes, array $enrichment, array $propertyList, ?CarbonImmutable $now)`-fase SIDST i `build()` (efter truncateToCap, så kort/aggregat aldrig beregnes for bortskårne noder): (a) aggregat: gruppér `$propertyList` på owner-node-id (genbrug `ownedTargetId`-normaliseringen fra addProperties), sæt `agg` på ejer-noder med ≥1 ejendom; (b) selskabs-`card`+`signals` fra `enrichment['companies']`; (c) ejendoms-`card` fra `enrichment['properties']` + lat/lng fra property-rækken. Null-felter filtreres med `array_filter(..., fn($v) => $v !== null)`.
- [ ] **Step 4: Hele suiten grøn (`vendor/bin/pest`). Step 5: Commit** — `git commit -am "feat(graph): enrichment-lag i builderen — aggregat, signaler, kort-data"`

---

### Task 2: RegistryApi — pooled company-info + cache-nøgle-rename

**Files:** Modify `src/Services/RegistryApi.php`; Test `tests/Unit/RegistryApiTest.php`

**Interfaces:**
- Produces: `fetchCompanyInfosPooled(array $cvrs): array` — map `cvr => company-array|null`. Implementering: (1) slå cache op pr. cvr (`metis:company_info:{cvr}` — se rename nedenfor); (2) manglende cvr'er hentes med `Http::pool` (samtidighed styres af pool'en; byg requests med samme base-url/token/headers som `client()`); (3) succes-svar caches 24 t (samme ikke-cache-ved-null-regel som `fetchCompanyInfo`); (4) fejlede enkelt-kald → `null` for DET cvr (IKKE alt-eller-intet — ét selskabs manglende kort må ikke koste alle kortene; forskellen fra batch-endpointet er at disse kald er uafhængige).
- **Todo 003 (cache-nøgle-konvention):** omdøb `metis.company-info.{cvr}` → `metis:company_info:{cvr}` og `metis.company-structure.{cvr}` → `metis:company_structure:{cvr}` (filens etablerede stil). Opdatér de eksisterende tests. Konsekvens: ét koldt cache-miss pr. nøgle efter deploy — harmløst.

- [ ] **Step 1: Failing tests** (Http::fake: 3 cvr'er hvoraf 1 cachet → kun 2 HTTP-kald; 1 af de 2 fejler m. 500 → resultatet har `null` for den og data for de andre; cache-hit-test m. ny nøgle-form).
- [ ] **Step 2-4: Rød → implementér → hele suiten grøn.**
- [ ] **Step 5: Commit** — `git commit -am "feat(api): pooled company-info til graf-berigelse + cache-nøgle-konvention (todo 003)"`

---

### Task 3: Komponent — berigelses-trin og fuld batch-map

**Files:** Modify `src/Livewire/Sections/CompanyStructure.php`; Test `tests/Feature/Livewire/Sections/CompanyStructureTest.php`

**Interfaces:**
- Produces (Livewire public API): `string $enrichmentStatus` (`'pending'|'loaded'|'failed'`), action `loadEnrichment(): void`. Protected: `array $enrichmentData = ['companies' => [], 'properties' => []]`.
- Flow: bladen kalder `loadEnrichment` efter ejendoms-trinnet (Alpine-kæde: eksisterende building/loaded-note får et `x-init` der kalder `$wire.loadEnrichment()` når `propertiesStatus` er `loaded`/`empty` — se Task 4). **Gated:** `loadEnrichment` no-op'er (forbliver `pending`) mens `$this->enriching` er true (spec'ens poll-payload-hensyn); den eksisterende enrichment-poll-completion kalder den til sidst.
- `loadEnrichment`: (1) selskabs-cvr'er = alle selskabs-/searched-noder i `graphModel`; (2) `fetchCompanyInfosPooled($cvrs)` → map til builderens felter (equity/result/fiscal_year fra seneste financials-række — SPEJL `CompanyOverview.php`'s eksisterende læsning; website fra contact som `CompanyInfo` gør; founded_date; industry; employees); (3) batch-berigelsen: udvid det eksisterende `fetchPropertiesBatch`-kald (fra `loadProperties`) til at gemme FULD map i `enrichmentData['properties']` (usage + latest_transaction-dato/pris + valuation) — `usageMapFor()` omdøbes til `propertyEnrichmentFromBatch()`; (4) streetview-URL bygges HER pr. ejendom: kun når portfolio-lat/lng findes OG `config('metis.google_maps_api_key')` er sat; (5) `enrichmentStatus='loaded'` + `rebuild()`. Pool-delfejl → de ramte selskaber er null i mappen (kortet viser "berigelse utilgængelig"); HELE kaldet exception → `'failed'` + diskret retry (genbrug fejl-note-mønstret).
- Rehydration: `rehydrateBeforeRebuild()` udvides symmetrisk — `enrichmentStatus === 'loaded' && enrichmentData['companies'] === []` → genhent (alle kilder er cachede → billigt). Regressionstest over to requests (2a.1-P0-mønstret!).
- `config/metis.php`: tilføj `'google_maps_api_key' => env('GOOGLE_MAPS_API_KEY')`.

- [ ] **Step 1: Failing tests:** (a) loadEnrichment → noder får card/signals/agg i graphModel; (b) gated mens enriching=true (status forbliver pending, ingen company-info-kald); (c) pool-delfejl → øvrige selskabers kort findes stadig; (d) P0-mønster: loadEnrichment i request A → expandNode i request B → card-data stadig i graphModel; (e) streetview-URL kun når config-nøgle sat (config(['metis.google_maps_api_key' => 'x']) i testen).
- [ ] **Step 2-4: Rød → implementér → hele suiten grøn. Step 5: Commit** — `git commit -am "feat(graph): berigelses-trin — pooled nøgletal, fuld batch-map, streetview-URL m. config-gate"`

---

### Task 4: Blade — aggregat-række, signal-ikoner og singleton-kortet

**Files:** Modify `partials/graph-node.blade.php` + `partials/ownership-graph.blade.php`; komponent-tests renderer bladen (fanger fejl)

- [ ] **Step 1: graph-node:** aggregat-række på selskabs-noder — `<span class="mgraph-node__agg" x-show="node.agg" x-cloak x-text="node.agg ? (node.agg.count + ' ejendomme · ' + fmtDKK(node.agg.value) + (node.agg.valued < node.agg.count ? ' (' + node.agg.valued + ' vurderet)' : '')) : ''"></span>` (`fmtDKK` = lille hjælper i Alpine-komponenten: mio./tkr.-formatering). Signal-ikoner: `<span class="mgraph-node__signals" x-show="node.signals?.length" x-cloak><template x-for="s in (node.signals ?? [])" :key="s"><span class="mgraph-signal" :class="'mgraph-signal--' + s" :title="signalTitle(s)" x-text="signalGlyph(s)"></span></template></span>` — `signalGlyph`/`signalTitle` i JS: `negative_equity` → '▼' / 'Negativ egenkapital', `newly_founded` → '✦' / 'Nystiftet (<12 mdr.)', `no_financials` → '?' / 'Ingen regnskabsdata'. ALLE udtryk null-sikre.
- [ ] **Step 2: Singleton-kortet i ownership-graph.blade.php** (INDE i x-data-scope, EFTER `.mgraph-frame` så det ikke skalerer med canvas):

```blade
<template x-if="card.node">
    <div class="mgraph-card" :style="`left:${card.x}px; top:${card.y}px;`"
         @mouseenter="cardHover(true)" @mouseleave="cardHover(false)">
        <div class="mgraph-card__title" x-text="card.node.label"></div>
        <img x-show="card.node.card?.streetview_url" :src="card.node.card?.streetview_url ?? ''"
             class="mgraph-card__img" loading="lazy" @error="$el.style.display='none'" alt="">
        <dl class="mgraph-card__rows">
            <template x-for="row in cardRows(card.node)" :key="row.label">
                <div><dt x-text="row.label"></dt><dd x-text="row.value"></dd></div>
            </template>
        </dl>
        <div class="mgraph-card__links">
            <a x-show="card.node.card?.website" :href="card.node.card?.website ?? '#'" target="_blank" rel="noopener" class="mgraph-card__link">Website ↗</a>
            <a x-show="card.node.kind === 'property' && card.node.card?.lat" :href="skraafotoUrl(card.node)" target="_blank" rel="noopener" class="mgraph-card__link">Se skråfoto ↗</a>
            <a :href="nodeUrl(card.node)" class="mgraph-card__link" x-show="nodeUrl(card.node)">Åbn opslag →</a>
        </div>
    </div>
</template>
```

`cardRows(node)` (JS, Task 5) leverer label/value-par pr. kind — selskab: egenkapital (m. regnskabsår), resultat, ansatte, branche, "Ingen regnskabsdata" når `no_financials`; "Berigelse ikke hentet" når node uden card; ejendom: anvendelse, AVM-vurdering, seneste handel (dato+pris). `skraafotoUrl` bygges KLIENT-side af lat/lng via UTM32-koordinaterne som builderen leverer i card (se Task 6 — builderen konverterer og sender `utm_e`/`utm_n`, så JS blot formaterer URL'en). `nodeUrl`: selskab → `/lookup/cvr/{cvr}`, person → null i 2a.2 (2b ejer person-siden — vis intet link), ejendom → null (2c).
- [ ] **Step 3: CSS** i partial'en: `.mgraph-card` (absolut, sand-kort, skygge, max-width 280px, z-index over frame), `.mgraph-signal--negative_equity { color: #7a2f2f; }` osv. — følg de eksisterende reglers form; INGEN lilla/AI-farver.
- [ ] **Step 4: Hele suiten grøn. Step 5: Commit** — `git commit -am "feat(graph): aggregat-række, signal-ikoner og singleton hover-kort (blade)"`

---

### Task 5: Host-JS — kort-interaktion, node-klik, touch-pan, dims

**Files:** Modify `/Users/Frederik/Herd/metis/resources/js/ownership-graph.js` (branch `feat/ownership-graph-2a2-js` fra origin/main — VERIFICÉR med `git branch --show-current` FØR commit; tidligere task committede på forkert branch)

- [ ] **Step 1: Kort-state:** `card: {node: null, x: 0, y: 0}`, `_cardTimer: null`, `_overCard: false`. `showCard(node)`: position = `(node.x + node.w) * scale + tx + 8`, clamp mod frame-bredde/højde (flip til venstre side når højre kant rammes); `hideCardSoon()` = 150 ms timer der annulleres af `cardHover(true)` (grace-area). Node-mouseenter → 120 ms hover-intent-delay → showCard; mouseleave → hideCardSoon.
- [ ] **Step 2: Tap-model (mobil):** `@click` på node: hvis `this._moved` → ignorér (var en pan); ellers på touch-enheder (`window.matchMedia('(hover: none)').matches`): første tap åbner kortet (ingen navigation), tap på frame-baggrund lukker. På hover-enheder: klik navigerer — `if (n.kind !== 'person' && n.cvr) window.location = '/lookup/cvr/' + encodeURIComponent(n.cvr)` (kun selskabs-kinds; property/person navigerer ikke i 2a.2).
- [ ] **Step 3: Touch-pan:** `@touchstart/@touchmove/@touchend` på frame → genbrug startPan/onPan/endPan-logikken med `e.touches[0].clientX/Y` (samme 4 px-tærskel og `_pending`-invariant — RØR IKKE flush-logikken).
- [ ] **Step 4: Dims:** noder med `agg`- eller `signals`-indhold: +14 px (én ekstra række; signaler deler række med agg). `cardRows`/`fmtDKK`/`signalGlyph`/`signalTitle`/`nodeUrl`/`skraafotoUrl`-hjælpere tilføjes komponenten.
- [ ] **Step 5: `npm run build` OK → commit** — `"feat(graph): singleton-kort, node-klik-navigation, touch-pan, berigelses-dims (fase 2a.2)"`

---

### Task 6: Utm32Projection-udtræk (skråfoto-link uden Livewire-afhængighed)

**Files:** Create `src/Services/Utm32Projection.php`; Modify `src/Livewire/Sections/AddressSkraafoto.php` + `OwnershipGraphBuilder.php`; Create `tests/Unit/Utm32ProjectionTest.php`

- [ ] **Step 1: Failing test:** kendt fixture — konverter (55.6761, 12.5683) (København) og assert E/N inden for ±2 m af de værdier den NUVÆRENDE `AddressSkraafoto::wgs84ToUtm32` giver (kør metoden via en midlertidig test-subklasse for at generere fixture-værdierne — paritet er kravet, ikke geodætisk nyudvikling).
- [ ] **Step 2: Flyt matematikken 1:1** til `Utm32Projection::toUtm(float $lat, float $lng): array{e: float, n: float}`; `AddressSkraafoto` delegerer (dens egen adfærd uændret — eksisterende tests skal forblive grønne). Builderen beriger ejendoms-`card` med `utm_e`/`utm_n` når lat/lng findes (Task 1's card-test udvides).
- [ ] **Step 3: Suiten grøn → commit** — `"refactor(geo): Utm32Projection udtrukket — skråfoto-URL kan bygges fra graf-kortet"`

---

### Task 7: Lokal browser-verifikation (2a.1-opskriften m. alle gotchas)

- [ ] Setup: path-repo-symlink i host-composer (uncommitted), REGISTRY_API_KEY + `GOOGLE_MAPS_API_KEY` + `METIS_GATING=false` i host-.env (midlertidigt, IKKE committes), **APP_URL=http://127.0.0.1:8787** (herd link OMSKRIVER den!), `php artisan serve --port=8787`, `view:clear && config:clear`. HUSK: Livewire-updates der udebliver = tjek `route:clear` + at output-felter læses TOP-LEVEL.
- [ ] FDL (38653806), konsol åben: hover selskabs-node → kort m. nøgletal efter berigelse; hover ejendom → kort m. anvendelse/AVM/handel + skråfoto-link (+ streetview-billede hvis nøgle sat); grace-area (flyt mus fra node til kort uden at det lukker); klik selskabs-node → navigation; aggregat-rækker + signal-ikoner synlige; INGEN Alpine-fejl.
- [ ] Verificér berigelses-timing: kort-data ankommer EFTER ejendomme uden at nulstille zoom/pan; `enriching`-gate (slå op på u-enriched CVR og se berigelsen vente pænt).
- [ ] Rul ALT setup tilbage (composer.json/lock, .env-nøgler, serve-proces).

### Task 8: PR-par + koordineret deploy

- [ ] Package-PR + host-PR (beskrivelser: koordineret deploy-rækkefølge, rollback = revert×3, `GOOGLE_MAPS_API_KEY`-env-trinnet som EKSPLICIT Frederik-beslutning m. anbefaling om referrer-restriktion på nøglen). CI grøn. Merge-sekvens som 2a.1 (package → bump → host → view:clear → prod-verif m. konsol + Flare-watch).

---

## Self-review (kørt)

- **Spec-dækning 2a.2:** hover-/tap-kort ✅ (T4-5), singleton+koordinat-transform+grace ✅ (T4-5), touch-pan ✅ (T5), værdi-aggregat m. dækningsgrad ✅ (T1), signaler m. no_financials-tilstand ✅ (T1), pooled datavej ✅ (T2-3), streetview m. config-gate + skråfoto-link ✅ (T3-6), branche/website/ansatte i kort ✅ (T3-4), node-klik-navigation (F4-opfølgning) ✅ (T5), Utm32-udtræk ✅ (T6), todo 003 ✅ (T2). Todo 004 (builder-refactor) BEVIDST udeladt: T1 tilføjer en isoleret fase, og refactor+feature i samme PR øger review-fladen — 004 forbliver i todos/.
- **Placeholder-scan:** ingen TBD; kode/eksakte instruktioner i alle steps.
- **Type-konsistens:** `enrichment`-shape (T1↔T3), `card`-felter (T1↔T4↔T5), `agg`-shape (T1↔T4), signal-navne (T1↔T4/T5), `utm_e/utm_n` (T6↔T4) — konsistente.
