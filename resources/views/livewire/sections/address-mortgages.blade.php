<div>
    <flux:card>
        <flux:heading size="lg" class="mb-4">{{ __('Mortgages') }}</flux:heading>
        @if(count($mortgages) > 0)
            @if($totalDebt)
                <div class="flex flex-wrap items-center gap-2 mb-3">
                    <p class="text-sm text-zinc-500">{{ __('Total debt') }}: <span class="font-medium text-zinc-900 dark:text-white">{{ number_format($totalDebt, 0, ',', '.') }} kr.</span></p>
                    @if(!is_null($ltv))
                        @php
                            $ltvColor = match (true) {
                                $ltv < 60 => 'green',
                                $ltv <= 80 => 'yellow',
                                default => 'red',
                            };
                        @endphp
                        <flux:badge color="{{ $ltvColor }}" size="sm">LTV {{ number_format($ltv, 1, ',', '.') }}%</flux:badge>
                        <span class="text-xs text-zinc-400">{{ __('vs. public valuation') }}</span>
                    @endif
                </div>
            @endif
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200 dark:border-zinc-700">
                            <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('Creditor') }}</th>
                            <th class="text-right py-2 pr-4 font-medium text-zinc-500">{{ __('Principal') }}</th>
                            <th class="text-right py-2 pr-4 font-medium text-zinc-500">{{ __('Rate') }}</th>
                            <th class="text-left py-2 font-medium text-zinc-500">{{ __('Maturity') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($mortgages as $m)
                            <tr class="border-b border-zinc-100 dark:border-zinc-800">
                                <td class="py-2 pr-4">{{ $m['creditor'] ?? '-' }}</td>
                                <td class="py-2 pr-4 text-right">{{ isset($m['principal']) ? number_format($m['principal'], 0, ',', '.') . ' kr.' : '-' }}</td>
                                <td class="py-2 pr-4 text-right">{{ isset($m['interest_rate']) ? number_format($m['interest_rate'], 2, ',', '.') . '%' : '-' }}</td>
                                <td class="py-2">{{ $m['maturity_date'] ?? '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="text-xs text-zinc-400 mt-3">{{ __('Registered principal is not the same as current outstanding debt.') }}</p>
        @elseif(! $erUndersoegt)
            {{-- 🚨 "Ingen pantebreve fundet" ville være en FALSK PÅSTAND OM
                 FRAVÆR her: vi har ikke kigget endnu. Målt 10/8 blev 1.105.049
                 ejendomme crawl-klare på én gang efter adresse-backfillen, og
                 crawlen tager ~60 døgn fordi Tinglysningen rate-limiter til
                 12,3 opslag/min. I hele den periode ville u-crawlede ejendomme
                 se gældfri ud — det værste en kreditvurdering kan bygge på. --}}
            <p class="text-sm text-zinc-500">
                {{ __('Gældsoplysninger er ikke hentet for denne ejendom endnu.') }}
            </p>
            <p class="text-xs text-zinc-400 mt-2">
                {{ __('Det betyder ikke at ejendommen er gældfri — vi har blot ikke undersøgt den.') }}
            </p>
        @else
            <p class="text-sm text-zinc-500">{{ __('No mortgages found.') }}</p>
        @endif
    </flux:card>
</div>
