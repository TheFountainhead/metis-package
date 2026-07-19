<div class="space-y-4">
    @php
        // Store beløb (kr) som mia når over 1.000 mio, ellers mio, så tallet kan
        // læses i stedet for at tælles. Returnerer [værdi, enhed] til visning.
        $kpiSum = function (int $kr): array {
            return $kr >= 1_000_000_000
                ? [number_format($kr / 1_000_000_000, 1, ',', '.'), __('mia. kr')]
                : [number_format($kr / 1_000_000, 1, ',', '.'), __('mio. kr')];
        };
        [$valVal, $valUnit] = $kpiSum((int) $totalValuation);
        [$debtVal, $debtUnit] = $kpiSum((int) $totalDebt);
    @endphp
    {{-- KPI tiles --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <div class="bg-white rounded-xl border border-zinc-200 p-4">
            <div class="text-xs text-zinc-500 uppercase tracking-wide mb-1">{{ __('Properties') }}</div>
            <div class="text-xl font-semibold text-zinc-900 tabular-nums">{{ number_format($propertyCount, 0, ',', '.') }}</div>
        </div>
        <div class="bg-white rounded-xl border border-zinc-200 p-4">
            <div class="text-xs text-zinc-500 uppercase tracking-wide mb-1">{{ __('Total valuation') }}</div>
            <div class="text-xl font-semibold text-zinc-900 tabular-nums whitespace-nowrap">
                @if($totalValuation > 0)
                    {{ $valVal }} <span class="text-sm font-normal text-zinc-500">{{ $valUnit }}</span>
                @else
                    <span class="text-zinc-400">—</span>
                @endif
            </div>
        </div>
        <div class="bg-white rounded-xl border border-zinc-200 p-4">
            <div class="text-xs text-zinc-500 uppercase tracking-wide mb-1">{{ __('Total mortgage debt') }}</div>
            <div class="text-xl font-semibold text-zinc-900 tabular-nums whitespace-nowrap">
                @if($totalDebt > 0)
                    {{ $debtVal }} <span class="text-sm font-normal text-zinc-500">{{ $debtUnit }}</span>
                @else
                    <span class="text-zinc-400">—</span>
                @endif
            </div>
            @if($totalValuation > 0 && $totalDebt > 0)
                <div class="text-xs text-zinc-500 mt-1">{{ __('LTV') }}: {{ number_format(($totalDebt / $totalValuation) * 100, 0) }}%</div>
            @endif
        </div>
        <div class="bg-white rounded-xl border border-zinc-200 p-4">
            <div class="text-xs text-zinc-500 uppercase tracking-wide mb-1">{{ __('Employees') }}</div>
            <div class="text-xl font-semibold text-zinc-900 tabular-nums">
                {{ $employees !== null ? number_format($employees, 0, ',', '.') : '—' }}
            </div>
            @if($companyName)
                <div class="text-xs text-zinc-500 mt-1 truncate" title="{{ $companyName }}">{{ $companyName }}</div>
            @endif
        </div>
    </div>

    {{-- Map + charts row --}}
    @if(count($mapPins) > 0 || count($usageDistribution) > 0 || count($financialHistory) > 0)
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-3">
            {{-- Mini-map (2/3 width) --}}
            @if(count($mapPins) > 0)
                <div class="lg:col-span-2 bg-white rounded-xl border border-zinc-200 p-4">
                    <div class="text-xs text-zinc-500 uppercase tracking-wide mb-3">{{ __('Property map') }}</div>
                    <div
                        x-data='companyOverviewMap(@json($mapPins))'
                        x-init="init()"
                        wire:ignore
                        class="w-full rounded-lg overflow-hidden"
                        style="height: 300px;"
                    >
                        <div x-ref="map" class="w-full h-full"></div>
                    </div>
                </div>
            @endif

            {{-- Pie chart (1/3 width) --}}
            @if(count($usageDistribution) > 0)
                <div class="bg-white rounded-xl border border-zinc-200 p-4">
                    <div class="text-xs text-zinc-500 uppercase tracking-wide mb-3">{{ __('Property type') }}</div>
                    <div
                        x-data='companyOverviewPie(@json($usageDistribution))'
                        x-init="init()"
                        wire:ignore
                        style="height: 280px;"
                    >
                        <canvas x-ref="canvas"></canvas>
                    </div>
                </div>
            @endif
        </div>

        {{-- Financial history bars (full width below) --}}
        @if(count($financialHistory) > 0)
            <div class="bg-white rounded-xl border border-zinc-200 p-4">
                <div class="text-xs text-zinc-500 uppercase tracking-wide mb-3">{{ __('Financial history (3 years)') }}</div>
                <div
                    x-data='companyOverviewFinancials(@json($financialHistory))'
                    x-init="init()"
                    wire:ignore
                    style="height: 260px;"
                >
                    <canvas x-ref="canvas"></canvas>
                </div>
            </div>
        @endif
    @endif

    {{-- Listed addresses (sub-list of pins) --}}
    @if(count($mapPins) > 0)
        <div class="bg-white rounded-xl border border-zinc-200 p-4">
            <div class="text-xs text-zinc-500 uppercase tracking-wide mb-2">{{ __('Addresses on map') }}</div>
            <div class="text-sm text-zinc-700 space-y-1">
                @foreach(array_slice($mapPins, 0, 6) as $pin)
                    <div class="truncate">• {{ $pin['address'] }}</div>
                @endforeach
                @if(count($mapPins) > 6)
                    <div class="text-zinc-500 text-xs">+ {{ count($mapPins) - 6 }} {{ __('more') }}</div>
                @endif
            </div>
        </div>
    @endif

    {{-- Alpine-komponenterne (companyOverviewMap/Pie/Financials) registreres i
         konsument-appens app.js: @push når aldrig layoutet fra lazy sections,
         og @script-blokke eksekveres upålideligt for denne komponent. --}}
</div>
