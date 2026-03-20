<div>
    <flux:card>
        <flux:heading size="lg" class="mb-4">Overblik</flux:heading>

        {{-- Key metrics grid --}}
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4 mb-6">
            <div class="rounded-lg bg-zinc-50 dark:bg-zinc-800 p-3 text-center">
                <div class="text-2xl font-bold">{{ $activeCompanyCount }}</div>
                <div class="text-xs text-zinc-500 mt-1">Aktive selskaber</div>
            </div>
            @if($historicalCompanyCount > 0)
                <div class="rounded-lg bg-zinc-50 dark:bg-zinc-800 p-3 text-center">
                    <div class="text-2xl font-bold text-zinc-400">{{ $historicalCompanyCount }}</div>
                    <div class="text-xs text-zinc-500 mt-1">Tidligere</div>
                </div>
            @endif
            <div class="rounded-lg bg-zinc-50 dark:bg-zinc-800 p-3 text-center">
                <div class="text-2xl font-bold">{{ $propertyCount }}</div>
                <div class="text-xs text-zinc-500 mt-1">Ejendomme</div>
            </div>
            @if($totalPropertyValuation > 0)
                <div class="rounded-lg bg-zinc-50 dark:bg-zinc-800 p-3 text-center">
                    <div class="text-lg font-bold">{{ number_format($totalPropertyValuation / 1000000, 1, ',', '.') }}M</div>
                    <div class="text-xs text-zinc-500 mt-1">Ejendomsværdi</div>
                </div>
            @endif
            @if($totalPropertyDebt > 0)
                <div class="rounded-lg bg-zinc-50 dark:bg-zinc-800 p-3 text-center">
                    <div class="text-lg font-bold text-red-600">{{ number_format($totalPropertyDebt / 1000000, 1, ',', '.') }}M</div>
                    <div class="text-xs text-zinc-500 mt-1">Gæld</div>
                </div>
            @endif
            @if($totalEquityShare != 0)
                <div class="rounded-lg bg-zinc-50 dark:bg-zinc-800 p-3 text-center">
                    <div class="text-lg font-bold {{ $totalEquityShare < 0 ? 'text-red-600' : '' }}">{{ number_format($totalEquityShare / 1000000, 1, ',', '.') }}M</div>
                    <div class="text-xs text-zinc-500 mt-1">Egenkapitalandel</div>
                </div>
            @endif
        </div>

        {{-- Estimated net worth --}}
        @if($totalPropertyValuation > 0 || $totalEquityShare != 0)
            <div class="rounded-lg border-2 border-sky-200 dark:border-sky-800 bg-sky-50 dark:bg-sky-900/20 p-4 mb-6">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="text-sm font-medium text-sky-700 dark:text-sky-300">Estimeret nettoformue</div>
                        <div class="text-xs text-sky-600/70 dark:text-sky-400/70 mt-0.5">Ejendomme + egenkapitalandel - gæld</div>
                    </div>
                    <div class="text-2xl font-bold {{ $estimatedNetWorth < 0 ? 'text-red-600' : 'text-sky-700 dark:text-sky-300' }}">
                        {{ number_format($estimatedNetWorth / 1000000, 1, ',', '.') }}M kr.
                    </div>
                </div>
                @if($totalPropertyValuation > 0 && $totalEquityShare != 0)
                    <div class="flex gap-4 mt-3 pt-3 border-t border-sky-200/50 dark:border-sky-700/50 text-xs text-sky-600/70 dark:text-sky-400/70">
                        <span>Ejendomme: {{ number_format(($totalPropertyValuation - $totalPropertyDebt) / 1000000, 1, ',', '.') }}M</span>
                        <span>Selskaber: {{ number_format($totalEquityShare / 1000000, 1, ',', '.') }}M</span>
                    </div>
                @endif
            </div>
        @endif

    </flux:card>

    {{-- Detected capital increases (kapitalforhøjelser) — separate card at bottom --}}
    @if(count($valuations) > 0)
        <flux:card>
            <div class="flex items-center gap-2 mb-3">
                <flux:heading size="lg">Kapitalforhøjelser</flux:heading>
                <flux:badge size="sm" color="amber">{{ count($valuations) }}</flux:badge>
            </div>
            <p class="text-xs text-zinc-500 mb-3">Detekteret fra ændringer i indskudskapital på tværs af årsregnskaber</p>
            <div class="space-y-3">
                @foreach($valuations as $val)
                    <div class="rounded-lg border border-amber-200 dark:border-amber-800 bg-amber-50/50 dark:bg-amber-900/10 p-4">
                        <div class="flex items-center justify-between mb-2">
                            <div class="flex items-center gap-2">
                                <x-metis-link type="cvr" :query="$val['cvr']" :label="$val['company_name']" class="font-medium" />
                                <flux:badge size="sm" color="amber">{{ $val['year'] }}</flux:badge>
                            </div>
                            @if($val['implied_valuation'])
                                <div class="text-right">
                                    <div class="text-lg font-bold text-amber-700 dark:text-amber-400">
                                        {{ number_format($val['implied_valuation'] / 100 / 1000000, 1, ',', '.') }}M kr.
                                    </div>
                                    <div class="text-xs text-amber-600/70 dark:text-amber-400/70">Implied valuation</div>
                                </div>
                            @endif
                        </div>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
                            <div>
                                <div class="text-xs text-zinc-400">Kapital før</div>
                                <div>{{ number_format($val['capital_before'] / 100, 0, ',', '.') }} kr.</div>
                            </div>
                            <div>
                                <div class="text-xs text-zinc-400">Kapital efter</div>
                                <div>{{ number_format($val['capital_after'] / 100, 0, ',', '.') }} kr.</div>
                            </div>
                            <div>
                                <div class="text-xs text-zinc-400">Forhøjelse</div>
                                <div class="text-amber-700 dark:text-amber-400">+{{ number_format($val['capital_increase'] / 100, 0, ',', '.') }} kr.</div>
                            </div>
                            @if($val['capital_injection'])
                                <div>
                                    <div class="text-xs text-zinc-400">Samlet indskud</div>
                                    <div>{{ number_format($val['capital_injection'] / 100, 0, ',', '.') }} kr.</div>
                                </div>
                            @endif
                        </div>
                        @if($val['share_premium'])
                            <div class="mt-2 text-xs text-zinc-500">
                                Overkurs: {{ number_format($val['share_premium'] / 100, 0, ',', '.') }} kr.
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
            <p class="text-xs text-zinc-400 mt-3 italic">
                Implied valuation er estimeret fra kapitalindskud ÷ ny aktieandel. Baseret på offentlige regnskaber — faktiske vilkår kan afvige.
            </p>
        </flux:card>
    @endif
</div>
