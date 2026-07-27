<?php

namespace TheFountainhead\Metis\Services;

use Carbon\CarbonImmutable;

/**
 * Builds the flat {nodes, edges} model for the ownership graph.
 *
 * PURE + DECLARATIVE: same input → same output, no HTTP, no side effects.
 * Every code path (mount, enrichment poll, property fetch, expand click)
 * REBUILDS the model through this class — nothing ever appends to the
 * model directly, because pollForUpdates() rebuilds from source and would
 * silently wipe any appended state (review finding, spec v3).
 *
 * $enrichment (fase 2a.2) carries per-cvr and per-property hover-card data:
 * ['companies' => [cvr => [...]], 'properties' => [matrikelId => [...]]].
 * It is applied as the LAST step of build(), after truncateToCap, so
 * card/aggregate data is never computed for a node that got cut by the cap.
 */
class OwnershipGraphBuilder
{
    /**
     * Node kinds eligible for company-card/signals enrichment (fase 2a.2
     * scope). Deliberately excludes 'person' and 'foreign' (spec: no
     * enrichment for individuals before fase 2b) and 'other' (orphan-parent
     * stubs synthesised in addAncestors() — a placeholder id, not a company
     * a user asked to see enriched). Shared with CompanyStructure's cvr
     * collection for the enrichment pool, so a stub's cvr is never sent
     * there either.
     *
     * Cross-reference (F-E): NOT the same set as JS's COMPANY_KINDS
     * (resources/js/ownership-graph.js) — that one additionally includes
     * 'other'/'foreign' and gates CVR-page navigation, not enrichment.
     * Related but independent; do not merge into one shared list.
     */
    public const ENRICHABLE_KINDS = ['searched', 'subsidiary', 'legal', 'reel'];

    public function build(
        string $query,
        ?string $companyName,
        array $structure,
        array $properties,
        array $enrichment,
        array $expandedNodeIds,
        array $caps,
        ?CarbonImmutable $now = null,
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
        $this->addSubsidiaries($structure['subsidiaries'] ?? [], 'searched', 1, $caps['subsidiary_depth'], $expandedNodeIds, $nodes, $seen, $edges, $edgeSeen);

        // usage-bagudkompatibilitet: enrichment['properties'][mid]['usage'] wins
        // over the legacy properties['usage'][mid] map (2a.1 shape) so Task 3 can
        // migrate the usage-populating component without a big-bang cutover.
        $usage = $properties['usage'] ?? [];
        foreach ($enrichment['properties'] ?? [] as $mid => $entry) {
            if (($entry['usage'] ?? null) !== null) {
                $usage[$mid] = $entry['usage'];
            }
        }

        $this->addProperties($properties['list'] ?? [], $usage, $expandedNodeIds, $caps['properties_per_company'], $query, $nodes, $seen, $edges, $edgeSeen);
        $this->finalize($nodes, $edges, $properties['list'] ?? [], $enrichment, $caps, $query, $now);

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    /**
     * Person entry point (fase 2b): the searched PERSON is the root and the
     * companies they own / hold a role in hang directly below. Same purity
     * contract as build() — every path (chip toggle, poll tick, expand click)
     * REBUILDS through this method; nothing ever appends to the model.
     *
     * CPR NEVER enters the model: the root id is the constant 'person:root'
     * and the only other ids are cvr's, so the graph payload (and the DOM
     * attributes derived from it) can never leak the CPR the page was looked
     * up by.
     *
     * $layers is a subset of ['ownership', 'roles'] — the two filter chips.
     * Filtering happens HERE, not in the view, because the dedup between the
     * two layers is layer-aware (see dedup rule below).
     *
     * $structures / $properties are the progressive-loading inputs, both
     * keyed by cvr and both containing ONLY the cvr's fetched so far — a
     * value of null means "fetch failed for this cvr" and is skipped, never
     * fatal. Every tick hands a slightly bigger map to a full rebuild, so
     * each intermediate state must be a deterministic, edge-consistent
     * subgraph of the final one.
     *
     *   $structures: cvr => ?['subsidiaries' => [...], ...]  (fetchCompanyStructuresPooled shape)
     *   $properties: cvr => ?list-of-portfolio-rows
     *
     * NOTE the $properties shape difference vs. build(): build() takes ONE
     * ['list' => [...], 'usage' => [...]] wrapper, because CompanyStructure
     * resolves a matrikel_id => usage map itself (usageMapFor()) alongside
     * the single portfolio. The person path takes plain LISTS per root cvr
     * and derives usage from $enrichment['properties'][mid]['usage'] only —
     * there is no legacy per-cvr usage map to stay backwards-compatible
     * with, and PersonStructure's enrichment phase is the single place that
     * produces usage for a person graph. The lists are flattened into ONE
     * list before addProperties(), so a row hangs on whichever node its
     * owner_cvr matches (a root OR a subsidiary of any root) regardless of
     * which root's portfolio it arrived in.
     *
     * finalize() is called with $searchedAlias = null: a person graph has no
     * single searched-COMPANY alias to normalise owner_cvr against (see the
     * finalize() docblock's null contract), and with $personPriority = true
     * so the person-specific truncation order applies (see
     * truncateToCapForPerson).
     */
    public function buildForPerson(
        string $personName,
        array $ownershipCompanies,
        array $roleCompanies,
        array $crossOwnership,
        array $structures,
        array $properties,
        array $enrichment,
        array $expandedNodeIds,
        array $layers,
        array $caps,
        ?CarbonImmutable $now = null,
    ): array {
        $nodes = [[
            'id' => 'person:root',
            'label' => $personName,
            'cvr' => null, 'kind' => 'person', 'share' => null, 'expand' => null,
        ]];
        $seen = ['person:root' => true];
        $edges = [];
        $edgeSeen = [];

        $ownershipOn = in_array('ownership', $layers, true);
        $rolesOn = in_array('roles', $layers, true);

        $ownershipCompanies = $ownershipOn ? array_values(array_filter($ownershipCompanies, fn ($c) => ($c['cvr'] ?? null) !== null)) : [];
        // Layer-aware double-relation dedup: a company the person BOTH owns and
        // holds a role in is drawn in the ownership layer only — but if the
        // ownership chip is off, the role edge must still be drawn, otherwise a
        // board seat would vanish from the roles view merely because the person
        // also owns the company.
        $ownedCvrs = array_flip(array_column($ownershipCompanies, 'cvr'));
        $roleCompanies = $rolesOn
            ? array_values(array_filter($roleCompanies, fn ($c) => ($c['cvr'] ?? null) !== null && ! isset($ownedCvrs[$c['cvr']])))
            : [];

        // Cross-ownership demotion: a company in the ownership set that is
        // owned by ANOTHER company in that same set is a CHILD, not a root.
        // Its parent edge is drawn here, in the skeleton, so the child is never
        // orphaned while phase 2 fetches the parent's structure.
        $childRelations = array_values(array_filter(
            $crossOwnership,
            fn ($r) => isset($ownedCvrs[$r['parent_cvr'] ?? null]) && ($r['child_cvr'] ?? null) !== null,
        ));
        $demoted = array_flip(array_column($childRelations, 'child_cvr'));

        $roots = array_values(array_filter($ownershipCompanies, fn ($c) => ! isset($demoted[$c['cvr']])));

        // First-level caps, in input order (deterministic: the caller passes
        // fetchCompaniesByCpr's order). The two expand-ids lift their own cap
        // independently.
        $rootCap = in_array('sub:person:root', $expandedNodeIds, true) ? count($roots) : $caps['person_roots'];
        $roleCap = in_array('roles:person:root', $expandedNodeIds, true) ? count($roleCompanies) : $caps['person_roles'];
        $hidden = max(0, count($roots) - $rootCap) + max(0, count($roleCompanies) - $roleCap);

        foreach (array_slice($roots, 0, $rootCap) as $c) {
            $this->addPersonChild($c['cvr'], $c['name'] ?? null, $c['ownership_share'] ?? null, false, $nodes, $seen);
            $this->addPersonEdge('person:root', $c['cvr'], $this->shareLabel($c['ownership_share'] ?? null), null, $edges, $edgeSeen);
        }

        // Demoted children: node at depth 2 + the parent→child edge. A child
        // the person ALSO owns directly keeps its person edge too (both facts
        // are true) — the node is deduped by $seen, the edges are not.
        //
        // Rule 6: a demoted child can ALSO be a role company (person sits on
        // its board). Node identity/layer must not depend on write order —
        // the role loop below runs AFTER this one, so if this loop wrote the
        // node first with the demotion's own (often absent) name and
        // roleLayer=false, $seen would win the race and the role loop's
        // addPersonChild would no-op, leaving the node with the 'CVR <id>'
        // fallback label and no role_layer. Looking the cvr up in the capped
        // role list HERE and merging its name + role_layer into this write
        // makes the outcome identical regardless of which loop runs first.
        $roleByCvr = collect(array_slice($roleCompanies, 0, $roleCap))->keyBy('cvr');
        foreach ($childRelations as $r) {
            $cvr = $r['child_cvr'];
            if (! isset($seen[$r['parent_cvr']])) {
                continue; // Parent fell outside the roots cap — no dangling child.
            }
            $direct = collect($ownershipCompanies)->firstWhere('cvr', $cvr);
            $role = $roleByCvr->get($cvr);
            $name = $direct['name'] ?? $role['name'] ?? null;
            $this->addPersonChild($cvr, $name, $direct['ownership_share'] ?? null, $role !== null, $nodes, $seen, 2);
            $this->addPersonEdge($r['parent_cvr'], $cvr, $this->shareLabel($r['ownership_share'] ?? null), null, $edges, $edgeSeen);
            if ($direct !== null) {
                $this->addPersonEdge('person:root', $cvr, $this->shareLabel($direct['ownership_share'] ?? null), null, $edges, $edgeSeen);
            }
        }

        foreach (array_slice($roleCompanies, 0, $roleCap) as $c) {
            $this->addPersonChild($c['cvr'], $c['name'] ?? null, null, true, $nodes, $seen);
            $this->addPersonEdge('person:root', $c['cvr'], ($c['role_label'] ?? null) ?: 'rolle', 'dashed', $edges, $edgeSeen);
        }

        if ($hidden > 0) {
            $nodes[0]['expand'] = ['relations' => $hidden, 'properties' => 0];
        }

        // --- Fase 2: subsidiary layers under every first-level node ---------
        //
        // Walked in $nodes order (skeleton write order = the caller's
        // companies order), so the traversal — and therefore node/edge
        // ordering, $seen dedup and the depth values truncation keys off —
        // is fully determined by the input, not by $structures' key order.
        //
        // Depth offset: a first-level node IS depth 1, so its own
        // subsidiaries start at 2 and the configured subsidiary_depth (= the
        // number of company layers BELOW the node the structure belongs to)
        // shifts up by the node's own depth. For the depth-1 nodes Task 7
        // actually fetches structures for (visible roots + role companies)
        // this reduces to the specified `2` / `subsidiary_depth + 1`; writing
        // it relative to $node['depth'] means a demoted depth-2 child would
        // also get its configured number of layers rather than one fewer,
        // should a later phase ever fetch its structure.
        //
        // Auto-expand (≤3 descendants) and sub:<cvr> expansion come free from
        // the addSubsidiaries reuse.
        //
        // Injection fallback (regel 2): a cross-ownership child that the
        // parent's fetched tree does NOT contain keeps its skeleton node and
        // edge — $seen/$edgeSeen only ever SUPPRESS a duplicate write, never
        // remove one, so an arriving structure can only add.
        foreach (array_slice($nodes, 1) as $node) {
            $structure = $structures[$node['id']] ?? null;
            if ($structure === null) {
                continue; // Not fetched yet, or the fetch failed for this cvr.
            }

            $this->addSubsidiaries($structure['subsidiaries'] ?? [], $node['id'], $node['depth'] + 1, $caps['subsidiary_depth'] + $node['depth'], $expandedNodeIds, $nodes, $seen, $edges, $edgeSeen);
        }

        // --- Fase 3: properties on any company node in the graph ------------
        //
        // One flat list across all fetched portfolios: addProperties() hangs
        // each row on the node matching its owner_cvr (root OR subsidiary)
        // and skips rows whose owner is not in the graph, so the per-root
        // keying of $properties is irrelevant to the outcome. array_filter
        // drops the null (failed-fetch) entries; array_merge preserves the
        // caller's per-cvr order, keeping the per-company cap deterministic.
        $propertyList = array_merge(...array_values(array_filter($properties)));

        $usage = [];
        foreach ($enrichment['properties'] ?? [] as $mid => $entry) {
            if (($entry['usage'] ?? null) !== null) {
                $usage[$mid] = $entry['usage'];
            }
        }

        $this->addProperties($propertyList, $usage, $expandedNodeIds, $caps['properties_per_company'], null, $nodes, $seen, $edges, $edgeSeen);
        $this->finalize($nodes, $edges, $propertyList, $enrichment, $caps, null, $now, personPriority: true);

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    /**
     * Adds one company node under the person root, reusing the exact node
     * shape addSubsidiaries() produces (kind 'subsidiary' + 'depth') so the
     * ENRICHABLE_KINDS gate, truncateToCap's deepest-layer ordering and the
     * host-JS COMPANY_KINDS click-navigation all work unchanged — fase 2b
     * introduces no new node kind.
     *
     * Role-layer nodes additionally carry 'role_layer' => true: Task 5's
     * person-specific truncation priority cuts role nodes BEFORE ownership
     * roots, and both sit at depth 1, so the layer must be readable off the
     * node itself.
     *
     * $seen dedup means a cvr reachable two ways (owned AND cross-owned, or
     * role AND subsidiary) collapses to one node — first write wins, exactly
     * as in addSubsidiaries/addSubtreeFully.
     */
    protected function addPersonChild(string $cvr, ?string $name, ?float $share, bool $roleLayer, array &$nodes, array &$seen, int $depth = 1): void
    {
        if (isset($seen[$cvr])) {
            return;
        }
        $seen[$cvr] = true;

        $node = ['id' => $cvr, 'label' => $name ?: ('CVR '.$cvr), 'cvr' => $cvr, 'kind' => 'subsidiary', 'share' => $share, 'expand' => null, 'depth' => $depth];
        if ($roleLayer) {
            $node['role_layer'] = true;
        }

        $nodes[] = $node;
    }

    /**
     * Adds one edge with the same from/to/label shape build() emits, plus the
     * fase 2b 'style' key — set ONLY for role edges ('dashed'). Ownership and
     * subsidiary edges carry no style key at all, because absence-of-style is
     * the backwards-compatible "solid" contract every existing build() edge
     * already relies on (host-JS treats a missing style as solid).
     */
    protected function addPersonEdge(string $from, string $to, string $label, ?string $style, array &$edges, array &$edgeSeen): void
    {
        if (isset($edgeSeen[$from.'|'.$to])) {
            return;
        }
        $edgeSeen[$from.'|'.$to] = true;

        $edge = ['from' => $from, 'to' => $to, 'label' => $label];
        if ($style !== null) {
            $edge['style'] = $style;
        }

        $edges[] = $edge;
    }

    /**
     * Shared tail of build(): enforce the total node cap, then attach
     * enrichment — in that order, so aggregates/cards are never computed for
     * a node the cap already removed. Runs identically regardless of which
     * structure walk (company ancestors/subsidiaries/properties, or a future
     * person-graph walk) populated $nodes/$edges beforehand.
     *
     * $searchedAlias is the 'searched'-normalisation key used by
     * applyEnrichment/aggregateProperties (owner === $searchedAlias →
     * 'searched'). build() passes $query; buildForPerson(), which has no
     * single searched-company alias, passes null, which simply never matches
     * (an owner === null case is already skipped first, so a null $searchedAlias
     * can never accidentally match a null owner_cvr).
     *
     * $personPriority selects the truncation variant. The two graphs have
     * genuinely different cut orders (the person graph has a role layer and a
     * never-cut person root; the company graph has an ancestor chain), and
     * the ordering must be chosen from the ENTRY POINT, not inferred inside
     * truncateToCap from node contents — a person graph with the roles chip
     * off contains no role_layer node at all, so content-sniffing would
     * silently pick the company ordering for it. A named argument at the one
     * call site that needs it keeps build()'s path byte-identical (it passes
     * nothing and gets the unchanged shared truncateToCap).
     */
    protected function finalize(array &$nodes, array &$edges, array $propertyList, array $enrichment, array $caps, ?string $searchedAlias, ?CarbonImmutable $now, bool $personPriority = false): void
    {
        $personPriority
            ? $this->truncateToCapForPerson($caps['total_nodes'], $nodes, $edges)
            : $this->truncateToCap($caps['total_nodes'], $nodes, $edges);

        $this->applyEnrichment($nodes, $enrichment, $propertyList, $searchedAlias, $now);
    }

    /**
     * First step of finalize(): enforce the total node cap deterministically.
     * Priority: property nodes are cut first (back-to-front in addition
     * order), then the deepest subsidiary layer. The ancestor chain is
     * NEVER touched. Removed nodes' edges are removed too, and the removed
     * count is folded onto the owner's/parent's expand affordance — reusing
     * the same `?? 0` pattern as addSubsidiaries/addProperties so an
     * existing hidden-count is preserved, never overwritten.
     */
    protected function truncateToCap(int $cap, array &$nodes, array &$edges): void
    {
        if (count($nodes) <= $cap) {
            return;
        }

        // --- Pass 1: drop property nodes, back-to-front in addition order. ---
        for ($i = count($nodes) - 1; $i >= 0 && count($nodes) > $cap; $i--) {
            if ($nodes[$i]['kind'] !== 'property') {
                continue;
            }
            $this->removeNode($nodes, $edges, $i, 'properties');
        }

        if (count($nodes) <= $cap) {
            return;
        }

        // --- Pass 2: drop the deepest subsidiary layer(s), back-to-front. ---
        while (count($nodes) > $cap) {
            $subsidiaryIndexes = array_keys(array_filter($nodes, fn ($n) => $n['kind'] === 'subsidiary'));
            if ($subsidiaryIndexes === []) {
                break; // Nothing left that's safe to cut (ancestors are never touched).
            }

            $maxDepth = max(array_map(fn ($i) => $nodes[$i]['depth'] ?? 1, $subsidiaryIndexes));
            $deepest = array_filter($subsidiaryIndexes, fn ($i) => ($nodes[$i]['depth'] ?? 1) === $maxDepth);

            foreach (array_reverse($deepest) as $i) {
                if (count($nodes) <= $cap) {
                    break;
                }
                $this->removeNode($nodes, $edges, $i, 'relations');
            }
        }
    }

    /**
     * Person-graph variant of truncateToCap (fase 2b). Same mechanics
     * (removeNode, back-to-front, expand roll-up) but a four-step priority
     * that the company ordering cannot express:
     *
     *   1. property nodes
     *   2. the deepest subsidiary LAYER(s) — depth 2 and below
     *   3. role-layer nodes (depth 1, 'role_layer' => true)
     *   4. ownership roots (depth 1, no role_layer) — LAST
     *
     * The person root is NEVER a candidate: it carries no 'depth' and is not
     * kind 'subsidiary', so no pass can select it, and cutting it would
     * disconnect the whole graph rather than shrink it.
     *
     * Steps 3 and 4 both operate on depth 1, which is precisely why the
     * shared truncateToCap cannot be reused: its pass 2 treats one depth
     * level as one undifferentiated layer and would cut roles and roots in
     * interleaved write order. A person's directly-owned companies are the
     * single most important thing on the page — a board seat must give way
     * to them, never the reverse.
     */
    protected function truncateToCapForPerson(int $cap, array &$nodes, array &$edges): void
    {
        if (count($nodes) <= $cap) {
            return;
        }

        // --- Pass 1: property nodes, back-to-front in addition order. -------
        for ($i = count($nodes) - 1; $i >= 0 && count($nodes) > $cap; $i--) {
            if ($nodes[$i]['kind'] !== 'property') {
                continue;
            }
            $this->removeNode($nodes, $edges, $i, 'properties');
        }

        // --- Pass 2: deepest subsidiary layers, down to (but excluding)
        // depth 1 — the first level is handled by the two passes below.
        $this->cutDeepestLayers($cap, $nodes, $edges, minDepth: 2);

        // --- Pass 3: role-layer nodes, back-to-front. -----------------------
        for ($i = count($nodes) - 1; $i >= 0 && count($nodes) > $cap; $i--) {
            if (($nodes[$i]['role_layer'] ?? false) !== true) {
                continue;
            }
            $this->removeNode($nodes, $edges, $i, 'relations');
        }

        // --- Pass 4: ownership roots (everything left at depth 1). ----------
        $this->cutDeepestLayers($cap, $nodes, $edges, minDepth: 1);
    }

    /**
     * Shared by truncateToCapForPerson's passes 2 and 4: cut subsidiary nodes
     * deepest-layer-first, back-to-front within a layer, stopping at
     * $minDepth. Splitting the depth range in two is what lets pass 3 slot
     * between the deep layers and the first level.
     */
    protected function cutDeepestLayers(int $cap, array &$nodes, array &$edges, int $minDepth): void
    {
        while (count($nodes) > $cap) {
            $indexes = array_keys(array_filter(
                $nodes,
                fn ($n) => $n['kind'] === 'subsidiary' && ($n['depth'] ?? 1) >= $minDepth,
            ));
            if ($indexes === []) {
                return;
            }

            $maxDepth = max(array_map(fn ($i) => $nodes[$i]['depth'] ?? 1, $indexes));
            $deepest = array_filter($indexes, fn ($i) => ($nodes[$i]['depth'] ?? 1) === $maxDepth);

            foreach (array_reverse($deepest) as $i) {
                if (count($nodes) <= $cap) {
                    return;
                }
                $this->removeNode($nodes, $edges, $i, 'relations');
            }
        }
    }

    /**
     * Remove node at $index, drop its edges, and fold it onto EVERY parent's
     * expand affordance (the `$field` key: 'relations' or 'properties'). A
     * "parent" is any node with an inbound edge to the removed node — for a
     * co-owned property (one bfe: node, two owner edges, an explicitly
     * supported/tested shape) that is more than one node, and each of them
     * independently loses visibility of the removed node, so each must be
     * incremented. A naive single `$parentId` variable overwritten per
     * matching edge would let only the LAST owner win, silently under-
     * counting portfolios for every earlier co-owner (review finding F1).
     *
     * A removed subsidiary can itself have hidden children behind the depth
     * cap (its own expand.relations) — those must not vanish silently, so
     * they're added to the parent's count alongside the node itself. This
     * roll-up describes the removed node's own content, which is identical
     * regardless of which owner is looking at it — so nodeCountToAdd (1 +
     * own hidden children) is computed once and applied UNCHANGED to each
     * owner: every owner lost the same node, carrying the same hidden
     * subtree behind it, from their own perspective.
     *
     * Property nodes never carry expand.relations of their own (they have no
     * children), so this roll-up is a no-op in the co-owned-property case —
     * it only matters for the (single-parent, by construction — see docblock
     * below) subsidiary-removal path. Both call sites can never collide: the
     * properties pass (addProperties-created nodes, single owner by
     * construction) always runs before the subsidiary pass in truncateToCap,
     * so a multi-parent removal is never combined with a non-zero relations
     * roll-up in the same call.
     *
     * The parent's expand array is also flagged `capped_{$field}: true` — on
     * the SAME field that was folded, not the whole expand object. This
     * count was created by TOTAL-cap truncation, not depth-cap truncation,
     * so expandNode() cannot resolve it (expandedNodeIds only affects the
     * depth-recursion in addSubsidiaries/addProperties) — without the flag
     * the Blade would render an expand button that busies to '…' forever on
     * click, since the rebuild it triggers can never satisfy the request.
     * Field-specific because a parent can carry a LEGITIMATE depth-cap
     * relations count alongside a total-cap-truncated properties count (or
     * vice versa) — flagging the whole object would freeze the still-
     * resolvable field's button too.
     */
    protected function removeNode(array &$nodes, array &$edges, int $index, string $field): void
    {
        $removedId = $nodes[$index]['id'];
        $nodeCountToAdd = 1;
        if ($field === 'relations') {
            $nodeCountToAdd += $nodes[$index]['expand']['relations'] ?? 0;
        }
        $parentIds = [];

        $edges = array_values(array_filter($edges, function ($e) use ($removedId, &$parentIds) {
            if ($e['to'] === $removedId) {
                $parentIds[$e['from']] = true;
            }

            return $e['from'] !== $removedId && $e['to'] !== $removedId;
        }));

        array_splice($nodes, $index, 1);

        if ($parentIds === []) {
            return;
        }

        foreach ($nodes as &$node) {
            if (! isset($parentIds[$node['id']])) {
                continue;
            }

            $node['expand'] = [
                'relations' => $node['expand']['relations'] ?? 0,
                'properties' => $node['expand']['properties'] ?? 0,
                // Preserve a flag already set by an earlier removeNode call
                // on this same parent (e.g. properties cut in pass 1, then
                // relations cut in pass 2) — only the field cut THIS call
                // gets newly flagged.
                'capped_relations' => $node['expand']['capped_relations'] ?? false,
                'capped_properties' => $node['expand']['capped_properties'] ?? false,
            ];
            $node['expand'][$field] += $nodeCountToAdd;
            $node['expand']['capped_'.$field] = true;
        }
        unset($node);
    }

    /**
     * Last step of finalize() (itself the last step of build()): attaches
     * per-node enrichment data — runs AFTER truncateToCap so aggregates/cards
     * are never computed for a node the cap already removed. Three
     * independent sub-steps: (a) value aggregate per
     * owner, derived from $propertyList regardless of whether enrichment was
     * fetched at all; (b) company card+signals, only for nodes present in
     * enrichment['companies']; (c) property card, only for nodes present in
     * enrichment['properties'].
     */
    protected function applyEnrichment(array &$nodes, array $enrichment, array $propertyList, ?string $searchedAlias, ?CarbonImmutable $now): void
    {
        $nodeIds = array_flip(array_column($nodes, 'id'));
        $agg = $this->aggregateProperties($propertyList, $nodeIds, $searchedAlias);
        $companies = $enrichment['companies'] ?? [];
        $propertiesById = $enrichment['properties'] ?? [];
        // lat/lng come from the portfolio row itself (the skråfoto link needs
        // them), not from enrichment — keyed by matrikel_id, last row wins for
        // a co-owned property (identical coordinates regardless of owner).
        $coordsByMid = [];
        foreach ($propertyList as $p) {
            $mid = (string) ($p['matrikel_id'] ?? '');
            if ($mid !== '') {
                $coordsByMid[$mid] = ['lat' => $p['latitude'] ?? null, 'lng' => $p['longitude'] ?? null];
            }
        }

        foreach ($nodes as &$node) {
            if (isset($agg[$node['id']])) {
                $node['agg'] = $agg[$node['id']];
            }

            if ($node['kind'] === 'property') {
                // Keyed off the node id, not meta.bfe — meta.bfe is deliberately
                // null for non-matriculated properties (addProperties), but the
                // matrikel_id (and its enrichment lookup) still applies to them.
                $mid = substr($node['id'], 4);
                if (isset($propertiesById[$mid])) {
                    $node['card'] = $this->propertyCard($propertiesById[$mid], $coordsByMid[$mid] ?? []);
                }

                continue;
            }

            $cvr = $node['cvr'];
            // Company enrichment is scoped to actual company kinds (spec:
            // person/foreign nodes get no enrichment before fase 2b, and
            // 'other' orphan-parent stubs never had their cvr sent to the
            // pool in the first place — see CompanyStructure::fetchEnrichmentData).
            if ($cvr !== null && in_array($node['kind'], self::ENRICHABLE_KINDS, true) && isset($companies[$cvr])) {
                $node['card'] = $this->companyCard($companies[$cvr]);
                $node['signals'] = $this->companySignals($companies[$cvr], $now);
            }
        }
        unset($node);
    }

    /**
     * Groups the property list by owner-node-id, reusing the SAME
     * ownedTargetId-style normalisation as addProperties (owner_cvr → node id,
     * or 'searched' when it equals $searchedAlias) so aggregates land on the
     * exact node a property was hung on — including the searched-company
     * case. $searchedAlias is null when there is no searched-company alias
     * (e.g. a future person-graph entry point); the owner === null case is
     * skipped first, so a null $searchedAlias can never accidentally match.
     * Independent of enrichment: this is derived purely from the property
     * list, so it is present even when no enrichment was ever fetched.
     */
    protected function aggregateProperties(array $propertyList, array $nodeIds, ?string $searchedAlias): array
    {
        $agg = [];
        foreach ($propertyList as $p) {
            $owner = $p['owner_cvr'] ?? null;
            $ownerId = $owner === null ? null : (isset($nodeIds[$owner]) ? $owner : ($owner === $searchedAlias ? 'searched' : null));
            if ($ownerId === null) {
                continue;
            }

            $agg[$ownerId] ??= ['count' => 0, 'value' => 0, 'valued' => 0];
            $agg[$ownerId]['count']++;
            $valuation = $p['valuation'] ?? null;
            if ($valuation !== null) {
                $agg[$ownerId]['value'] += $valuation;
                $agg[$ownerId]['valued']++;
            }
        }

        return $agg;
    }

    protected function companyCard(array $company): array
    {
        return array_filter([
            'equity' => $company['equity'] ?? null,
            'result' => $company['result'] ?? null,
            'fiscal_year' => $company['fiscal_year'] ?? null,
            'employees' => $company['employees'] ?? null,
            'website' => $company['website'] ?? null,
            'founded_date' => $company['founded_date'] ?? null,
            'industry' => $company['industry'] ?? null,
        ], fn ($v) => $v !== null);
    }

    /**
     * negative_equity: latest equity < 0. newly_founded: founded within the
     * last 12 months of a caller-supplied, deterministic $now — without $now
     * this signal can never be evaluated (never CarbonImmutable::now(), which
     * would make build() non-deterministic). no_financials: the company IS in
     * the enrichment map but carries no equity figure — distinct from a
     * company absent from enrichment entirely (no signals key at all, handled
     * by the caller), because "not fetched yet" must never look "financially
     * healthy".
     */
    protected function companySignals(array $company, ?CarbonImmutable $now): array
    {
        $signals = [];
        $equity = $company['equity'] ?? null;

        if ($equity !== null && $equity < 0) {
            $signals[] = 'negative_equity';
        } elseif ($equity === null) {
            $signals[] = 'no_financials';
        }

        $foundedDate = $company['founded_date'] ?? null;
        if ($now !== null && $foundedDate !== null && CarbonImmutable::parse($foundedDate)->greaterThan($now->subMonths(12))) {
            $signals[] = 'newly_founded';
        }

        return $signals;
    }

    protected function propertyCard(array $property, array $coords): array
    {
        return array_filter([
            'usage' => $property['usage'] ?? null,
            'latest_sale_date' => $property['latest_sale_date'] ?? null,
            'latest_sale_price' => $property['latest_sale_price'] ?? null,
            'valuation' => $property['valuation'] ?? null,
            'streetview_url' => $property['streetview_url'] ?? null,
            'lat' => $coords['lat'] ?? null,
            'lng' => $coords['lng'] ?? null,
        ], fn ($v) => $v !== null);
    }

    /**
     * Known company owner_kind values a company-ancestor row can legitimately
     * carry (reel = beneficial/ultimate owner, legal = direct/legal owner —
     * see ENRICHABLE_KINDS above, which both feed). Any other value (e.g. a
     * future/unexpected API value like 'ultimate', or simply absent) is
     * normalised to 'legal' below — NOT passed through raw. A raw passthrough
     * would let an unrecognised owner_kind collide with 'other' (the distinct
     * kind addAncestors() itself synthesises for orphan-parent stubs, below)
     * and would silently exclude a genuine company-ancestor from
     * ENRICHABLE_KINDS-gated enrichment (companyEnrichmentFromInfo's pool
     * call, applyEnrichment's card/signals) purely because the API used a
     * kind string this builder didn't happen to already know about.
     */
    protected const KNOWN_COMPANY_OWNER_KINDS = ['legal', 'reel'];

    protected function addAncestors(array $ancestors, string $query, array &$nodes, array &$seen, array &$edges, array &$edgeSeen): void
    {
        foreach ($ancestors as $i => $a) {
            $isCompany = $a['is_company'] ?? false;
            $foreign = $a['foreign'] ?? false;
            $cvr = $a['cvr'] ?? null;
            // Row index folded in so two distinct same-named persons never collapse (fase 1).
            $id = $cvr ?: 'person:'.md5($i.'|'.($a['person_name'] ?? '').'|'.($a['parent_of_cvr'] ?? ''));
            $ownerKind = $a['owner_kind'] ?? 'legal';
            $kind = $foreign ? 'foreign' : (! $isCompany ? 'person' : (
                in_array($ownerKind, self::KNOWN_COMPANY_OWNER_KINDS, true) ? $ownerKind : 'legal'
            ));

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
                // 'depth' drives deterministic cap-truncation (deepest layer cut
                // first); it is additive node metadata, not a shape change other
                // consumers depend on positionally.
                $nodes[] = ['id' => $cvr, 'label' => $s['name'] ?? ('CVR '.$cvr), 'cvr' => $cvr, 'kind' => 'subsidiary', 'share' => $s['ownership_share'] ?? null, 'expand' => null, 'depth' => $depth];
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
                // Depth-cap boundary reached (Task 9, Resights-dybde-4-fundet):
                // a lineær kæde of hidden descendants costs one expand-click per
                // level for no space saved. If N's ENTIRE hidden subtree (every
                // descendant, not just direct children) is small (≤3 nodes), skip
                // the expand-signal and render it fully — deterministically, from
                // the raw structure, with no expandedNodeIds involvement (Resights
                // must be visible on the FIRST build for the RS HoldCo case).
                // Larger subtrees keep the unchanged expand-button behaviour.
                if ($this->countDescendants($children) <= 3) {
                    $this->addSubtreeFully($children, $cvr, $depth + 1, $nodes, $seen, $edges, $edgeSeen);
                } else {
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
    }

    /**
     * Counts every descendant (children, grandchildren, ...) of $subs,
     * recursively — NOT just the direct children count used for the
     * expand-signal. Drives the Task 9 auto-expand threshold: a linear
     * chain of 3 single-child levels is 3 descendants, not 1.
     */
    protected function countDescendants(array $subs): int
    {
        $count = 0;
        foreach ($subs as $s) {
            if (! ($s['cvr'] ?? null)) {
                continue;
            }
            $count++;
            $count += $this->countDescendants($s['children'] ?? []);
        }

        return $count;
    }

    /**
     * Renders an entire hidden subtree unconditionally (Task 9 auto-expand),
     * with no depth cap and no expandedNodeIds involvement — every node down
     * to the leaves is added. Reuses the exact same node/edge shape and
     * dedup (`$seen`/`$edgeSeen`) as addSubsidiaries so a node that is ALSO
     * reachable another way (ancestor dedup, multi-parent co-owner) still
     * collapses to one node, one id, consistent with the rest of the
     * builder. 'depth' keeps incrementing for truncateToCap's deepest-layer-
     * first ordering, since auto-expanded nodes are still real, total-cap-
     * countable nodes (brief: "kan stadig trunkeres af total-cap").
     */
    protected function addSubtreeFully(array $subs, string $parentId, int $depth, array &$nodes, array &$seen, array &$edges, array &$edgeSeen): void
    {
        foreach ($subs as $s) {
            $cvr = $s['cvr'] ?? null;
            if (! $cvr) {
                continue;
            }
            if (! isset($seen[$cvr])) {
                $seen[$cvr] = true;
                $nodes[] = ['id' => $cvr, 'label' => $s['name'] ?? ('CVR '.$cvr), 'cvr' => $cvr, 'kind' => 'subsidiary', 'share' => $s['ownership_share'] ?? null, 'expand' => null, 'depth' => $depth];
            }
            if (! isset($edgeSeen[$parentId.'|'.$cvr])) {
                $edgeSeen[$parentId.'|'.$cvr] = true;
                $edges[] = ['from' => $parentId, 'to' => $cvr, 'label' => $this->shareLabel($s['ownership_share'] ?? null)];
            }

            $this->addSubtreeFully($s['children'] ?? [], $cvr, $depth + 1, $nodes, $seen, $edges, $edgeSeen);
        }
    }

    protected function addProperties(array $props, array $usage, array $expandedNodeIds, int $capPerCompany, ?string $searchedAlias, array &$nodes, array &$seen, array &$edges, array &$edgeSeen): void
    {
        $nodeIds = array_flip(array_column($nodes, 'id'));
        $perOwner = [];

        foreach ($props as $p) {
            $owner = $p['owner_cvr'] ?? null;
            $mid = (string) ($p['matrikel_id'] ?? '');
            // Owner must already be a node — properties of pruned companies appear
            // only once their owner is expanded into the graph (spec §Lazy-flow).
            $ownerId = $owner === null ? null : (isset($nodeIds[$owner]) ? $owner : ($owner === $searchedAlias ? 'searched' : null));
            if ($mid === '' || $ownerId === null) {
                continue;
            }

            $perOwner[$ownerId] ??= 0;
            $capLifted = in_array('props:'.$ownerId, $expandedNodeIds, true)
                || ($ownerId === 'searched' && $searchedAlias !== null && in_array('props:'.$searchedAlias, $expandedNodeIds, true));
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
