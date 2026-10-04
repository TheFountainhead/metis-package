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

    {{-- Dækningsforbehold under KPI-rækken.

         "Tinglyst gæld —" ser ud som "ingen gæld". For AKACIETORVET var
         sandheden at 3 af 4 ejendomme aldrig var undersøgt og bar 79,9 mio.
         Samme ordlyd som tinglysning-sektionen, så brugeren møder ét sprog. --}}
    @if($coverage && ! ($coverage['all_properties_answered'] ?? true) && (($coverage['properties_total'] ?? 0) > 0 || ($coverage['ejf_known_total'] ?? 0) > 0))
        <div class="mt-3 rounded-lg border border-zinc-200 bg-white px-4 py-3 text-sm">
            <p class="text-zinc-900">
                @php($known = max($coverage['properties_total'] ?? 0, $coverage['ejf_known_total'] ?? 0))
                {{ __(':answered af :total ejendomme undersøgt.', [
                    'answered' => $coverage['properties_answered'] ?? 0,
                    'total' => $known,
                ]) }}
                {{-- EJF kender ejendomme vi aldrig har haft. Uden denne linje
                     ville teksten sige "2 af 2 undersøgt" om et grundlag hvor
                     en fjerdedel manglede — en beroligelse, ikke et forbehold. --}}
                @if(($coverage['properties_missing_vs_ejf'] ?? 0) > 0)
                    {{ __(':n ejendomme kender vi slet ikke — de står i EJF, men mangler i vores data.', ['n' => $coverage['properties_missing_vs_ejf']]) }}
                @endif
                @if(($coverage['properties_blocked'] ?? 0) > 0)
                    {{ __(':n mangler adressedata og kan ikke slås op.', ['n' => $coverage['properties_blocked']]) }}
                @endif
                @if(($coverage['properties_pending'] ?? 0) > 0)
                    {{ __(':n er endnu ikke hentet.', ['n' => $coverage['properties_pending']]) }}
                @endif
            </p>
            <p class="mt-1 text-zinc-500">
                {{ __('Tallene ovenfor dækker kun det undersøgte. Fravær af gæld er ikke et udsagn om at der ingen er.') }}
            </p>
        </div>
    @endif

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

        {{-- Udvikling i nøgletal: graf eller tabel over op til seks år --}}
        @if(count($financialHistory) > 0)
            <div class="bg-white rounded-xl border border-zinc-200 p-4" x-data="{ view: 'chart' }">
                <div class="flex items-center justify-between mb-3">
                    <div class="text-xs text-zinc-500 uppercase tracking-wide">{{ __('Key figures over time') }}</div>
                    <div class="inline-flex rounded-md border border-zinc-200 text-xs overflow-hidden" role="group">
                        <button type="button" class="px-2 py-1" :class="view === 'chart' ? 'bg-zinc-100 font-medium' : 'text-zinc-500'" @click="view = 'chart'">{{ __('Chart') }}</button>
                        <button type="button" class="px-2 py-1 border-l border-zinc-200" :class="view === 'table' ? 'bg-zinc-100 font-medium' : 'text-zinc-500'" @click="view = 'table'">{{ __('Table') }}</button>
                    </div>
                </div>

                <div x-show="view === 'chart'">
                    <div
                        x-data='companyOverviewFinancials(@json($financialHistory), @json($this->keyFigureLabels()))'
                        x-init="init()"
                        wire:ignore
                        wire:key="financial-history-{{ substr(md5(json_encode($financialHistory)), 0, 12) }}"
                        style="height: 280px;"
                    >
                        <canvas x-ref="canvas"></canvas>
                    </div>
                </div>

                <div x-show="view === 'table'" x-cloak class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-zinc-200">
                                <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('Amounts in t.DKK') }}</th>
                                @foreach($financialHistory as $year)
                                    <th class="text-right py-2 pl-4 font-medium text-zinc-500 whitespace-nowrap">
                                        {{ $year['year'] }}@if($year['comparative'])<sup>*</sup>@endif
                                        @if($year['short_period'])
                                            <div class="text-[10px] font-normal text-zinc-400">{{ number_format($year['months'], 1, ',', '.') }} {{ __('mo.') }}</div>
                                        @endif
                                        @if($year['consolidated'])
                                            <div class="text-[10px] font-normal text-zinc-400">{{ __('group') }}</div>
                                        @endif
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($this->keyFigureLabels() as $key => $label)
                                <tr class="border-b border-zinc-100">
                                    <td class="py-2 pr-4 text-zinc-600 whitespace-nowrap">{{ $label }}</td>
                                    @foreach($financialHistory as $year)
                                        <td class="py-2 pl-4 text-right tabular-nums {{ ($year[$key] ?? 0) < 0 ? 'text-red-600' : '' }}">
                                            {{ $year[$key] === null ? '–' : number_format(round($year[$key] / 1000), 0, ',', '.') }}
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                    <div class="mt-3 text-[11px] text-zinc-400 space-y-0.5">
                        @if(collect($financialHistory)->contains('comparative', true))<div>* {{ __('Comparative figures from the following year\'s annual report.') }}</div>@endif
                        @if(collect($financialHistory)->contains('short_period', true))<div>{{ __('Short fiscal years are shown with their length in months.') }}</div>@endif
                        @if(collect($financialHistory)->contains('consolidated', true))<div>{{ __('Group figures where the company prepares consolidated accounts.') }}</div>@endif
                        <div>{{ __('EBITDA is shown only where the annual report states depreciation and amortisation.') }}</div>
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
