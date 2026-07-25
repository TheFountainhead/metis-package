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
