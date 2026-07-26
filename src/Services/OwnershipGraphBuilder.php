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

        // usage-bagudkompatibilitet: enrichment['properties'][mid]['usage'] wins
        // over the legacy properties['usage'][mid] map (2a.1 shape) so Task 3 can
        // migrate the usage-populating component without a big-bang cutover.
        $usage = $properties['usage'] ?? [];
        foreach ($enrichment['properties'] ?? [] as $mid => $entry) {
            if (($entry['usage'] ?? null) !== null) {
                $usage[$mid] = $entry['usage'];
            }
        }

        $this->addAncestors($structure['ancestors'] ?? [], $query, $nodes, $seen, $edges, $edgeSeen);
        $this->addSubsidiaries($structure['subsidiaries'] ?? [], 'searched', 1, $caps['subsidiary_depth'], $expandedNodeIds, $nodes, $seen, $edges, $edgeSeen);
        $this->addProperties($properties['list'] ?? [], $usage, $expandedNodeIds, $caps['properties_per_company'], $query, $nodes, $seen, $edges, $edgeSeen);
        $this->truncateToCap($caps['total_nodes'], $nodes, $edges);
        $this->applyEnrichment($nodes, $enrichment, $properties['list'] ?? [], $query, $now);

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    /**
     * Last step of build(): enforce the total node cap deterministically.
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
     * Last step of build(): attaches per-node enrichment data — runs AFTER
     * truncateToCap so aggregates/cards are never computed for a node the cap
     * already removed. Three independent sub-steps: (a) value aggregate per
     * owner, derived from $propertyList regardless of whether enrichment was
     * fetched at all; (b) company card+signals, only for nodes present in
     * enrichment['companies']; (c) property card, only for nodes present in
     * enrichment['properties'].
     */
    protected function applyEnrichment(array &$nodes, array $enrichment, array $propertyList, string $query, ?CarbonImmutable $now): void
    {
        $nodeIds = array_flip(array_column($nodes, 'id'));
        $agg = $this->aggregateProperties($propertyList, $nodeIds, $query);
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
            if ($cvr !== null && isset($companies[$cvr])) {
                $node['card'] = $this->companyCard($companies[$cvr]);
                $node['signals'] = $this->companySignals($companies[$cvr], $now);
            }
        }
        unset($node);
    }

    /**
     * Groups the property list by owner-node-id, reusing the SAME
     * ownedTargetId-style normalisation as addProperties (owner_cvr → node id,
     * or 'searched' when it equals the query) so aggregates land on the exact
     * node a property was hung on — including the searched-company case.
     * Independent of enrichment: this is derived purely from the property
     * list, so it is present even when no enrichment was ever fetched.
     */
    protected function aggregateProperties(array $propertyList, array $nodeIds, string $query): array
    {
        $agg = [];
        foreach ($propertyList as $p) {
            $owner = $p['owner_cvr'] ?? null;
            $ownerId = $owner === null ? null : (isset($nodeIds[$owner]) ? $owner : ($owner === $query ? 'searched' : null));
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

    protected function addProperties(array $props, array $usage, array $expandedNodeIds, int $capPerCompany, string $query, array &$nodes, array &$seen, array &$edges, array &$edgeSeen): void
    {
        $nodeIds = array_flip(array_column($nodes, 'id'));
        $perOwner = [];

        foreach ($props as $p) {
            $owner = $p['owner_cvr'] ?? null;
            $mid = (string) ($p['matrikel_id'] ?? '');
            // Owner must already be a node — properties of pruned companies appear
            // only once their owner is expanded into the graph (spec §Lazy-flow).
            $ownerId = $owner === null ? null : (isset($nodeIds[$owner]) ? $owner : ($owner === $query ? 'searched' : null));
            if ($mid === '' || $ownerId === null) {
                continue;
            }

            $perOwner[$ownerId] ??= 0;
            $capLifted = in_array('props:'.$ownerId, $expandedNodeIds, true)
                || ($ownerId === 'searched' && in_array('props:'.$query, $expandedNodeIds, true));
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
