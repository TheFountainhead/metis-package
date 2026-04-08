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
            <div class="mb-3 flex flex-wrap gap-4 text-sm">
                <span class="text-zinc-500">{{ __('Properties') }}: <span class="font-medium text-zinc-900 dark:text-white">{{ $portfolio['total_count'] ?? $portfolio['property_count'] ?? 0 }}</span></span>
                @if(($portfolio['total_valuation'] ?? 0) > 0)
                    <span class="text-zinc-500">{{ __('Total valuation') }}: <span class="font-medium text-zinc-900 dark:text-white">{{ number_format($portfolio['total_valuation'], 0, ',', '.') }} kr.</span></span>
                @endif
                @if(($portfolio['total_area'] ?? 0) > 0)
                    <span class="text-zinc-500">{{ __('Total area') }}: <span class="font-medium text-zinc-900 dark:text-white">{{ number_format($portfolio['total_area'], 0, ',', '.') }} m²</span></span>
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
                            <th class="text-right py-2 pr-4 font-medium text-zinc-500">{{ __('Area') }}</th>
                            <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('Year') }}</th>
                            <th class="text-right py-2 font-medium text-zinc-500">{{ __('Valuation') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($grouped as $address => $units)
                            @php
                                $totalArea = $units->sum(fn ($u) => $u['total_area'] ?? 0);
                                $totalVal = $units->sum(fn ($u) => $u['valuation'] ?? 0);
                                $year = $units->pluck('building_year')->filter()->first();
                                $first = $units->first();
                                $addr = trim(($first['address'] ?? '') . ', ' . ($first['postal_code'] ?? '') . ' ' . ($first['city'] ?? ''), ', ');
                                $rowBfe = $first['matrikel_id'] ?? null;
                            @endphp
                            <tr class="border-b border-zinc-100 dark:border-zinc-800 cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-800/30"
                                @if($rowBfe) wire:click="toggleProperty('{{ $rowBfe }}')" @endif>
                                <td class="py-2 pr-4">
                                    <x-metis-link type="address" :query="$addr" />
                                </td>
                                <td class="py-2 pr-4 text-right">
                                    @if($units->count() > 1)
                                        <span class="text-zinc-500">{{ $units->count() }}</span>
                                    @else
                                        <span class="text-zinc-300">1</span>
                                    @endif
                                </td>
                                <td class="py-2 pr-4 text-right">{{ $totalArea ? number_format($totalArea, 0, ',', '.') . ' m²' : '-' }}</td>
                                <td class="py-2 pr-4">{{ $year ?? '-' }}</td>
                                <td class="py-2 text-right">{{ $totalVal ? number_format($totalVal, 0, ',', '.') . ' kr.' : '-' }}</td>
                            </tr>
                            @if($rowBfe && $expandedBfe === $rowBfe && $expandedDetails)
                                <tr>
                                    <td colspan="5" class="p-0">
                                        <div class="p-4 bg-zinc-50 dark:bg-zinc-800/50 border-t border-zinc-200 dark:border-zinc-700">
                                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                                {{-- Left: Street View photo --}}
                                                <div>
                                                    @if($expandedDetails['street_view_url'] ?? null)
                                                        <img src="{{ $expandedDetails['street_view_url'] }}"
                                                             alt="{{ $expandedDetails['address'] }}"
                                                             class="w-full rounded-lg shadow-sm" loading="lazy">
                                                    @else
                                                        <div class="w-full h-48 bg-zinc-200 dark:bg-zinc-700 rounded-lg flex items-center justify-center text-zinc-400">
                                                            {{ __('No photo available') }}
                                                        </div>
                                                    @endif
                                                </div>

                                                {{-- Right: Details --}}
                                                <div class="space-y-3">
                                                    {{-- Owner --}}
                                                    @if($expandedDetails['owner'] ?? null)
                                                        <div>
                                                            <h4 class="text-xs font-medium text-zinc-500 uppercase">{{ __('Owner') }}</h4>
                                                            <p class="text-sm font-medium">{{ $expandedDetails['owner']['name'] ?? '—' }}</p>
                                                            @if($expandedDetails['owner']['ownership_share'] ?? null)
                                                                <p class="text-xs text-zinc-500">{{ $expandedDetails['owner']['ownership_share'] }}%</p>
                                                            @endif
                                                        </div>
                                                    @endif

                                                    {{-- BBR --}}
                                                    <div>
                                                        <h4 class="text-xs font-medium text-zinc-500 uppercase">{{ __('Building') }}</h4>
                                                        <div class="grid grid-cols-2 gap-1 text-sm">
                                                            <span class="text-zinc-500">{{ __('Year') }}:</span>
                                                            <span>{{ $expandedDetails['year_built'] ?? '—' }}</span>
                                                            <span class="text-zinc-500">{{ __('Area') }}:</span>
                                                            <span>{{ $expandedDetails['area_building'] ? $expandedDetails['area_building'] . ' m²' : '—' }}</span>
                                                            @if($expandedDetails['bbr']['buildings'][0]['usage_label'] ?? null)
                                                                <span class="text-zinc-500">{{ __('Usage') }}:</span>
                                                                <span>{{ $expandedDetails['bbr']['buildings'][0]['usage_label'] }}</span>
                                                            @endif
                                                        </div>
                                                    </div>

                                                    {{-- Valuation --}}
                                                    @if($expandedDetails['valuation'] ?? null)
                                                        <div>
                                                            <h4 class="text-xs font-medium text-zinc-500 uppercase">{{ __('Valuation') }}</h4>
                                                            <div class="grid grid-cols-2 gap-1 text-sm">
                                                                <span class="text-zinc-500">{{ __('Property value') }}:</span>
                                                                <span>{{ number_format($expandedDetails['valuation']['property_value'] ?? 0, 0, ',', '.') }} DKK</span>
                                                                <span class="text-zinc-500">{{ __('Land value') }}:</span>
                                                                <span>{{ number_format($expandedDetails['valuation']['land_value'] ?? 0, 0, ',', '.') }} DKK</span>
                                                            </div>
                                                        </div>
                                                    @endif
                                                </div>
                                            </div>

                                            {{-- Mortgages --}}
                                            @if(count($expandedDetails['mortgages'] ?? []) > 0)
                                                <div class="mt-4">
                                                    <h4 class="text-xs font-medium text-zinc-500 uppercase mb-2">{{ __('Mortgages') }} ({{ count($expandedDetails['mortgages']) }})</h4>
                                                    <table class="w-full text-sm">
                                                        <thead>
                                                            <tr class="text-left text-zinc-500 border-b border-zinc-200 dark:border-zinc-700">
                                                                <th class="py-1 pr-2">{{ __('Creditor') }}</th>
                                                                <th class="py-1 px-2 text-right">{{ __('Amount') }}</th>
                                                                <th class="py-1 pl-2 text-right">{{ __('Rate') }}</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            @foreach($expandedDetails['mortgages'] as $mortgage)
                                                                <tr class="border-b border-zinc-100 dark:border-zinc-800">
                                                                    <td class="py-1 pr-2">{{ $mortgage['creditor'] }}</td>
                                                                    <td class="py-1 px-2 text-right">{{ number_format($mortgage['principal_amount'] ?? 0, 0, ',', '.') }} DKK</td>
                                                                    <td class="py-1 pl-2 text-right">{{ number_format($mortgage['interest_rate'] ?? 0, 2, ',', '.') }}%</td>
                                                                </tr>
                                                            @endforeach
                                                        </tbody>
                                                    </table>
                                                </div>
                                            @endif

                                            {{-- Map --}}
                                            @if(($expandedDetails['latitude'] ?? null) && ($expandedDetails['longitude'] ?? null))
                                                <div class="mt-4">
                                                    <div class="w-full h-48 rounded-lg overflow-hidden">
                                                        <img src="https://maps.googleapis.com/maps/api/staticmap?center={{ $expandedDetails['latitude'] }},{{ $expandedDetails['longitude'] }}&zoom=16&size=600x200&maptype=roadmap&markers={{ $expandedDetails['latitude'] }},{{ $expandedDetails['longitude'] }}&key={{ config('metis.google_maps_api_key', config('services.google_maps.api_key', '')) }}"
                                                             alt="Map" class="w-full h-full object-cover" loading="lazy">
                                                    </div>
                                                </div>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endif
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
