@if(count($properties ?? []) > 0)
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-zinc-200 dark:border-zinc-700">
                    <th class="text-left py-1.5 pr-4 font-medium text-zinc-500 text-xs">{{ __('Address') }}</th>
                    <th class="text-right py-1.5 pr-4 font-medium text-zinc-500 text-xs">{{ __('Valuation') }}</th>
                    <th class="text-right py-1.5 pr-4 font-medium text-zinc-500 text-xs">{{ __('Area') }}</th>
                    <th class="text-right py-1.5 pr-4 font-medium text-zinc-500 text-xs">{{ __('Year') }}</th>
                    <th class="text-right py-1.5 font-medium text-zinc-500 text-xs">{{ __('Share') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($properties as $prop)
                    @php
                        $addr = trim(($prop['address'] ?? '') . ', ' . ($prop['postal_code'] ?? '') . ' ' . ($prop['city'] ?? ''), ', ');
                    @endphp
                    <tr class="border-b border-zinc-100 dark:border-zinc-800">
                        <td class="py-1.5 pr-4">
                            @if($addr && $addr !== ', ')
                                <x-metis-link type="address" :query="$addr" />
                            @else
                                <span class="text-zinc-400">BFE {{ $prop['bfe'] ?? '-' }}</span>
                            @endif
                        </td>
                        <td class="py-1.5 pr-4 text-right">{{ ($prop['valuation'] ?? null) ? number_format($prop['valuation'], 0, ',', '.') . ' kr.' : '-' }}</td>
                        <td class="py-1.5 pr-4 text-right">{{ ($prop['total_area'] ?? $prop['building_area'] ?? null) ? ($prop['total_area'] ?? $prop['building_area']) . ' m²' : '-' }}</td>
                        <td class="py-1.5 pr-4 text-right">{{ $prop['building_year'] ?? '-' }}</td>
                        <td class="py-1.5 text-right">{{ isset($prop['ownership_share']) ? number_format($prop['ownership_share'], 0) . '%' : '-' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
