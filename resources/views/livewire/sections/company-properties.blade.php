<div>
    <flux:card>
        <flux:heading size="lg" class="mb-4">{{ __('Property Portfolio') }}</flux:heading>
        @if($properties && count($properties) > 0)
            <div class="mb-3 flex flex-wrap gap-4 text-sm">
                <span class="text-zinc-500">{{ __('Properties') }}: <span class="font-medium text-zinc-900 dark:text-white">{{ $totalCount }}</span></span>
                @if(($summary['total_valuation'] ?? 0) > 0)
                    <span class="text-zinc-500">{{ __('Total valuation') }}: <span class="font-medium text-zinc-900 dark:text-white">{{ number_format($summary['total_valuation'], 0, ',', '.') }} kr.</span></span>
                @endif
                @if(($summary['total_area'] ?? 0) > 0)
                    <span class="text-zinc-500">{{ __('Total area') }}: <span class="font-medium text-zinc-900 dark:text-white">{{ number_format($summary['total_area'], 0, ',', '.') }} m²</span></span>
                @endif
            </div>

            @php
                $grouped = collect($properties)->groupBy(function ($p) {
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
                            @endphp
                            <tr class="border-b border-zinc-100 dark:border-zinc-800">
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
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($totalCount > count($properties))
                <div class="mt-3 text-center">
                    <button wire:click="loadMore" class="text-sm text-blue-600 hover:text-blue-800 transition">
                        {{ __('Show more') }} ({{ count($properties) }} / {{ $totalCount }})
                    </button>
                </div>
            @endif
        @else
            <p class="text-sm text-zinc-500">{{ __('No properties found for this company.') }}</p>
        @endif
    </flux:card>
</div>
