<div>
    @if($outgoingCount > 0 || $incomingCount > 0)
        <flux:card>
            <div class="flex flex-wrap items-center gap-2 mb-1">
                <flux:heading size="lg">{{ __('Stiftere og stiftede selskaber') }}</flux:heading>
            </div>
            <p class="text-xs text-zinc-500 mb-4">
                {{ __('Fra CVR\'s registrering af stiftere. Viser ikke nuværende ejerskab. Ejere og datterselskaber står under Virksomhedsstruktur.') }}
            </p>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                {{-- Selskaber, dette selskab står som stifter af (CVR's organisation Stiftere) --}}
                <div>
                    <h3 class="text-sm font-semibold text-zinc-700 dark:text-zinc-200 mb-2">
                        {{ __('Stifter af') }}
                        @if($outgoingCount > 0)<span class="text-zinc-400 font-normal">({{ $outgoingCount }})</span>@endif
                    </h3>
                    @if($outgoingCount > 0)
                        <ul class="space-y-1 text-sm">
                            @foreach(array_slice($outgoing, 0, 15) as $rel)
                                <li>
                                    <x-metis-link type="cvr" :query="$rel['cvr']" :label="$rel['name'] ?: $rel['cvr']" />
                                    <span class="text-zinc-400 text-xs">{{ $rel['cvr'] }}</span>
                                    @if(($rel['property_count'] ?? 0) > 0)
                                        <span class="text-xs text-zinc-500 block">
                                            {{ trans_choice('{1}:count ejendom|[2,*]:count ejendomme', $rel['property_count'], ['count' => $rel['property_count']]) }}@if(($rel['property_value_kr'] ?? 0) > 0) · {{ number_format($rel['property_value_kr'], 0, ',', '.') }} kr.@endif
                                        </span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                        @if($outgoingCount > 15)
                            <p class="text-xs text-zinc-400 mt-1">{{ __('+ :n flere', ['n' => $outgoingCount - 15]) }}</p>
                        @endif
                    @else
                        <p class="text-sm text-zinc-400">{{ __('Ingen') }}</p>
                    @endif
                </div>

                {{-- Selskaber, der står som stiftere af dette selskab --}}
                <div>
                    <h3 class="text-sm font-semibold text-zinc-700 dark:text-zinc-200 mb-2">
                        {{ __('Stiftet af') }}
                        @if($incomingCount > 0)<span class="text-zinc-400 font-normal">({{ $incomingCount }})</span>@endif
                    </h3>
                    @if($incomingCount > 0)
                        <ul class="space-y-1 text-sm">
                            @foreach(array_slice($incoming, 0, 15) as $rel)
                                <li>
                                    <x-metis-link type="cvr" :query="$rel['cvr']" :label="$rel['name'] ?: $rel['cvr']" />
                                    <span class="text-zinc-400 text-xs">{{ $rel['cvr'] }}</span>
                                    @if(($rel['property_count'] ?? 0) > 0)
                                        <span class="text-xs text-zinc-500 block">
                                            {{ trans_choice('{1}:count ejendom|[2,*]:count ejendomme', $rel['property_count'], ['count' => $rel['property_count']]) }}@if(($rel['property_value_kr'] ?? 0) > 0) · {{ number_format($rel['property_value_kr'], 0, ',', '.') }} kr.@endif
                                        </span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                        @if($incomingCount > 15)
                            <p class="text-xs text-zinc-400 mt-1">{{ __('+ :n flere', ['n' => $incomingCount - 15]) }}</p>
                        @endif
                    @else
                        <p class="text-sm text-zinc-400">{{ __('Ingen') }}</p>
                    @endif
                </div>
            </div>
        </flux:card>
    @endif
</div>
