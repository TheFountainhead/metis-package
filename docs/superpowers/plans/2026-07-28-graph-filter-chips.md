# Graf-filter-chips (A: selskabs-graf, B: privat-ejendoms-lag) — implementeringsplan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Filter-chips på selskabs-grafen (Ejere/Datterselskaber/Ejendomme) + "Private ejendomme"-chip på person-grafen (Ejerfortegnelsen).

**Architecture:** `build()` får `layers`-parameter (bagudkompatibel default); CompanyStructure får person-sidens chip-mønster 1:1 inkl. `buildGraph($layers)` og lag-uafhængigt enrichment-scope. Person-grafen får fjerde fase (private ejendomme, cache-only recovery) og `pp:`-noder i builderen. Lille host-PR (cardRows-felter).

**Tech Stack:** Laravel 12, Livewire 3, Pest, eksisterende graf-motor (2a/2b, LIVE).

**Spec (autoritativ, v2 m. bindende præciseringer):** `docs/superpowers/specs/2026-07-28-graph-filter-chips-design.md` — sektionen "Bindende præciseringer fra spec-review (v2)" GÆLDER FORAN alt andet ved tvivl.

## Global Constraints

- CPR ALDRIG i node-ids/kanter/DOM/cache-keys. Cache-key for person-portefølje: `metis:person_property_portfolio:`+sha1(cpr), 5-min TTL, fejl caches aldrig.
- Builder ren/deterministisk; ingen `now()`/HTTP.
- `pp:`-id: `'pp:'.sha1(trim+lowercase(matrikelnummer).'|'.trim+lowercase(address).'|'.$rowIndex-fallback)` — rækkeindeks foldes KUN ind når matrikelnummer er tomt (spec P2-2).
- `public_valuation` er HELE KRONER — 1:1-mapping, ingen konvertering (spec P2-1, afgjort).
- Enrichment-scope er ALDRIG chip-afhængigt: enrichment-stier resolver mod graf bygget med ALLE lag (spec P1-4).
- Aldrig-tom-reglen: afvis toggle hvis prøvebygning giver `count(nodes) <= 1`; blade-lås via server-beregnet `layerContributesNodes()` — IKKE badge-tal (spec P1-6). Badges forbliver pre-cap.
- `applyEnrichment()`s property-gren gates på `str_starts_with($id, 'bfe:')`; `pp:`-kort skrives ved node-oprettelse (spec P1-1).
- Hentning fortsætter uanset chips; chip-toggle dispatcher `graph-refit`; `wire:loading.attr="disabled" wire:target="toggleLayer"` på chips.
- Fuld suite grøn + mutations-tjek af nye guards før hver commit; commit pr. task.

---

### Task 1: Builder A — `layers` på `build()`

**Files:** Modify `src/Services/OwnershipGraphBuilder.php` · Test `tests/Unit/OwnershipGraphBuilderTest.php`

**Interfaces:** `build(..., array $caps, ?CarbonImmutable $now = null, array $layers = ['owners','subsidiaries','properties'])` — trailing param m. default; begge eksisterende kaldesteder bruger navngivne argumenter (verificeret), så intet ændres.

**Bindende:** `'owners' ∉ layers` ⇒ addAncestors springes over. `'subsidiaries' ∉ layers` ⇒ addSubsidiaries springes over. `'properties' ∉ layers` ⇒ addProperties springes over OG `finalize()` kaldes med `propertyList: []` (ellers agg for usynlige ejendomme — spec P2-5). Rod-noden ALTID.

- [ ] **Step 1:** RED-tests: hvert lag udeladt enkeltvis (assert noder/kanter/agg-fravær); default = eksisterende fuld graf (byt-test: `build(...)` uden layers === `build(..., layers: alle)`).
- [ ] **Step 2:** Implementér gates. **Step 3:** Fuld suite grøn (eksisterende tests uændrede = bagudkompatibilitets-beviset). **Step 4:** Commit `feat: layers-parameter på build() (chips A, builder-del)`.

### Task 2: CompanyStructure chips

**Files:** Modify `src/Livewire/Sections/CompanyStructure.php`, `resources/views/livewire/sections/company-structure.blade.php` · Test `tests/Feature/Livewire/Sections/CompanyStructureTest.php`

**Interfaces (spejl person-sidens navne 1:1):** public `array $layers = ['owners','subsidiaries','properties']`; `toggleLayer(string $layer): void`; protected `buildGraph(array $layers): array` (wrapper om build-kaldet m. eksplicit layers); `layerContributesNodes(string $layer): bool` (prøvebygning UDEN laget vs. med — afgør blade-låsen).

**Bindende:** (1) `toggleLayer` afviser server-side når prøvebygning af `$next` giver `count(nodes) <= 1` — genbrug person-sidens metode-form inkl. rebuild+`graph-refit`-dispatch. (2) ALLE enrichment-stier (`fetchEnrichmentData`/scope-beregning i `ResolvesGraphEnrichment`-kaldene) bruger `buildGraph(ALLE-lag)` — ALDRIG `$this->layers` (spec P1-4; person-sidens `visibleFirstLevelCvrs()`-docblock er begrundelsen — kopiér essensen ind som kommentar). (3) Blade: chips over canvas; markup+`$otherCount`-låselogik kopieres fra `person-structure.blade.php:20-57` **inkl. kommentaren** (spec P3-2) men låsen bygger på `layerContributesNodes()`, ikke badge-tal. Badges pre-cap: N=ancestors-RÆKKER (spec P3-1), M=`countDescendants` over subsidiaries-træet, K=portefølje-listens længde — beregnes i `rebuild()`-stien. (4) `$layers` med i `rehydrateBeforeRebuild`-flowet som public state (som person-siden).

- [ ] **Step 1:** RED-tests (min. 6): toggle filtrerer (ejere væk/til); enrichment-scope uafhængigt af chips (owners fra → enrichment kørt → owners til → ejer-noder HAR card — mutations-følsom: skift buildGraph(alle) til $this->layers og se rød); aldrig-tom `<= 1`; `layerContributesNodes` false for cappet-væk lag (byg 130+ ejendomme, total-cap æder properties → chip fravælgelig trods K>0); badges; hentning fortsætter for skjult lag.
- [ ] **Step 2-4:** Implementér → fuld suite → Commit `feat: filter-chips på selskabs-grafen`.

### Task 3: RegistryApi cache + PersonProperties-skifte

**Files:** Modify `src/Services/RegistryApi.php`, `src/Livewire/Sections/PersonProperties.php` · Test `tests/Unit/RegistryApiTest.php`

- [ ] **Step 1:** RED: `fetchPersonPropertyPortfolioByCprCached` — 2. kald = cache-hit (assertSentCount 1); key uden rå CPR; fejl (`['error']`/null) caches aldrig (retry-sekvens-test m. `Http::failedConnection`-invoke-mønstret).
- [ ] **Step 2:** Implementér (spejl `fetchCompaniesByCprCached` præcist: 300s, sha1-key). `PersonProperties::mount` skifter til den cachede variant.
- [ ] **Step 3:** Fuld suite. **Step 4:** Commit `feat: cachet person-portefølje + PersonProperties genbruger den`.

### Task 4: Builder B — `pp:`-noder

**Files:** Modify `src/Services/OwnershipGraphBuilder.php` · Test `tests/Unit/OwnershipGraphBuilderTest.php`

**Interfaces:** `buildForPerson(...)` får `array $privateProperties = []` (rå personal_properties-rækker) og `'private_properties'` som muligt lag i `$layers`; caps-nøgle `person_private_properties => 10`.

**Bindende:** (1) Node: id efter Global Constraints-reglen, `kind: 'property'`, label=address, `meta`: card skrives VED OPRETTELSE fra rækken (`public_valuation` [kroner 1:1], `area_building`, `year_built`, `mortgage_count = count(mortgages)`, `co_owner_count = count(co_owners)`) — IKKE via applyEnrichments bfe-gren. (2) Kant `person:root`→pp m. `shareLabel(ownership_share)`, intet style-felt. (3) `applyEnrichment` property-gren gates på `str_starts_with($id, 'bfe:')` (spec P1-1) — test: graf m. begge slags noder, pp får aldrig bfe-kort. (4) Cap-fold: over-cap-antal skrives i `expand['properties']` på `person:root` i NY kodesti i buildForPerson (spec P1-3); `props:person:root` i expandedNodeIds løfter cappen; `sub:person:root` rører den IKKE (test begge). (5) `person:root` får INTET agg fra private (spec P2-3). (6) Id-stabilitet: dobbelt-byg → identiske ids; to tomme-matrikel-rækker samme adresse → to noder (index-fallback).

- [ ] **Step 1:** RED-tests (min. 7 jf. ovenstående + lag-filtrering + CPR-regex-fixture). **Step 2-4:** Implementér → fuld suite → Commit `feat: private ejendomme som pp:-noder i person-grafen`.

### Task 5: PersonStructure fase + chip + blade-fix

**Files:** Modify `src/Livewire/Sections/PersonStructure.php`, `resources/views/livewire/sections/person-structure.blade.php`, `resources/views/livewire/sections/partials/graph-node.blade.php` · Test `tests/Feature/Livewire/Sections/PersonStructureTest.php`

**Bindende:** (1) `$layers` udvides m. `'private_properties'` (prævalgt); chip m. badge `privatePropertiesCount` (pre-cap; ved `failed` sættes 0 og visningen viser "(–)" — spec P2-4). (2) `privatePropertiesStatus: pending/loaded/empty/failed`; `privatePropertiesData` PROTECTED. (3) `tick()`: NY gren FØR fase-2-grenen — én cachet hentning når status er pending, derefter falder grenen igennem (spec P1-5c). (4) Bladens poll-gate udvides `|| $privatePropertiesStatus === 'pending'`. (5) Recovery: `recoverPrivatePropertiesResults()` cache-only fra `recoverPhaseResults()`; miss → status pending, poll overtager (spec P1-5a) — mutations-følsom test over `rehydratedFrom`-grænsen + FRESH-mount-varianten (lærdommen fra T8: state-kopi kan ikke observere fravær). (6) EGEN `retryPrivateProperties()` (rører ikke fase-3-budgettet). (7) `graph-node.blade.php`: ejendoms-udvid-knappen → `'props:' + (node.cvr ?? node.id)` m. kommentar (spec P1-2; package-partial, no-op for selskabssiden) — test: klik på person-rodens ejendoms-udvid → `props:person:root` i expandedNodeIds + nodevækst. (8) Aldrig-tom dækker automatisk tredje lag (eksisterende `<= 1`-regel — pin-test m. kun-private person).

- [ ] **Step 1:** RED-tests (min. 8 jf. ovenstående). **Step 2-4:** Implementér → fuld suite → Commit `feat: Private ejendomme-chip på person-grafen`.

### Task 6: Host-JS cardRows

**Files:** Modify `/Users/Frederik/Herd/metis/resources/js/ownership-graph.js` (branch oprettes af kontrolleren)

- [ ] **Step 1:** `cardRows()` udvides m. rækkerne (kun når feltet findes — additivt): `Vurdering` (fmtDKK(public_valuation)), `Areal` (`X m²`), `Byggeår`, `Pantebreve` (antal), `Medejere` (antal). Fravær = række udelades (eksisterende mønster).
- [ ] **Step 2:** `npm run build` grøn. **Step 3:** Commit `feat: kort-rækker for private ejendomme`.

### Task 7: Verifikation + PR-par

- [ ] Fuld package-suite + mutations-tjek-log i rapporten. PR-par (kontrolleren): package-PR + host-PR — additive begge veje, rækkefølge fri (spec P3-3: props-blade-fixet ligger i PACKAGE). Merge → bump → deploy → `view:clear` → prod-verifikation m. konsol: selskabs-chips på Lars Horsbøl-siden (40072772), person-chips + private ejendomme på admin-CPR-siden (Travervænget 3, 50% — den HAR privat ejendom, så B-laget KAN prod-verificeres visuelt!) + Flare-watch.

## Self-review (udført ved planskrivning)

Spec-dækning: alle 6 P1-præciseringer har eksplicit task-hjem (P1-1→T4.3, P1-2→T5.7, P1-3→T4.4, P1-4→T2.2, P1-5→T5.3-6, P1-6→T2.1/T2-tests); P2'erne ligeledes (P2-1→GC, P2-2→GC+T4.6, P2-3→T4.5, P2-4→T5.1, P2-5→T1). Typer/navne konsistente m. eksisterende kode (buildGraph/toggleLayer-spejling). Ingen placeholders. NB: admin-CPR'et gør B-laget prod-verificerbart visuelt — første gang person-grafen får synligt indhold for den identitet.
