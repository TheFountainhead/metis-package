<div>
    @if(count($rounds) > 1)
        <flux:card>
            <div class="flex flex-wrap items-center gap-2 mb-4">
                <flux:heading size="lg">{{ __('Kapitalhistorik') }}</flux:heading>
                @if(($summary['round_count'] ?? 0) > 0)
                    <flux:badge color="sky" size="sm">{{ trans_choice('{1} :count kapitaludvidelse|[2,*] :count kapitaludvidelser', $summary['round_count'], ['count' => $summary['round_count']]) }}</flux:badge>
                @endif
                @if($summary['total_funding'] ?? null)
                    <flux:badge color="green" size="sm">{{ __('Rejst i alt') }}: {{ number_format($summary['total_funding'], 0, ',', '.') }} {{ $currency }}</flux:badge>
                @endif
            </div>

            <div class="flex flex-wrap gap-x-6 gap-y-1 text-sm text-zinc-500 mb-3">
                @if(($summary['founding_capital'] ?? null) && ($summary['current_capital'] ?? null))
                    <p>
                        {{ __('Stiftet med') }} <span class="font-medium text-zinc-900 dark:text-white">{{ number_format($summary['founding_capital'], 0, ',', '.') }} {{ $currency }}</span>
                        → {{ __('nuværende kapital') }} <span class="font-medium text-zinc-900 dark:text-white">{{ number_format($summary['current_capital'], 0, ',', '.') }} {{ $currency }}</span>
                    </p>
                @endif
                @if($summary['latest_valuation'] ?? null)
                    <p>
                        {{ __('Seneste implied valuation') }}: <span class="font-medium text-zinc-900 dark:text-white">{{ number_format($summary['latest_valuation'], 0, ',', '.') }} {{ $currency }}</span>
                        <span class="text-zinc-400">({{ $summary['latest_valuation_date'] ?? '' }})</span>
                    </p>
                @endif
            </div>

            @if(count($valuationSeries) >= 2)
                <div class="mb-4 rounded-lg border border-zinc-200 dark:border-zinc-700 p-3 h-56" wire:ignore>
                    <canvas data-metis-funding-chart></canvas>
                </div>
            @endif

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200 dark:border-zinc-700">
                            <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('Dato') }}</th>
                            <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('Hændelse') }}</th>
                            <th class="text-right py-2 pr-4 font-medium text-zinc-500">{{ __('Beløb') }}</th>
                            <th class="text-right py-2 pr-4 font-medium text-zinc-500" title="{{ __('Hele kapitalen prissat til rundens kurs') }}">{{ __('Implied valuation') }}</th>
                            <th class="text-right py-2 pr-4 font-medium text-zinc-500">{{ __('Kapital') }}</th>
                            <th class="text-left py-2 font-medium text-zinc-500">{{ __('Ejer-ændringer samme dato') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rounds as $round)
                            <tr class="border-b border-zinc-100 dark:border-zinc-800">
                                <td class="py-2 pr-4 whitespace-nowrap align-top">{{ $round['date'] }}</td>
                                <td class="py-2 pr-4 align-top">
                                    @switch($round['type'])
                                        @case('founding')<flux:badge color="zinc" size="sm">{{ __('Stiftelse') }}</flux:badge>@break
                                        @case('increase')<flux:badge color="green" size="sm">{{ __('Forhøjelse') }}</flux:badge>@break
                                        @case('decrease')<flux:badge color="amber" size="sm">{{ __('Nedsættelse') }}</flux:badge>@break
                                        @default<flux:badge color="zinc" size="sm">{{ __('Uændret') }}</flux:badge>
                                    @endswitch
                                    @if($round['payment_type'] ?? null)
                                        <div class="text-[11px] text-zinc-400 mt-1">
                                            {{ match ($round['payment_type']) {
                                                'cash' => __('Kontant'),
                                                'debt_conversion' => __('Konvertering af gæld'),
                                                'in_kind' => __('Apportindskud'),
                                                'bonus_issue' => __('Fondsforhøjelse'),
                                                'payout' => __('Udbetaling til ejere'),
                                                default => '',
                                            } }}
                                        </div>
                                    @endif
                                </td>
                                <td class="py-2 pr-4 text-right whitespace-nowrap align-top">
                                    @if(($round['amount'] ?? null) !== null)
                                        <span class="{{ $round['amount'] >= 0 ? '' : 'text-amber-700 dark:text-amber-400' }}">{{ number_format($round['amount'], 0, ',', '.') }} {{ $currency }}</span>
                                    @else
                                        -
                                    @endif
                                </td>
                                <td class="py-2 pr-4 text-right whitespace-nowrap align-top">
                                    {{ ($round['implied_valuation'] ?? null) !== null ? number_format($round['implied_valuation'], 0, ',', '.') . ' ' . $currency : '-' }}
                                </td>
                                <td class="py-2 pr-4 text-right whitespace-nowrap align-top">
                                    {{ number_format($round['capital'], 0, ',', '.') }} {{ $currency }}
                                    @if($round['change'] !== null)
                                        <div class="text-[11px] {{ $round['change'] >= 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-amber-700 dark:text-amber-400' }}">
                                            {{ $round['change'] >= 0 ? '+' : '' }}{{ number_format($round['change'], 0, ',', '.') }}
                                            @if($round['change_pct'] !== null)
                                                ({{ $round['change_pct'] >= 0 ? '+' : '' }}{{ number_format($round['change_pct'], 1, ',', '.') }}%)
                                            @endif
                                        </div>
                                    @endif
                                </td>
                                <td class="py-2 text-xs text-zinc-500 align-top">
                                    @forelse($round['owner_changes'] ?? [] as $oc)
                                        <div>
                                            @if($oc['cvr'] ?? null)
                                                <x-metis-link type="cvr" :query="$oc['cvr']" :label="$oc['owner']" />
                                            @else
                                                <x-metis-link type="person" :query="$oc['owner']" />
                                            @endif
                                            → {{ number_format($oc['share_pct'], $oc['share_pct'] == (int) $oc['share_pct'] ? 0 : 2, ',', '.') }}%
                                        </div>
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
                {{ __('Kilde:') }} {{ __('CVR-registrets kapitalhistorik og registreringstekster (kurs ved kapitalændringer). Implied valuation antager hele kapitalen prissat til rundens kurs; reelle vilkår som præferencer og warrants kan afvige. Beløb er indbetalt kapital, ikke omsætning.') }}
            </p>
        </flux:card>

        @if(count($valuationSeries) >= 2)
            {{-- @push('scripts') virker IKKE fra lazy sections (XHR-render efter
                 layoutet er sendt) — @script kører ved Livewire component-init. --}}
            @script
            <script>
                (async () => {
                    window.metisLoadChartjs = window.metisLoadChartjs || (async () => {
                        if (window.Chart) return window.Chart;
                        await new Promise((resolve, reject) => {
                            const s = document.createElement('script');
                            s.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js';
                            s.onload = resolve;
                            s.onerror = reject;
                            document.head.appendChild(s);
                        });
                        return window.Chart;
                    });

                    const Chart = await window.metisLoadChartjs();
                    const canvas = $wire.$el.querySelector('[data-metis-funding-chart]');
                    const series = $wire.valuationSeries;
                    if (!canvas || !series?.length) return;
                    Chart.getChart(canvas)?.destroy();
                    const fmt = (v) => new Intl.NumberFormat('da-DK', { maximumFractionDigits: 1 }).format(v / 1_000_000) + ' mio.';
                    new Chart(canvas, {
                        type: 'line',
                        data: {
                            labels: series.map(p => p.date),
                            datasets: [{
                                label: 'Implied valuation',
                                data: series.map(p => p.valuation),
                                borderColor: '#7a1f1f',
                                backgroundColor: 'rgba(122, 31, 31, 0.08)',
                                fill: true,
                                tension: 0.15,
                                pointRadius: 4,
                                pointBackgroundColor: '#7a1f1f',
                            }],
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: { display: false },
                                tooltip: { callbacks: { label: (ctx) => fmt(ctx.parsed.y) + ' DKK' } },
                            },
                            scales: {
                                y: { beginAtZero: true, ticks: { callback: (v) => fmt(v) } },
                            },
                        },
                    });
                })();
            </script>
            @endscript
        @endif
    @endif
</div>
