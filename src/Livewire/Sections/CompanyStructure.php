<?php

namespace TheFountainhead\Metis\Livewire\Sections;

use TheFountainhead\Metis\Services\RegistryApi;

class CompanyStructure extends MetisSection
{
    public array $owners = [];
    public array $subsidiaries = [];
    public array $ancestors = [];
    public bool $enriching = false;
    public int $companiesFound = 0;
    public ?string $companyName = null;

    /**
     * Per-owner expansion state for the inline "Udfold struktur"-toggle.
     * Keyed by ownerKey() (cvr or person_name slug). Value: array of
     * companies the owner is currently active in.
     */
    public array $expandedOwners = [];

    protected function sectionTitle(): string
    {
        return __('Company Structure');
    }

    /**
     * Override the parent placeholder with a structure-shaped skeleton
     * (3 stacked card-rows + connectors) so users can see at a glance
     * that the org-chart is being assembled.
     */
    public function placeholder(): string
    {
        $title = __('Company Structure');
        $loading = __('Loading company structure...');

        return <<<HTML
            <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
                <div class="flex items-center justify-between mb-6">
                    <span class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">{$title}</span>
                    <div class="flex items-center gap-2 text-blue-600 dark:text-blue-400 text-sm">
                        <svg class="size-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span>{$loading}</span>
                    </div>
                </div>

                <div class="flex flex-col items-center gap-3 animate-pulse">
                    <div class="flex gap-4">
                        <div class="h-14 w-40 bg-zinc-100 dark:bg-zinc-800 rounded-lg"></div>
                        <div class="h-14 w-40 bg-zinc-100 dark:bg-zinc-800 rounded-lg"></div>
                    </div>
                    <div class="w-0.5 h-5 bg-zinc-200 dark:bg-zinc-700"></div>
                    <div class="h-16 w-48 bg-amber-50 dark:bg-amber-900/20 border-2 border-amber-200 dark:border-amber-800 rounded-lg"></div>
                    <div class="w-0.5 h-5 bg-zinc-200 dark:bg-zinc-700"></div>
                    <div class="flex gap-4">
                        <div class="h-12 w-36 bg-zinc-100 dark:bg-zinc-800 rounded-lg"></div>
                        <div class="h-12 w-36 bg-zinc-100 dark:bg-zinc-800 rounded-lg"></div>
                        <div class="h-12 w-36 bg-zinc-100 dark:bg-zinc-800 rounded-lg"></div>
                    </div>
                </div>
            </div>
        HTML;
    }

    public function mount(string $query): void
    {
        $this->query = $query;
        $api = app(RegistryApi::class);

        // Try local DB first (has full hierarchy)
        $result = rescue(fn () => $api->fetchCompanyStructure($query), []);
        $this->owners = $result['owners'] ?? [];
        $this->subsidiaries = $result['subsidiaries'] ?? [];
        $this->ancestors = $result['ancestors'] ?? [];
        $this->companyName = $result['name'] ?? null;

        // Fallback: fetch owners + name from CVR Elasticsearch
        if (empty($this->owners) || ! $this->companyName) {
            $company = rescue(fn () => $api->fetchCompanyInfo($query));
            if (empty($this->owners)) {
                $this->owners = $company['owners'] ?? [];
            }
            $this->companyName = $this->companyName ?? ($company['name'] ?? null);
        }

        // For each company owner, fetch their owners too (one level deep).
        // NB: tidligere blev EJERENS datterselskaber vist som det søgte selskabs
        // egne når subsidiaries var tomme — faktuelt forkert under et
        // DATTERSELSKABER-label (JEUDAN viste Chr. Augustinus' porteføljeselskaber).
        // Ejerens øvrige selskaber ses korrekt mærket via 'Udfold struktur'.
        foreach ($this->owners as $i => $owner) {
            if (($owner['is_company'] ?? false) && ($owner['cvr'] ?? null)) {
                $parentInfo = rescue(fn () => $api->fetchCompanyInfo($owner['cvr']));
                if ($parentInfo) {
                    $this->owners[$i]['parent_owners'] = $parentInfo['owners'] ?? [];
                }
            }
        }

        $status = rescue(fn () => app(RegistryApi::class)->getEnrichmentStatus($query));
        $this->enriching = in_array($status['status'] ?? '', ['pending', 'running']);
        $this->companiesFound = $status['companies_found'] ?? 0;
    }

    public function pollForUpdates(): void
    {
        if (! $this->enriching) {
            return;
        }

        $status = rescue(fn () => app(RegistryApi::class)->getEnrichmentStatus($this->query));
        $newStatus = $status['status'] ?? 'completed';
        $this->companiesFound = $status['companies_found'] ?? 0;

        if (in_array($newStatus, ['completed', 'failed'])) {
            $this->enriching = false;
            $result = rescue(fn () => app(RegistryApi::class)->fetchCompanyStructure($this->query), []);
            // Owners don't change during subsidiary-enrichment — preserve the lifted shape
            $this->subsidiaries = $result['subsidiaries'] ?? $this->subsidiaries;
            // Ancestors deepen as enrichment fills them in
            $this->ancestors = $result['ancestors'] ?? $this->ancestors;
        }
    }

    /**
     * Lazy-load and cache the list of OTHER companies a given owner is active in.
     * Triggered from Alpine.js click on the "Udfold struktur"-button per owner card.
     */
    public function toggleOwnerExpansion(string $ownerKey): void
    {
        if (isset($this->expandedOwners[$ownerKey])) {
            unset($this->expandedOwners[$ownerKey]);

            return;
        }

        $owner = collect($this->owners)->first(fn ($o) => $this->ownerKey($o) === $ownerKey);
        if (! $owner) {
            return;
        }

        $api = app(RegistryApi::class);
        $companies = [];

        if ($owner['is_company'] ?? false) {
            // For company-owners: fetch their structure (subs) + parent_owners (already loaded)
            if ($owner['cvr'] ?? null) {
                $structure = rescue(fn () => $api->fetchCompanyStructure($owner['cvr']), []);
                $companies = collect($structure['subsidiaries'] ?? [])
                    ->map(fn ($s) => [
                        'name' => $s['name'] ?? $s['cvr'],
                        'cvr' => $s['cvr'],
                        'role' => __('Subsidiary'),
                        'share' => $s['ownership_share'] ?? null,
                    ])
                    ->values()
                    ->toArray();
            }
        } else {
            // For person-owners: fetch their roles across all companies
            $rolesData = rescue(fn () => $api->fetchPersonRoles($owner['person_name'] ?? ''));
            $allCompanies = collect($rolesData['data']['companies'] ?? $rolesData['companies'] ?? []);
            $companies = $allCompanies
                ->filter(fn ($c) => collect($c['roles'] ?? [])->contains('is_current', true))
                ->filter(fn ($c) => ($c['cvr'] ?? '') !== $this->query) // exclude searched company
                ->map(function ($c) {
                    $activeRoles = collect($c['roles'] ?? [])->filter(fn ($r) => $r['is_current'] ?? false);

                    return [
                        'name' => $c['name'] ?? '',
                        'cvr' => $c['cvr'] ?? '',
                        'role' => $activeRoles->pluck('role_label')->unique()->implode(', '),
                        'share' => null,
                    ];
                })
                ->values()
                ->toArray();
        }

        $this->expandedOwners[$ownerKey] = $companies;
    }

    public function ownerKey(array $owner): string
    {
        return ($owner['is_company'] ?? false)
            ? 'cvr:'.($owner['cvr'] ?? '')
            : 'person:'.md5($owner['person_name'] ?? '');
    }

    /**
     * Flat {nodes, edges} model for the dagre graph render (fase 1). Built
     * directly from the flat `$ancestors` list: one node per owner plus a
     * `searched` node for the queried company, and one edge per owner→owned
     * relation. dagre lays this out; the Blade renders nodes as frankston
     * cards and edges as SVG lines. Node ids: cvr for companies, `person:<md5>`
     * for persons/foreign entities without a cvr, `searched` for the queried co.
     *
     * @return array{nodes: list<array>, edges: list<array>}
     */
    public function ownershipGraphData(): array
    {
        $nodes = [
            ['id' => 'searched', 'label' => $this->companyName ?? __('Searched company'), 'cvr' => $this->query, 'kind' => 'searched', 'share' => null],
        ];
        $seen = ['searched' => true];
        $edges = [];
        $edgeSeen = [];

        foreach ($this->ancestors as $i => $a) {
            $isCompany = $a['is_company'] ?? false;
            $foreign = $a['foreign'] ?? false;
            $cvr = $a['cvr'] ?? null;

            // Stable id: real cvr for DK companies (a genuine unique key), else a
            // per-row hash. The row index $i is folded in so two distinct people
            // sharing a name+parent (common in CVR data, e.g. two "Jens Hansen"
            // owning the same company) never collapse into one node and drop an
            // owner. cvr'd companies still dedup correctly across rows.
            $id = $cvr ?: 'person:'.md5($i.'|'.($a['person_name'] ?? '').'|'.($a['parent_of_cvr'] ?? ''));

            // A non-company owner is a physical person → the dark person-node.
            // Companies carry their owner_kind (legal/reel/other); foreign wins.
            $kind = $foreign ? 'foreign' : (! $isCompany ? 'person' : ($a['owner_kind'] ?? 'legal'));

            if (! isset($seen[$id])) {
                $seen[$id] = true;
                $nodes[] = [
                    'id' => $id,
                    'label' => $a['person_name'] ?? '',
                    'cvr' => $cvr,
                    'kind' => $kind,
                    'share' => $a['ownership_share'] ?? null,
                ];
            }

            // Edge: this owner owns the company identified by parent_of_cvr.
            // A null parent_of_cvr — OR a parent_of_cvr equal to the searched cvr
            // (some backend rows fill the searched company's own cvr instead of
            // null for a direct owner) — means it owns the searched company, so
            // the edge points at the synthetic 'searched' node. Normalising here
            // also stops the orphan-stub pass below from minting a duplicate root
            // node for the searched company.
            $ownedId = $this->ownedTargetId($a['parent_of_cvr'] ?? null);
            $edge = ['from' => $id, 'to' => $ownedId, 'label' => $this->shareLabel($a['ownership_share'] ?? null)];
            // Dedup identical edges: a repeated ancestor row (backend BFS quirk)
            // would otherwise draw two overlapping lines with stacked % labels.
            if (! isset($edgeSeen[$id.'|'.$ownedId])) {
                $edgeSeen[$id.'|'.$ownedId] = true;
                $edges[] = $edge;
            }
        }

        // Orphan-parent stubs: an ancestor's parent_of_cvr can point at a company
        // that was capped/pruned upstream and so has no row of its own. Without a
        // node, the render drops the edge and the owner floats detached, reading
        // as a top-UBO it is not. Synthesise a minimal stub for every referenced
        // parent that isn't already a node, so the chain stays connected. The
        // searched company itself is never a stub (normalised to 'searched' above).
        foreach ($this->ancestors as $a) {
            $parent = $this->ownedTargetId($a['parent_of_cvr'] ?? null);
            if ($parent !== 'searched' && ! isset($seen[$parent])) {
                $seen[$parent] = true;
                $nodes[] = [
                    'id' => $parent,
                    'label' => 'CVR '.$parent,
                    'cvr' => $parent,
                    'kind' => 'other',
                    'share' => null,
                ];
            }
        }

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    /**
     * Resolve an ancestor's parent_of_cvr to the node id it owns. Null, or the
     * searched company's own cvr, both mean "owns the searched company" → the
     * synthetic 'searched' node. Any other cvr is that company's node id.
     */
    protected function ownedTargetId(?string $parentOfCvr): string
    {
        if ($parentOfCvr === null || $parentOfCvr === $this->query) {
            return 'searched';
        }

        return $parentOfCvr;
    }

    /**
     * Topology key for the graph's Livewire wrapper. The graph lives in a
     * wire:ignore subtree, so Livewire only re-mounts (and Alpine only re-lays-out
     * via dagre) when the wire:key VALUE changes. Keying on the query alone freezes
     * a stale partial graph after an enrichment poll deepens the ancestor chain.
     *
     * We hash only the SHAPE — node ids + kinds and the from/to edge topology —
     * NOT labels or shares. A fuller graph (new node/edge) changes the key and
     * triggers a fresh layout; a mere relabel (e.g. a late companyName fetch) or a
     * share change does not, so the user's zoom/pan survives an enrichment poll
     * that only refined text. Alpine updates those via x-text without a re-mount.
     */
    public function graphKey(array $graph): string
    {
        $topology = [
            'n' => array_map(fn ($node) => [$node['id'], $node['kind']], $graph['nodes']),
            'e' => array_map(fn ($edge) => [$edge['from'], $edge['to']], $graph['edges']),
        ];

        return substr(md5(json_encode($topology)), 0, 12);
    }

    /**
     * Format an ownership share for an edge label. A single percentage for now;
     * fase 2 can swap in the CVR interval band (e.g. "20-24,99%").
     */
    protected function shareLabel(?float $share): string
    {
        if ($share === null) {
            return '';
        }

        return (fmod($share, 1.0) === 0.0 ? (string) (int) $share : rtrim(rtrim(number_format($share, 2, ',', ''), '0'), ',')).' %';
    }

    public function render()
    {
        return view('metis::livewire.sections.company-structure');
    }
}
