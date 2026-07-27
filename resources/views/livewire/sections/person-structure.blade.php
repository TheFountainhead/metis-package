{{-- Fase 2b: the CPR person page's ownership graph. Person as root,
     ownership chains downward, plus a dashed ROLE layer. Replaces the old
     PersonNetwork org-chart + separate board table.

     Phase gating is written ONCE here against the full status model, even
     though Tasks 7-8 own phases 2-4 — every status property already exists at
     its 'pending' start value, so those tasks add behaviour, not markup. --}}
<div>
    <flux:card>
        <div class="flex items-center justify-between mb-4">
            <flux:heading size="lg">{{ __('Selskabsstruktur') }}</flux:heading>

            {{-- Filter chips with count badges. The never-empty rule is
                 enforced SERVER-side in toggleLayer(); :disabled here is only
                 the affordance, never the guarantee — a chip that carries
                 every visible node cannot be switched off, but a chip for an
                 EMPTY layer always can (it removes nothing). --}}
            @if($skeletonStatus === 'loaded')
                <div class="flex items-center gap-2">
                    @foreach([['ownership', __('Ejerskab'), $ownershipCount], ['roles', __('Roller'), $roleCount]] as [$layer, $label, $count])
                        @php
                            $active = in_array($layer, $layers);
                            // Switching this chip off would empty the graph iff it is
                            // the only ACTIVE chip that contributes any node at all.
                            $locked = $active && $count > 0 && count($layers) === 1;
                        @endphp
                        <button
                            type="button"
                            wire:click="toggleLayer('{{ $layer }}')"
                            wire:key="chip-{{ $layer }}"
                            @disabled($locked)
                            @class([
                                'mgraph-chip',
                                'mgraph-chip--active' => $active,
                                'mgraph-chip--locked' => $locked,
                            ])
                            @if($locked) title="{{ __('Kan ikke slås fra — grafen ville være tom') }}" @endif
                        >
                            <span aria-hidden="true">{{ $active ? '✓' : '' }}</span>
                            {{ $label }} ({{ $count }})
                        </button>
                    @endforeach
                </div>
            @endif
        </div>

        @if($skeletonStatus === 'failed')
            <p class="mgraph-note" wire:key="skeleton-failed">
                {{ __('Selskabsrelationerne kunne ikke hentes.') }}
                <button type="button" wire:click="retrySkeleton" class="underline">{{ __('Prøv igen') }}</button>
            </p>
        @elseif($skeletonStatus === 'empty')
            <p class="text-sm text-zinc-500" wire:key="skeleton-empty">{{ __('Ingen aktive selskabsrelationer') }}</p>
        @elseif($skeletonStatus === 'loaded')
            <div class="metis-org-chart">
                @php
                    $graph = $this->graphModel;
                @endphp
                @include('metis::livewire.sections.partials.ownership-graph', ['graph' => $graph])

                @if(count($graph['nodes']) > 1)
                    <p class="mgraph-note">
                        {{ __('Ownership shares come from CVR and may total under 100% — the register lists only owners of 5% or more, often in bands.') }}
                    </p>
                @endif

                {{-- Phase status notes. Only FAILURE states are visible: a
                     settled 'loaded'/'empty' phase says nothing (2a's rule —
                     'empty' silently means none, not an error). Phases 2-4
                     have no retry action yet (Tasks 7-8 add them), so their
                     notes are informational until then. --}}
                @if($structuresStatus === 'failed')
                    <p class="mgraph-note" wire:key="structures-failed">
                        {{ __('Nogle datterselskaber kunne ikke hentes.') }}
                    </p>
                @endif

                @if($propertiesStatus === 'failed')
                    <p class="mgraph-note" wire:key="properties-failed">
                        {{ __('Ejendomme kunne ikke hentes.') }}
                    </p>
                @endif

                @if($enrichmentStatus === 'failed')
                    <p class="mgraph-note" wire:key="enrichment-failed">
                        {{ __('Nøgletal kunne ikke hentes.') }}
                    </p>
                @endif
            </div>
        @endif
    </flux:card>

<style>
    /* Filter chips (fase 2b). Same editorial mono/sand treatment as the
       graph's own controls so the chips read as part of the graph surface,
       not as generic UI chrome. NO purple/AI-palette. */
    .mgraph-chip {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 3px 10px;
        border: 1px solid #b8a884;
        background: #f6efe3;
        color: #6b6457;
        font-family: "IBM Plex Mono", monospace;
        font-size: 11px;
        line-height: 1.6;
        cursor: pointer;
    }
    .mgraph-chip:hover { background: #efe6d4; }
    .mgraph-chip--active { background: #efe6d4; color: #1a1a1a; border-color: #1a1a1a; }
    /* Locked = switching it off would empty the graph. Dimmed + default
       cursor; the title attribute carries the reason. */
    .mgraph-chip--locked { opacity: 0.6; cursor: default; }
    .mgraph-chip--locked:hover { background: #efe6d4; }
</style>
</div>
