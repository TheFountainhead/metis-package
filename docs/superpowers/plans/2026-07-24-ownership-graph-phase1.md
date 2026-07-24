# Ejerstruktur-graf Fase 1 — Implementeringsplan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Afløs det nuværende CSS-org-chart med en fri-form ejerskabs-graf (dagre) i frankston.io-stil, med zoom/pan — som første fase mod det Resights-lignende relations-diagram.

**Architecture:** Livewire-komponenten (metis-package) leverer en flad {nodes, edges}-model fra `ownershipTree()`-dataen. dagre (bundlet i host-appens Vite-build) beregner layout i en Alpine-komponent; noder renderes som absolut-positionerede frankston-kort, kanter som SVG. Zoom/pan genbruges fra org-chart-arbejdet.

**Tech Stack:** Laravel/Livewire/Alpine + Blade (metis-package); Vite + npm/dagre (host-app `metis/`); Pest for tests.

## Global Constraints

- **To repos:** JS/dagre-dependency + Vite-glue lever i **host-appen** (`TheFountainhead/metis`, har `package.json` + `vite.config.js`). Blade + Livewire-komponent + data-model lever i **pakken** (`metis-package`). Planen rører begge.
- **CSP:** dagre bundles via npm+Vite, ALDRIG CDN. Same-origin fonts (allerede fikset, `/fonts/`).
- **Frankston-stil:** bg #f6efe3, Spectral serif (selskabsnavne), IBM Plex Mono (labels), rule #b8a884, teal #3e5e63 / oxblood #7a1f1f / grøn #0a5c4a. Person-node mørk #2b2333. INGEN venstre-strimmel (AI-tell, forbudt).
- **Retning:** ejere/person ØVERST → søgt selskab NEDERST. dagre `rankdir: BT` (bottom-to-top) eller TB + flip.
- **XSS:** alle navne/CVR gennem escaped output; dagre får data via en JSON-`@js()`/`wire:` kanal, ikke rå HTML-interpolation.
- **Genbrug:** zoom/pan-mekanik (auto-fit + +/− + træk + hjul) fra org-chart-previewet; `ownershipTree()`/`buildOwnerChildren()` cycle-guard.
- Data-kontrakt uændret: `ancestors`-noderne (person_name, cvr, is_company, ownership_share, owner_kind, depth, parent_of_cvr, foreign, cycle) fra registry-api.

## File Structure

- Host-app `metis/package.json` — tilføj `dagre` (eller `@dagrejs/dagre`) dependency.
- Host-app `metis/resources/js/ownership-graph.js` (create) — Alpine-komponent: bygger dagre-graf fra data, beregner layout, håndterer zoom/pan.
- Host-app `metis/resources/js/app.js` (modify) — registrér Alpine-komponenten.
- `metis-package/src/Livewire/Sections/CompanyStructure.php` (modify) — ny metode `ownershipGraphData(): array` returnerer `{nodes:[{id,label,cvr,kind,...}], edges:[{from,to,label}]}` fra det eksisterende nested-tree.
- `metis-package/resources/views/livewire/sections/company-structure.blade.php` (modify) — erstat org-chart-blokken med graf-container (Alpine-mount + SVG + node-template).
- `metis-package/resources/views/livewire/sections/partials/graph-node.blade.php` (create) — frankston node-kort-template (person/selskab/søgt).
- `metis-package/tests/Feature/Livewire/Sections/CompanyStructureTest.php` (modify) — test `ownershipGraphData()` shape.

## Task 0 (SPIKE): Verificér dagre + Livewire/Alpine spiller sammen

**Ikke TDD — det er research. Byg det mindst mulige der beviser integrationen, FØR resten.**

**Files:** host-app `metis/` (throwaway spike-branch or scratch page)

- [ ] **Step 1:** I host-appen: `npm install dagre` (eller `@dagrejs/dagre`). Bekræft det bundler i `npm run build` uden fejl.
- [ ] **Step 2:** Lav en minimal Alpine-komponent der importerer dagre, bygger en 3-node-graf (A→B→C), kører `dagre.layout()`, og logger node-positionerne. Mount den på en scratch-Blade-side.
- [ ] **Step 3:** Kør `npm run build` + åbn siden. Bekræft i browser-console at dagre gav x/y-koordinater OG at komponenten overlever en Livewire DOM-morph (trigger en `wire:` opdatering på siden og se at grafen re-layouter, ikke dør).
- [ ] **Step 4:** DOKUMENTÉR resultatet: virker dagre+Livewire? Hvis Livewire's morphdom ødelægger dagre's DOM, notér fallback (wrap grafen i `wire:ignore`, re-layout via Alpine `$watch` på data). Dette afgør Task 2's tilgang.
- [ ] **Step 5:** Fjern spike-koden (throwaway). Commit KUN `package.json`+lockfile-tilføjelsen af dagre hvis den er ren.

**GATE:** Præsentér spike-resultatet til bruger før Task 1. Hvis dagre ikke spiller pænt, revidér planen (elkjs eller manuel layout).

## Task 1: `ownershipGraphData()` — flad graf-model fra nested tree

**Files:**
- Modify: `metis-package/src/Livewire/Sections/CompanyStructure.php`
- Test: `metis-package/tests/Feature/Livewire/Sections/CompanyStructureTest.php`

**Interfaces:**
- Produces: `ownershipGraphData(): array` → `['nodes' => [['id'=>string,'label'=>string,'cvr'=>?string,'kind'=>'person'|'legal'|'reel'|'foreign'|'searched','share'=>?float]], 'edges' => [['from'=>string,'to'=>string,'label'=>string]]]`. `id` = cvr for selskaber, `person:<md5>` for personer, `searched` for det søgte. `edges.label` = ejerandels-interval.

- [ ] **Step 1: Write the failing test**

```php
it('builds a flat graph model with nodes and edges from ancestors', function () {
    Http::fake([
        '*cvr/company-structure*' => Http::response(['data' => [
            'name' => 'OpCo', 'owners' => [], 'subsidiaries' => [],
            'ancestors' => [
                ['person_name'=>'HoldCo ApS','cvr'=>'20000002','is_company'=>true,'ownership_share'=>100.0,'owner_kind'=>'legal','depth'=>1,'parent_of_cvr'=>null,'foreign'=>false,'cycle'=>false,'enriching'=>false],
                ['person_name'=>'Top Ejer','cvr'=>null,'is_company'=>false,'ownership_share'=>100.0,'owner_kind'=>'reel','depth'=>2,'parent_of_cvr'=>'20000002','foreign'=>false,'cycle'=>false,'enriching'=>false],
            ],
        ]]),
        '*cvr/company/*' => Http::response(['data'=>['company'=>['name'=>'OpCo','owners'=>[]]]]),
        '*enrichment*' => Http::response(['data'=>['status'=>'completed']]),
    ]);

    $g = Livewire::test(CompanyStructure::class, ['query'=>'20000001'])->instance()->ownershipGraphData();

    // searched company is a node; HoldCo + Top Ejer are nodes
    $ids = collect($g['nodes'])->pluck('id');
    expect($ids)->toContain('searched');       // the searched company
    expect($ids)->toContain('20000002');       // HoldCo (cvr id)
    expect(collect($g['nodes'])->firstWhere('kind','person'))->not->toBeNull(); // Top Ejer
    // edges connect owner -> owned
    expect(collect($g['edges'])->firstWhere('to','searched')['from'])->toBe('20000002'); // HoldCo owns searched
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Livewire/Sections/CompanyStructureTest.php --filter="flat graph model" -v`
Expected: FAIL — `ownershipGraphData()` undefined.

- [ ] **Step 3: Implement**

Add `ownershipGraphData()` to `CompanyStructure`. Reuse `ownershipTree()` (already builds the nested structure from `ancestors` via `parent_of_cvr`). Walk the tree, emitting one node per owner (id = cvr or `person:`+md5(name), kind from owner_kind, `foreign`→kind 'foreign') plus a `searched` node for the queried company. For each parent→child edge, emit `['from'=>ownerId,'to'=>ownedId,'label'=>intervalFor($share)]`. Add a private `intervalFor(?float $s): string` returning the CVR interval band (`'20-24,99%'` etc.) or the exact `%` if you prefer — match the spec's "interval on edges". Dedup nodes by id (cycle-guard already prevents infinite recursion). Persons are leaves.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Feature/Livewire/Sections/CompanyStructureTest.php --filter="flat graph model" -v`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Livewire/Sections/CompanyStructure.php tests/Feature/Livewire/Sections/CompanyStructureTest.php
git commit -m "feat(graph): ownershipGraphData — flat node/edge model for the graph render"
```

## Task 2: dagre Alpine-komponent (host-app) — layout + render

**Files:**
- Create: host-app `metis/resources/js/ownership-graph.js`
- Modify: host-app `metis/resources/js/app.js` (register Alpine component)

**Interfaces:**
- Consumes: `{nodes, edges}` from Task 1 (passed via `x-data` / `@js()` in the blade).
- Produces: an Alpine component `ownershipGraph` that on init runs dagre layout and exposes `nodes` (with x/y), `edges` (with points), `scale`, `tx`, `ty` for the template.

- [ ] **Step 1:** Write `ownership-graph.js` — Alpine component factory `ownershipGraph({nodes, edges})`. On `init()`: build a `dagre.graphlib.Graph`, set `rankdir` per spike result (BT so owners land on top), add nodes (with measured w/h — use fixed 200×54 for now, refine later), add edges, `dagre.layout(g)`, then read back x/y into `this.positioned`. Include the zoom/pan methods from the org-chart preview (`fit()`, `zoomBy()`, drag, wheel).
- [ ] **Step 2:** Register in `app.js`: `import {ownershipGraph} from './ownership-graph'; Alpine.data('ownershipGraph', ownershipGraph);` (respect however this metis app wires Alpine — check existing `app.js`).
- [ ] **Step 3:** `npm run build` — confirm no bundle error, dagre included.
- [ ] **Step 4:** Commit (host-app):
```bash
git add resources/js/ownership-graph.js resources/js/app.js package.json package-lock.json
git commit -m "feat(graph): dagre Alpine component for ownership-graph layout + zoom/pan"
```

## Task 3: Graf-render Blade — frankston node-kort + SVG-kanter

**Files:**
- Modify: `metis-package/resources/views/livewire/sections/company-structure.blade.php` (replace org-chart block)
- Create: `metis-package/resources/views/livewire/sections/partials/graph-node.blade.php`
- Test: `metis-package/tests/Feature/Livewire/Sections/CompanyStructureTest.php`

**Interfaces:**
- Consumes: `ownershipGraphData()` (Task 1) mounted into the Alpine `ownershipGraph` (Task 2).

- [ ] **Step 1: Write the failing test**

```php
it('renders the ownership graph container with node data and no left-stripe', function () {
    Http::fake([
        '*cvr/company-structure*' => Http::response(['data' => [
            'name'=>'OpCo','owners'=>[],'subsidiaries'=>[],
            'ancestors'=>[['person_name'=>'HoldCo ApS','cvr'=>'20000002','is_company'=>true,'ownership_share'=>100.0,'owner_kind'=>'legal','depth'=>1,'parent_of_cvr'=>null,'foreign'=>false,'cycle'=>false,'enriching'=>false]],
        ]]),
        '*cvr/company/*' => Http::response(['data'=>['company'=>['name'=>'OpCo','owners'=>[]]]]),
        '*enrichment*' => Http::response(['data'=>['status'=>'completed']]),
    ]);

    $html = Livewire::test(CompanyStructure::class, ['query'=>'20000001'])->html();
    // graph container present, mounts Alpine with data
    expect($html)->toContain('x-data="ownershipGraph');
    expect($html)->toContain('HoldCo ApS');
    // frankston, no banned left-stripe
    expect($html)->not->toMatch('/\.graph-node\s*\{[^}]*border-left:\s*[3-9]/');
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/pest tests/Feature/Livewire/Sections/CompanyStructureTest.php --filter="renders the ownership graph" -v`
Expected: FAIL — no `ownershipGraph` container yet.

- [ ] **Step 3: Implement**

Replace the org-chart block in `company-structure.blade.php` with a graph container: a scroll/zoom wrapper `<div x-data="ownershipGraph(@js($this->ownershipGraphData()))" ...>` containing an SVG layer for edges and an absolute-positioned layer that `x-for`s over `positioned` nodes, each rendering `@include('metis::...graph-node', ...)`. Add the frankston CSS (scoped `.mgraph-*` classes: card, person/co/prop/searched variants, mono labels, NO left-stripe). Node template `graph-node.blade.php`: person (dark), company (CVR+branche rows), searched (thick ink border), all names via `{{ }}`. Edge labels (intervals) as small sand pills at edge midpoints. Include the +/−/Tilpas controls + zoom label. Wrap in `wire:ignore` if the spike showed morphdom conflicts.

- [ ] **Step 4: Run to verify it passes + full suite**

Run: `vendor/bin/pest tests/Feature/Livewire/Sections/CompanyStructureTest.php -v`
Expected: PASS; existing structure tests still green (or updated to the graph structure — don't gut assertions, update them to assert the graph shows the owner).

- [ ] **Step 5: Commit**

```bash
git add resources/views/livewire/sections/company-structure.blade.php resources/views/livewire/sections/partials/graph-node.blade.php tests/Feature/Livewire/Sections/CompanyStructureTest.php
git commit -m "feat(graph): render ownership graph (frankston nodes + SVG edges + zoom/pan)"
```

## Task 4: Browser-verificér + manuel test

- [ ] **Step 1:** Build host-app assets (`npm run build`). Render the CompanyStructure component with a Resights-like fixture (or against a demo/staging Metis if available). Screenshot with headless Chrome → `/tmp/graph-phase1.png`. Confirm: owners on top, searched company at bottom, dagre layout (no overlaps), frankston style, intervals on edges, zoom/pan works.
- [ ] **Step 2:** Test against the real large case (BDO Finans, 85 owners) if reachable — confirm dagre lays it out and zoom/pan makes it navigable, no overlap.
- [ ] **Step 3:** Confirm no console errors, no CSP violations (dagre bundled, fonts same-origin).

## Self-Review

- **Spec coverage:** fri-form graf (Task 2-3), dagre (Task 0 spike + Task 2), person-øverst (rankdir BT, Task 2), rig node-info (Task 1 model + Task 3 template), intervaller på kanter (Task 1 `intervalFor` + Task 3 edge labels), zoom/pan (Task 2-3, genbrug), frankston-stil (Task 3), afløser org-chart (Task 3 replaces block). Ejendomme/aktieposter = fase 2-3, out of scope her. Alt fase-1-scope dækket.
- **Placeholder scan:** ingen TBD; hvert kode-trin har konkret kode eller præcis instruktion. Task 0 er bevidst research (spike), ikke TDD — flagget som sådan.
- **Type consistency:** `ownershipGraphData()` node/edge-shape ens i Task 1 (produce), Task 2 (consume), Task 3 (render). `id`-konvention (cvr / person:md5 / searched) konsistent. `kind`-værdier ('person'|'legal'|'reel'|'foreign'|'searched') ens gennem model→komponent→template.
- **Cross-repo:** Task 0+2 host-app (npm/dagre/Alpine), Task 1+3 pakke (PHP/Blade/test). Eksplicit markeret pr. task.
