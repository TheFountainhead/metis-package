<div>
    @if(count($rounds) > 1)
        <flux:card>
            <div class="flex flex-wrap items-center gap-2 mb-4">
                <flux:heading size="lg">{{ __('Kapitalhistorik') }}</flux:heading>
                @if(($summary['round_count'] ?? 0) > 0)
                    <flux:badge color="sky" size="sm">{{ trans_choice('{1} :count kapitaludvidelse|[2,*] :count kapitaludvidelser', $summary['round_count'], ['count' => $summary['round_count']]) }}</flux:badge>
                @endif
            </div>

            @if(($summary['founding_capital'] ?? null) && ($summary['current_capital'] ?? null))
                <p class="text-sm text-zinc-500 mb-3">
                    {{ __('Stiftet med') }} <span class="font-medium text-zinc-900 dark:text-white">{{ number_format($summary['founding_capital'], 0, ',', '.') }} {{ $currency }}</span>
                    → {{ __('nuværende kapital') }} <span class="font-medium text-zinc-900 dark:text-white">{{ number_format($summary['current_capital'], 0, ',', '.') }} {{ $currency }}</span>
                </p>
            @endif

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200 dark:border-zinc-700">
                            <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('Dato') }}</th>
                            <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('Hændelse') }}</th>
                            <th class="text-right py-2 pr-4 font-medium text-zinc-500">{{ __('Kapital') }}</th>
                            <th class="text-right py-2 pr-4 font-medium text-zinc-500">{{ __('Ændring') }}</th>
                            <th class="text-left py-2 font-medium text-zinc-500">{{ __('Ejer-ændringer samme dato') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rounds as $round)
                            <tr class="border-b border-zinc-100 dark:border-zinc-800">
                                <td class="py-2 pr-4 whitespace-nowrap">{{ $round['date'] }}</td>
                                <td class="py-2 pr-4">
                                    @switch($round['type'])
                                        @case('founding')<flux:badge color="zinc" size="sm">{{ __('Stiftelse') }}</flux:badge>@break
                                        @case('increase')<flux:badge color="green" size="sm">{{ __('Forhøjelse') }}</flux:badge>@break
                                        @case('decrease')<flux:badge color="amber" size="sm">{{ __('Nedsættelse') }}</flux:badge>@break
                                        @default<flux:badge color="zinc" size="sm">{{ __('Uændret') }}</flux:badge>
                                    @endswitch
                                </td>
                                <td class="py-2 pr-4 text-right whitespace-nowrap">{{ number_format($round['capital'], 0, ',', '.') }} {{ $currency }}</td>
                                <td class="py-2 pr-4 text-right whitespace-nowrap">
                                    @if($round['change'] !== null)
                                        <span class="{{ $round['change'] >= 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-amber-700 dark:text-amber-400' }}">
                                            {{ $round['change'] >= 0 ? '+' : '' }}{{ number_format($round['change'], 0, ',', '.') }}
                                            @if($round['change_pct'] !== null)
                                                <span class="text-zinc-400">({{ $round['change_pct'] >= 0 ? '+' : '' }}{{ number_format($round['change_pct'], 1, ',', '.') }}%)</span>
                                            @endif
                                        </span>
                                    @else
                                        -
                                    @endif
                                </td>
                                <td class="py-2 text-xs text-zinc-500">
                                    @forelse($round['owner_changes'] ?? [] as $oc)
                                        <div>{{ $oc['owner'] }} → {{ number_format($oc['share_pct'], $oc['share_pct'] == (int) $oc['share_pct'] ? 0 : 2, ',', '.') }}%</div>
                                    @empty
                                        -
                                    @endforelse
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="text-[11px] text-zinc-400 dark:text-zinc-500 mt-2 italic">
                {{ __('Kilde:') }} {{ __('CVR-registrets kapitalhistorik (KAPITAL + ejerandels-perioder). Beløb er nominel selskabskapital, ikke investeret beløb eller værdiansættelse.') }}
            </p>
        </flux:card>
    @endif
</div>
