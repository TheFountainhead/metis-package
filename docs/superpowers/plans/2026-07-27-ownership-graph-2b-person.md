# Ejer-relations-graf 2b (person-siden) — implementeringsplan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Person-grafen på `/lookup/cpr/{cpr}`: person som rod, ejerskabs- og rolle-lag med filter-chips, progressiv fase-opbygning — afløser `PersonNetwork`.

**Architecture:** Ren deklarativ `buildForPerson()`-indgang på `OwnershipGraphBuilder` (delt `finalize()`-hale med `build()`), ny Livewire-sektion `PersonStructure` med 4-faset statusmodel og poll-baseret progressiv hentning. Host-JS får kant-`style`-gennemstilling (deployes FØRST).

**Tech Stack:** Laravel 12, Livewire 3, Pest, dagre (host-JS), eksisterende graf-partial.

**Spec (autoritativ):** `docs/superpowers/specs/2026-07-27-ownership-graph-2b-person-design.md` (v2, review-hærdet). Ved tvivl vinder spec'en.

## Global Constraints

- **CPR må ALDRIG indgå i node-id'er, kanter, DOM-attributter eller cache-keys.** Person-rodens id er `person:root`. Cache-key for companies-by-cpr bruger `sha1($cpr)`.
- Builderen er REN og deklarativ: samme input → samme output; ingen HTTP; alle stier rebuild'er. `CarbonImmutable::now()` er FORBUDT i builderen (`$now` er caller-supplied).
- Node-kind for ejerskabs-rødder og rolle-selskaber er **`'subsidiary'` med `depth => 1`** (gør ENRICHABLE_KINDS, truncateToCap og host-JS COMPANY_KINDS-navigation gratis).
- Kant-shape: `['from','to','label']` + NYT valgfrit `'style' => 'solid'|'dashed'`; **fravær af style = solid** (eksisterende `build()` ændres IKKE til at sætte style).
- Statusstrings (2b's egne): skeleton `pending/loading/loaded/empty/failed`; structures aggregat + per-cvr `pending/loading/loaded/failed`; properties aggregat + per-cvr `pending/building/loaded/empty/failed`; enrichment `pending/loaded/failed`.
- Caps: `subsidiary_depth => 2`, `properties_per_company => 6`, `total_nodes => 120` + first-level `person_roots => 20`, `person_roles => 15`. Udvid-ids: `sub:person:root`, `roles:person:root`.
- Fase-budgetter: strukturer 3 cvr/tick (`Http::pool` concurrency 3); ejendomme 3 cvr/tick, delt attempts-budget **24**; enrichment-batch KUN matrikel-ids fra graf-modellen.
- null ≠ tom: fejlet API-kald må aldrig rendere som tom-tilstand.
- Deploy-rækkefølge: host-JS-PR FØRST, derefter package-PR.
- Fuld suite grøn før hver commit; commit efter hver task.

---

### Task 1: Host-JS kant-style-gennemstilling (repo: `/Users/Frederik/Herd/metis`)

**Files:**
- Modify: `/Users/Frederik/Herd/metis/resources/js/ownership-graph.js` (setEdge ~linje 164; buildEdgesSvg ~linje 191-206)

**Interfaces:**
- Consumes: edge-objekter `{from, to, label, style?}` fra graf-modellen (style er nyt, valgfrit).
- Produces: edge-`<path>` med klasse `mgraph-edge-line mgraph-edge-dashed` når `style === 'dashed'`. CSS-varianten kommer i package-PR'en (Task 8) — indtil da renderer dashed-klassen som solid, hvilket er det accepterede deploy-vindue.

- [ ] **Step 1: Find de to steder.** `g.setEdge(e.from, e.to, { label: e.label })` kopierer kun label; `buildEdgesSvg` udskriver `class="mgraph-edge-line"` hårdkodet.

- [ ] **Step 2: Thread style igennem:**

```js
// setEdge-stedet (~164):
g.setEdge(e.from, e.to, { label: e.label, style: e.style });

// buildEdgesSvg-stedet (~197) — edge-objektet fra g.edge(e) bærer nu style:
const cls = edge.style === 'dashed' ? 'mgraph-edge-line mgraph-edge-dashed' : 'mgraph-edge-line';
// ...og brug ${cls} i class-attributten i stedet for den hårdkodede streng.
```

Ingen andre ændringer — labels renderes allerede verbatim med halo, NODE_DIMS/pan/zoom/kort urørt.

- [ ] **Step 3: Manuel sanity:** `npm run build` (eller Vite build-kommandoen host-repoet bruger) kompilerer uden fejl.

- [ ] **Step 4: Commit** på branch `feat/graph-edge-style` i host-repoet:

```bash
git add resources/js/ownership-graph.js
git commit -m "feat: graf-kanter kan bære style (dashed) — 2b rolle-lag, additivt"
```

**Denne PR merges og deployes FØR package-arbejdet går live** (kontrolleren håndterer PR/merge — ikke implementer-agenten).

---

### Task 2: `fetchCompaniesByCprCached` + pooled struktur-hentning (RegistryApi)

**Files:**
- Modify: `src/Services/RegistryApi.php`
- Test: `tests/Unit/RegistryApiTest.php`

**Interfaces:**
- Produces: `fetchCompaniesByCprCached(string $cpr): ?array` — 5-min cache, key `metis:companies_by_cpr:{sha1($cpr)}`; cacher KUN ikke-null svar (null = fejl må ikke caches som tom).
- Produces: `fetchCompanyStructuresPooled(array $cvrs): array` — `cvr => ?array` via `Http::pool` med concurrency **3** (genbrug POOL-mønstret fra `fetchCompanyInfosPooled`, men mod structure-endpointet som `fetchCompanyStructure` bruger, UCACHET — fase 2 henter hvert cvr præcis én gang; cache-varianten er kun til rehydrering). Per-cvr fejl → null for det cvr, aldrig exception ud.

- [ ] **Step 1: Failing tests** (følg RegistryApiTest's eksisterende `Http::fake`-stil):

```php
it('caches companies-by-cpr on a hashed key and never caches null', function () {
    Http::fake(['*/v1/cvr/search-by-cpr' => Http::response(['companies' => [['cvr' => '1']]])]);
    $api = app(RegistryApi::class);
    $api->fetchCompaniesByCprCached('0101011234');
    $api->fetchCompaniesByCprCached('0101011234');
    Http::assertSentCount(1);
    expect(Cache::has('metis:companies_by_cpr:'.sha1('0101011234')))->toBeTrue()
        ->and(collect(array_keys((array) Cache::get('metis:companies_by_cpr:'.sha1('0101011234'))))->join(','))->not->toContain('0101011234');
});

it('companies-by-cpr cache key never contains the raw cpr', function () {
    Http::fake(['*/v1/cvr/search-by-cpr' => Http::response(['companies' => []])]);
    app(RegistryApi::class)->fetchCompaniesByCprCached('0101011234');
    // sha1-nøglen er deterministisk — selve asserten er at ingen key med rå CPR findes:
    expect(Cache::has('metis:companies_by_cpr:0101011234'))->toBeFalse();
});

it('pools structure fetches with per-cvr null on failure', function () {
    Http::fake([
        '*company-structure*' => Http::sequence()
            ->push(['owners' => [], 'subsidiaries' => []])
            ->push(['error' => 'x'], 500),
    ]);
    $r = app(RegistryApi::class)->fetchCompanyStructuresPooled(['11111111', '22222222']);
    expect($r['11111111'])->toBeArray()->and($r['22222222'])->toBeNull();
});
```

(Justér fake-URL-mønstre til de faktiske endpoints i RegistryApi — læs `fetchCompanyStructure`/`fetchCompaniesByCpr` først; test-forventningerne ovenfor er kravene.)

- [ ] **Step 2: Kør tests → FAIL** (metoder findes ikke).
- [ ] **Step 3: Implementér** de to metoder (spejl `fetchCompanyInfosPooled`s dedup-input + pool-mønster; 300 s TTL for cpr-cachen).
- [ ] **Step 4: Kør fuld suite → PASS. Commit** `feat: cpr-hash-cache + pooled struktur-hentning (2b fase 1-2)`.

---

### Task 3: Builder-refactor — delt `finalize()`-hale (ren refactor, adfærd uændret)

**Files:**
- Modify: `src/Services/OwnershipGraphBuilder.php`

**Interfaces:**
- Produces: `protected function finalize(array &$nodes, array &$edges, array $propertyList, array $enrichment, array $caps, ?string $searchedAlias, ?CarbonImmutable $now): void` — usage-merge + `truncateToCap` + `applyEnrichment`.
- `$searchedAlias` erstatter `$query`-parameteren i `applyEnrichment`/`aggregateProperties`/`addProperties`-normaliseringen (`owner === $searchedAlias → 'searched'`); `build()` sender `$query`, person-stien sender **null** (person-grafen har intet 'searched'-alias — `ownedTargetId`-tilsvarende logik skal håndtere null ved simpelthen aldrig at matche).

- [ ] **Step 1: Ingen nye tests** — dette er en ren refactor; de 302 eksisterende tests ER sikkerhedsnettet.
- [ ] **Step 2: Udtræk** usage-merge-blokken (build() linje 61-66) + kaldene til `truncateToCap`+`applyEnrichment` (linje 71-72) til `finalize()`; `build()` kalder den. Skift `applyEnrichment(...,string $query,...)` og `aggregateProperties(...,string $query)` til `?string $searchedAlias` (null ⇒ ingen 'searched'-mapping). `addProperties`' `$query`-parameter samme behandling.
- [ ] **Step 3: Kør fuld suite → 302 PASS uændret. Commit** `refactor: delt finalize()-hale + nullable searched-alias (forberedelse til buildForPerson)`.

---

### Task 4: `buildForPerson()` — skelet-laget

**Files:**
- Modify: `src/Services/OwnershipGraphBuilder.php`
- Test: `tests/Unit/OwnershipGraphBuilderTest.php`

**Interfaces:**
- Produces (signaturen fra spec'en, bindende):

```php
public function buildForPerson(
    string $personName,
    array $ownershipCompanies, // [{cvr, name, company_type, ownership_share}] — ALLE aktive m. direct ownership (før cross-ownership-dedup; dedup sker HER i builderen)
    array $roleCompanies,      // [{cvr, name, company_type, role_label}]
    array $crossOwnership,     // relationships[] {parent_cvr, child_cvr, ownership_share}
    array $structures,         // cvr => ?structure (kun hentede)
    array $properties,         // cvr => ?portfolio-list (kun hentede)
    array $enrichment,
    array $expandedNodeIds,
    array $layers,             // delmængde af ['ownership','roles']
    array $caps,
    ?CarbonImmutable $now = null,
): array
```

**Bindende regler (fra spec v2):**
1. Person-rod: `['id' => 'person:root', 'label' => $personName, 'cvr' => null, 'kind' => 'person', 'share' => null, 'expand' => null]`.
2. Cross-ownership-dedup: cvr'er der optræder som `child_cvr` med en `parent_cvr` der OGSÅ er i ejerskabs-sættet er BØRN — de får node (kind subsidiary, depth 2) + kant parent→child med shareLabel ALLEREDE i skelettet, IKKE person-kant som rod. Ejer personen barnet DIREKTE (barnet står selv i $ownershipCompanies), tegnes person→barn-kanten OGSÅ (begge kanter er sande).
3. Ejerskabs-rødder (kun når `'ownership' ∈ $layers`): kant `person:root`→cvr med `shareLabel(ownership_share)` (null share ⇒ tom label), ingen style-felt (= solid).
4. Rolle-selskaber (kun når `'roles' ∈ $layers`): kant `person:root`→cvr med `'label' => $role_label ?: 'rolle'`, `'style' => 'dashed'`.
5. Lag-bevidst dobbelt-relation-dedup: cvr i BEGGE input-lister → med begge lag aktive vises kun ejerskabs-noden/kanten (ingen rolle-kant); med KUN `roles` aktivt vises noden med rolle-kant.
6. Rolle-selskab der også er subsidiary i et ejerskabs-træ: `$seen`-dedup giver én node; BEGGE kanter beholdes (dashed person-kant + solid parent-kant).
7. First-level caps: højst `$caps['person_roots']` ejerskabs-rødder og `$caps['person_roles']` rolle-selskaber i input-rækkefølgen; overskydende foldes på person-rodens expand: `'expand' => ['relations' => N_skjulte_rødder + M_skjulte_roller, 'properties' => 0]` og udvid-ids `sub:person:root` (løfter roots-cappen) / `roles:person:root` (løfter rolle-cappen) i `$expandedNodeIds`.
8. Alle selskabs-noder: `kind => 'subsidiary'`, rødder/rolle-selskaber `depth => 1`.

- [ ] **Step 1: Failing tests** — én test pr. regel ovenfor (8 tests min.), fx:

```php
it('buildForPerson: role edges are dashed with role label, ownership edges solid with %', function () {
    $m = app(OwnershipGraphBuilder::class)->buildForPerson(
        'Lars Sørensen',
        [['cvr' => '40072772', 'name' => 'Holding', 'company_type' => 'ApS', 'ownership_share' => 100.0]],
        [['cvr' => '41527080', 'name' => 'Resights', 'company_type' => 'ApS', 'role_label' => 'bestyrelse']],
        [], [], [], [], [], ['ownership', 'roles'], personCaps(),
    );
    $own = collect($m['edges'])->firstWhere('to', '40072772');
    $role = collect($m['edges'])->firstWhere('to', '41527080');
    expect($own['label'])->toBe('100 %')->and($own)->not->toHaveKey('style')
        ->and($role['style'])->toBe('dashed')->and($role['label'])->toBe('bestyrelse');
});

it('buildForPerson: node ids never contain a cpr-like string', function () {
    $m = app(OwnershipGraphBuilder::class)->buildForPerson('X', [], [], [], [], [], [], [], ['ownership','roles'], personCaps());
    expect(json_encode($m))->not->toMatch('/\d{10}/');
});
```

Definér `personCaps()`-helper i testfilen: 2a-caps + `person_roots => 20, person_roles => 15`.

- [ ] **Step 2: Kør → FAIL.**
- [ ] **Step 3: Implementér** skelet-delen af `buildForPerson` (endnu uden structures/properties — de er tomme maps i disse tests; kald `finalize()` til sidst med tom property-liste og `$searchedAlias = null`).
- [ ] **Step 4: Fuld suite → PASS. Commit** `feat: buildForPerson skelet-lag (chips, cross-ownership, dedup, first-level caps)`.

---

### Task 5: `buildForPerson()` — progressive strukturer, ejendomme, trunkering

**Files:**
- Modify: `src/Services/OwnershipGraphBuilder.php`
- Test: `tests/Unit/OwnershipGraphBuilderTest.php`

**Bindende regler:**
1. Pr. rod-cvr med non-null `$structures[$cvr]`: `addSubsidiaries($structures[$cvr]['subsidiaries'] ?? [], $cvr, 2, $caps['subsidiary_depth'] + 1, ...)` — depth starter på 2 (roden selv er 1), depth-cap forskydes tilsvarende. Auto-udvid (≤3 descendants) og `sub:<cvr>`-udvid virker via genbruget.
2. Injektion-fallback: en cross-ownership-relationship hvis child IKKE optræder i forælderens hentede subsidiaries-træ → barnet renderes alligevel (kanten fra skelettet består; noden er allerede i grafen fra skelettet — asserten er at hentet struktur IKKE fjerner den).
3. Properties: fladgør `array_filter($properties)` til én samlet liste og kald `addProperties(..., $searchedAlias = null, ...)` — ejendomme hænger via `owner_cvr` på et hvilket som helst node-cvr i grafen (rod ELLER subsidiary). `props:<cvr>`-udvid genbruges.
4. Person-trunkering: `truncateToCap`-prioriteten skal for person-grafen være ejendomme → dybeste datter-lag → **rolle-noder** → ejerskabs-rødder sidst. Implementering: rolle-noder markeres `'role_layer' => true` i node-metadata; person-varianten af trunkering (ny protected `truncateToCapForPerson` ELLER et prioritets-parameter) skærer efter subsidiary-pass 2 først nodes med `role_layer`, dernæst depth-1-rødder bagfra — person-roden røres ALDRIG.
5. Enrichment/aggregat/signaler: via `finalize()` — rolle-selskaber HAR kind subsidiary og beriges dermed.

- [ ] **Step 1: Failing tests** (min. 6): delmængde-structures → deterministisk delgraf; auto-udvid under person-rod; injektion-fallback; ejendom på subsidiary under rod; trunkeringsprioritet (byg 130+ noder: assert ejendomme ryger først, person-rod + rødder overlever længst, rolle-noder før rødder); enrichment-kort på rolle-selskab.
- [ ] **Step 2: FAIL → Step 3: Implementér → Step 4: fuld suite PASS. Commit** `feat: buildForPerson progressive strukturer/ejendomme + person-trunkeringsprioritet`.

---

### Task 6: `PersonStructure` Livewire-sektion — fase 1 (skelet) + udskiftning

**Files:**
- Create: `src/Livewire/Sections/PersonStructure.php`
- Create: `resources/views/livewire/sections/person-structure.blade.php`
- Modify: `src/MetisServiceProvider.php` (registrér `metis-person-structure`; SLET `metis-person-network`-linjen)
- Modify: `resources/views/livewire/lookup.blade.php` (`:28` → `<livewire:metis-person-structure :query="$query" lazy />`)
- Delete: `src/Livewire/Sections/PersonNetwork.php`, `resources/views/livewire/sections/person-network.blade.php`
- Test: `tests/Feature/Livewire/Sections/PersonStructureTest.php`

**Interfaces:**
- Komponent-state (protected — ALDRIG public/wire-payload): `$companiesData` (rå companies-svar), `$crossOwnershipData`, `$structureData` (cvr-map), `$propertyData` (cvr-map), `$enrichmentData`. Public: `$query` (CPR — som i dag, URL bærer det), `$graphModel`, statuses, `$layers = ['ownership','roles']`, `$expandedNodeIds = []`.
- `rebuild()`: klassificér companies (PersonNetworks regler: `is_active`, `has_direct_ownership`, første current rolle m. share; role_label = `title ?? role ?? null`) → kald `buildForPerson`.

**Bindende regler:**
1. `mount()`: `skeletonStatus = 'loading'` → `fetchCompaniesByCprCached` → **null ⇒ `failed`** (fejlnote + `retrySkeleton()`-knap); tom companies-liste ⇒ `empty` ("Ingen aktive selskabsrelationer", ingen graf-canvas); ellers `fetchCrossOwnership` (kun ved ≥2 ejerskabs-cvr'er) — **null dér ⇒ skeleton `failed`** (forkert graf er værre end ingen) → `loaded` + rebuild + `structuresStatus = 'loading'` (per-cvr map `pending` for synlige rødder+rolle-selskaber).
2. Chips: `toggleLayer(string $layer)` — server-side aldrig-tom-regel: afvis toggle hvis resultatet er nul synlige noder ud over personen (returnér uden state-ændring); ellers flip + rebuild + dispatch `graph-refit`.
3. Blade: chips med tælle-badges (`Ejerskab (N)` / `Roller (M)`), inkludér den delte graf-partial (`partials/ownership-graph`), status-noter med `wire:key` som 2a's blade.
4. Person-roden er ikke klikbar: graf-partialen/graph-node behøver ingen ændring hvis person-noder allerede kun navigerer via navn — VERIFICÉR i `graph-node.blade.php`/host-JS hvordan person-klik håndteres i 2a og gate `person:root` fra navigation (id-tjek i blade-bindingen er nok).

- [ ] **Step 1: Failing tests** (Livewire::test, `Http::fake`-fixtures `fakeRegistryCpr(...)`/`fakeRegistryCrossOwnership(...)` — nye helpers ved siden af 2a's): null→failed+retry; tom→empty; loaded→graf med person-rod; cross-ownership-fejl→failed; toggleLayer aldrig-tom; badge-tal.
- [ ] **Step 2: FAIL → Step 3: Implementér + registrér + slet PersonNetwork (alle 3 referencer) → Step 4: fuld suite PASS** (grep `person-network` = 0 hits). **Commit** `feat: PersonStructure fase 1 — skelet, chips, null≠tom; PersonNetwork slettet`.

---

### Task 7: `PersonStructure` — fase 2 (strukturer) + fase 3 (ejendomme)

**Files:**
- Modify: `src/Livewire/Sections/PersonStructure.php` + blade
- Test: `tests/Feature/Livewire/Sections/PersonStructureTest.php`

**Bindende regler:**
1. Blade: `wire:poll.2s="tick"` gated: `@if(in_array($structuresStatus, ['loading']) || in_array($propertiesStatus, ['pending','building']))`.
2. `tick()`: (a) er der `pending` structure-cvr'er → tag de første **3** (companies-rækkefølgen), `fetchCompanyStructuresPooled` → per-cvr `loaded`/`failed` i `$structureByCompany`, gem i `$structureData`, rebuild. Når ingen `pending`/`loading` tilbage → aggregat `loaded` (eller `failed` hvis ≥1 fejlede — fejlnote + `retryStructures()`), og fase 3 starter (`propertiesStatus = 'building'`, per-cvr `pending` for SYNLIGE rødder+rolle-selskaber). (b) fase 3: 3 cvr'er/tick, `fetchCompanyPropertyPortfolio` (limit 500) pr. ROD-cvr; 'building'-svar → cvr'et forbliver `building` og tæller på det DELTE budget (`$propertiesAttempts`, max **24** for hele fasen); budget opbrugt → aggregat `failed` + `retryProperties()`. Tomt svar → `empty`; fejl → `failed` for cvr'et (blokerer ikke de andre).
3. `retryStructures()`: resetter fejlede structure-cvr'er til `pending`, **og** resetter fase 3-status for de berørte cvr'er + `enrichmentStatus = 'pending'` (retry-kaskaden — ellers forbliver nye subtræer uberigede).
4. Fase 3 starter når fase 2 ikke har `pending`/`loading` — `failed`-cvr'er blokerer IKKE.
5. Hentning fortsætter uanset chip-tilstand (køerne bygges fra companies-klassifikationen, ikke fra de synlige lag) — MEN trunkerede (ikke-udvidede) first-level-selskaber deltager ikke i køerne.

- [ ] **Step 1: Failing tests** (min. 7): 3-pr-tick; per-cvr fejl blokerer ikke; fase 3 starter trods failed; delt budget → failed ved 24; retry-kaskade (fase 3 + enrichment resettes); skjult-lag-hentning fortsætter; trunkerede rødder hentes ikke.
- [ ] **Step 2: FAIL → Step 3: Implementér → Step 4: fuld suite PASS. Commit** `feat: PersonStructure fase 2+3 — pooled strukturer, ejendomme pr. rod, budgetter, retry-kaskade`.

---

### Task 8: `PersonStructure` — berigelse, rehydrering, dashed-CSS

**Files:**
- Modify: `src/Livewire/Sections/PersonStructure.php`
- Modify: `resources/views/livewire/sections/partials/ownership-graph.blade.php` (CSS: `.mgraph-edge-dashed { stroke-dasharray: 4 4; }` ved siden af den eksisterende edge-line-regel ~:137)
- Test: `tests/Feature/Livewire/Sections/PersonStructureTest.php`

**Bindende regler:**
1. `loadEnrichment()`: 2a-mønstret (gated på settled properties-aggregat + `!$enriching`; `fetchCompanyInfosPooled` for ENRICHABLE-noder i graf-modellen; `companyEnrichmentFromInfo`-logikken GENBRUGES — flyt den + `propertyEnrichmentFromBatch` + `attachStreetviewUrls` til en delt trait/service `ResolvesGraphEnrichment` så CompanyStructure og PersonStructure deler én implementering i stedet for copy-paste; CompanyStructure opdateres til at bruge den delte kode, alle 2a-tests skal forblive grønne).
2. **Batch-kaldet sender KUN matrikel-ids for ejendomme i graf-modellen** (`collect($graphModel['nodes'])->where('kind','property')` → id minus `bfe:`-prefix) — aldrig hele portefølje-listerne.
3. `rehydrateBeforeRebuild()`: partial-tolerant — companies fra cpr-cachen (hash-key); structures fra `fetchCompanyStructureCached` KUN for cvr'er hvis gemte svar var enrichment-complete (ellers: reset cvr'ets fase-status til `pending` og lad poll-loopet hente); ALDRIG mere end én ticks batch (3) hentes synkront i en interaktiv request.
4. Chips/udvid/toggle → rebuild + `graph-refit`-dispatch (host-JS lytter allerede/eller genbrug 2a's refit-mekanisme — verificér hvordan 2a trigger refit efter expand og genbrug præcis den).

- [ ] **Step 1: Failing tests** (min. 5): enrichment-batch indeholder kun in-graph-ids (Http::assertSent-inspektion af payload); financials-enhed API/pdf (genbrug 2a's to pins mod PersonStructure); rehydrate over to requests partial-tolerant (cache tom for ét cvr → status reset, ikke synkron hentning af alt); dashed-CSS-klassen findes i partial; delt enrichment-kode — 2a's CompanyStructure-tests stadig grønne.
- [ ] **Step 2: FAIL → Step 3: Implementér → Step 4: fuld suite PASS. Commit** `feat: PersonStructure berigelse (in-graph batch), partial-tolerant rehydrering, delt enrichment-kode, dashed-CSS`.

---

### Task 9: Fuld verifikation + PR-par

- [ ] **Step 1:** Fuld package-suite + `grep -r "PersonNetwork\|person-network"` = 0 hits.
- [ ] **Step 2:** PR-par oprettes (kontrolleren): host-PR (Task 1) FØRST → merge+deploy; derefter package-PR → merge → lock-bump → `view:clear`.
- [ ] **Step 3: Prod-verifikation med konsol åben** på en CPR-side (find Lars Sørensen via Lars Horsbøl Holding 40072772 → Lars Sørensen-link): begge chips prævalgt og badges korrekte; rolle-kanter stiplede; chip-fravalg/genvalg (øjeblikkelig — ingen ny hentning); progressiv vækst synlig; udvid; hover-kort med korrekte beløb (kort vs. tabel-krydstjek som 2a!); Flare-watch. Dokumentér caveats.

---

## Self-review (udført ved planskrivning)

- Spec-dækning: alle v2-afsnit har en task (skelet→T4/T6, faser→T7, berigelse/rehydrering→T8, host-JS→T1, cache→T2, sletteliste→T6, deploy-rækkefølge→T1/T9). First-level-udvid-ids implementeres i T4 regel 7 og forbruges via `$expandedNodeIds` (uændret mekanisme).
- Type-konsistens: `buildForPerson`-signaturen er identisk i spec og T4; statusstrings i T6/T7 matcher Global Constraints; caps-nøgler `person_roots`/`person_roles` bruges i T4 og `personCaps()`-helperen.
- Placeholder-scan: ingen TBD'er; hvor eksisterende kode genbruges refereres den præcist (fil + metode), og kravene står i tasken selv.
