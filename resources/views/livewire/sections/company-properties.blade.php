<div @if($enriching) wire:poll.2s="pollForUpdates" @elseif($building) wire:poll.15s="pollPortfolio" @endif>
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
        @if($building)
            <div class="flex items-center gap-2 text-zinc-500 text-sm mb-2 px-1">
                <svg class="size-4 animate-spin text-zinc-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span>
                    {{ (($portfolio['source'] ?? null) === 'koncern_bfe') ? __('Porteføljen dækker hele koncernen (moder + datterselskaber)') : __('Porteføljen hentes fra Ejerfortegnelsen') }}@if(($portfolio['total_count'] ?? 0) > 0): <span class="font-medium">{{ $portfolio['total_count'] }} {{ __('ejendomme fundet') }}</span>@endif.
                    {{ __('Store porteføljer tager et par minutter første gang; siden opdaterer selv.') }}
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
                    @php
                        $valCov = $portfolio['valuation_coverage'] ?? null;
                        $partialVal = $valCov && $valCov['with_valuation'] < $valCov['total'];
                        $valCovNote = $partialVal ? __('Offentlig vurdering foreligger kun for :n af :t ejendomme; totalen er derfor et minimum.', ['n' => $valCov['with_valuation'], 't' => $valCov['total']]) : null;
                    @endphp
                    <span class="text-zinc-500" @if($partialVal) title="{{ $valCovNote }}" @endif>{{ __('Total valuation') }}: <span class="font-medium text-zinc-900 dark:text-white">{{ number_format($portfolio['total_valuation'], 0, ',', '.') }} kr.</span>@if($partialVal)<span class="text-zinc-400 text-xs"> ({{ __('vurdering for :n/:t', ['n' => $valCov['with_valuation'], 't' => $valCov['total']]) }})</span>@endif</span>
                @endif
                @if(($portfolio['total_area'] ?? 0) > 0)
                    <span class="text-zinc-500">{{ __('Land area') }}: <span class="font-medium text-zinc-900 dark:text-white">{{ number_format($portfolio['total_area'], 0, ',', '.') }} m²</span></span>
                @endif
                @if(($portfolio['total_footprint_area'] ?? 0) > 0)
                    <span class="text-zinc-500" title="{{ __('Bygningens fodaftryk på grunden (byg041BebyggetAreal)') }}">{{ __('Built area') }}: <span class="font-medium text-zinc-900 dark:text-white">{{ number_format($portfolio['total_footprint_area'], 0, ',', '.') }} m²</span></span>
                @endif
                @if(($portfolio['total_building_area'] ?? 0) > 0)
                    <span class="text-zinc-500" title="{{ __('Sum af alle etagers areal (byg038SamletBygningsareal)') }}">{{ __('Floor area') }}: <span class="font-medium text-zinc-900 dark:text-white">{{ number_format($portfolio['total_building_area'], 0, ',', '.') }} m²</span></span>
                @endif
                @if($loadedDebt > 0)
                    <span class="text-zinc-500" title="{{ __('Sum af tinglyst gæld for de viste ejendomme, yderligere gæld kan være på endnu-ikke-loadede sider') }}">{{ __('Tinglyst gæld') }} ({{ count($portfolio['properties']) }}/{{ $portfolio['total_count'] }}): <span class="font-medium text-red-700 dark:text-red-400">{{ number_format($loadedDebt, 0, ',', '.') }} kr.</span></span>
                @endif
            </div>

            @php
                // Group properties by address to deduplicate ejerlejligheder
                $grouped = collect($portfolio['properties'])->groupBy(function ($p) {
                    $addr = trim(($p['address'] ?? '') . ', ' . ($p['postal_code'] ?? '') . ' ' . ($p['city'] ?? ''), ', ');
                    // Kort-label: BFE er en nødløsning, men mærkes så den ikke
                    // læses som en adresse.
                    return $addr ?: __('Adresse ikke hentet') . ' (BFE ' . ($p['matrikel_id'] ?? '?') . ')';
                });

                // Ejer-kolonne: kun på koncern-stien OG kun når datterselskaber
                // rent faktisk ejer noget (ellers er kolonnen redundant). EJF-stien
                // har ingen per-ejendom owner_cvr → kolonnen forbliver skjult.
                $rootCvr = $portfolio['owner_cvr'] ?? null;
                $showOwnerColumn = ($portfolio['source'] ?? null) === 'koncern_bfe'
                    && collect($portfolio['properties'])->contains(fn ($p) => ($p['owner_cvr'] ?? $rootCvr) !== $rootCvr);
            @endphp

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200 dark:border-zinc-700">
                            <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('Address') }}</th>
                            @if($showOwnerColumn)
                                <th class="text-left py-2 pr-4 font-medium text-zinc-500" title="{{ __('Hvilket koncern-selskab der ejer ejendommen') }}">{{ __('Ejer') }}</th>
                            @endif
                            <th class="text-right py-2 pr-4 font-medium text-zinc-500">{{ __('Units') }}</th>
                            <th class="text-right py-2 pr-4 font-medium text-zinc-500" title="{{ __('Land area / built area') }}">{{ __('Area') }}</th>
                            <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('Year') }}</th>
                            <th class="text-right py-2 pr-4 font-medium text-zinc-500" title="{{ __('Offentlig ejendomsvurdering (VUR)') }}">{{ __('Off. vurdering') }}</th>
                            <th class="text-right py-2 pr-4 font-medium text-zinc-500" title="{{ __('Seneste handelspris (tinglysning)') }}">{{ __('Seneste handel') }}</th>
                            <th class="text-right py-2 font-medium text-zinc-500" title="{{ __('Tinglyst gæld i alt (sum af aktive pantebreve)') }}">{{ __('Tinglyst gæld') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($grouped as $address => $units)
                            @php
                                $totalArea = $units->sum(fn ($u) => $u['total_area'] ?? 0);
                                $totalBuildingArea = $units->sum(fn ($u) => $u['total_building_area'] ?? 0);
                                $totalFootprint = $units->sum(fn ($u) => $u['total_footprint_area'] ?? 0);
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
                                    $matrikelLabel = __('Unbuilt parcel') . ' · ' . $ejerlav;
                                } else {
                                    $matrikelLabel = null;
                                }
                                // Latest sale across grouped units (max by date)
                                $latestSale = $units->pluck('latest_sale')->filter()->sortByDesc('date')->first();
                                // Ejer(e) i denne adresse-gruppe (koncern-stien).
                                $groupOwners = $units->pluck('owner_name')->filter()->unique()->values();
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
                                        {{-- BFE er en intern nøgle, ikke en adresse. Vist alene
                                             i adresse-kolonnen så tre af fire rækker ulæselige ud
                                             (Frederik i browseren 30/7). Nummeret beholdes som
                                             reference, men feltet siger nu hvad der mangler. --}}
                                        <div>
                                            <span class="text-zinc-500 dark:text-zinc-400">{{ __('Adresse ikke hentet') }}</span>
                                            <div class="text-zinc-400 text-xs">BFE {{ $bfe }}</div>
                                        </div>
                                    @else
                                        <span class="text-zinc-400">-</span>
                                    @endif
                                </td>
                                @if($showOwnerColumn)
                                    <td class="py-2 pr-4 text-zinc-600 dark:text-zinc-400">
                                        @if($groupOwners->count() === 1)
                                            {{ $groupOwners->first() }}
                                        @elseif($groupOwners->count() > 1)
                                            <span title="{{ $groupOwners->implode(', ') }}">{{ $groupOwners->first() }} <span class="text-zinc-400 text-xs">+{{ $groupOwners->count() - 1 }}</span></span>
                                        @else
                                            <span class="text-zinc-300">-</span>
                                        @endif
                                    </td>
                                @endif
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
                                        @if($totalFootprint)
                                            <div class="text-zinc-500 text-xs" title="{{ __('Bygningens fodaftryk på grunden') }}">{{ number_format($totalFootprint, 0, ',', '.') }} m² {{ __('bebygget') }}</div>
                                        @endif
                                        @if($totalBuildingArea && $totalBuildingArea !== $totalFootprint)
                                            <div class="text-zinc-400 text-xs" title="{{ __('Etageareal (sum af alle etagers areal)') }}">{{ number_format($totalBuildingArea, 0, ',', '.') }} m² {{ __('etageareal') }}</div>
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

            {{-- Frasolgte ejendomme (EJF stadig viser dem, men Tinglysning bekræfter salg) --}}
            @if(($portfolio['sold_count'] ?? 0) > 0)
                <div x-data="{ open: false }" class="mt-6 pt-4 border-t border-zinc-200 dark:border-zinc-700">
                    <button type="button" @click="open = !open" class="text-sm text-zinc-600 dark:text-zinc-400 hover:text-zinc-900 dark:hover:text-zinc-100 inline-flex items-center gap-1.5">
                        <svg x-show="!open" class="size-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
                        <svg x-show="open" x-cloak class="size-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" /></svg>
                        <span>{{ $portfolio['sold_count'] }} {{ __('frasolgte ejendomme') }}</span>
                        <span class="text-xs text-zinc-400">— {{ __('iflg. EJF, men solgt iflg. Tinglysning') }}</span>
                    </button>
                    <div x-show="open" x-cloak class="mt-3 overflow-x-auto">
                        <table class="w-full text-sm opacity-75">
                            <thead>
                                <tr class="border-b border-zinc-200 dark:border-zinc-700">
                                    <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('Address') }}</th>
                                    <th class="text-right py-2 pr-4 font-medium text-zinc-500">{{ __('Areal') }}</th>
                                    <th class="text-right py-2 pr-4 font-medium text-zinc-500">{{ __('Solgt dato') }}</th>
                                    <th class="text-right py-2 pr-4 font-medium text-zinc-500">{{ __('Salgspris') }}</th>
                                    <th class="text-right py-2 font-medium text-zinc-500">{{ __('Køber') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($portfolio['sold_properties'] ?? [] as $sold)
                                    @php
                                        $addr = trim(($sold['address'] ?? '') . ', ' . ($sold['postal_code'] ?? '') . ' ' . ($sold['city'] ?? ''), ', ');
                                    @endphp
                                    <tr class="border-b border-zinc-100 dark:border-zinc-800">
                                        <td class="py-2 pr-4">
                                            @if($addr)
                                                <x-metis-link type="address" :query="$addr" />
                                            @else
                                                <span class="text-zinc-400 text-xs">BFE {{ $sold['matrikel_id'] }}</span>
                                            @endif
                                        </td>
                                        <td class="py-2 pr-4 text-right">{{ $sold['total_area'] ? number_format($sold['total_area'], 0, ',', '.') . ' m²' : '-' }}</td>
                                        <td class="py-2 pr-4 text-right">{{ $sold['sold_date'] ?? '-' }}</td>
                                        <td class="py-2 pr-4 text-right">
                                            @if($sold['latest_sale']['price'] ?? null)
                                                {{ number_format($sold['latest_sale']['price'], 0, ',', '.') }} kr.
                                            @else
                                                -
                                            @endif
                                        </td>
                                        <td class="py-2 text-right text-zinc-500 text-xs">
                                            {{ __('Se ejendom for køber') }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        @elseif(! $enriching && ! $building)
            <p class="text-sm text-zinc-500">{{ __('No properties found for this company.') }}</p>
        @endif
    </flux:card>
</div>
