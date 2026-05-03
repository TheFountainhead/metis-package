<div @if($enriching) wire:poll.3s="pollForUpdates" @endif>
    <flux:card>
        <flux:heading size="lg" class="mb-4">{{ __('Company Structure') }}</flux:heading>

        @if(count($owners) === 0 && count($subsidiaries) === 0 && ! $enriching)
            <p class="text-sm text-zinc-500">{{ __('No structure data found.') }}</p>
        @else
            <div class="metis-org-chart">

                @if(count($owners) > 0)
                    {{-- Owners (above searched company) --}}
                    <div class="org-row {{ count($owners) > 1 ? 'multi' : '' }}">
                        @foreach($owners as $owner)
                            <div class="org-cell">
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
                            </div>
                        @endforeach
                    </div>

                    {{-- Connectors: stems-down from each owner + horizontal bridge (only for multi) + trunk to searched --}}
                    @if(count($owners) > 1)
                        <div class="org-stem-row" style="--node-count: {{ count($owners) }}">
                            @foreach($owners as $owner)
                                <div class="org-stem-cell"><div class="org-stem-line"></div></div>
                            @endforeach
                        </div>
                        <div class="org-bridge-row" style="--node-count: {{ count($owners) }}">
                            <div class="org-bridge-line"></div>
                        </div>
                    @endif
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
                    {{-- Connectors: trunk down + horizontal bridge (only for multi) + stems-up to each subsidiary --}}
                    <div class="org-trunk"></div>
                    @if(count($subsidiaries) > 1)
                        <div class="org-bridge-row" style="--node-count: {{ count($subsidiaries) }}">
                            <div class="org-bridge-line"></div>
                        </div>
                        <div class="org-stem-row" style="--node-count: {{ count($subsidiaries) }}">
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
     All connectors are real divs (no pseudo-elements) so flux:card overflow can't hide them. --}}
<style>
    .metis-org-chart {
        --org-line: rgb(113 113 122); /* zinc-500 */
    }
    .dark .metis-org-chart {
        --org-line: rgb(161 161 170); /* zinc-400 */
    }

    .metis-org-chart {
        display: flex;
        flex-direction: column;
        align-items: center;
        padding: 0.5rem 0;
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

    /* Trunk: vertical 2px line linking layers */
    .metis-org-chart .org-trunk {
        width: 2px;
        height: 1.25rem;
        background: var(--org-line);
    }

    /* Stem-row: container that flexes its children evenly so each stem aligns with the cell-center above */
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

    /* Bridge-row: horizontal line spanning from first-cell-center to last-cell-center.
       Each cell is 100%/N wide, so bridge width = 100% - 100%/N = (N-1) * (100%/N) */
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
</style>
</div>
