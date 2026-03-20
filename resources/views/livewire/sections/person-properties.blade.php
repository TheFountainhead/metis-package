<div>
    <flux:card>
        <flux:heading size="lg" class="mb-4">{{ __('Properties') }}</flux:heading>

        @if(!empty($summary) && $summary['total_property_count'] > 0)
            <div class="mb-4 flex flex-wrap gap-4 text-sm">
                <span class="text-zinc-500">{{ __('Total') }}: <span class="font-medium text-zinc-900 dark:text-white">{{ $summary['total_property_count'] }}</span></span>
                @if($summary['total_valuation'] > 0)
                    <span class="text-zinc-500">{{ __('Valuation') }}: <span class="font-medium text-zinc-900 dark:text-white">{{ number_format($summary['total_valuation'], 0, ',', '.') }} kr.</span></span>
                @endif
                @if($summary['personal_property_count'] > 0)
                    <span class="text-zinc-500">{{ __('Personal') }}: <span class="font-medium text-zinc-900 dark:text-white">{{ $summary['personal_property_count'] }}</span></span>
                @endif
                @if($summary['company_property_count'] > 0)
                    <span class="text-zinc-500">{{ __('Via companies') }}: <span class="font-medium text-zinc-900 dark:text-white">{{ $summary['company_property_count'] }}</span></span>
                @endif
            </div>
        @endif

        {{-- Personal properties --}}
        @if(count($personalProperties) > 0)
            <flux:heading size="sm" class="mb-2 text-zinc-500">{{ __('Personal properties') }}</flux:heading>
            <div class="overflow-x-auto mb-6">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200 dark:border-zinc-700">
                            <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('Address') }}</th>
                            <th class="text-right py-2 pr-4 font-medium text-zinc-500">{{ __('Valuation') }}</th>
                            <th class="text-right py-2 pr-4 font-medium text-zinc-500">{{ __('Debt') }}</th>
                            <th class="text-right py-2 pr-4 font-medium text-zinc-500">{{ __('Area') }}</th>
                            <th class="text-right py-2 font-medium text-zinc-500">{{ __('Share') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($personalProperties as $prop)
                            @php
                                $addr = trim(($prop['address'] ?? '') . ', ' . ($prop['zip'] ?? '') . ' ' . ($prop['city'] ?? ''));
                                $totalDebt = collect($prop['mortgages'] ?? [])->sum('principal');
                            @endphp
                            <tr class="border-b border-zinc-100 dark:border-zinc-800">
                                <td class="py-2 pr-4">
                                    <x-metis-link type="address" :query="$addr" />
                                </td>
                                <td class="py-2 pr-4 text-right">{{ isset($prop['public_valuation']) ? number_format($prop['public_valuation'], 0, ',', '.') . ' kr.' : '-' }}</td>
                                <td class="py-2 pr-4 text-right">{{ $totalDebt > 0 ? number_format($totalDebt, 0, ',', '.') . ' kr.' : '-' }}</td>
                                <td class="py-2 pr-4 text-right">{{ isset($prop['area_building']) ? $prop['area_building'] . ' m²' : '-' }}</td>
                                <td class="py-2 text-right">{{ isset($prop['ownership_share']) ? number_format($prop['ownership_share'], 0) . '%' : '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{-- Company properties --}}
        @if(count($companies) > 0)
            @php
                $companiesWithProperties = collect($companies)->filter(fn ($c) =>
                    count($c['properties'] ?? []) > 0 ||
                    collect($c['subsidiaries'] ?? [])->contains(fn ($s) =>
                        count($s['properties'] ?? []) > 0 ||
                        collect($s['subsidiaries'] ?? [])->contains(fn ($ss) => count($ss['properties'] ?? []) > 0)
                    )
                );
            @endphp

            @if($companiesWithProperties->isNotEmpty())
                <flux:heading size="sm" class="mb-2 text-zinc-500">{{ __('Properties via companies') }}</flux:heading>

                @foreach($companiesWithProperties as $company)
                    <div class="mb-4">
                        <div class="flex items-center gap-2 mb-2">
                            <span class="font-medium text-zinc-900 dark:text-white text-sm">
                                <x-metis-link type="cvr" :query="$company['cvr']">{{ $company['name'] }}</x-metis-link>
                            </span>
                            @if($company['roles'])
                                <span class="text-xs text-zinc-400">
                                    {{ collect($company['roles'])->map(fn ($r) => $r['title'] . ($r['ownership_share'] ? ' (' . number_format($r['ownership_share'], 0) . '%)' : ''))->join(', ') }}
                                </span>
                            @endif
                        </div>

                        @include('metis::livewire.sections.partials.property-table', ['properties' => $company['properties']])

                        {{-- Subsidiaries --}}
                        @foreach($company['subsidiaries'] ?? [] as $sub)
                            @if(count($sub['properties'] ?? []) > 0 || collect($sub['subsidiaries'] ?? [])->contains(fn ($ss) => count($ss['properties'] ?? []) > 0))
                                <div class="ml-4 mt-2 mb-3">
                                    <div class="flex items-center gap-2 mb-1">
                                        <span class="text-zinc-400">└</span>
                                        <span class="font-medium text-zinc-900 dark:text-white text-sm">
                                            <x-metis-link type="cvr" :query="$sub['cvr']">{{ $sub['name'] }}</x-metis-link>
                                        </span>
                                        @if($sub['ownership_share'])
                                            <span class="text-xs text-zinc-400">({{ number_format($sub['ownership_share'], 0) }}%)</span>
                                        @endif
                                    </div>
                                    @include('metis::livewire.sections.partials.property-table', ['properties' => $sub['properties']])

                                    {{-- Grand-children --}}
                                    @foreach($sub['subsidiaries'] ?? [] as $child)
                                        @if(count($child['properties'] ?? []) > 0)
                                            <div class="ml-4 mt-2">
                                                <div class="flex items-center gap-2 mb-1">
                                                    <span class="text-zinc-400">└</span>
                                                    <span class="font-medium text-zinc-900 dark:text-white text-sm">
                                                        <x-metis-link type="cvr" :query="$child['cvr']">{{ $child['name'] }}</x-metis-link>
                                                    </span>
                                                    @if($child['ownership_share'])
                                                        <span class="text-xs text-zinc-400">({{ number_format($child['ownership_share'], 0) }}%)</span>
                                                    @endif
                                                </div>
                                                @include('metis::livewire.sections.partials.property-table', ['properties' => $child['properties']])
                                            </div>
                                        @endif
                                    @endforeach
                                </div>
                            @endif
                        @endforeach
                    </div>
                @endforeach
            @endif
        @endif

        @if(empty($personalProperties) && empty($companies))
            <p class="text-sm text-zinc-500">{{ __('No properties found.') }}</p>
        @endif
    </flux:card>
</div>
