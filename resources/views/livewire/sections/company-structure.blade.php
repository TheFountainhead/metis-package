<div @if($enriching) wire:poll.3s="pollForUpdates" @endif>
    <flux:card>
        <flux:heading size="lg" class="mb-4">{{ __('Company Structure') }}</flux:heading>

        @if(count($owners) === 0 && count($subsidiaries) === 0 && ! $enriching)
            <p class="text-sm text-zinc-500">{{ __('No structure data found.') }}</p>
        @else
            <div class="metis-org-chart">

                @if(count($owners) > 0)
                    {{-- Owners row (above searched company) --}}
                    <ul class="org-row {{ count($owners) > 1 ? 'multi' : '' }}" style="--node-count: {{ count($owners) }}">
                        @foreach($owners as $owner)
                            <li>
                                <div class="org-node {{ ! ($owner['is_current'] ?? true) ? 'historical' : '' }}">
                                    @if($owner['is_company'] ?? false)
                                        <x-metis-link type="cvr" :query="$owner['cvr'] ?? ''" :label="$owner['person_name'] ?? '-'" />
                                    @else
                                        <x-metis-link type="person" :query="$owner['person_name'] ?? '-'" :label="$owner['person_name'] ?? '-'" />
                                    @endif
                                    <div class="org-meta">
                                        @if($owner['ownership_share'] ?? null)
                                            <flux:badge size="sm" color="sky">{{ number_format($owner['ownership_share'], 0) }}%</flux:badge>
                                        @endif
                                        @if(! ($owner['is_current'] ?? true))
                                            <flux:badge size="sm" color="zinc">{{ __('Historical') }}</flux:badge>
                                        @endif
                                    </div>
                                    {{-- Grand-parent owners (one level above the owner) --}}
                                    @if(! empty($owner['parent_owners']))
                                        <div class="org-grandparents">
                                            <div class="text-[10px] text-zinc-400 uppercase tracking-wide mb-1">{{ __('Owned by') }}</div>
                                            @foreach($owner['parent_owners'] as $parentOwner)
                                                <div class="text-xs">
                                                    @if($parentOwner['is_company'] ?? false)
                                                        <x-metis-link type="cvr" :query="$parentOwner['cvr'] ?? ''" :label="$parentOwner['person_name'] ?? '-'" />
                                                    @else
                                                        <x-metis-link type="person" :query="$parentOwner['person_name'] ?? '-'" :label="$parentOwner['person_name'] ?? '-'" />
                                                    @endif
                                                    @if($parentOwner['ownership_share'] ?? null)
                                                        <span class="text-zinc-400 ml-1">{{ number_format($parentOwner['ownership_share'], 0) }}%</span>
                                                    @endif
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>

                    {{-- Vertical connector down to searched company --}}
                    <div class="org-trunk"></div>
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
                    {{-- Vertical connector down to subsidiaries --}}
                    <div class="org-trunk"></div>

                    <ul class="org-row {{ count($subsidiaries) > 1 ? 'multi' : '' }} subs" style="--node-count: {{ count($subsidiaries) }}">
                        @foreach($subsidiaries as $sub)
                            <li>
                                <div class="org-node">
                                    <x-metis-link type="cvr" :query="$sub['cvr']" :label="$sub['name'] ?? $sub['cvr']" />
                                    @if($sub['ownership_share'] ?? null)
                                        <div class="org-meta">
                                            <flux:badge size="sm" color="zinc">{{ $sub['ownership_share'] }}%</flux:badge>
                                        </div>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif

            </div>
        @endif

        @if($enriching)
            <div class="flex items-center gap-2 text-blue-500 text-sm mt-4">
                <svg class="size-3 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                {{ __('Discovering subsidiary tree...') }}
                @if($companiesFound > 0)
                    <span class="font-medium">{{ $companiesFound }} {{ __('companies') }}</span>
                @endif
            </div>
        @endif
    </flux:card>

{{-- Style INSIDE root-div for Livewire 3 morphdom-compatibility.
     @once outside root-div gets stripped on lazy-load + every poll.
     Pure CSS tree connectors via pseudo-elements. No JS dependency. --}}
<style>
    .metis-org-chart {
        display: flex;
        flex-direction: column;
        align-items: center;
        padding: 0.5rem 0;
        gap: 0;
    }

    .metis-org-chart .org-row {
        list-style: none;
        padding: 0;
        margin: 0;
        display: flex;
        gap: 1rem;
        flex-wrap: nowrap;
        justify-content: center;
        position: relative;
        width: 100%;
    }

    /* Multi-row: equal-width slots so up-stems and horizontal bridge align */
    .metis-org-chart .org-row.multi {
        justify-content: space-around;
    }

    .metis-org-chart .org-row.multi > li {
        flex: 1;
        display: flex;
        justify-content: center;
    }

    .metis-org-chart .org-row > li {
        position: relative;
        list-style: none;
    }

    .metis-org-chart .org-node {
        background: white;
        border: 1px solid rgb(228 228 231); /* zinc-200 */
        border-radius: 0.5rem;
        padding: 0.5rem 0.75rem;
        min-width: 10rem;
        max-width: 16rem;
        text-align: center;
        font-size: 0.875rem;
        box-shadow: 0 1px 2px rgba(0,0,0,0.04);
    }

    @media (prefers-color-scheme: dark) {
        .metis-org-chart .org-node {
            background: rgb(24 24 27);
            border-color: rgb(63 63 70);
        }
    }

    .dark .metis-org-chart .org-node {
        background: rgb(24 24 27);
        border-color: rgb(63 63 70);
    }

    .metis-org-chart .org-node.primary {
        background: rgb(254 243 199); /* amber-100 */
        border: 2px solid rgb(252 211 77); /* amber-300 */
        font-weight: 500;
    }

    .dark .metis-org-chart .org-node.primary {
        background: rgba(120, 53, 15, 0.2); /* amber-900/20 */
        border-color: rgb(180 83 9); /* amber-700 */
    }

    .metis-org-chart .org-node.historical {
        opacity: 0.55;
    }

    .metis-org-chart .org-meta {
        margin-top: 0.25rem;
        display: flex;
        gap: 0.25rem;
        justify-content: center;
        flex-wrap: wrap;
    }

    .metis-org-chart .org-grandparents {
        margin-top: 0.5rem;
        padding-top: 0.5rem;
        border-top: 1px dashed rgb(212 212 216);
        text-align: left;
    }

    /* Trunk: vertical line connecting layers */
    .metis-org-chart .org-trunk {
        width: 2px;
        height: 1.5rem;
        background: rgb(161 161 170); /* zinc-400 */
    }

    /* Multi-node rows: each li gets a vertical line going UP (connecting to trunk above) */
    .metis-org-chart .org-row.multi > li::before {
        content: '';
        position: absolute;
        top: -1.5rem;
        left: 50%;
        transform: translateX(-50%);
        width: 2px;
        height: 1.5rem;
        background: rgb(161 161 170);
    }

    /* Multi-node rows: pseudo-bridge spanning between FIRST li's center and LAST li's center,
       creating the horizontal "T" connector visual. Uses li::after with full-width
       absolute positioning + clip via the row's overflow. */
    .metis-org-chart .org-row.multi {
        /* Horizontal bridge: drawn as a thin element on the row that visually connects
           all up-stems. Implemented via gradient on the row's ::before to span across. */
    }

    /* Horizontal bridge: span from first li's center to last li's center.
       With justify-content: space-around + flex:1, each li occupies 100%/N width
       and is centered. Bridge insets by half-slot on each end to match. */
    .metis-org-chart .org-row.multi::before {
        content: '';
        position: absolute;
        top: -1.5rem;
        left: calc(100% / var(--node-count, 1) / 2);
        right: calc(100% / var(--node-count, 1) / 2);
        height: 2px;
        background: rgb(161 161 170);
    }

    /* Single-node rows: just one vertical up-stem aligned with trunk above */
    .metis-org-chart .org-row:not(.multi) > li::before {
        content: '';
        position: absolute;
        top: -1.5rem;
        left: 50%;
        transform: translateX(-50%);
        width: 2px;
        height: 1.5rem;
        background: rgb(161 161 170);
    }

    /* Hide the up-stem on the FIRST row (no parent above it) */
    .metis-org-chart > .org-row:first-child > li::before {
        display: none;
    }
    .metis-org-chart > .org-row:first-child::before {
        display: none;
    }

    /* Subsidiary row: stems go UP toward the trunk above (which connects to searched-self) */
    .metis-org-chart .org-row.subs > li::before {
        top: -1.5rem;
    }
</style>
</div>
