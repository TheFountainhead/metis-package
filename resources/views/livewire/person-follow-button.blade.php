<div class="inline-flex flex-col items-end gap-1">
    <button wire:click="openModal"
            wire:loading.attr="disabled"
            wire:target="openModal"
            class="px-3 py-1.5 text-sm border rounded bg-white border-zinc-300 hover:bg-zinc-50 transition disabled:opacity-50">
        ☆ {{ __('Følg en specifik person') }}
    </button>

    @if($open)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40"
             wire:click.self="closeModal">
            <div class="bg-white rounded-xl shadow-2xl max-w-2xl w-full mx-4 max-h-[80vh] overflow-hidden flex flex-col">
                {{-- Header --}}
                <div class="flex items-start justify-between p-4 border-b border-zinc-200">
                    <div>
                        <h2 class="text-lg font-semibold">{{ __('Vælg hvilken person du vil følge') }}</h2>
                        <p class="text-sm text-zinc-500 mt-1">
                            {{ __('Søgning på') }} "<span class="font-medium">{{ $name }}</span>"
                            @if(! $loading && count($candidates) > 0)
                                — {{ count($candidates) }} {{ count($candidates) === 1 ? __('kandidat') : __('kandidater') }}
                            @endif
                        </p>
                    </div>
                    <button wire:click="closeModal"
                            class="text-zinc-400 hover:text-zinc-600 text-xl leading-none">&times;</button>
                </div>

                {{-- Body --}}
                <div class="flex-1 overflow-y-auto p-4">
                    @if($loading && empty($candidates))
                        <div class="flex items-center gap-2 text-sm text-zinc-500">
                            <div class="size-4 border-2 border-zinc-300 border-t-zinc-500 rounded-full animate-spin"></div>
                            {{ __('Henter kandidater fra CVR...') }}
                        </div>
                    @elseif($error)
                        <div class="text-sm text-red-600">{{ $error }}</div>
                    @elseif(empty($candidates))
                        <div class="text-sm text-zinc-500">{{ __('Ingen kandidater fundet i CVR for dette navn.') }}</div>
                    @else
                        <ul class="space-y-2">
                            @foreach($candidates as $candidate)
                                @php
                                    $en = $candidate['enhedsnummer'];
                                    $state = $followState[$en] ?? ['is_followed' => false, 'watchlist_id' => null];
                                    $isFollowed = $state['is_followed'];
                                    $addressParts = array_filter([
                                        ($candidate['address']['street'] ?? null) . ' ' . ($candidate['address']['number'] ?? ''),
                                        $candidate['address']['postal_code'] ?? null,
                                        $candidate['address']['city'] ?? null,
                                    ]);
                                @endphp
                                <li class="border border-zinc-200 rounded-lg p-3 hover:bg-zinc-50">
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="flex-1 min-w-0">
                                            <div class="font-medium text-zinc-900">{{ $candidate['name'] }}</div>
                                            @if(! empty($addressParts))
                                                <div class="text-xs text-zinc-500 mt-0.5">
                                                    {{ trim(implode(', ', $addressParts)) }}
                                                </div>
                                            @endif
                                            @if(! empty($candidate['companies']))
                                                <div class="text-xs text-zinc-600 mt-1.5 space-y-0.5">
                                                    @foreach(array_slice($candidate['companies'], 0, 3) as $company)
                                                        @php
                                                            $latestRole = collect($company['roles'] ?? [])
                                                                ->firstWhere('is_current', true);
                                                            $roleLabel = $latestRole['role'] ?? null;
                                                        @endphp
                                                        <div>
                                                            <span class="font-medium">{{ $company['name'] }}</span>
                                                            @if($roleLabel)
                                                                <span class="text-zinc-400">— {{ $roleLabel }}</span>
                                                            @endif
                                                        </div>
                                                    @endforeach
                                                    @if(count($candidate['companies']) > 3)
                                                        <div class="text-zinc-400">
                                                            +{{ count($candidate['companies']) - 3 }} {{ __('flere selskaber') }}
                                                        </div>
                                                    @endif
                                                </div>
                                            @endif
                                        </div>
                                        <div class="flex-shrink-0">
                                            @if($isFollowed)
                                                <button wire:click="unfollow('{{ $en }}')"
                                                        wire:loading.attr="disabled"
                                                        class="text-xs px-2.5 py-1 rounded border bg-blue-50 border-blue-300 text-blue-700 hover:bg-red-50 hover:border-red-300 hover:text-red-700">
                                                    ✓ {{ __('Følger') }}
                                                </button>
                                            @else
                                                <button wire:click="follow('{{ $en }}')"
                                                        wire:loading.attr="disabled"
                                                        class="text-xs px-2.5 py-1 rounded border bg-white border-zinc-300 hover:bg-zinc-50">
                                                    ☆ {{ __('Følg') }}
                                                </button>
                                            @endif
                                        </div>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                {{-- Footer --}}
                <div class="px-4 py-3 border-t border-zinc-200 text-xs text-zinc-500 bg-zinc-50">
                    {{ __('Data fra CVR (Erhvervsstyrelsen). Bruger får email-notifikation ved rolle-ændringer på den valgte person.') }}
                </div>
            </div>
        </div>
    @endif
</div>
