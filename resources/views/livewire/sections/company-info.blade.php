<div>
    <flux:card>
        @if($company)
            <div class="flex items-start justify-between mb-4">
                <div>
                    <flux:heading size="xl">{{ $company['name'] ?? $query }}</flux:heading>
                    <div class="flex items-center gap-2 mt-1">
                        <span class="text-sm text-zinc-500">CVR {{ $query }}</span>
                        @if($company['company_type'] ?? null)
                            <span class="text-zinc-300">&middot;</span>
                            <span class="text-sm text-zinc-500">{{ $company['long_company_type'] ?? $company['company_type'] }}</span>
                        @endif
                    </div>
                </div>
                @if($company['status'] ?? null)
                    <flux:badge size="sm" :color="($company['status'] ?? '') === 'NORMAL' ? 'green' : 'zinc'">
                        {{ ($company['status'] ?? '') === 'NORMAL' ? __('Active') : $company['status'] }}
                    </flux:badge>
                @endif
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-3 gap-4 text-sm">
                @if($company['address'] ?? null)
                    <div>
                        <div class="text-xs text-zinc-400">{{ __('Address') }}</div>
                        <div>{{ $company['address'] }}, {{ $company['postal_code'] ?? '' }} {{ $company['city'] ?? '' }}</div>
                    </div>
                @endif
                @if($company['industry'] ?? null)
                    <div>
                        <div class="text-xs text-zinc-400">{{ __('Industry') }}</div>
                        <div>{{ $company['industry'] }}</div>
                    </div>
                @endif
                @if($company['founded_date'] ?? null)
                    <div>
                        <div class="text-xs text-zinc-400">{{ __('Founded') }}</div>
                        <div>{{ \Carbon\Carbon::parse($company['founded_date'])->format('d. M Y') }}</div>
                    </div>
                @endif
                @if(data_get($company, 'employees.latest.count'))
                    <div>
                        <div class="text-xs text-zinc-400">{{ __('Employees') }}</div>
                        <div>~{{ number_format(data_get($company, 'employees.latest.count'), 0, ',', '.') }}
                            @if(data_get($company, 'employees.latest.interval'))
                                <span class="text-xs text-zinc-400">({{ str_replace(['ANTAL_', '_'], ['', '–'], data_get($company, 'employees.latest.interval')) }})</span>
                            @endif
                        </div>
                    </div>
                @endif
                @if(data_get($company, 'contact.phone'))
                    <div>
                        <div class="text-xs text-zinc-400">{{ __('Phone') }}</div>
                        <div><a href="tel:{{ data_get($company, 'contact.phone') }}" class="text-sky-600 hover:text-sky-800">{{ data_get($company, 'contact.phone') }}</a></div>
                    </div>
                @endif
                @if(data_get($company, 'contact.email'))
                    <div>
                        <div class="text-xs text-zinc-400">{{ __('Email') }}</div>
                        <div><a href="mailto:{{ data_get($company, 'contact.email') }}" class="text-sky-600 hover:text-sky-800">{{ data_get($company, 'contact.email') }}</a></div>
                    </div>
                @endif
                @if(data_get($company, 'contact.website'))
                    <div>
                        <div class="text-xs text-zinc-400">{{ __('Website') }}</div>
                        <div><a href="{{ str_starts_with(data_get($company, 'contact.website'), 'http') ? data_get($company, 'contact.website') : 'https://' . data_get($company, 'contact.website') }}" target="_blank" class="text-sky-600 hover:text-sky-800">{{ data_get($company, 'contact.website') }} ↗</a></div>
                    </div>
                @endif
            </div>

            {{-- Purpose --}}
            @if($company['purpose'] ?? null)
                <div class="mt-4 pt-4 border-t border-zinc-100 dark:border-zinc-800">
                    <div class="text-xs text-zinc-400 uppercase mb-1">{{ __('Purpose') }}</div>
                    <div class="text-sm text-zinc-600 dark:text-zinc-300">{{ $company['purpose'] }}</div>
                </div>
            @endif

            {{-- Signing Rule --}}
            @if($company['signing_rule'] ?? null)
                <div class="mt-4 pt-4 border-t border-zinc-100 dark:border-zinc-800">
                    <div class="text-xs text-zinc-400 uppercase mb-1">{{ __('Signing Rule') }}</div>
                    <div class="text-sm text-zinc-600 dark:text-zinc-300">{{ $company['signing_rule'] }}</div>
                </div>
            @endif

            {{-- Employee History --}}
            @if(count(data_get($company, 'employees.history', [])) > 1)
                <div class="mt-4 pt-4 border-t border-zinc-100 dark:border-zinc-800">
                    <div class="text-xs text-zinc-400 uppercase mb-2">{{ __('Employee History') }}</div>
                    <div class="flex items-end gap-1 h-20">
                        @php
                            $empHistory = collect(data_get($company, 'employees.history', []))->sortBy('year');
                            $maxCount = $empHistory->max('count') ?: 1;
                        @endphp
                        @foreach($empHistory as $emp)
                            <div class="flex flex-col items-center flex-1">
                                <div class="w-full bg-sky-200 rounded-t" style="height: {{ max(4, (data_get($emp, 'count', 0) / $maxCount) * 60) }}px"></div>
                                <div class="text-xs text-zinc-400 mt-1">{{ data_get($emp, 'year') }}</div>
                                <div class="text-xs font-medium">~{{ data_get($emp, 'count', '?') }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Financial Statements (multi-year) --}}
            @php
                $financials = $company['financials'] ?? [];
                if (is_array($financials) && ! array_is_list($financials) && ! empty($financials)) {
                    $financials = [$financials]; // Wrap single financial as array
                }
                // PDF-sourced entries are already in t.DKK, API entries are in DKK
                $toTdkk = function($value, $fin) {
                    if ($value === null) return null;
                    // PDF source values are already small (t.DKK), API values are large (DKK)
                    return ($fin['source'] ?? '') === 'pdf' ? (int) $value : (int) ($value / 1000);
                };
            @endphp
            @if(count($financials) > 0)
                <div class="mt-4 pt-4 border-t border-zinc-100 dark:border-zinc-800">
                    <div class="flex items-center justify-between mb-2">
                        <div class="text-xs text-zinc-400 uppercase">{{ __('Financial Statements') }}</div>
                        <span class="text-xs text-zinc-400">{{ __('Amounts in t.DKK') }}</span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-zinc-200 dark:border-zinc-700">
                                    <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('Year') }}</th>
                                    <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('Net Equity') }}</th>
                                    <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('Total Assets') }}</th>
                                    <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('Liabilities') }}</th>
                                    <th class="text-left py-2 font-medium text-zinc-500">{{ __('Result') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach(array_slice($financials, 0, 5) as $fin)
                                    <tr class="border-b border-zinc-100 dark:border-zinc-800">
                                        <td class="py-2 pr-4">{{ $fin['year'] ?? ($fin['period_end'] ? \Carbon\Carbon::parse($fin['period_end'])->format('Y') : '—') }}</td>
                                        @php
                                            $eq = $toTdkk($fin['equity'] ?? null, $fin);
                                            $as = $toTdkk($fin['assets'] ?? null, $fin);
                                            $li = ($as !== null && $eq !== null) ? $as - $eq : null;
                                            $pl = $toTdkk($fin['profit_loss'] ?? null, $fin);
                                        @endphp
                                        <td class="py-2 pr-4 {{ ($eq ?? 0) < 0 ? 'text-red-600' : '' }}">
                                            {{ $eq !== null ? number_format($eq, 0, ',', '.') : '—' }}
                                        </td>
                                        <td class="py-2 pr-4">
                                            {{ $as !== null ? number_format($as, 0, ',', '.') : '—' }}
                                        </td>
                                        <td class="py-2 pr-4">
                                            {{ $li !== null ? number_format($li, 0, ',', '.') : '—' }}
                                        </td>
                                        <td class="py-2 {{ ($pl ?? 0) < 0 ? 'text-red-600' : ($pl !== null ? 'text-green-600' : '') }}">
                                            {{ $pl !== null ? number_format($pl, 0, ',', '.') : '—' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            {{-- Financial Ratios --}}
            @if($ratios = $company['financial_ratios'] ?? null)
                <div class="mt-4 pt-4 border-t border-zinc-100 dark:border-zinc-800">
                    <div class="text-xs text-zinc-400 uppercase mb-2">
                        {{ __('Key Ratios') }}
                        @if($ratios['latest_year'] ?? null) ({{ $ratios['latest_year'] }}) @endif
                    </div>
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-4 text-sm">
                        @if(($ratios['solvency_ratio'] ?? null) !== null)
                            <div>
                                <div class="text-xs text-zinc-400">{{ __('Solvency Ratio') }}</div>
                                <div class="font-medium">{{ number_format($ratios['solvency_ratio'], 1, ',', '.') }}%</div>
                            </div>
                        @endif
                        @if(($ratios['debt_ratio'] ?? null) !== null)
                            <div>
                                <div class="text-xs text-zinc-400">{{ __('Debt Ratio') }}</div>
                                <div class="font-medium">{{ number_format($ratios['debt_ratio'], 1, ',', '.') }}%</div>
                            </div>
                        @endif
                        @if(($ratios['return_on_equity'] ?? null) !== null)
                            <div>
                                <div class="text-xs text-zinc-400">{{ __('Return on Equity') }}</div>
                                <div class="font-medium {{ $ratios['return_on_equity'] >= 0 ? 'text-green-600' : 'text-red-600' }}">{{ number_format($ratios['return_on_equity'], 1, ',', '.') }}%</div>
                            </div>
                        @endif
                        @if($ratios['equity_trend'] ?? null)
                            <div>
                                <div class="text-xs text-zinc-400">{{ __('Equity Trend') }}</div>
                                <div class="mt-0.5">
                                    <flux:badge size="sm" :color="match($ratios['equity_trend']) { 'growing' => 'green', 'stable' => 'amber', 'declining' => 'red', default => 'zinc' }">
                                        {{ match($ratios['equity_trend']) { 'growing' => __('Growing'), 'stable' => __('Stable'), 'declining' => __('Declining'), default => $ratios['equity_trend'] } }}
                                    </flux:badge>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            @endif
        @else
            <flux:heading size="lg" class="mb-4">{{ __('Company Information') }}</flux:heading>
            <p class="text-sm text-zinc-500">{{ __('No data found for this CVR number.') }}</p>
        @endif
    </flux:card>
</div>
