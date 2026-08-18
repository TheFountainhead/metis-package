<div>
    <flux:card>
        <flux:heading size="lg" class="mb-4">{{ __('Building Data (BBR)') }}</flux:heading>
        @if($bbr)
            <dl class="grid grid-cols-2 sm:grid-cols-3 gap-x-6 gap-y-3 text-sm">
                @if($bbr['year_built'] ?? null)
                    <div>
                        <dt class="text-zinc-400 text-xs">{{ __('Year built') }}</dt>
                        <dd class="font-medium">{{ $bbr['year_built'] }}</dd>
                    </div>
                @endif
                @if($bbr['total_area'] ?? null)
                    <div>
                        <dt class="text-zinc-400 text-xs">{{ __('Total area') }}</dt>
                        <dd class="font-medium">{{ number_format($bbr['total_area'], 0, ',', '.') }} m²</dd>
                    </div>
                @endif
                @if($bbr['commercial_area'] ?? null)
                    <div>
                        <dt class="text-zinc-400 text-xs">{{ __('Commercial area') }}</dt>
                        <dd class="font-medium">{{ number_format($bbr['commercial_area'], 0, ',', '.') }} m²</dd>
                    </div>
                @endif
                @if($bbr['residential_area'] ?? null)
                    <div>
                        <dt class="text-zinc-400 text-xs">{{ __('Residential area') }}</dt>
                        <dd class="font-medium">{{ number_format($bbr['residential_area'], 0, ',', '.') }} m²</dd>
                    </div>
                @endif
                @if($bbr['floors'] ?? null)
                    <div>
                        <dt class="text-zinc-400 text-xs">{{ __('Floors') }}</dt>
                        <dd class="font-medium">{{ $bbr['floors'] }}</dd>
                    </div>
                @endif
                @if($bbr['unit_count'] ?? null)
                    <div>
                        <dt class="text-zinc-400 text-xs">{{ __('Units') }}</dt>
                        <dd class="font-medium">{{ $bbr['unit_count'] }}</dd>
                    </div>
                @endif
                @if($bbr['wall_material'] ?? null)
                    <div>
                        <dt class="text-zinc-400 text-xs">{{ __('Wall material') }}</dt>
                        <dd class="font-medium">{{ $bbr['wall_material'] }}</dd>
                    </div>
                @endif
                @if($bbr['roof_material'] ?? null)
                    <div>
                        <dt class="text-zinc-400 text-xs">{{ __('Roof material') }}</dt>
                        <dd class="font-medium">{{ $bbr['roof_material'] }}</dd>
                    </div>
                @endif
                @if($bbr['heating'] ?? null)
                    <div>
                        <dt class="text-zinc-400 text-xs">{{ __('Heating') }}</dt>
                        <dd class="font-medium">{{ $bbr['heating'] }}</dd>
                    </div>
                @endif
            </dl>

            {{-- Units table --}}
            @if(count($bbr['units'] ?? []) > 0)
                <div class="mt-4 pt-4 border-t border-zinc-100">
                    <div class="text-xs text-zinc-400 uppercase mb-2">{{ __('Units') }}</div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-zinc-200 text-left">
                                    <th class="py-1.5 pr-3 font-medium text-zinc-500 text-xs">{{ __('Usage') }}</th>
                                    <th class="py-1.5 pr-3 font-medium text-zinc-500 text-xs text-right">{{ __('Area') }}</th>
                                    <th class="py-1.5 font-medium text-zinc-500 text-xs text-right">{{ __('Rooms') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($bbr['units'] as $unit)
                                    <tr class="border-b border-zinc-50">
                                        <td class="py-1.5 pr-3">{{ $unit['usage_label'] ?? $unit['usage_code'] ?? '-' }}</td>
                                        <td class="py-1.5 pr-3 text-right">{{ $unit['area'] ? number_format($unit['area'], 0, ',', '.') . ' m²' : '-' }}</td>
                                        <td class="py-1.5 text-right">{{ $unit['rooms'] ?? '-' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        @else
            @if($hasError)
                @include('metis::livewire.sections.partials.lookup-error', ['errorMessage' => $errorMessage])
            @else
            <p class="text-sm text-zinc-500">{{ __('No building data found.') }}</p>
            @endif
        @endif
    </flux:card>
</div>
