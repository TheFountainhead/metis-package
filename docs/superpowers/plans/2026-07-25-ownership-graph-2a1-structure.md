# Ejer-relations-graf 2a.1 (struktur) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Selskabs-opslagets graf viser hele Resights-billedet: ejere opad + datterselskaber nedad (2 niveauer, udvid for mere) + ejendoms-noder (BFE/adresse/anvendelse, cap 6 pr. selskab, async) — og den gamle DATTERSELSKABER-sektion fjernes.

**Architecture:** Al graf-sammensætning flyttes til en ny ren, deklarativ `OwnershipGraphBuilder` (`build(...)` er en ren funktion af input — ALLE stier genbygger `graphModel` gennem den; ingen sti appender direkte). Ejendomme hentes async efter first paint (portfolio + properties/batch), merget via eksisterende `$wire.$watch('graphModel')`-bro. Host-appens dagre-JS får per-node-type dimensioner.

**Tech Stack:** Laravel/Livewire 3 (metis-package), Alpine + @dagrejs/dagre (metis host-app), Pest-tests.

**Spec:** `docs/superpowers/specs/2026-07-25-ownership-graph-phase2-design.md` (v3, commit `67644c3`)

## Global Constraints

- **TO repos:** metis-package (`/Users/Frederik/Code/metis-package`, branch `feat/ownership-graph-phase2`) + metis host-app (`/Users/Frederik/Herd/metis`). Koordineret deploy; rollback = revert BEGGE PR'er.
- **registry-api ændres IKKE** (`/Users/Frederik/Herd/registry-api` er kun reference-læsning).
- Caps: `subsidiary_depth: 2`, `properties_per_company: 6`, `total_nodes: 120`. Trunkering i builderen FØR graphModel; prioritet: ejendomme skæres først, så dybeste datter-lag, aldrig ejer-kæden.
- Node-id'er: CVR for selskaber, `person:<md5(i|navn|parent)>` for personer, **`bfe:{matrikel_id}`** for ejendomme, `searched` for centrum.
- Kant-label: **eksakt tal som i dag** (`shareLabel`-format). Interval-bånd er en SEPARAT follow-up gated på Task 13's verifikation — implementér IKKE bånd-mapning i denne plan.
- Fejl ≠ tom: fejlet/building ejendoms-kald må aldrig ligne "ingen ejendomme".
- Alpine-tekst bindes med `x-text`/`escapeXml` (aldrig rå HTML). `<template x-for>` må ALDRIG ind i `<svg>` (blank graf — fase 1-lektie).
- Efter Blade-ændringer på prod: `php artisan view:clear` (atomic deploy deler compiled views). Prod-verifikation sker med browser-KONSOLLEN åben.
- Prod-deploy kræver PROD-OVERRIDE-flow; Forge-API-mønstre jf. memory.

## Verificerede prod-fakta (25/7 — brugt af planen)

- `POST /v1/cvr/company-structure {cvr}` → `data.{owners, subsidiaries, ancestors}`; `subsidiaries[]` er **nested**: `{cvr, name, company_type, ownership_share, children[]}`.
- `GET /v1/company/{cvr}/property-portfolio` → `data.portfolio.{owner_cvr, property_count, total_valuation, valuation_coverage{with_valuation,total}, total_debt, source: "koncern_bfe", properties[]}`; pr. ejendom: `{owner_cvr, owner_name, matrikel_id, is_matriculated, address, city, postal_code, latitude, longitude, valuation, total_debt, depth, property_id, building_area, building_year, land_value, total_area}`. **`matrikel_id` ER BFE-nummeret når `is_matriculated=true`** (ellers DAWA-slug). **Første kald kan returnere `properties: []` (building) — andet kald fuldt.** INGEN anvendelse/BFE-felt-navne herudover.
- `POST /v1/properties/batch {matrikel_ids: [...]}` (max 200) → pr. ejendom `{matrikel_id, bbr: {building_count, buildings: [{usage, usage_label, total_area, year_built, floors, units}]}, latest_transaction, street_view_url (kan være null), mortgages, valuation, ...}`. **Anvendelse = `bbr.buildings[].usage`-koden** → `BbrUsageCategory::label()` (`src/Services/BbrUsageCategory.php:32`, `public static function label(string|int|null $usage): ?string`).

## File Structure

**metis-package:**
- Create: `src/Services/OwnershipGraphBuilder.php` — al {nodes, edges}-sammensætning (ren funktion, ingen HTTP)
- Create: `tests/Unit/OwnershipGraphBuilderTest.php`
- Modify: `src/Services/RegistryApi.php` — `fetchCompanyStructureCached()`, `fetchPropertiesBatch()`, cache på `fetchCompanyInfo()`
- Modify: `src/Livewire/Sections/CompanyStructure.php` — builder-integration, state-sanering, async ejendomme, udvid-actions; `toggleOwnerExpansion`/`expandedOwners` FJERNES
- Create: `resources/views/livewire/sections/partials/ownership-graph.blade.php` — delt graf-partial (frame + controls + CSS), udtrukket af company-structure.blade.php
- Modify: `resources/views/livewire/sections/company-structure.blade.php` — brug partial; FJERN DATTERSELSKABER-sektionen + "Udfold struktur"
- Modify: `resources/views/livewire/sections/partials/graph-node.blade.php` — property-variant, meta-rækker, udvid-knapper
- Modify: `tests/Feature/CompanyStructureTest.php` (eller tilsvarende eksisterende komponent-test — find med `grep -rl "CompanyStructure" tests/`)

**metis host-app:**
- Modify: `resources/js/ownership-graph.js` — per-kind node-dimensioner + klik-vs-pan-tærskel

---

### Task 1: OwnershipGraphBuilder-skelet — portér fase 1-logikken uændret

**Files:**
- Create: `src/Services/OwnershipGraphBuilder.php`
- Create: `tests/Unit/OwnershipGraphBuilderTest.php`

**Interfaces:**
- Produces: `OwnershipGraphBuilder::build(string $query, ?string $companyName, array $structure, array $properties, array $enrichment, array $expandedNodeIds, array $caps): array{nodes: list<array>, edges: list<array>}` — `$structure = ['ancestors'=>[], 'subsidiaries'=>[]]`; `$properties = ['list'=>[], 'usage'=>[]]` (tom i denne task); `$enrichment` RESERVERET (tom array, ubrugt i 2a.1); `$caps = ['subsidiary_depth'=>2,'properties_per_company'=>6,'total_nodes'=>120]`. Node-shape: `{id, label, cvr, kind, share, expand: ?{relations: int, properties: int}}`.
- Consumes: fase 1-logik fra `CompanyStructure::ownershipGraphData()` (`src/Livewire/Sections/CompanyStructure.php:218-321`) — kopiér den derfra; komponenten omlægges først i Task 8.

- [ ] **Step 1: Skriv failing test for fase 1-paritet**

```php
<?php
// tests/Unit/OwnershipGraphBuilderTest.php
use TheFountainhead\Metis\Services\OwnershipGraphBuilder;

function buildGraph(array $overrides = []): array
{
    $builder = new OwnershipGraphBuilder;

    return $builder->build(
        query: $overrides['query'] ?? '38653806',
        companyName: $overrides['companyName'] ?? 'FDL-Invest ApS',
        structure: $overrides['structure'] ?? ['ancestors' => [], 'subsidiaries' => []],
        properties: $overrides['properties'] ?? ['list' => [], 'usage' => []],
        enrichment: [],
        expandedNodeIds: $overrides['expandedNodeIds'] ?? [],
        caps: $overrides['caps'] ?? ['subsidiary_depth' => 2, 'properties_per_company' => 6, 'total_nodes' => 120],
    );
}

it('builds the searched node alone for empty input', function () {
    $g = buildGraph();

    expect($g['nodes'])->toHaveCount(1)
        ->and($g['nodes'][0]['id'])->toBe('searched')
        ->and($g['nodes'][0]['label'])->toBe('FDL-Invest ApS')
        ->and($g['edges'])->toBeEmpty();
});

it('builds ancestor nodes and edges to the searched node', function () {
    $g = buildGraph(['structure' => ['ancestors' => [
        ['person_name' => 'Frederik G D Larnæs', 'is_company' => false, 'cvr' => null, 'ownership_share' => 100.0, 'parent_of_cvr' => null],
    ], 'subsidiaries' => []]]);

    expect($g['nodes'])->toHaveCount(2)
        ->and($g['nodes'][1]['kind'])->toBe('person')
        ->and($g['edges'][0])->toMatchArray(['to' => 'searched', 'label' => '100 %']);
});

it('never collapses two same-named persons owning the same company', function () {
    $rows = [
        ['person_name' => 'Jens Hansen', 'is_company' => false, 'cvr' => null, 'ownership_share' => 50.0, 'parent_of_cvr' => null],
        ['person_name' => 'Jens Hansen', 'is_company' => false, 'cvr' => null, 'ownership_share' => 50.0, 'parent_of_cvr' => null],
    ];
    $g = buildGraph(['structure' => ['ancestors' => $rows, 'subsidiaries' => []]]);

    expect($g['nodes'])->toHaveCount(3)->and($g['edges'])->toHaveCount(2);
});

it('synthesises a stub node for a pruned parent cvr', function () {
    $g = buildGraph(['structure' => ['ancestors' => [
        ['person_name' => 'Holding ApS', 'is_company' => true, 'cvr' => '11111111', 'ownership_share' => 100.0, 'parent_of_cvr' => '99999999'],
    ], 'subsidiaries' => []]]);

    $stub = collect($g['nodes'])->firstWhere('id', '99999999');
    expect($stub)->not->toBeNull()->and($stub['kind'])->toBe('other');
});
```

- [ ] **Step 2: Kør testen — verificér FAIL** — `cd /Users/Frederik/Code/metis-package && vendor/bin/pest tests/Unit/OwnershipGraphBuilderTest.php` → FAIL: class not found.

- [ ] **Step 3: Implementér builderen (portér fase 1-koden ordret hvor muligt)**

```php
<?php
// src/Services/OwnershipGraphBuilder.php

namespace TheFountainhead\Metis\Services;

/**
 * Builds the flat {nodes, edges} model for the ownership graph.
 *
 * PURE + DECLARATIVE: same input → same output, no HTTP, no side effects.
 * Every code path (mount, enrichment poll, property fetch, expand click)
 * REBUILDS the model through this class — nothing ever appends to the
 * model directly, because pollForUpdates() rebuilds from source and would
 * silently wipe any appended state (review finding, spec v3).
 *
 * $enrichment is RESERVED for fase 2a.2 (per-cvr hover-card data) so the
 * signature never changes between the two PRs. It is unused here.
 */
class OwnershipGraphBuilder
{
    public function build(
        string $query,
        ?string $companyName,
        array $structure,
        array $properties,
        array $enrichment,
        array $expandedNodeIds,
        array $caps,
    ): array {
        $nodes = [[
            'id' => 'searched',
            'label' => $companyName ?? __('Searched company'),
            'cvr' => $query, 'kind' => 'searched', 'share' => null, 'expand' => null,
        ]];
        $seen = ['searched' => true];
        $edges = [];
        $edgeSeen = [];

        $this->addAncestors($structure['ancestors'] ?? [], $query, $nodes, $seen, $edges, $edgeSeen);
        // Tasks 2+4 tilføjer addSubsidiaries()/addProperties() her.

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    protected function addAncestors(array $ancestors, string $query, array &$nodes, array &$seen, array &$edges, array &$edgeSeen): void
    {
        foreach ($ancestors as $i => $a) {
            $isCompany = $a['is_company'] ?? false;
            $foreign = $a['foreign'] ?? false;
            $cvr = $a['cvr'] ?? null;
            // Row index folded in so two distinct same-named persons never collapse (fase 1).
            $id = $cvr ?: 'person:'.md5($i.'|'.($a['person_name'] ?? '').'|'.($a['parent_of_cvr'] ?? ''));
            $kind = $foreign ? 'foreign' : (! $isCompany ? 'person' : ($a['owner_kind'] ?? 'legal'));

            if (! isset($seen[$id])) {
                $seen[$id] = true;
                $nodes[] = ['id' => $id, 'label' => $a['person_name'] ?? '', 'cvr' => $cvr, 'kind' => $kind, 'share' => $a['ownership_share'] ?? null, 'expand' => null];
            }

            $ownedId = $this->ownedTargetId($a['parent_of_cvr'] ?? null, $query);
            if (! isset($edgeSeen[$id.'|'.$ownedId])) {
                $edgeSeen[$id.'|'.$ownedId] = true;
                $edges[] = ['from' => $id, 'to' => $ownedId, 'label' => $this->shareLabel($a['ownership_share'] ?? null)];
            }
        }

        // Orphan-parent stubs (fase 1): keep the chain connected when a parent was pruned upstream.
        foreach ($ancestors as $a) {
            $parent = $this->ownedTargetId($a['parent_of_cvr'] ?? null, $query);
            if ($parent !== 'searched' && ! isset($seen[$parent])) {
                $seen[$parent] = true;
                $nodes[] = ['id' => $parent, 'label' => 'CVR '.$parent, 'cvr' => $parent, 'kind' => 'other', 'share' => null, 'expand' => null];
            }
        }
    }

    protected function ownedTargetId(?string $parentOfCvr, string $query): string
    {
        return ($parentOfCvr === null || $parentOfCvr === $query) ? 'searched' : $parentOfCvr;
    }

    /**
     * Exact percentage, unchanged from fase 1. Interval bands are a separate
     * follow-up GATED on the CVR interval-vs-exact verification (plan Task 13)
     * — do not add band mapping here.
     */
    protected function shareLabel(?float $share): string
    {
        if ($share === null) {
            return '';
        }

        return (fmod($share, 1.0) === 0.0 ? (string) (int) $share : rtrim(rtrim(number_format($share, 2, ',', ''), '0'), ',')).' %';
    }
}
```

- [ ] **Step 4: Kør testen — verificér PASS** — `vendor/bin/pest tests/Unit/OwnershipGraphBuilderTest.php` → 4 passed.
- [ ] **Step 5: Commit** — `git add src/Services/OwnershipGraphBuilder.php tests/Unit/OwnershipGraphBuilderTest.php && git commit -m "feat(graph): OwnershipGraphBuilder — fase 1-logik som ren, deklarativ funktion"`

---

### Task 2: Datterselskabs-laget (nested træ, dybde-trunkering, N-tal)

**Files:**
- Modify: `src/Services/OwnershipGraphBuilder.php`
- Test: `tests/Unit/OwnershipGraphBuilderTest.php`

**Interfaces:**
- Consumes: `subsidiaries[]` nested shape (verificeret): `{cvr, name, company_type, ownership_share, children[]}`.
- Produces: datter-noder `kind: 'subsidiary'`; kant `searched→datter` osv. (retning ejer→ejet, dvs. FRA moderselskab TIL datter — vent: ejerskabskanter løber ejer→ejet, og moderselskabet EJER datteren, så kanten er `from: parent, to: child`? NEJ — fase 1's kanter løber ejer→ejet med ejeren ØVERST (TB-layout). Datterselskaber skal ligge UNDER, så kanten løber `from: parentCvr, to: childCvr` — samme retning, dagre placerer dem korrekt nedad). `expand.relations` sættes på noder med afskårne børn.

- [ ] **Step 1: Failing tests**

```php
it('adds subsidiaries two levels deep with edges parent→child', function () {
    $subs = [[
        'cvr' => '44507781', 'name' => 'Kirketorvet Ejendomme ApS', 'ownership_share' => 50.0,
        'children' => [[
            'cvr' => '44018942', 'name' => 'Trygve 1 ApS', 'ownership_share' => 100.0,
            'children' => [[
                'cvr' => '44027992', 'name' => 'Schneidereit Trygve 1 A/S', 'ownership_share' => 67.0, 'children' => [],
            ]],
        ]],
    ]];
    $g = buildGraph(['structure' => ['ancestors' => [], 'subsidiaries' => $subs]]);

    $ids = collect($g['nodes'])->pluck('id');
    expect($ids)->toContain('44507781')->toContain('44018942')
        ->and($ids)->not->toContain('44027992')                       // level 3 truncated (depth cap 2)
        ->and(collect($g['edges'])->firstWhere('to', '44507781')['from'])->toBe('searched')
        ->and(collect($g['edges'])->firstWhere('to', '44018942')['from'])->toBe('44507781');

    $trygve1 = collect($g['nodes'])->firstWhere('id', '44018942');
    expect($trygve1['expand']['relations'])->toBe(1);                 // 1 hidden child signalled
});

it('marks subsidiary nodes with kind subsidiary and labels edges with the share', function () {
    $g = buildGraph(['structure' => ['ancestors' => [], 'subsidiaries' => [
        ['cvr' => '45170209', 'name' => 'Inova ApS', 'ownership_share' => 100.0, 'children' => []],
    ]]]);

    $node = collect($g['nodes'])->firstWhere('id', '45170209');
    expect($node['kind'])->toBe('subsidiary')
        ->and(collect($g['edges'])->firstWhere('to', '45170209')['label'])->toBe('100 %');
});

it('dedups a subsidiary that is also an ancestor node', function () {
    $g = buildGraph(['structure' => [
        'ancestors' => [['person_name' => 'Loop ApS', 'is_company' => true, 'cvr' => '22222222', 'ownership_share' => 10.0, 'parent_of_cvr' => null]],
        'subsidiaries' => [['cvr' => '22222222', 'name' => 'Loop ApS', 'ownership_share' => 5.0, 'children' => []]],
    ]]);

    expect(collect($g['nodes'])->where('id', '22222222'))->toHaveCount(1);
});
```

- [ ] **Step 2: Kør — FAIL** (`expand`-nøgle findes ikke / noder mangler).
- [ ] **Step 3: Implementér `addSubsidiaries()` (kaldes fra `build()` efter `addAncestors`)**

```php
    protected function addSubsidiaries(array $subs, string $parentId, int $depth, int $maxDepth, array $expandedNodeIds, array &$nodes, array &$seen, array &$edges, array &$edgeSeen): void
    {
        foreach ($subs as $s) {
            $cvr = $s['cvr'] ?? null;
            if (! $cvr) {
                continue;
            }
            $children = $s['children'] ?? [];
            // Beyond the depth cap the node itself is NOT rendered; its parent
            // carries expand.relations instead (handled by the caller's count).
            if (! isset($seen[$cvr])) {
                $seen[$cvr] = true;
                $nodes[] = ['id' => $cvr, 'label' => $s['name'] ?? ('CVR '.$cvr), 'cvr' => $cvr, 'kind' => 'subsidiary', 'share' => $s['ownership_share'] ?? null, 'expand' => null];
            }
            if (! isset($edgeSeen[$parentId.'|'.$cvr])) {
                $edgeSeen[$parentId.'|'.$cvr] = true;
                $edges[] = ['from' => $parentId, 'to' => $cvr, 'label' => $this->shareLabel($s['ownership_share'] ?? null)];
            }

            $expandedHere = in_array('sub:'.$cvr, $expandedNodeIds, true);
            if ($depth < $maxDepth || $expandedHere) {
                // Expanded nodes recurse one extra level per expansion; passing
                // maxDepth+1 down keeps grandchildren gated behind their own expand.
                $this->addSubsidiaries($children, $cvr, $depth + 1, $expandedHere ? $depth + 1 : $maxDepth, $expandedNodeIds, $nodes, $seen, $edges, $edgeSeen);
            } elseif (($n = count($children)) > 0) {
                // Signal hidden children on THIS node (find it and set expand).
                foreach ($nodes as &$node) {
                    if ($node['id'] === $cvr) {
                        $node['expand'] = ['relations' => $n, 'properties' => $node['expand']['properties'] ?? 0];
                        break;
                    }
                }
                unset($node);
            }
        }
    }
```

Kald i `build()` efter ancestors: `$this->addSubsidiaries($structure['subsidiaries'] ?? [], 'searched', 1, $caps['subsidiary_depth'], $expandedNodeIds, $nodes, $seen, $edges, $edgeSeen);`

- [ ] **Step 4: Kør ALLE builder-tests — PASS.**
- [ ] **Step 5: Commit** — `git commit -am "feat(graph): datterselskabs-lag i builderen — 2 niveauer + udvid-signal"`

---

### Task 3: `sub:`-udvid via expandedNodeIds (idempotens)

**Files:** samme som Task 2.

- [ ] **Step 1: Failing tests**

```php
it('renders a hidden third level when its parent is expanded', function () {
    $subs = [['cvr' => '44507781', 'name' => 'A', 'ownership_share' => 50.0, 'children' => [
        ['cvr' => '44018942', 'name' => 'B', 'ownership_share' => 100.0, 'children' => [
            ['cvr' => '44027992', 'name' => 'C', 'ownership_share' => 67.0, 'children' => []],
        ]],
    ]]];
    $g = buildGraph(['structure' => ['ancestors' => [], 'subsidiaries' => $subs], 'expandedNodeIds' => ['sub:44018942']]);

    expect(collect($g['nodes'])->pluck('id'))->toContain('44027992');
});

it('is idempotent: duplicate expand ids change nothing', function () {
    $subs = [['cvr' => '44507781', 'name' => 'A', 'ownership_share' => 50.0, 'children' => [
        ['cvr' => '44018942', 'name' => 'B', 'ownership_share' => 100.0, 'children' => []],
    ]]];
    $once = buildGraph(['structure' => ['ancestors' => [], 'subsidiaries' => $subs], 'expandedNodeIds' => ['sub:44507781']]);
    $twice = buildGraph(['structure' => ['ancestors' => [], 'subsidiaries' => $subs], 'expandedNodeIds' => ['sub:44507781', 'sub:44507781']]);

    expect($twice)->toEqual($once);
});
```

- [ ] **Step 2: Kør — første test bør allerede PASSe fra Task 2's implementering; fixér indtil begge er grønne** (dobbelt-id-testen kræver at `in_array`-tjekket er eneste sted settet læses — det er det).
- [ ] **Step 3: Kør alle tests — PASS. Commit** — `git commit -am "test(graph): sub:-udvid + idempotens"`

---

### Task 4: Ejendoms-laget (bfe:-noder, owner_cvr-hængning, cap + props:-udvid, anvendelse)

**Files:**
- Modify: `src/Services/OwnershipGraphBuilder.php`
- Test: `tests/Unit/OwnershipGraphBuilderTest.php`

**Interfaces:**
- Consumes: `$properties = ['list' => <portfolio properties[]>, 'usage' => <matrikel_id => usage_label string|null>]` (usage-mappen bygges i Task 7 fra properties/batch).
- Produces: ejendoms-noder `{id: 'bfe:'.$matrikelId, label: adresse ?: 'BFE '.$matrikelId, cvr: null, kind: 'property', share: null, meta: {bfe: ?string, usage: ?string}, expand: null}`; kant `from: owner_cvr-node, to: bfe-node, label: ''`. Ejendomme hvis `owner_cvr` IKKE er en node i grafen udelades (spec: vises først når ejeren udvides ind).

- [ ] **Step 1: Failing tests**

```php
function fdlProperty(array $overrides = []): array
{
    return array_merge([
        'owner_cvr' => '44507781', 'matrikel_id' => '2573669', 'is_matriculated' => true,
        'address' => 'Kongshøjvej 2', 'city' => 'Store Heddinge', 'valuation' => 534000, 'depth' => 1,
    ], $overrides);
}

it('hangs property nodes on their owning company with bfe: ids', function () {
    $subs = [['cvr' => '44507781', 'name' => 'Kirketorvet Ejendomme ApS', 'ownership_share' => 50.0, 'children' => []]];
    $g = buildGraph([
        'structure' => ['ancestors' => [], 'subsidiaries' => $subs],
        'properties' => ['list' => [fdlProperty()], 'usage' => ['2573669' => 'Fritliggende enfamiliehus']],
    ]);

    $prop = collect($g['nodes'])->firstWhere('id', 'bfe:2573669');
    expect($prop)->not->toBeNull()
        ->and($prop['kind'])->toBe('property')
        ->and($prop['label'])->toBe('Kongshøjvej 2')
        ->and($prop['meta'])->toMatchArray(['bfe' => '2573669', 'usage' => 'Fritliggende enfamiliehus'])
        ->and(collect($g['edges'])->firstWhere('to', 'bfe:2573669')['from'])->toBe('44507781');
});

it('drops properties whose owner is not in the graph', function () {
    $g = buildGraph(['properties' => ['list' => [fdlProperty(['owner_cvr' => '99999999'])], 'usage' => []]]);

    expect(collect($g['nodes'])->pluck('id'))->not->toContain('bfe:2573669');
});

it('caps properties per company at 6 and signals the rest via expand.properties', function () {
    $subs = [['cvr' => '44507781', 'name' => 'K', 'ownership_share' => 50.0, 'children' => []]];
    $props = collect(range(1, 9))->map(fn ($i) => fdlProperty(['matrikel_id' => (string) (1000000 + $i), 'address' => 'Vej '.$i]))->all();
    $g = buildGraph(['structure' => ['ancestors' => [], 'subsidiaries' => $subs], 'properties' => ['list' => $props, 'usage' => []]]);

    expect(collect($g['nodes'])->where('kind', 'property'))->toHaveCount(6)
        ->and(collect($g['nodes'])->firstWhere('id', '44507781')['expand']['properties'])->toBe(3);
});

it('lifts the property cap for a props:-expanded company', function () {
    $subs = [['cvr' => '44507781', 'name' => 'K', 'ownership_share' => 50.0, 'children' => []]];
    $props = collect(range(1, 9))->map(fn ($i) => fdlProperty(['matrikel_id' => (string) (1000000 + $i)]))->all();
    $g = buildGraph([
        'structure' => ['ancestors' => [], 'subsidiaries' => $subs],
        'properties' => ['list' => $props, 'usage' => []],
        'expandedNodeIds' => ['props:44507781'],
    ]);

    expect(collect($g['nodes'])->where('kind', 'property'))->toHaveCount(9);
});

it('dedups a property owned by two graph companies into one node with two edges', function () {
    $subs = [
        ['cvr' => '44507781', 'name' => 'K', 'ownership_share' => 50.0, 'children' => []],
        ['cvr' => '45170209', 'name' => 'I', 'ownership_share' => 100.0, 'children' => []],
    ];
    $props = [fdlProperty(), fdlProperty(['owner_cvr' => '45170209'])];
    $g = buildGraph(['structure' => ['ancestors' => [], 'subsidiaries' => $subs], 'properties' => ['list' => $props, 'usage' => []]]);

    expect(collect($g['nodes'])->where('kind', 'property'))->toHaveCount(1)
        ->and(collect($g['edges'])->where('to', 'bfe:2573669'))->toHaveCount(2);
});

it('falls back to BFE-number label when address is missing and omits missing usage', function () {
    $subs = [['cvr' => '44507781', 'name' => 'K', 'ownership_share' => 50.0, 'children' => []]];
    $g = buildGraph([
        'structure' => ['ancestors' => [], 'subsidiaries' => $subs],
        'properties' => ['list' => [fdlProperty(['address' => null])], 'usage' => []],
    ]);

    $prop = collect($g['nodes'])->firstWhere('kind', 'property');
    expect($prop['label'])->toBe('BFE 2573669')->and($prop['meta']['usage'])->toBeNull();
});
```

- [ ] **Step 2: Kør — FAIL.**
- [ ] **Step 3: Implementér `addProperties()` (kaldes SIDST i `build()`, efter subsidiaries — noderne skal findes for owner-tjekket)**

```php
    protected function addProperties(array $props, array $usage, array $expandedNodeIds, int $capPerCompany, array &$nodes, array &$seen, array &$edges, array &$edgeSeen): void
    {
        $nodeIds = array_flip(array_column($nodes, 'id'));
        $perOwner = [];

        foreach ($props as $p) {
            $owner = $p['owner_cvr'] ?? null;
            $mid = (string) ($p['matrikel_id'] ?? '');
            // Owner must already be a node — properties of pruned companies appear
            // only once their owner is expanded into the graph (spec §Lazy-flow).
            $ownerId = $owner === null ? null : (isset($nodeIds[$owner]) ? $owner : ($owner === ($nodes[0]['cvr'] ?? null) ? 'searched' : null));
            if ($mid === '' || $ownerId === null) {
                continue;
            }

            $perOwner[$ownerId] ??= 0;
            $capLifted = in_array('props:'.$ownerId, $expandedNodeIds, true)
                || ($ownerId === 'searched' && in_array('props:'.($nodes[0]['cvr'] ?? ''), $expandedNodeIds, true));
            if (! $capLifted && $perOwner[$ownerId] >= $capPerCompany) {
                // Count hidden properties on the owner's expand affordance.
                foreach ($nodes as &$node) {
                    if ($node['id'] === $ownerId) {
                        $node['expand'] = ['relations' => $node['expand']['relations'] ?? 0, 'properties' => ($node['expand']['properties'] ?? 0) + 1];
                        break;
                    }
                }
                unset($node);

                continue;
            }

            $id = 'bfe:'.$mid;
            if (! isset($seen[$id])) {
                $seen[$id] = true;
                $isMatriculated = $p['is_matriculated'] ?? false;
                $nodes[] = [
                    'id' => $id,
                    'label' => ($p['address'] ?? null) ?: 'BFE '.$mid,
                    'cvr' => null, 'kind' => 'property', 'share' => null,
                    'meta' => ['bfe' => $isMatriculated ? $mid : null, 'usage' => $usage[$mid] ?? null],
                    'expand' => null,
                ];
                $nodeIds[$id] = true;
            }
            if (! isset($edgeSeen[$ownerId.'|'.$id])) {
                $edgeSeen[$ownerId.'|'.$id] = true;
                $edges[] = ['from' => $ownerId, 'to' => $id, 'label' => ''];
            }
            $perOwner[$ownerId]++;
        }
    }
```

Kald i `build()`: `$this->addProperties($properties['list'] ?? [], $properties['usage'] ?? [], $expandedNodeIds, $caps['properties_per_company'], $nodes, $seen, $edges, $edgeSeen);`

Bemærk: det søgte selskabs egne ejendomme (`owner_cvr === $query`) hænges på `searched`-noden — testen "hangs property nodes" dækker datter-tilfældet; tilføj under implementering en assert i samme test-fil for searched-tilfældet hvis den mangler.

- [ ] **Step 4: Kør alle tests — PASS.**
- [ ] **Step 5: Commit** — `git commit -am "feat(graph): ejendoms-lag — bfe:-noder, owner-hængning, cap+udvid, anvendelse"`

---

### Task 5: Samlet node-cap 120 med deterministisk trunkering

**Files:** samme som Task 4.

- [ ] **Step 1: Failing test**

```php
it('enforces the total node cap deterministically: properties are cut before subsidiaries', function () {
    // 100 subsidiaries level 1 + 60 properties → cap 120 must keep ALL company
    // nodes (101 + searched = 101) and cut properties down to fit.
    $subs = collect(range(1, 100))->map(fn ($i) => ['cvr' => (string) (60000000 + $i), 'name' => 'S'.$i, 'ownership_share' => 1.0, 'children' => []])->all();
    $props = collect(range(1, 60))->map(fn ($i) => fdlProperty(['matrikel_id' => (string) (2000000 + $i), 'owner_cvr' => (string) (60000000 + ($i % 100) + 1)]))->all();
    $g = buildGraph(['structure' => ['ancestors' => [], 'subsidiaries' => $subs], 'properties' => ['list' => $props, 'usage' => []]]);

    expect(count($g['nodes']))->toBeLessThanOrEqual(120)
        ->and(collect($g['nodes'])->where('kind', 'subsidiary'))->toHaveCount(100);
});
```

- [ ] **Step 2: Kør — FAIL** (over cap).
- [ ] **Step 3: Implementér trunkering i `build()` som SIDSTE trin:** tæl noder; er totalen > `caps['total_nodes']`, fjern property-noder (og deres kanter) bagfra i tilføjelses-orden til cappen holder, og læg de fjernede på ejerens `expand.properties`-tal. Er totalen stadig over cappen uden properties, fjern dybeste datter-lag på samme måde (relations-tal på forælderen). Ejer-kæden (ancestors) røres aldrig. Implementeringen er ren array-manipulation i builderen — genbrug `expand`-opdaterings-mønstret fra Task 4.
- [ ] **Step 4: Kør alle tests — PASS. Commit** — `git commit -am "feat(graph): samlet node-cap 120 m. deterministisk trunkering"`

---

### Task 6: RegistryApi — batch-opslag + cache-gated struktur

**Files:**
- Modify: `src/Services/RegistryApi.php`
- Test: `tests/Unit/RegistryApiTest.php` (findes — følg dens eksisterende `Http::fake`-mønster)

**Interfaces:**
- Produces: `fetchPropertiesBatch(array $matrikelIds): ?array` → rå liste fra `POST /v1/properties/batch` (chunker input i 200); `fetchCompanyStructureCached(string $cvr): array` → `Cache::remember("metis.company-structure.{$cvr}", 300, ...)` uden om når svaret er tomt; `fetchCompanyInfo()` får `Cache::remember("metis.company-info.{$cvr}", 86400, ...)`.
- **Cache-gating (review-fund):** `fetchCompanyStructureCached` bruges KUN af komponenten når `$enriching === false` — mens enrichment kører kaldes den eksisterende ucachede `fetchCompanyStructure()`, ellers fryser datter-væksten. To metoder, ingen boolean-flag.

- [ ] **Step 1: Failing tests** (Http::fake på `*/v1/properties/batch` → assert chunking ved 250 ids = 2 kald; Cache::spy på structure-cached; company-info rammer cache ved andet kald). Skriv testene efter mønstret i den eksisterende `tests/Unit/RegistryApiTest.php`.
- [ ] **Step 2: Kør — FAIL. Step 3: Implementér de tre metoder** (samme `$this->get()`/`Http`-hjælpere som filens øvrige metoder; `fetchPropertiesBatch` poster `['matrikel_ids' => $chunk]` pr. chunk og fladgør `data`-listerne).
- [ ] **Step 4: PASS. Step 5: Commit** — `git commit -am "feat(api): properties/batch + cache-gated company-structure + company-info-cache"`

---

### Task 7: CompanyStructure-komponenten — builder-integration, async ejendomme, udvid-actions, state-sanering

**Files:**
- Modify: `src/Livewire/Sections/CompanyStructure.php`
- Test: eksisterende komponent-test (find: `grep -rl "CompanyStructure" tests/`) + nye cases

**Interfaces:**
- Produces (Livewire public API som Blade/Alpine bruger): `array $graphModel`; `string $propertiesStatus` (`'pending'|'building'|'loaded'|'empty'|'failed'`); actions `loadProperties(): void` (wire:init), `retryProperties(): void`, `expandNode(string $nodeId): void` (nodeId = `'sub:'.$cvr` eller `'props:'.$cvr` — kaldes fra Alpine via `$wire.expandNode(...)`).
- Consumes: `OwnershipGraphBuilder::build(...)` (Task 1-5), RegistryApi-metoderne (Task 6).
- **FJERNES:** `toggleOwnerExpansion()`, `ownerKey()`, `$expandedOwners`, `ownershipGraphData()`, `ownedTargetId()`, `shareLabel()` (logik bor nu i builderen).

- [ ] **Step 1: Failing tests (Livewire::test)**

```php
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\Sections\CompanyStructure;

it('rebuilds the graph declaratively when a node is expanded — poll cannot wipe it', function () {
    // Http::fake: structure med 3-niveau datter-træ (Task 3-fixturen) + enrichment complete.
    fakeRegistryStructure();   // hjælper: Http::fake for company-structure + enrichment-status — genbrug/udbyg eksisterende test-hjælpere i filen

    $c = Livewire::test(CompanyStructure::class, ['query' => '38653806'])
        ->call('expandNode', 'sub:44018942');

    expect(collect($c->get('graphModel')['nodes'])->pluck('id'))->toContain('44027992');

    // Et efterfølgende poll (rebuild fra kilde) må IKKE miste udvidelsen:
    $c->call('pollForUpdates');
    expect(collect($c->get('graphModel')['nodes'])->pluck('id'))->toContain('44027992');
});

it('loads properties async and merges them via rebuild', function () {
    fakeRegistryStructure();
    fakeRegistryPortfolio(properties: [fdlPortfolioProperty()], batchUsage: 'Fritliggende enfamiliehus');

    $c = Livewire::test(CompanyStructure::class, ['query' => '38653806'])
        ->call('loadProperties');

    expect($c->get('propertiesStatus'))->toBe('loaded')
        ->and(collect($c->get('graphModel')['nodes'])->firstWhere('kind', 'property'))->not->toBeNull();
});

it('reports building when the portfolio is still assembling — never silently empty', function () {
    fakeRegistryStructure();
    fakeRegistryPortfolio(properties: [], propertyCount: 13);   // tom liste MEN count>0 = building

    $c = Livewire::test(CompanyStructure::class, ['query' => '38653806'])->call('loadProperties');

    expect($c->get('propertiesStatus'))->toBe('building');
});

it('reports failed when the portfolio call errors — never silently empty', function () {
    fakeRegistryStructure();
    Http::fake(['*/property-portfolio*' => Http::response(null, 500)]);

    $c = Livewire::test(CompanyStructure::class, ['query' => '38653806'])->call('loadProperties');

    expect($c->get('propertiesStatus'))->toBe('failed');
});
```

- [ ] **Step 2: Kør — FAIL.**
- [ ] **Step 3: Ombyg komponenten.** Kernen:

```php
    public array $graphModel = ['nodes' => [], 'edges' => []];
    public array $expandedNodeIds = [];
    public string $propertiesStatus = 'pending';
    public int $propertiesAttempts = 0;

    /** Builder-input holdes som beskyttet state (IKKE public → ude af wire-payload). */
    protected array $structureData = [];
    protected array $propertyData = ['list' => [], 'usage' => []];

    protected function rebuild(): void
    {
        $this->graphModel = app(OwnershipGraphBuilder::class)->build(
            query: $this->query,
            companyName: $this->companyName,
            structure: $this->structureData,
            properties: $this->propertyData,
            enrichment: [],
            expandedNodeIds: $this->expandedNodeIds,
            caps: ['subsidiary_depth' => 2, 'properties_per_company' => 6, 'total_nodes' => 120],
        );
    }

    public function expandNode(string $nodeId): void
    {
        if (! str_starts_with($nodeId, 'sub:') && ! str_starts_with($nodeId, 'props:')) {
            return;
        }
        if (! in_array($nodeId, $this->expandedNodeIds, true)) {
            $this->expandedNodeIds[] = $nodeId;
        }
        $this->refreshStructureData();   // cachet når !enriching (Task 6) — reelt gratis
        $this->rebuild();
    }

    public function loadProperties(): void
    {
        $portfolio = rescue(fn () => app(RegistryApi::class)->fetchCompanyPropertyPortfolio($this->query), null);
        if ($portfolio === null) {
            $this->propertiesStatus = 'failed';

            return;
        }
        $list = $portfolio['properties'] ?? [];
        $count = $portfolio['property_count'] ?? 0;
        if ($list === [] && $count > 0) {
            // Backend bygger stadig porteføljen (verificeret prod-adfærd: første
            // kald tomt, andet fuldt). Bladen re-forsøger m. stigende delay.
            $this->propertiesStatus = 'building';
            $this->propertiesAttempts++;

            return;
        }
        if ($list === []) {
            $this->propertiesStatus = 'empty';

            return;
        }
        $usage = $this->usageMapFor($list);
        $this->propertyData = ['list' => $list, 'usage' => $usage];
        $this->propertiesStatus = 'loaded';
        $this->rebuild();
    }

    public function retryProperties(): void
    {
        $this->propertiesStatus = 'pending';
        $this->loadProperties();
    }

    /** matrikel_id => primær anvendelses-label via properties/batch + BbrUsageCategory. */
    protected function usageMapFor(array $properties): array
    {
        $ids = collect($properties)->pluck('matrikel_id')->filter()->map(fn ($m) => (string) $m)->unique()->values()->all();
        $batch = rescue(fn () => app(RegistryApi::class)->fetchPropertiesBatch($ids), null) ?? [];

        return collect($batch)->mapWithKeys(function ($p) {
            $buildings = collect($p['bbr']['buildings'] ?? []);
            // Primær bygning: største areal blandt ikke-småbygninger (9xx-koder =
            // garager/udhuse); fallback = største uanset kode.
            $primary = $buildings->filter(fn ($b) => (int) ($b['usage'] ?? 0) < 900)->sortByDesc('total_area')->first()
                ?? $buildings->sortByDesc('total_area')->first();

            return [(string) ($p['matrikel_id'] ?? '') => $primary ? BbrUsageCategory::label($primary['usage'] ?? null) : null];
        })->all();
    }
```

`mount()`: hent struktur (som nu), gem i `$structureData`, kald `$this->rebuild()`. **Fallback-loopets pr.-ejer `fetchCompanyInfo`-kald bliver billige via Task 6's 24 t-cache — loopet BEHOLDES (det føder historical-owners-visningen), men wrap alle kald i det eksisterende `rescue`-mønster.** `pollForUpdates()`: ved completion → `refreshStructureData()` + `rebuild()` (IKKE direkte model-tildeling). `refreshStructureData()`: `$this->enriching ? fetchCompanyStructure(...) : fetchCompanyStructureCached(...)`. Slet de fjernede metoder/properties (se Interfaces). Livewire-note: protected properties overlever ikke requests — `refreshStructureData()` + `rebuild()` kaldes derfor i starten af `expandNode`/`loadProperties` hvis `$structureData === []` (hydration-guard; skriv en test for udvid-efter-request).

- [ ] **Step 4: Kør alle package-tests — PASS** (`vendor/bin/pest`). Ret eksisterende tests der refererer fjernede metoder.
- [ ] **Step 5: Commit** — `git commit -am "feat(graph): deklarativ komponent — builder-rebuild alle stier, async ejendomme m. building/fejl-tilstande, udvid-actions"`

---

### Task 8: Blade — delt graf-partial, property-noder, udvid-knapper, gammel sektion fjernes

**Files:**
- Create: `resources/views/livewire/sections/partials/ownership-graph.blade.php`
- Modify: `resources/views/livewire/sections/company-structure.blade.php`
- Modify: `resources/views/livewire/sections/partials/graph-node.blade.php`

**Interfaces:**
- Consumes: `$graphModel`-node-shape inkl. `kind: 'property'`, `meta.{bfe,usage}`, `expand.{relations,properties}` (Task 4); `$wire.expandNode('sub:'+cvr)` (Task 7).
- Partial'en modtager `$graph` (modellen) + wire-konteksten; den indeholder frame + controls + `.mgraph`-CSS flyttet 1:1.

- [ ] **Step 1: Udtræk graf-øen til partial.** Flyt blokken `company-structure.blade.php` linje ~64-95 (`<div wire:ignore ... </div>` inkl. controls) OG hele `.mgraph`-`<style>`-blokken (linje ~426-533) til den nye partial. I company-structure erstattes blokken af `@include('metis::livewire.sections.partials.ownership-graph', ['graph' => $graph])` + `wire:init="loadProperties"` på sektionens rod-element. CVR-sum-noten (linje 97-102) bliver i company-structure.
- [ ] **Step 2: Ejendoms-status-UI (uden for wire:ignore-øen), under grafen:**

```blade
@if($propertiesStatus === 'building')
    <p class="mgraph-note" x-data x-init="setTimeout(() => $wire.loadProperties(), {{ min(3 + 3 * $propertiesAttempts, 12) * 1000 }})">
        {{ __('Ejendomme hentes stadig…') }}
    </p>
@elseif($propertiesStatus === 'failed')
    <p class="mgraph-note">
        {{ __('Ejendomme kunne ikke hentes.') }}
        <button type="button" wire:click="retryProperties" class="underline">{{ __('Prøv igen') }}</button>
    </p>
@endif
```

(`empty` og `loaded` viser ingen note — kun FEJL/BUILDING er synlige tilstande, jf. spec'ens null ≠ tom.)

- [ ] **Step 3: Udvid `graph-node.blade.php`:**

```blade
<template x-for="node in nodes" :key="node.id">
    <div class="mgraph-node" :class="'mgraph-node--' + node.kind"
         :style="`left:${node.x}px; top:${node.y}px; width:${node.w}px; height:${node.h}px;`">
        <div class="mgraph-node__name" x-text="node.label"></div>
        <div class="mgraph-node__meta" x-show="node.cvr" x-cloak>
            <span class="mgraph-node__cvr" x-text="node.cvr"></span>
        </div>
        {{-- Property meta rows: BFE + anvendelse (rows omitted when null) --}}
        <div class="mgraph-node__meta" x-show="node.kind === 'property'" x-cloak>
            <span class="mgraph-node__cvr" x-show="node.meta?.bfe" x-text="'BFE ' + node.meta.bfe"></span>
            <span class="mgraph-node__usage" x-show="node.meta?.usage" x-text="node.meta.usage"></span>
        </div>
        {{-- Expand affordances. @mousedown.stop so the frame's pan never starts;
             _expanding gives a per-node loading state until the watcher re-renders. --}}
        <div class="mgraph-node__expand" x-show="node.expand && (node.expand.relations || node.expand.properties)" x-cloak>
            <button type="button" x-show="node.expand?.relations" x-data="{busy:false}"
                    @mousedown.stop @click.stop="busy = true; $wire.expandNode('sub:' + node.cvr)"
                    :disabled="busy" x-text="busy ? '…' : ('↓ ' + node.expand.relations + ' relationer')"></button>
            <button type="button" x-show="node.expand?.properties" x-data="{busy:false}"
                    @mousedown.stop @click.stop="busy = true; $wire.expandNode('props:' + node.cvr)"
                    :disabled="busy" x-text="busy ? '…' : ('+ ' + node.expand.properties + ' ejendomme')"></button>
        </div>
    </div>
</template>
```

- [ ] **Step 4: CSS-tilføjelser i partial'ens style-blok** (frankston-sprog fra spec §Node-design): `.mgraph-node--property { border-style: dashed; }`, `.mgraph-node--property .mgraph-node__name { color: #8a6d1f; }`, `.mgraph-node--subsidiary` = samme sand-kort som `--legal`, `.mgraph-node__expand button` = lille mono-tekstknap. Følg de eksisterende `.mgraph-node--*`-reglers form præcist.
- [ ] **Step 5: FJERN den gamle DATTERSELSKABER-render-blok (linje ~148-177) og alle "Udfold struktur"-rester i company-structure.blade.php.** `$subsidiaries`-dataene bruges stadig som builder-input — kun render-blokken ryger.
- [ ] **Step 6: Kør package-tests + `php artisan view:clear` i host-appen og verificér ingen Blade-fejl.** Commit — `git commit -am "feat(graph): delt graf-partial, property-noder, udvid-knapper; gammel DATTERSELSKABER-sektion fjernet"`

---

### Task 9: Host-JS — per-kind node-dimensioner + klik-vs-pan-tærskel

**Files:**
- Modify: `/Users/Frederik/Herd/metis/resources/js/ownership-graph.js`

**Interfaces:**
- Consumes: node-shape m. `kind`, `meta`, `expand` (payload-felterne er allerede i modellen).
- Produces: `NODE_DIMS`-map; uændret offentlig komponent-API.

- [ ] **Step 1: Erstat `NODE_W/NODE_H` med et kind-map:**

```js
const NODE_DIMS = {
    default:    { w: 210, h: 56 },
    property:   { w: 210, h: 72 },   // ekstra meta-række (BFE + anvendelse)
    subsidiary: { w: 210, h: 56 },
};
const dims = (n) => NODE_DIMS[n.kind] || NODE_DIMS.default;
// layout(): g.setNode(n.id, { ...n, width: dims(n).w, height: dims(n).h });
// nodes-map: w/h fra g.node(id) er allerede de satte — uændret.
```

Noder med `expand`-knapper får +18 px højde: `const h = dims(n).h + (n.expand && (n.expand.relations || n.expand.properties) ? 18 : 0);`

- [ ] **Step 2: Klik-vs-pan-tærskel** (udvid-knapper bor i pan-canvas; `@mousedown.stop` på knapperne klarer knap-tilfældet, men tilføj tærsklen så et sjusket knap-klik ikke bliver en 2 px-pan): i `startPan` gem `_moved = false`; i `onPan` sæt `_moved = true` først når `|dx|+|dy| > 4`; flyt pan-anvendelsen bag det tjek.
- [ ] **Step 3: Byg host-appens assets:** `cd /Users/Frederik/Herd/metis && npm run build` → ingen fejl.
- [ ] **Step 4: Commit i host-repoet** — `git add resources/js/ownership-graph.js && git commit -m "feat(graph): per-kind node-dimensioner + pan-tærskel (fase 2a.1)"`

---

### Task 10: Lokal browser-verifikation (ÆGTE side, konsol åben)

**Files:** ingen ændringer — verifikation.

- [ ] **Step 1:** Sæt midlertidigt `REGISTRY_API_KEY` i `/Users/Frederik/Herd/metis/.env` (hent read-only fra Forge-env som under plan-verifikationen — MÅ IKKE COMMITTES). `php artisan view:clear && php artisan config:clear`.
- [ ] **Step 2:** Åbn `https://metis.test/lookup/cvr/38653806` i Chrome m. DevTools-konsol åben. Verificér og screenshot: (a) person øverst → FDL i midten → 3 datterselskaber nedad, (b) ejendomme popper ind async under deres ejere (Kirketorvet Ejendomme: 4 synlige + "+N ejendomme"-knap hvis >6 — FDL-koncernen har 13), (c) udvid-klik virker og zoom/pan overlever, (d) INGEN konsol-fejl, (e) gammel DATTERSELSKABER-sektion er væk.
- [ ] **Step 3:** Test en STOR koncern (fx JEUDAN A/S, CVR 14246916) — node-cap + trunkering + ydelse; notér dagre-layout-tid fra konsollen (`console.time` midlertidigt eller Performance-tab). Spec-krav: måling ved ~120 noder.
- [ ] **Step 4:** Test fejl-flowet: sæt midlertidigt ugyldig API-nøgle → "Ejendomme kunne ikke hentes" + Prøv igen. Gendan nøglen. Fjern nøglen fra .env efter verifikation.

---

### Task 11: Package-PR + host-PR + koordineret deploy

- [ ] **Step 1:** Push `feat/ownership-graph-phase2` (package) → PR mod main med spec-link + screenshots fra Task 10. Kør `vendor/bin/pest` en sidste gang; grøn CI.
- [ ] **Step 2:** Host-appen: branch `feat/ownership-graph-2a1-js` → PR (JS-ændringen). PR-beskrivelsen SKAL nævne: "Deploy sammen med metis-package 2a.1 — rollback = revert begge."
- [ ] **Step 3 (efter Frederiks godkendelse — prod = PROD-OVERRIDE):** Merge package-PR først, bump host-lock (auto-deploy site 3093070), merge host-PR. Efter deploy: `php artisan view:clear` på metis-prod (Blade-ændringer + atomic deploy).
- [ ] **Step 4:** Prod-verifikation på metis.frankston.io med konsollen åben (samme tjekliste som Task 10 step 2). Watch Flare for nye metis-fejl i 30 min.

---

### Task 12 (follow-up-gate, IKKE del af 2a.1-PR'en): Interval-verifikation

- [ ] **Step 1:** Read-only prod-forespørgsel (Forge tinker-mønstret fra memory, read-only SELECT): fordeling af `company_roles.ownership_share` (`GROUP BY ownership_share ORDER BY count DESC LIMIT 30`) — ligger massen på båndgrænser (5, 10, 15, 20, 25, 33.33, 50, 66.66, 90, 100) eller på vilkårlige værdier?
- [ ] **Step 2:** Hent ét rå CVR-ES-svar for et selskab med kendt bånd-ejerskab (fx FDL 38653806) og inspicér `attributter[]`-typerne i deltager-relationen: findes der et felt der EKSPLICIT skelner interval-registrering fra eksakt andel?
- [ ] **Step 3:** Findes skelnen → lille separat PR: bånd-mapning i `OwnershipGraphBuilder::shareLabel()` KUN for interval-registrerede + tests. Findes den IKKE → notér i spec'en at interval-visning er droppet (eksakt tal beholdes), og luk sporet.

---

## Self-review (kørt)

- **Spec-dækning 2a.1:** datterselskaber nedad ✅ (T2-3), ejendomme async m. building/fejl ✅ (T4, T7-8), udvid-affordances ✅ (T2-4, T8), label-regel sikker gren ✅ (T1, T12-gate), gammel sektion fjernes ✅ (T8), partial-udtræk ✅ (T8), mount-fallback afbødet via cache ✅ (T6-7), node-cap ✅ (T5), to-repo ✅ (T9, T11), JS-dimensioner ✅ (T9), viewport-regel = eksisterende `_fitted`-adfærd (ingen ændring nødvendig — auto-center sker kun ved første fit) ✅.
- **Placeholder-scan:** ingen TBD/TODO; alle steps har kode eller eksakte instruktioner m. linjenumre.
- **Type-konsistens:** `expand.{relations,properties}` (T2/T4/T8/T9), `propertiesStatus`-værdier (T7/T8), builder-signatur (T1/T7) — konsistente.
- **Bevidst udeladt af 2a.1** (hører til 2a.2): hover-/tap-kort, singleton-kort, touch-pan, værdi-aggregat, signaler, streetview, node-klik-navigation.
