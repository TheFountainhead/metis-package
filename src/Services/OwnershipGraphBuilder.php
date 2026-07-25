<?php

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
        $this->addSubsidiaries($structure['subsidiaries'] ?? [], 'searched', 1, $caps['subsidiary_depth'], $expandedNodeIds, $nodes, $seen, $edges, $edgeSeen);
        $this->addProperties($properties['list'] ?? [], $properties['usage'] ?? [], $expandedNodeIds, $caps['properties_per_company'], $query, $nodes, $seen, $edges, $edgeSeen);
        $this->truncateToCap($caps['total_nodes'], $nodes, $edges);

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
     * Remove node at $index, drop its edges, and fold it onto its parent's
     * expand affordance (the `$field` key: 'relations' or 'properties').
     * The parent is whichever node the removed node's inbound edge came from.
     *
     * A removed subsidiary can itself have hidden children behind the depth
     * cap (its own expand.relations) — those must not vanish silently, so
     * they're added to the parent's count alongside the node itself.
     */
    protected function removeNode(array &$nodes, array &$edges, int $index, string $field): void
    {
        $removedId = $nodes[$index]['id'];
        $nodeCountToAdd = 1;
        if ($field === 'relations') {
            $nodeCountToAdd += $nodes[$index]['expand']['relations'] ?? 0;
        }
        $parentId = null;

        $edges = array_values(array_filter($edges, function ($e) use ($removedId, &$parentId) {
            if ($e['to'] === $removedId) {
                $parentId = $e['from'];
            }

            return $e['from'] !== $removedId && $e['to'] !== $removedId;
        }));

        array_splice($nodes, $index, 1);

        if ($parentId === null) {
            return;
        }

        foreach ($nodes as &$node) {
            if ($node['id'] === $parentId) {
                $node['expand'] = [
                    'relations' => $node['expand']['relations'] ?? 0,
                    'properties' => $node['expand']['properties'] ?? 0,
                ];
                $node['expand'][$field] += $nodeCountToAdd;
                break;
            }
        }
        unset($node);
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
