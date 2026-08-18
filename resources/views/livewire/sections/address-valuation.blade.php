<div>
    <flux:card>
        <flux:heading size="lg" class="mb-4">{{ __('Property Valuation') }}</flux:heading>
        @if($valuation)
            <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm mb-4">
                <dt class="text-zinc-500">{{ __('Property value') }}</dt>
                <dd class="font-medium">{{ number_format($valuation['estimated_value'] ?? 0, 0, ',', '.') }} kr.</dd>
                @if(isset($valuation['land_value']))
                    <dt class="text-zinc-500">{{ __('Land value') }}</dt>
                    <dd>{{ number_format($valuation['land_value'], 0, ',', '.') }} kr.</dd>
                @endif
                @if(isset($valuation['date']))
                    <dt class="text-zinc-500">{{ __('Valuation date') }}</dt>
                    <dd>{{ $valuation['date'] }}</dd>
                @endif
                @if(isset($valuation['source']))
                    <dt class="text-zinc-500">{{ __('Source') }}</dt>
                    <dd>{{ $valuation['source'] }}</dd>
                @endif
            </dl>

            @if(count($history) > 0)
                <h4 class="text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-2">{{ __('Valuation History') }}</h4>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200 dark:border-zinc-700">
                            <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('Date') }}</th>
                            <th class="text-right py-2 pr-4 font-medium text-zinc-500">{{ __('Property value') }}</th>
                            <th class="text-right py-2 font-medium text-zinc-500">{{ __('Land value') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($history as $entry)
                            <tr class="border-b border-zinc-100 dark:border-zinc-800">
                                <td class="py-2 pr-4">{{ $entry['date'] ?? '-' }}</td>
                                <td class="py-2 pr-4 text-right">{{ isset($entry['estimated_value']) ? number_format($entry['estimated_value'], 0, ',', '.') . ' kr.' : '-' }}</td>
                                <td class="py-2 text-right">{{ isset($entry['land_value']) ? number_format($entry['land_value'], 0, ',', '.') . ' kr.' : '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        @else
            @if($hasError)
                @include('metis::livewire.sections.partials.lookup-error', ['hvad' => __('vurderingsdata')])
            @else
            <p class="text-sm text-zinc-500">{{ __('No valuation data found.') }}</p>
            @endif
        @endif
    </flux:card>
</div>
