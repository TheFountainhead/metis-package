<div>
    <flux:card>
        <flux:heading size="lg" class="mb-4">{{ __('Energy Label') }}</flux:heading>
        @if($energy && ($energy['energy_label'] ?? null))
            @php
                $classification = $energy['energy_label'];
                $color = match (true) {
                    str_starts_with($classification, 'A') => 'green',
                    $classification === 'B' => 'lime',
                    $classification === 'C' => 'yellow',
                    $classification === 'D' => 'amber',
                    $classification === 'E' => 'orange',
                    default => 'red',
                };
            @endphp
            <div class="flex items-center gap-3 mb-4">
                <flux:badge color="{{ $color }}" size="lg">{{ $classification }}</flux:badge>
                @if($energy['is_expired'] ?? false)
                    <flux:badge color="zinc" size="sm">{{ __('Expired') }}</flux:badge>
                @endif
            </div>
            <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                @if($energy['valid_until'] ?? null)
                    <dt class="text-zinc-500">{{ __('Valid until') }}</dt>
                    <dd>{{ $energy['valid_until'] }}</dd>
                @endif
                @if($energy['heated_area_sqm'] ?? null)
                    <dt class="text-zinc-500">{{ __('Heated area') }}</dt>
                    <dd>{{ number_format($energy['heated_area_sqm'], 0, ',', '.') }} m²</dd>
                @endif
            </dl>
            @if($energy['report_url'] ?? null)
                <a href="{{ $energy['report_url'] }}" target="_blank" rel="noopener" class="mt-3 inline-block text-blue-600 hover:underline dark:text-blue-400 text-xs">{{ __('View energy label report (PDF)') }}</a>
            @endif
        @else
            <p class="text-sm text-zinc-500">{{ __('No energy label found.') }}</p>
        @endif
    </flux:card>
</div>
