<div @if($enriching) wire:poll.2s="pollForUpdates" @endif>
    <flux:card>
        <flux:heading size="lg" class="mb-4">{{ __('Property Portfolio') }}</flux:heading>
        @if($enriching)
            <div class="flex items-center gap-2 text-blue-500 text-sm mb-4 px-1">
                <svg class="size-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span>
                    {{ __('Searching subsidiary companies for properties...') }}
                    @if($propertiesFound > 0)
                        <span class="font-medium">{{ $propertiesFound }} {{ __('found') }}</span>
                    @endif
                </span>
            </div>
        @endif
        @if($portfolio && count($portfolio['properties'] ?? []) > 0)
            @php
                $loadedDebt = collect($portfolio['properties'] ?? [])->sum(fn ($p) => $p['total_debt'] ?? 0);
            @endphp
            <div class="mb-3 flex flex-wrap gap-4 text-sm">
                <span class="text-zinc-500">{{ __('Properties') }}: <span class="font-medium text-zinc-900 dark:text-white">{{ $portfolio['total_count'] ?? $portfolio['property_count'] ?? 0 }}</span></span>
                @if(($portfolio['total_valuation'] ?? 0) > 0)
                    <span class="text-zinc-500">{{ __('Total valuation') }}: <span class="font-medium text-zinc-900 dark:text-white">{{ number_format($portfolio['total_valuation'], 0, ',', '.') }} kr.</span></span>
                @endif
                @if(($portfolio['total_area'] ?? 0) > 0)
                    <span class="text-zinc-500">{{ __('Land area') }}: <span class="font-medium text-zinc-900 dark:text-white">{{ number_format($portfolio['total_area'], 0, ',', '.') }} m²</span></span>
                @endif
                @if(($portfolio['total_building_area'] ?? 0) > 0)
                    <span class="text-zinc-500">{{ __('Built area') }}: <span class="font-medium text-zinc-900 dark:text-white">{{ number_format($portfolio['total_building_area'], 0, ',', '.') }} m²</span></span>
                @endif
                @if($loadedDebt > 0)
                    <span class="text-zinc-500" title="{{ __('Sum af tinglyst gæld for de viste ejendomme — yderligere gæld kan være på endnu-ikke-loadede sider') }}">{{ __('Tinglyst gæld') }} ({{ count($portfolio['properties']) }}/{{ $portfolio['total_count'] }}): <span class="font-medium text-red-700 dark:text-red-400">{{ number_format($loadedDebt, 0, ',', '.') }} kr.</span></span>
                @endif
            </div>

            @php
                // Group properties by address to deduplicate ejerlejligheder
                $grouped = collect($portfolio['properties'])->groupBy(function ($p) {
                    $addr = trim(($p['address'] ?? '') . ', ' . ($p['postal_code'] ?? '') . ' ' . ($p['city'] ?? ''), ', ');
                    return $addr ?: 'BFE ' . ($p['matrikel_id'] ?? '?');
                });
            @endphp

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200 dark:border-zinc-700">
                            <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('Address') }}</th>
                            <th class="text-right py-2 pr-4 font-medium text-zinc-500">{{ __('Units') }}</th>
                            <th class="text-right py-2 pr-4 font-medium text-zinc-500" title="{{ __('Land area / built area') }}">{{ __('Area') }}</th>
                            <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('Year') }}</th>
                            <th class="text-right py-2 pr-4 font-medium text-zinc-500" title="{{ __('Offentlig ejendomsvurdering (VUR)') }}">{{ __('Off. vurdering') }}</th>
                            <th class="text-right py-2 pr-4 font-medium text-zinc-500" title="{{ __('Seneste handelspris (tinglysning)') }}">{{ __('Seneste handel') }}</th>
                            <th class="text-right py-2 font-medium text-zinc-500" title="{{ __('Tinglyst gæld i alt — sum af aktive pantebreve') }}">{{ __('Tinglyst gæld') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($grouped as $address => $units)
                            @php
                                $totalArea = $units->sum(fn ($u) => $u['total_area'] ?? 0);
                                $totalBuildingArea = $units->sum(fn ($u) => $u['total_building_area'] ?? 0);
                                $totalVal = $units->sum(fn ($u) => $u['valuation'] ?? 0);
                                $totalDebt = $units->sum(fn ($u) => $u['total_debt'] ?? 0);
                                $year = $units->pluck('building_year')->filter()->first();
                                $first = $units->first();
                                $addr = trim(($first['address'] ?? '') . ', ' . ($first['postal_code'] ?? '') . ' ' . ($first['city'] ?? ''), ', ');
                                $bfe = $first['matrikel_id'] ?? null;
                                $matrikelnr = $first['matrikelnr'] ?? null;
                                $ejerlav = $first['ejerlav'] ?? null;
                                if ($matrikelnr) {
                                    $matrikelLabel = trim('Matr. nr. ' . $matrikelnr . ($ejerlav ? ', ' . $ejerlav : ''));
                                } elseif ($ejerlav) {
                                    $matrikelLabel = __('Unbuilt parcel') . ' — ' . $ejerlav;
                                } else {
                                    $matrikelLabel = null;
                                }
                                // Latest sale across grouped units (max by date)
                                $latestSale = $units->pluck('latest_sale')->filter()->sortByDesc('date')->first();
                            @endphp
                            <tr class="border-b border-zinc-100 dark:border-zinc-800">
                                <td class="py-2 pr-4">
                                    @if($addr)
                                        <x-metis-link type="address" :query="$addr" />
                                    @elseif($matrikelLabel)
                                        <div>
                                            <span class="text-zinc-700 dark:text-zinc-300">{{ $matrikelLabel }}</span>
                                            @if($bfe)
                                                <div class="text-zinc-400 text-xs">BFE {{ $bfe }}</div>
                                            @endif
                                        </div>
                                    @elseif($bfe)
                                        <span class="text-zinc-400 text-xs">BFE {{ $bfe }}</span>
                                    @else
                                        <span class="text-zinc-400">-</span>
                                    @endif
                                </td>
                                <td class="py-2 pr-4 text-right">
                                    @if($units->count() > 1)
                                        <span class="text-zinc-500">{{ $units->count() }}</span>
                                    @else
                                        <span class="text-zinc-300">1</span>
                                    @endif
                                </td>
                                <td class="py-2 pr-4 text-right">
                                    @if($totalArea)
                                        <div>{{ number_format($totalArea, 0, ',', '.') }} m² <span class="text-zinc-400 text-xs">{{ __('grund') }}</span></div>
                                        @if($totalBuildingArea)
                                            <div class="text-zinc-500 text-xs">{{ number_format($totalBuildingArea, 0, ',', '.') }} m² {{ __('bebygget') }}</div>
                                        @endif
                                    @else
                                        -
                                    @endif
                                </td>
                                <td class="py-2 pr-4">{{ $year ?? '-' }}</td>
                                <td class="py-2 pr-4 text-right">{{ $totalVal ? number_format($totalVal, 0, ',', '.') . ' kr.' : '-' }}</td>
                                <td class="py-2 pr-4 text-right">
                                    @if($latestSale && ($latestSale['price'] ?? 0) > 0)
                                        <span class="text-zinc-700 dark:text-zinc-200">{{ number_format($latestSale['price'], 0, ',', '.') }} kr.</span>
                                        @if($latestSale['date'] ?? null)
                                            <span class="text-xs text-zinc-400 block">{{ \Carbon\Carbon::parse($latestSale['date'])->format('M Y') }}</span>
                                        @endif
                                    @else
                                        <span class="text-zinc-300">-</span>
                                    @endif
                                </td>
                                <td class="py-2 text-right {{ $totalDebt > 0 ? 'text-red-700 dark:text-red-400' : 'text-zinc-300' }}">
                                    {{ $totalDebt > 0 ? number_format($totalDebt, 0, ',', '.') . ' kr.' : '-' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if(($portfolio['total_count'] ?? 0) > count($portfolio['properties'] ?? []))
                <div class="mt-3 text-center">
                    <button wire:click="loadMore" class="text-sm text-blue-600 hover:text-blue-800 transition">
                        {{ __('Show more') }} ({{ count($portfolio['properties']) }} / {{ $portfolio['total_count'] }})
                    </button>
                </div>
            @endif
        @elseif(! $enriching)
            <p class="text-sm text-zinc-500">{{ __('No properties found for this company.') }}</p>
        @endif
    </flux:card>
</div>
