@php
    $isCompany = $owner['is_company'] ?? false;
    $ownerKey = $isCompany ? 'cvr:'.($owner['cvr'] ?? '') : 'person:'.md5($owner['person_name'] ?? '');
    $drilldownType = $isCompany ? 'cvr' : 'person';
    $drilldownQuery = $isCompany ? ($owner['cvr'] ?? '') : ($owner['person_name'] ?? '');
    $expansion = $expandedOwners[$ownerKey] ?? null;
    $isExpanded = $expansion !== null;
@endphp

<div class="org-cell">
    <div class="org-node">
        @if($isCompany)
            <x-metis-link type="cvr" :query="$owner['cvr'] ?? ''" :label="$owner['person_name'] ?? '-'" />
        @else
            <x-metis-link type="person" :query="$owner['person_name'] ?? '-'" :label="$owner['person_name'] ?? '-'" />
        @endif

        <div class="org-meta">
            @if($owner['ownership_share'] ?? null)
                <flux:badge size="sm" :color="$badgeColor ?? 'sky'">{{ number_format($owner['ownership_share'], 0) }}%</flux:badge>
            @endif
        </div>

        @if($drilldownQuery)
            <a href="{{ route('metis.lookup', ['type' => $drilldownType, 'query' => $drilldownQuery]) }}" class="org-drilldown">
                <span>{{ $isCompany ? __('Se selskab') : __('Se alle selskaber') }}</span>
                <svg class="size-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" /></svg>
            </a>
        @endif

        @if($drilldownQuery)
            <button type="button" wire:click="toggleOwnerExpansion('{{ $ownerKey }}')"
                    wire:loading.attr="disabled"
                    wire:target="toggleOwnerExpansion('{{ $ownerKey }}')"
                    class="org-expand-toggle">
                <span wire:loading.remove wire:target="toggleOwnerExpansion('{{ $ownerKey }}')">
                    <svg class="size-3 inline" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        @if($isExpanded)
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7" />
                        @else
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                        @endif
                    </svg>
                    {{ $isExpanded ? __('Skjul struktur') : __('Udfold struktur') }}
                </span>
                <span wire:loading wire:target="toggleOwnerExpansion('{{ $ownerKey }}')" class="text-blue-500">
                    <svg class="size-3 animate-spin inline" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    {{ __('Henter...') }}
                </span>
            </button>
        @endif

        @if($isExpanded)
            <div class="org-expansion">
                <div class="text-[10px] text-zinc-400 uppercase tracking-wide mb-1">
                    {{ $isCompany ? __('Datterselskaber') : __('Andre selskaber') }}
                    ({{ count($expansion) }})
                </div>
                @forelse($expansion as $rel)
                    <div class="org-expansion-row">
                        <x-metis-link type="cvr" :query="$rel['cvr']" :label="$rel['name']" />
                        @if($rel['share'])
                            <span class="text-zinc-500">{{ $rel['share'] }}%</span>
                        @elseif($rel['role'])
                            <span class="text-zinc-400 text-[10px]">{{ $rel['role'] }}</span>
                        @endif
                    </div>
                @empty
                    <div class="text-xs text-zinc-400 italic">{{ __('Ingen andre relationer fundet') }}</div>
                @endforelse
            </div>
        @endif
    </div>
</div>
