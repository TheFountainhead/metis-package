<div>
    <flux:card>
        <div class="flex items-center justify-between mb-4">
            <flux:heading size="lg">{{ __('Lignende handler') }}</flux:heading>
            @if($totalCount > 0)
                <span class="text-xs text-zinc-500">{{ $totalCount }} {{ __('handler i området') }}</span>
            @endif
        </div>

        @if($hasError)
            @include('metis::livewire.sections.partials.lookup-error')
        @elseif($totalCount === 0)
            <p class="text-sm text-zinc-500">{{ __('Ingen lignende handler fundet i området') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200 dark:border-zinc-700">
                            <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('Adresse') }}</th>
                            <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('Dato') }}</th>
                            <th class="text-right py-2 pr-4 font-medium text-zinc-500">{{ __('Pris') }}</th>
                            <th class="text-right py-2 pr-4 font-medium text-zinc-500">{{ __('Kr/m²') }}</th>
                            <th class="text-right py-2 pr-4 font-medium text-zinc-500">{{ __('Areal') }}</th>
                            <th class="text-right py-2 font-medium text-zinc-500">{{ __('Byggeår') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach(array_slice($similar, 0, $limit) as $sale)
                            <tr class="border-b border-zinc-100 dark:border-zinc-800">
                                <td class="py-2 pr-4">
                                    @php
                                        $addr = trim(($sale['address'] ?? '') . ', ' . ($sale['postal_code'] ?? ''));
                                    @endphp
                                    @if($sale['address'] ?? null)
                                        <x-metis-link type="address" :query="$addr" />
                                    @else
                                        -
                                    @endif
                                </td>
                                <td class="py-2 pr-4 text-zinc-600 dark:text-zinc-300">
                                    {{ $sale['sale_date'] ? \Carbon\Carbon::parse($sale['sale_date'])->isoFormat('MMM YYYY') : '-' }}
                                </td>
                                <td class="py-2 pr-4 text-right">
                                    {{ $sale['sale_price'] ? number_format($sale['sale_price'], 0, ',', '.') . ' kr.' : '-' }}
                                </td>
                                <td class="py-2 pr-4 text-right font-medium">
                                    {{ $sale['price_per_sqm'] ? number_format($sale['price_per_sqm'], 0, ',', '.') : '-' }}
                                </td>
                                <td class="py-2 pr-4 text-right text-zinc-500">
                                    {{ $sale['total_area'] ? number_format($sale['total_area'], 0, ',', '.') . ' m²' : '-' }}
                                </td>
                                <td class="py-2 text-right text-zinc-500">
                                    {{ $sale['year_built'] ?? '-' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if($totalCount > $limit)
                <div class="mt-3 text-center">
                    <button wire:click="showMore" class="text-sm text-blue-600 hover:text-blue-800 transition">
                        {{ __('Vis flere') }} ({{ $limit }} / {{ $totalCount }})
                    </button>
                </div>
            @endif

            <div class="text-[11px] text-zinc-400 dark:text-zinc-500 mt-3 italic">
                {{ __('Kilde:') }} {{ __('Tinglysning: handler i samme postnr eller inden for :km km, ±:pct% areal-tolerance, sidste :months mdr.', ['km' => $radiusKm, 'pct' => $areaPct, 'months' => $monthsBack]) }}
            </div>
        @endif
    </flux:card>
</div>
