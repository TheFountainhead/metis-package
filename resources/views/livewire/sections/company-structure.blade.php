<div @if($enriching) wire:poll.3s="pollForUpdates" @endif wire:init="loadProperties">
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

        @if(count($owners) === 0 && count($graphModel['nodes'] ?? []) <= 1 && ! $enriching)
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

                {{-- Ownership relations graph (fase 1). Free-form dagre layout in
                     frankston.io editorial style: owners on top, searched company
                     at the bottom, ownership flowing downward. Replaces the old
                     CSS org-chart. Node positions + edge coordinates are computed
                     in JS (dagre); nodes are absolutely-positioned HTML cards,
                     edges are SVG lines.

                     Read the SAME $graphModel the Alpine watcher watches — a single
                     source of truth. $graphModel is rebuilt exclusively by
                     OwnershipGraphBuilder::build() (see CompanyStructure::rebuild());
                     no other code path may mutate it directly, so re-deriving it
                     here from anything else would let the graph's initial x-data
                     diverge from the watched property. One source, no divergence. --}}
                @php
                    $graph = $this->graphModel;
                @endphp
                @include('metis::livewire.sections.partials.ownership-graph', ['graph' => $graph])

                @if(count($graph['nodes']) > 1)
                    {{-- Always shown (not gated on an actual <100% sum): computing a
                         per-node share total across the whole chain is noise, and the
                         caveat holds for every CVR graph anyway. --}}
                    <p class="mgraph-note">
                        {{ __('Ownership shares come from CVR and may total under 100% — the register lists only owners of 5% or more, often in bands.') }}
                    </p>
                @endif

                {{-- Property-fetch status. `loaded`/`empty` show no note — only
                     the FAILURE/BUILDING states are visible (spec's null ≠ empty:
                     'empty' silently means no properties, not an error). --}}
                @if($propertiesStatus === 'building')
                    <p class="mgraph-note" wire:key="properties-status-{{ $propertiesAttempts }}" x-data x-init="setTimeout(() => $wire.loadProperties(), {{ min(3 + 3 * $propertiesAttempts, 12) * 1000 }})">
                        {{ __('Ejendomme hentes stadig…') }}
                    </p>
                @elseif($propertiesStatus === 'failed')
                    <p class="mgraph-note">
                        {{ __('Ejendomme kunne ikke hentes.') }}
                        <button type="button" wire:click="retryProperties" class="underline">{{ __('Prøv igen') }}</button>
                    </p>
                @endif
                {{-- No separate enrichment-trigger x-init here (multi-agent review, F-D):
                     every code path that settles $propertiesStatus already reaches
                     loadEnrichment() PHP-side — the top-level wire:init="loadProperties"
                     (this component's root element) fires loadProperties() once per
                     mount, and loadProperties() itself calls loadEnrichment() at the end
                     for every settled outcome (F2 fix); pollForUpdates() calls it again
                     on enrichment completion (F1 fix); retryProperties()/retryEnrichment()
                     each re-trigger their own path. A redundant Alpine trigger here added
                     no reachable case and only obscured that PHP-side coverage — removed,
                     verified by the full CompanyStructureTest suite staying green (every
                     enrichment-path test reaches enrichmentStatus==='loaded' via the real
                     component actions, not this trigger). --}}

                {{-- Company/property-enrichment fetch status (F5): mirrors the
                     properties-failed note above — a discreet retry affordance,
                     no other visible enrichment UI (Task 4 owns the rest). --}}
                @if($enrichmentStatus === 'failed')
                    <p class="mgraph-note">
                        {{ __('Nøgletal kunne ikke hentes.') }}
                        <button type="button" wire:click="retryEnrichment" class="underline">{{ __('Prøv igen') }}</button>
                    </p>
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

                {{-- The searched company AND its subsidiaries are rendered as
                     graph nodes in the shared ownership-graph partial (sand
                     card + thick ink border for 'searched'; subsidiary cards
                     below it) — the old CSS org-chart trunk/subsidiary rows
                     are superseded by the graph and removed (subsidiaries are
                     no longer public state; the builder reads them from
                     protected $structureData). --}}

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

    /* ---- Frankston.io editorial org-chart ------------------------------
       Deliberately its own light-palette styled section (frankston.io brand
       chart), independent of metis's own dark-mode surface. Technique:
       classic CSS ::before/::after org-chart connectors, built top-down with
       the searched company as the render-root and its owners as recursive
       children, then the WHOLE .org container is flipped vertically with
       transform:scaleY(-1) so owners land at the top (ownership flows
       downward to the searched company) — each .card counter-flips so its
       text stays upright. */
    /* Same-origin fonts (metis-host serves these from public/fonts/). Cross-origin
       from frankston.io lacks a CORS header, so the browser would block them and
       silently fall back to Georgia/system fonts — killing the editorial look. */
    @font-face { font-family:'Spectral'; src:url('/fonts/spectral-v15-latin-600.woff2') format('woff2'); font-weight:600; font-display:swap; }
    @font-face { font-family:'IBM Plex Sans'; src:url('/fonts/ibm-plex-sans-v23-latin-regular.woff2') format('woff2'); font-weight:400; font-display:swap; }
    @font-face { font-family:'IBM Plex Sans'; src:url('/fonts/ibm-plex-sans-v23-latin-500.woff2') format('woff2'); font-weight:500; font-display:swap; }
    @font-face { font-family:'IBM Plex Mono'; src:url('/fonts/ibm-plex-mono-v20-latin-regular.woff2') format('woff2'); font-weight:400; font-display:swap; }

    .frankston-org-scroll {
        --bg:#f6efe3; --bg-2:#efe6d4; --rule-strong:#b8a884; --ink:#1a1a1a; --ink-2:#3a3a3a; --ink-3:#6b6457;
        --fd:"Spectral",Georgia,serif; --fb:"IBM Plex Sans",sans-serif; --fm:"IBM Plex Mono",monospace;
        width: 100%;
        overflow-x: auto;
        background: var(--bg);
        border: 1px solid var(--rule-strong);
        border-radius: 0.5rem;
        padding: 32px 24px 24px;
        color: var(--ink);
        font-family: var(--fb);
    }

    .frankston-org-scroll .org { overflow-x: auto; padding-bottom: 8px; transform: scaleY(-1); }
    .frankston-org-scroll .org .card { transform: scaleY(-1); }
    .frankston-org-scroll .org ul { position: relative; padding-top: 24px; display: flex; justify-content: center; gap: 20px; list-style: none; margin: 0; }
    .frankston-org-scroll .org li { position: relative; padding: 24px 10px 0; }
    .frankston-org-scroll .org li::before,
    .frankston-org-scroll .org li::after { content: ''; position: absolute; top: 0; right: 50%; width: 50%; height: 24px; border-top: 1px solid var(--rule-strong); }
    .frankston-org-scroll .org li::after { right: auto; left: 50%; }
    .frankston-org-scroll .org li:only-child::before,
    .frankston-org-scroll .org li:only-child::after { display: none; }
    .frankston-org-scroll .org li:only-child { padding-top: 24px; }
    .frankston-org-scroll .org li:only-child::before { display: block; left: 50%; right: auto; width: 0; border-top: 0; border-left: 1px solid var(--rule-strong); height: 24px; }
    .frankston-org-scroll .org li:first-child::before,
    .frankston-org-scroll .org li:last-child::after { border: 0; }
    .frankston-org-scroll .org ul ul::before { content: ''; position: absolute; top: 0; left: 50%; height: 24px; border-left: 1px solid var(--rule-strong); }
    .frankston-org-scroll .org > ul > li { padding-top: 0; }
    .frankston-org-scroll .org > ul > li::before,
    .frankston-org-scroll .org > ul > li::after { display: none; }

    .frankston-org-scroll .node { display: flex; justify-content: center; }
    .frankston-org-scroll .card { display: inline-block; background: var(--bg); border: 1px solid var(--rule-strong); padding: 10px 14px 11px; min-width: 152px; text-align: left; }
    .frankston-org-scroll .cname { font-family: var(--fd); font-weight: 600; font-size: 15px; letter-spacing: -0.01em; line-height: 1.2; white-space: nowrap; }
    .frankston-org-scroll .cname a { color: inherit; text-decoration: none; }
    .frankston-org-scroll .cname a:hover { text-decoration: underline; }
    .frankston-org-scroll .cmeta { display: flex; align-items: baseline; gap: 12px; margin-top: 5px; }
    .frankston-org-scroll .ckind { font-family: var(--fm); font-size: 10px; text-transform: uppercase; letter-spacing: 0.06em; color: var(--m); }
    .frankston-org-scroll .ckind.you { color: var(--ink); }
    .frankston-org-scroll .cshare { font-family: var(--fm); font-size: 12.5px; color: var(--ink-2); font-variant-numeric: tabular-nums; margin-left: auto; }
    .frankston-org-scroll .cflag { font-family: var(--fm); font-size: 10px; color: var(--ink-3); margin-top: 4px; }
    .frankston-org-scroll .searched { background: var(--bg-2); border-color: var(--ink); border-width: 1.5px; }
    .frankston-org-scroll .searched .cname { font-size: 16.5px; }
</style>
</div>
