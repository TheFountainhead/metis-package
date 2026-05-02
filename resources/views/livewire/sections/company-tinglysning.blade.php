<div @if($streaming) wire:poll.2s="pollForUpdates" @endif>
    <flux:card>
        <flux:heading size="lg" class="mb-4">{{ __('Tinglysning') }}</flux:heading>

        @if($hasError)
            @include('metis::livewire.sections.partials.error-state', [
                'message' => $errorMessage,
            ])
        @else
            {{-- tree_meta summary --}}
            @if($treeMeta)
                <div class="mb-5 flex flex-wrap gap-x-6 gap-y-2 text-sm">
                    <span class="text-zinc-500">
                        {{ __('Selskaber i koncern') }}:
                        <span class="font-medium text-zinc-900 dark:text-white">{{ $treeMeta['total_descendant_companies'] ?? 0 }}</span>
                    </span>
                    <span class="text-zinc-500">
                        {{ __('Ejendomme') }}:
                        <span class="font-medium text-zinc-900 dark:text-white">{{ $treeMeta['total_properties'] ?? 0 }}</span>
                    </span>
                    <span class="text-zinc-500">
                        {{ __('Pantebreve') }}:
                        <span class="font-medium text-zinc-900 dark:text-white">{{ $treeMeta['total_mortgages'] ?? 0 }}</span>
                    </span>
                    @if(($treeMeta['total_principal_amount'] ?? 0) > 0)
                        <span class="text-zinc-500">
                            {{ __('Hovedstol total') }}:
                            <span class="font-medium text-zinc-900 dark:text-white">{{ number_format($treeMeta['total_principal_amount'] / 100, 0, ',', '.') }} kr.</span>
                        </span>
                    @endif
                    @if(isset($treeMeta['weighted_ltv']))
                        <span class="text-zinc-500">
                            {{ __('Vægtet LTV') }}:
                            <span class="font-medium text-zinc-900 dark:text-white">{{ number_format($treeMeta['weighted_ltv'] * 100, 0) }}%</span>
                        </span>
                    @endif
                </div>
            @endif

            {{-- streaming progress --}}
            @if($streaming)
                <div class="flex items-center gap-2 text-blue-600 text-xs mb-4 px-1">
                    <svg class="size-3.5 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <span>{{ __('Henter pantebreve') }}: {{ $deliveredSoFar }} / {{ $totalExpected }}</span>
                </div>
            @endif

            {{-- tier breakdown table --}}
            @if(count($tierBreakdown) > 0)
                <div class="mb-6">
                    <h3 class="text-sm font-medium text-zinc-700 dark:text-zinc-200 mb-2">{{ __('Tier breakdown') }}</h3>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-zinc-200 dark:border-zinc-700">
                                    <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('Selskab') }}</th>
                                    <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('CVR') }}</th>
                                    <th class="text-right py-2 pr-4 font-medium text-zinc-500">{{ __('Niveau') }}</th>
                                    <th class="text-right py-2 pr-4 font-medium text-zinc-500">{{ __('Ejendomme') }}</th>
                                    <th class="text-right py-2 pr-4 font-medium text-zinc-500">{{ __('Pantebreve') }}</th>
                                    <th class="text-right py-2 pr-4 font-medium text-zinc-500">{{ __('Hovedstol') }}</th>
                                    <th class="text-right py-2 font-medium text-zinc-500">{{ __('Vægtet LTV') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($tierBreakdown as $tier)
                                    <tr class="border-b border-zinc-100 dark:border-zinc-800">
                                        <td class="py-2 pr-4">{{ $tier['name'] ?? '-' }}</td>
                                        <td class="py-2 pr-4 text-zinc-500 text-xs">{{ $tier['cvr'] ?? '-' }}</td>
                                        <td class="py-2 pr-4 text-right">{{ $tier['depth'] ?? 0 }}</td>
                                        <td class="py-2 pr-4 text-right">{{ $tier['property_count'] ?? 0 }}</td>
                                        <td class="py-2 pr-4 text-right">{{ $tier['mortgage_count'] ?? 0 }}</td>
                                        <td class="py-2 pr-4 text-right">
                                            @if(($tier['principal_amount'] ?? 0) > 0)
                                                {{ number_format($tier['principal_amount'] / 100, 0, ',', '.') }} kr.
                                            @else
                                                <span class="text-zinc-300">-</span>
                                            @endif
                                        </td>
                                        <td class="py-2 text-right">
                                            @if(isset($tier['weighted_ltv']))
                                                {{ number_format($tier['weighted_ltv'] * 100, 0) }}%
                                            @else
                                                <span class="text-zinc-300">-</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            {{-- mortgages flat-list --}}
            @if(count($mortgages) > 0 || $streaming)
                <h3 class="text-sm font-medium text-zinc-700 dark:text-zinc-200 mb-2">{{ __('Pantebreve') }}</h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-zinc-200 dark:border-zinc-700">
                                <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('Adresse') }}</th>
                                <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('Ejer') }}</th>
                                <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('Type') }}</th>
                                <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('Kreditor') }}</th>
                                <th class="text-right py-2 pr-4 font-medium text-zinc-500">{{ __('Hovedstol') }}</th>
                                <th class="text-right py-2 pr-4 font-medium text-zinc-500">{{ __('LTV') }}</th>
                                <th class="text-left py-2 font-medium text-zinc-500">{{ __('Tinglyst') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($mortgages as $m)
                                <tr class="border-b border-zinc-100 dark:border-zinc-800">
                                    <td class="py-2 pr-4">
                                        @if($m['address'] ?? null)
                                            <x-metis-link type="address" :query="$m['address']" />
                                        @else
                                            <span class="text-zinc-300">-</span>
                                        @endif
                                    </td>
                                    <td class="py-2 pr-4">
                                        @if($m['owner_company']['cvr'] ?? null)
                                            <span class="text-zinc-700 dark:text-zinc-200">{{ $m['owner_company']['name'] ?? $m['owner_company']['cvr'] }}</span>
                                        @else
                                            <span class="text-zinc-300">-</span>
                                        @endif
                                    </td>
                                    <td class="py-2 pr-4 text-zinc-600 dark:text-zinc-300 text-xs">{{ $m['mortgage_type'] ?? '-' }}</td>
                                    <td class="py-2 pr-4 text-zinc-600 dark:text-zinc-300 text-xs">{{ $m['creditor'] ?? '-' }}</td>
                                    <td class="py-2 pr-4 text-right">
                                        @if(($m['principal_amount'] ?? 0) > 0)
                                            {{ number_format($m['principal_amount'] / 100, 0, ',', '.') }} kr.
                                        @else
                                            <span class="text-zinc-300">-</span>
                                        @endif
                                    </td>
                                    <td class="py-2 pr-4 text-right">
                                        @if(isset($m['ltv']['value']))
                                            <span title="{{ $m['ltv']['method'] ?? '' }}">{{ number_format($m['ltv']['value'] * 100, 0) }}%</span>
                                        @else
                                            <span class="text-zinc-300">-</span>
                                        @endif
                                    </td>
                                    <td class="py-2 text-zinc-500 text-xs">{{ $m['registration_date'] ?? '-' }}</td>
                                </tr>
                            @endforeach
                            @if($streaming)
                                @for($i = 0; $i < 3; $i++)
                                    @include('metis::livewire.sections.partials.skeleton-row', ['columns' => 7])
                                @endfor
                            @endif
                        </tbody>
                    </table>
                </div>
            @elseif(! $streaming)
                <p class="text-sm text-zinc-500">{{ __('Ingen pantebreve fundet for denne koncern.') }}</p>
            @endif
        @endif
    </flux:card>
</div>
