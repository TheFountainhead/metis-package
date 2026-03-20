<div>
    <flux:card>
        <flux:heading size="lg" class="mb-4">{{ __('Property Portfolio') }}</flux:heading>
        @if($portfolio && count($portfolio['properties'] ?? []) > 0)
            <div class="mb-3 flex gap-4 text-sm">
                <span class="text-zinc-500">{{ __('Properties') }}: <span class="font-medium text-zinc-900 dark:text-white">{{ $portfolio['property_count'] ?? 0 }}</span></span>
                <span class="text-zinc-500">{{ __('Total valuation') }}: <span class="font-medium text-zinc-900 dark:text-white">{{ number_format($portfolio['total_valuation'] ?? 0, 0, ',', '.') }} kr.</span></span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200 dark:border-zinc-700">
                            <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('Address') }}</th>
                            <th class="text-right py-2 pr-4 font-medium text-zinc-500">{{ __('Valuation') }}</th>
                            <th class="text-right py-2 pr-4 font-medium text-zinc-500">{{ __('Area') }}</th>
                            <th class="text-left py-2 font-medium text-zinc-500">{{ __('Year') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($portfolio['properties'] as $prop)
                            <tr class="border-b border-zinc-100 dark:border-zinc-800">
                                <td class="py-2 pr-4">
                                    @php $addr = trim(($prop['address'] ?? '') . ', ' . ($prop['postal_code'] ?? '') . ' ' . ($prop['city'] ?? ''), ', '); @endphp
                                    <x-metis-link type="address" :query="$addr" />
                                </td>
                                <td class="py-2 pr-4 text-right">{{ $prop['valuation'] ? number_format($prop['valuation'], 0, ',', '.') . ' kr.' : '-' }}</td>
                                <td class="py-2 pr-4 text-right">{{ $prop['total_area'] ? $prop['total_area'] . ' m²' : '-' }}</td>
                                <td class="py-2">{{ $prop['building_year'] ?? '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="text-sm text-zinc-500">{{ __('No properties found for this company.') }}</p>
        @endif
    </flux:card>
</div>
