<div>
    <flux:card>
        <flux:heading size="lg" class="mb-4">{{ __('Markedsanalyse') }}</flux:heading>

        @if ($comparison)
            @if ($pyramid = data_get($comparison, 'price_pyramid'))
                <div class="grid grid-cols-3 gap-3 mb-4">
                    <div class="bg-zinc-50 dark:bg-zinc-800 rounded-lg p-3 text-center">
                        <div class="font-bold">{{ number_format($pyramid['quick_sale'], 0, ',', '.') }}</div>
                        <div class="text-xs text-zinc-400">{{ __('Hurtigt salg') }}</div>
                    </div>
                    <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-700 rounded-lg p-3 text-center">
                        <div class="font-bold text-blue-700 dark:text-blue-400">{{ number_format($pyramid['market_price'], 0, ',', '.') }}</div>
                        <div class="text-xs text-blue-500">{{ __('Markedspris') }}</div>
                    </div>
                    <div class="bg-zinc-50 dark:bg-zinc-800 rounded-lg p-3 text-center">
                        <div class="font-bold">{{ number_format($pyramid['dream_price'], 0, ',', '.') }}</div>
                        <div class="text-xs text-zinc-400">{{ __('Drømmepris') }}</div>
                    </div>
                </div>
            @endif

            @if ($stats = data_get($comparison, 'market_stats'))
                <div class="grid grid-cols-4 gap-3 mb-4">
                    <div class="text-center">
                        <div class="font-bold">{{ number_format($stats['avg_sqm_price_sold'] ?? 0, 0, ',', '.') }}</div>
                        <div class="text-xs text-zinc-400">{{ __('Gns. kr/m²') }}</div>
                    </div>
                    <div class="text-center">
                        <div class="font-bold">{{ number_format($stats['median_sqm_price_sold'] ?? 0, 0, ',', '.') }}</div>
                        <div class="text-xs text-zinc-400">{{ __('Median kr/m²') }}</div>
                    </div>
                    <div class="text-center">
                        <div class="font-bold">{{ $stats['avg_days_on_market'] ?? '-' }}</div>
                        <div class="text-xs text-zinc-400">{{ __('Liggedage') }}</div>
                    </div>
                    <div class="text-center">
                        @php
                            $confidence = $stats['confidence'] ?? 'unknown';
                            $color = match($confidence) { 'high' => 'green', 'medium' => 'yellow', 'low' => 'red', default => 'zinc' };
                        @endphp
                        <flux:badge color="{{ $color }}">{{ ucfirst($confidence) }}</flux:badge>
                        <div class="text-xs text-zinc-400 mt-1">{{ $stats['sample_count_sold'] ?? 0 }} {{ __('handler') }}</div>
                    </div>
                </div>
            @endif

            @php
                $soldRefs = collect(data_get($comparison, 'sold_references', []))->take(3);
                $listedRefs = collect(data_get($comparison, 'listed_references', []))->take(3);
            @endphp

            @if ($soldRefs->isNotEmpty())
                <div class="mb-3">
                    <div class="text-xs font-semibold text-zinc-400 uppercase mb-2">{{ __('Seneste handler') }}</div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b text-left text-zinc-400 text-xs">
                                    <th class="pb-1">{{ __('Adresse') }}</th>
                                    <th class="pb-1 text-right">{{ __('m²') }}</th>
                                    <th class="pb-1 text-right">{{ __('Kr/m²') }}</th>
                                    <th class="pb-1 text-right">{{ __('Pris') }}</th>
                                    <th class="text-right text-xs text-zinc-400 font-normal pb-1">{{ __('Køber') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($soldRefs as $ref)
                                    <tr class="border-b border-zinc-100 dark:border-zinc-700">
                                        <td class="py-1.5">{{ $ref['address'] }}</td>
                                        <td class="text-right">{{ $ref['size'] ?? '-' }}</td>
                                        <td class="text-right">{{ number_format($ref['sqm_price'] ?? 0, 0, ',', '.') }}</td>
                                        <td class="text-right font-medium">{{ number_format(($ref['price'] ?? 0) / 1000000, 1, ',', '.') }}M</td>
                                        <td class="text-right text-xs py-1">
                                            @if ($ref['owner_age'] ?? null)
                                                {{ $ref['owner_age'] }} {{ __('år') }}
                                            @elseif (($ref['owner_type'] ?? null) === 'company')
                                                {{ __('Selskab') }}
                                            @else
                                                &mdash;
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            @if ($listedRefs->isNotEmpty())
                <div class="mb-3">
                    <div class="text-xs font-semibold text-zinc-400 uppercase mb-2">{{ __('Udbudte') }}</div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b text-left text-zinc-400 text-xs">
                                    <th class="pb-1">{{ __('Adresse') }}</th>
                                    <th class="pb-1 text-right">{{ __('m²') }}</th>
                                    <th class="pb-1 text-right">{{ __('Dage') }}</th>
                                    <th class="pb-1 text-right">{{ __('Pris') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($listedRefs as $ref)
                                    <tr class="border-b border-zinc-100 dark:border-zinc-700">
                                        <td class="py-1.5">{{ $ref['address'] }}</td>
                                        <td class="text-right">{{ $ref['size'] ?? '-' }}</td>
                                        <td class="text-right">{{ $ref['days_for_sale'] ?? '-' }}</td>
                                        <td class="text-right font-medium text-green-700 dark:text-green-400">{{ number_format(($ref['price'] ?? 0) / 1000000, 1, ',', '.') }}M</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            @if (! $showDetail)
                <flux:button variant="ghost" wire:click="$toggle('showDetail')" class="mt-2">
                    {{ __('Vis alle referencer') }}
                </flux:button>
            @endif

            @if ($showDetail)
                <div class="mt-4">
                    <livewire:metis-address-comparison-detail :comparison="$comparison" lazy />
                </div>
            @endif

            @if ($demographics = data_get($comparison, 'demographics'))
                @if ($demographics['avg_buyer_age'] ?? null)
                    <div class="mt-4 pt-4 border-t border-zinc-200 dark:border-zinc-700">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-xs font-medium text-zinc-500">{{ __('Køberprofil') }}</span>
                            <span class="text-xs text-zinc-400">{{ $demographics['coverage'] }}</span>
                        </div>

                        <div class="flex items-center gap-4 mb-3">
                            <div>
                                <div class="text-xl font-bold text-blue-600 dark:text-blue-400">
                                    {{ round($demographics['avg_buyer_age']) }}
                                </div>
                                <div class="text-xs text-zinc-400">{{ __('Gns. alder') }}</div>
                            </div>

                            @php
                                $groups = $demographics['buyer_age_groups'] ?? [];
                                $maxCount = !empty($groups) ? max(array_values($groups)) : 1;
                            @endphp
                            @if (count($groups) > 0)
                                <div class="flex-1 flex items-end gap-1 h-8">
                                    @foreach ($groups as $group => $count)
                                        <div class="flex-1 flex flex-col items-center">
                                            <div class="w-full bg-blue-200 dark:bg-blue-800 rounded-t"
                                                 style="height: {{ max(round(($count / $maxCount) * 100), 10) }}%;">
                                            </div>
                                            <span class="text-[9px] text-zinc-400 mt-0.5">{{ Str::before($group, '-') }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </div>
                @endif
            @endif
        @endif

        @if ($rentalEstimate || $profitability)
            @php
                $baseRentPerSqm = $rentalEstimate['avg_rent_per_sqm'] ?? 0;
                $baseAnnualRent = $rentalEstimate['estimated_annual_rent'] ?? 0;
                $baseGrossYield = $profitability['gross_yield'] ?? 0;
                $baseDscr = $profitability['estimated_dscr'] ?? 0;
                $rentSource = $profitability['rent_source'] ?? null;
                $isMarketEstimate = $rentSource === 'market_estimate';
            @endphp

            <div x-data="{
                    factor: 1.0,
                    factorLabel: '{{ __('Marked') }}',
                    setScenario(name, f, label) { this.factor = f; this.factorLabel = label; },
                    fmt(n) { return new Intl.NumberFormat('da-DK', { maximumFractionDigits: 0 }).format(Math.round(n)); },
                    fmtPct(n) { return new Intl.NumberFormat('da-DK', { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(n); },
                 }"
                 class="{{ $comparison ? 'mt-6 pt-4 border-t border-zinc-200 dark:border-zinc-700' : '' }}">

                @if($isMarketEstimate)
                    <div class="flex items-center gap-2 mb-3">
                        <span class="text-xs text-zinc-500">{{ __('Scenario:') }}</span>
                        <button type="button" @click="setScenario('conservative', 0.90, '{{ __('Konservativt') }}')"
                                :class="factor === 0.90 ? 'bg-zinc-200 dark:bg-zinc-700 font-medium' : 'bg-transparent'"
                                class="text-xs px-2 py-1 rounded border border-zinc-300 dark:border-zinc-600 hover:bg-zinc-100 dark:hover:bg-zinc-800">
                            {{ __('Konservativt') }} <span class="text-zinc-400">−10%</span>
                        </button>
                        <button type="button" @click="setScenario('market', 1.00, '{{ __('Marked') }}')"
                                :class="factor === 1.00 ? 'bg-zinc-200 dark:bg-zinc-700 font-medium' : 'bg-transparent'"
                                class="text-xs px-2 py-1 rounded border border-zinc-300 dark:border-zinc-600 hover:bg-zinc-100 dark:hover:bg-zinc-800">
                            {{ __('Marked') }}
                        </button>
                        <button type="button" @click="setScenario('aggressive', 1.05, '{{ __('Aggressivt') }}')"
                                :class="factor === 1.05 ? 'bg-zinc-200 dark:bg-zinc-700 font-medium' : 'bg-transparent'"
                                class="text-xs px-2 py-1 rounded border border-zinc-300 dark:border-zinc-600 hover:bg-zinc-100 dark:hover:bg-zinc-800">
                            {{ __('Aggressivt') }} <span class="text-zinc-400">+5%</span>
                        </button>
                    </div>
                @endif

                @if($rentalEstimate)
                    <div class="text-xs font-semibold text-zinc-400 uppercase mb-2">{{ __('Lejeestimat') }}</div>
                    <div class="grid grid-cols-3 gap-3">
                        <div class="bg-zinc-50 dark:bg-zinc-800 rounded-lg p-3 text-center">
                            <div class="font-bold" x-text="fmt({{ $baseRentPerSqm }} * factor)">{{ number_format($baseRentPerSqm, 0, ',', '.') }}</div>
                            <div class="text-xs text-zinc-400">{{ __('Kr/m²/år') }}</div>
                        </div>
                        <div class="bg-zinc-50 dark:bg-zinc-800 rounded-lg p-3 text-center">
                            <div class="font-bold" x-text="fmt({{ $baseAnnualRent }} * factor)">{{ number_format($baseAnnualRent, 0, ',', '.') }}</div>
                            <div class="text-xs text-zinc-400">{{ __('Årlig leje') }}</div>
                        </div>
                        <div class="bg-zinc-50 dark:bg-zinc-800 rounded-lg p-3 text-center">
                            <div class="font-bold">{{ $rentalEstimate['sample_count'] ?? '-' }}</div>
                            <div class="text-xs text-zinc-400">{{ __('Datapunkter') }}</div>
                        </div>
                    </div>
                    <div class="text-[11px] text-zinc-400 dark:text-zinc-500 mt-2 italic">
                        {{ __('Kilde:') }} <a href="https://www.ejendomstorvet.dk" target="_blank" rel="noopener" class="hover:underline">ejendomstorvet.dk</a> —
                        {{ __('median pr. m²/år ud fra :count aktuelle udlejnings-listings i postnummerområdet', ['count' => $rentalEstimate['sample_count'] ?? '?']) }}@if($rentalEstimate['property_type'] ?? null), {{ __('filtreret på type :type', ['type' => $rentalEstimate['property_type']]) }}@endif.
                        @if($isMarketEstimate)
                            <span x-show="factor !== 1.0" x-cloak>
                                {{ __('Justeret til scenario:') }} <span x-text="factorLabel" class="font-medium"></span>.
                            </span>
                        @endif
                    </div>
                @endif

                @if ($profitability)
                    <div class="mt-4">
                        <div class="text-xs font-semibold text-zinc-400 uppercase mb-2">{{ __('Rentabilitet') }}</div>
                        <div class="grid grid-cols-3 gap-3">
                            @if ($profitability['gross_yield'] ?? null)
                                <div class="bg-zinc-50 dark:bg-zinc-800 rounded-lg p-3 text-center" title="{{ __('Årlig leje / handelspris') }}">
                                    <div class="font-bold">
                                        @if($isMarketEstimate)
                                            <span x-text="fmtPct({{ $baseGrossYield }} * factor) + '%'">{{ $baseGrossYield }}%</span>
                                        @else
                                            {{ $baseGrossYield }}%
                                        @endif
                                    </div>
                                    <div class="text-xs text-zinc-400">{{ __('Bruttoafkast') }}</div>
                                </div>
                            @endif
                            @if ($profitability['ltv'] ?? null)
                                <div class="bg-zinc-50 dark:bg-zinc-800 rounded-lg p-3 text-center" title="{{ __('Sum af aktive pantebreve / handelspris') }}">
                                    <div class="font-bold">{{ $profitability['ltv'] }}%</div>
                                    <div class="text-xs text-zinc-400">{{ __('LTV') }}</div>
                                </div>
                            @endif
                            @if ($profitability['estimated_dscr'] ?? null)
                                <div class="bg-zinc-50 dark:bg-zinc-800 rounded-lg p-3 text-center" title="{{ __('NOI / årlig gældsbetjening (estimat)') }}">
                                    <div class="font-bold">
                                        @if($isMarketEstimate)
                                            <span x-text="(({{ $baseDscr }} * factor)).toLocaleString('da-DK', { minimumFractionDigits: 2, maximumFractionDigits: 2 })">{{ $baseDscr }}</span>
                                        @else
                                            {{ $baseDscr }}
                                        @endif
                                    </div>
                                    <div class="text-xs text-zinc-400">{{ __('DSCR') }}</div>
                                </div>
                            @endif
                        </div>
                        <div class="text-[11px] text-zinc-400 dark:text-zinc-500 mt-2 italic">
                            {{ __('Kilde:') }}
                            @if($profitability['gross_yield'] ?? null)
                                <span title="{{ __('Årlig leje / handelspris') }}">{{ __('Bruttoafkast') }} = {{ $isMarketEstimate ? __('lejeestimat (ejendomstorvet.dk)') : __('faktisk årlig leje') }} / {{ __('handelspris (Tinglysning)') }}</span>.
                            @endif
                            @if($profitability['ltv'] ?? null)
                                {{ __('LTV') }} = {{ __('aktive pantebreve (Tinglysning)') }} / {{ __('handelspris') }}.
                            @endif
                        </div>
                    </div>
                @endif
            </div>
        @endif

        @if (! $comparison && ! $rentalEstimate && ! $profitability)
            <p class="text-sm text-zinc-500">{{ __('Ingen markedsdata tilgængelig') }}</p>
        @endif
    </flux:card>
</div>
