<div @if($enriching) wire:poll.3s="pollForUpdates" @endif>
    <flux:card>
        <div class="flex items-center justify-between mb-4">
            <flux:heading size="lg">{{ __('Company Structure') }}</flux:heading>
            @if($enriching)
                <div class="flex items-center gap-2 text-blue-600 dark:text-blue-400 text-sm">
                    <svg class="size-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <span>{{ __('Discovering subsidiary tree...') }}</span>
                    @if($companiesFound > 0)
                        <span class="font-medium">({{ $companiesFound }} {{ __('companies') }})</span>
                    @endif
                </div>
            @endif
        </div>

        @if(count($owners) === 0 && count($subsidiaries) === 0 && count($ancestors) === 0 && ! $enriching)
            <p class="text-sm text-zinc-500">{{ __('No structure data found.') }}</p>
        @else
            @php
                // The ownership tree (below) is now the single source for the
                // searched company's current owners — reel/legal/other rows were
                // removed (Variant A: they duplicated the tree's depth-1 roots).
                // $owners is still consulted for HISTORICAL owners only, which the
                // tree (built from current-only `ancestors` edges) doesn't cover.
                $allOwners = collect($owners);
                $currentOwners = $allOwners->filter(fn ($o) => $o['is_current'] ?? true);
                $historicalOwners = $allOwners->reject(fn ($o) => $o['is_current'] ?? true)->values();
            @endphp

            <div class="metis-org-chart">

                {{-- Ownership hierarchy: ONE full nested tree built from the flat
                     `ancestors` adjacency list (via parent_of_cvr), mirroring the
                     CVR click-through top to bottom — the searched company as the
                     conceptual root, its direct owners nested beneath it as tree
                     roots, and THEIR owners nested further, each company shown
                     once under the company it owns. This supersedes the old
                     separate "Ultimate beneficial owner" / "Legal owner" / "Other"
                     rows below the tree, which would otherwise duplicate the same
                     direct owners the tree already renders (Variant A). --}}
                @php $ownershipTree = $this->ownershipTree(); @endphp
                @if(count($ownershipTree) > 0)
                    <div class="org-section-label">{{ __('Ownership structure') }}</div>
                    <div class="metis-ownership-tree-scroll">
                        <div class="metis-ownership-tree">
                            @foreach($ownershipTree as $rootOwner)
                                @include('metis::livewire.sections.partials.ownership-tree-node', [
                                    'node' => $rootOwner,
                                    'depth' => 0,
                                    'expandedOwners' => $expandedOwners,
                                ])
                            @endforeach
                        </div>
                    </div>
                    <div class="org-trunk"></div>
                @endif

                {{-- Historical owners (collapsible) --}}
                @if($historicalOwners->count() > 0)
                    <div class="org-historical-block" x-data="{ open: {{ $currentOwners->count() === 0 ? 'true' : 'false' }} }">
                        <button type="button" @click="open = !open" class="text-xs text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300 inline-flex items-center gap-1 mb-2">
                            <span x-show="!open" class="inline-flex items-center gap-1">
                                <svg class="size-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
                                {{ __('Show :count historical', ['count' => $historicalOwners->count()]) }}
                            </span>
                            <span x-show="open" x-cloak class="inline-flex items-center gap-1">
                                <svg class="size-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" /></svg>
                                {{ __('Hide historical') }}
                            </span>
                        </button>
                        <div x-show="open" {!! $currentOwners->count() === 0 ? '' : 'x-cloak' !!}>
                            <div class="org-row {{ $historicalOwners->count() > 1 ? 'multi' : '' }}">
                                @foreach($historicalOwners as $owner)
                                    <div class="org-cell">
                                        <div class="org-node historical">
                                            @if($owner['is_company'] ?? false)
                                                <x-metis-link type="cvr" :query="$owner['cvr'] ?? ''" :label="$owner['person_name'] ?? '-'" />
                                            @else
                                                <x-metis-link type="person" :query="$owner['person_name'] ?? '-'" :label="$owner['person_name'] ?? '-'" />
                                            @endif
                                            <div class="org-meta">
                                                @if($owner['ownership_share'] ?? null)
                                                    <flux:badge size="sm" color="zinc">{{ number_format($owner['ownership_share'], 0) }}%</flux:badge>
                                                @endif
                                                <flux:badge size="sm" color="zinc">{{ __('Historical') }}</flux:badge>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                @endif

                {{-- Searched company (highlighted center) --}}
                <div class="org-self">
                    <div class="org-node primary">
                        <div class="font-semibold">{{ $companyName ?? '—' }}</div>
                        <div class="text-xs text-zinc-500 font-mono mt-0.5">CVR {{ $query }}</div>
                        <div class="text-[10px] uppercase tracking-wide text-amber-700 dark:text-amber-300 font-medium mt-1">
                            {{ __('Searched company') }}
                        </div>
                    </div>
                </div>

                @if(count($subsidiaries) > 0)
                    <div class="org-trunk"></div>
                    @if(count($subsidiaries) > 1)
                        <div class="org-bridge-row" style="--node-count: {{ count($subsidiaries) }}">
                            <div class="org-bridge-line"></div>
                        </div>
                        <div class="org-stem-row">
                            @foreach($subsidiaries as $sub)
                                <div class="org-stem-cell"><div class="org-stem-line"></div></div>
                            @endforeach
                        </div>
                    @endif

                    <div class="org-row {{ count($subsidiaries) > 1 ? 'multi' : '' }}">
                        @foreach($subsidiaries as $sub)
                            <div class="org-cell">
                                <div class="org-node">
                                    <x-metis-link type="cvr" :query="$sub['cvr']" :label="$sub['name'] ?? $sub['cvr']" />
                                    @if($sub['ownership_share'] ?? null)
                                        <div class="org-meta">
                                            <flux:badge size="sm" color="zinc">{{ $sub['ownership_share'] }}%</flux:badge>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="org-section-label">{{ __('Subsidiaries') }}</div>
                @endif

            </div>
        @endif
    </flux:card>

<style>
    [x-cloak] { display: none !important; }

    .metis-org-chart {
        --org-line: rgb(113 113 122);
    }
    .dark .metis-org-chart {
        --org-line: rgb(161 161 170);
    }

    .metis-org-chart {
        display: flex;
        flex-direction: column;
        align-items: center;
        padding: 0.5rem 0;
    }

    .metis-org-chart .org-section-label {
        font-size: 0.6875rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        font-weight: 600;
        color: rgb(113 113 122);
        margin-bottom: 0.5rem;
        margin-top: 0.5rem;
    }

    .dark .metis-org-chart .org-section-label {
        color: rgb(161 161 170);
    }

    .metis-org-chart .org-row {
        display: flex;
        justify-content: center;
        gap: 1rem;
        width: 100%;
    }

    .metis-org-chart .org-row.multi > .org-cell {
        flex: 1;
        min-width: 0;
    }

    .metis-org-chart .org-cell {
        display: flex;
        justify-content: center;
    }

    .metis-org-chart .org-node {
        background: white;
        border: 1px solid rgb(228 228 231);
        border-radius: 0.5rem;
        padding: 0.5rem 0.75rem;
        min-width: 10rem;
        max-width: 16rem;
        text-align: center;
        font-size: 0.875rem;
        box-shadow: 0 1px 2px rgba(0,0,0,0.04);
    }

    .dark .metis-org-chart .org-node {
        background: rgb(24 24 27);
        border-color: rgb(63 63 70);
    }

    .metis-org-chart .org-node.primary {
        background: rgb(254 243 199);
        border: 2px solid rgb(252 211 77);
        font-weight: 500;
    }

    .dark .metis-org-chart .org-node.primary {
        background: rgba(120, 53, 15, 0.2);
        border-color: rgb(180 83 9);
    }

    .metis-org-chart .org-node.historical {
        opacity: 0.6;
        border-style: dashed;
    }

    .metis-org-chart .org-meta {
        margin-top: 0.25rem;
        display: flex;
        gap: 0.25rem;
        justify-content: center;
        flex-wrap: wrap;
    }

    .metis-org-chart .org-drilldown {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        margin-top: 0.5rem;
        padding-top: 0.5rem;
        border-top: 1px solid rgb(228 228 231);
        font-size: 0.6875rem;
        color: var(--color-claret, rgb(37 99 235));
        text-decoration: none;
    }

    .dark .metis-org-chart .org-drilldown {
        border-top-color: rgb(63 63 70);
        color: rgb(96 165 250);
    }

    .metis-org-chart .org-drilldown:hover {
        text-decoration: underline;
    }

    .metis-org-chart .org-expand-toggle {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        margin-top: 0.25rem;
        font-size: 0.6875rem;
        color: rgb(82 82 91);
        background: none;
        border: 0;
        cursor: pointer;
        padding: 0.125rem 0.25rem;
    }

    .dark .metis-org-chart .org-expand-toggle {
        color: rgb(161 161 170);
    }

    .metis-org-chart .org-expand-toggle:hover {
        color: var(--color-claret, rgb(37 99 235));
    }

    .dark .metis-org-chart .org-expand-toggle:hover {
        color: rgb(96 165 250);
    }

    .metis-org-chart .org-expansion {
        margin-top: 0.5rem;
        padding-top: 0.5rem;
        border-top: 1px dashed rgb(212 212 216);
        text-align: left;
        max-height: 16rem;
        overflow-y: auto;
    }

    .metis-org-chart .org-expansion-row {
        display: flex;
        justify-content: space-between;
        gap: 0.5rem;
        padding: 0.125rem 0;
        font-size: 0.75rem;
    }

    .metis-org-chart .org-trunk {
        width: 2px;
        height: 1.25rem;
        background: var(--org-line);
    }

    .metis-org-chart .org-stem-row {
        display: flex;
        width: 100%;
    }

    .metis-org-chart .org-stem-cell {
        flex: 1;
        display: flex;
        justify-content: center;
    }

    .metis-org-chart .org-stem-line {
        width: 2px;
        height: 1.25rem;
        background: var(--org-line);
    }

    .metis-org-chart .org-bridge-row {
        width: 100%;
        display: flex;
        justify-content: center;
    }

    .metis-org-chart .org-bridge-line {
        width: calc(100% - (100% / var(--node-count, 1)));
        height: 2px;
        background: var(--org-line);
    }

    .metis-org-chart .org-historical-block {
        margin: 0.5rem 0;
        text-align: center;
        width: 100%;
    }

    /* Ownership tree: a real nested org-chart (parent above, owners nested
       beneath it) instead of the old flat depth-indented list. Deep chains
       (6+ levels) can run wide, so the tree scrolls horizontally inside its
       own container rather than the page. */
    .metis-org-chart .metis-ownership-tree-scroll {
        width: 100%;
        overflow-x: auto;
    }

    .metis-org-chart .metis-ownership-tree {
        display: inline-flex;
        flex-direction: column;
        align-items: flex-start;
        min-width: 100%;
        padding: 0.25rem 0;
    }

    .metis-org-chart .metis-tree-node {
        display: flex;
        flex-direction: column;
    }

    .metis-org-chart .metis-tree-row {
        display: flex;
        align-items: stretch;
    }

    /* The connector is a short horizontal branch reaching from the parent's
       vertical trunk (drawn by .metis-tree-children's border-left) into the
       card, so nesting reads as a real hierarchy rather than plain
       left-margin indentation. */
    .metis-org-chart .metis-tree-connector {
        position: relative;
        width: 1.25rem;
        flex-shrink: 0;
    }

    .metis-org-chart .metis-tree-connector::before {
        content: '';
        position: absolute;
        left: 0;
        top: 50%;
        width: 100%;
        height: 2px;
        background: var(--org-line);
    }

    .metis-org-chart .metis-tree-card {
        padding: 0.375rem 0;
    }

    .metis-org-chart .metis-tree-card .org-cell {
        justify-content: flex-start;
    }

    .metis-org-chart .metis-tree-card .org-node {
        text-align: left;
        min-width: 12rem;
    }

    /* Children hang beneath their parent; the border-left is the vertical
       trunk each child's connector branches off from. Removed on the last
       child so the trunk doesn't overshoot past the final branch. */
    .metis-org-chart .metis-tree-children {
        display: flex;
        flex-direction: column;
        margin-left: 0.75rem;
        border-left: 2px solid var(--org-line);
    }
</style>
</div>
