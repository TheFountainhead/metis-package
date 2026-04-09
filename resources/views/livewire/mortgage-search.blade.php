<div>
    {{-- Stats --}}
    @if($stats)
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
            <div class="bg-white dark:bg-zinc-800 rounded-lg p-4 shadow-sm">
                <div class="text-2xl font-bold">{{ number_format($stats['total_mortgages'] ?? 0, 0, ',', '.') }}</div>
                <div class="text-sm text-zinc-500">{{ __('Mortgages') }}</div>
            </div>
            <div class="bg-white dark:bg-zinc-800 rounded-lg p-4 shadow-sm">
                <div class="text-2xl font-bold">{{ number_format($stats['properties_with_mortgage'] ?? 0, 0, ',', '.') }}</div>
                <div class="text-sm text-zinc-500">{{ __('Properties') }}</div>
            </div>
            <div class="bg-white dark:bg-zinc-800 rounded-lg p-4 shadow-sm">
                <div class="text-2xl font-bold">{{ number_format(($stats['total_amount'] ?? 0) / 1000000000, 1, ',', '.') }} mia.</div>
                <div class="text-sm text-zinc-500">{{ __('Total amount') }}</div>
            </div>
            <div class="bg-white dark:bg-zinc-800 rounded-lg p-4 shadow-sm">
                <div class="text-2xl font-bold">{{ number_format($stats['average_interest_rate'] ?? 0, 2, ',', '.') }}%</div>
                <div class="text-sm text-zinc-500">{{ __('Avg. interest rate') }}</div>
            </div>
        </div>
    @endif

    {{-- Search Form --}}
    <div class="bg-white dark:bg-zinc-800 rounded-lg p-6 shadow-sm mb-6">
        <h3 class="text-lg font-semibold mb-4">{{ __('Search mortgages') }}</h3>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label class="block text-sm text-zinc-500 mb-1">{{ __('Creditor') }}</label>
                <input type="text" wire:model="creditor" placeholder="{{ __('e.g. Nykredit, Totalkredit...') }}"
                       class="w-full rounded-lg border border-zinc-300 dark:border-zinc-600 px-3 py-2 text-sm dark:bg-zinc-700">
            </div>
            <div>
                <label class="block text-sm text-zinc-500 mb-1">{{ __('Amount (DKK)') }}</label>
                <div class="flex gap-2">
                    <input type="number" wire:model="minAmount" placeholder="{{ __('Min') }}"
                           class="w-full rounded-lg border border-zinc-300 dark:border-zinc-600 px-3 py-2 text-sm dark:bg-zinc-700">
                    <input type="number" wire:model="maxAmount" placeholder="{{ __('Max') }}"
                           class="w-full rounded-lg border border-zinc-300 dark:border-zinc-600 px-3 py-2 text-sm dark:bg-zinc-700">
                </div>
            </div>
            <div>
                <label class="block text-sm text-zinc-500 mb-1">{{ __('Interest rate') }}</label>
                <div class="flex gap-2">
                    <input type="number" step="0.01" wire:model="minRate" placeholder="{{ __('Min %') }}"
                           class="w-full rounded-lg border border-zinc-300 dark:border-zinc-600 px-3 py-2 text-sm dark:bg-zinc-700">
                    <input type="number" step="0.01" wire:model="maxRate" placeholder="{{ __('Max %') }}"
                           class="w-full rounded-lg border border-zinc-300 dark:border-zinc-600 px-3 py-2 text-sm dark:bg-zinc-700">
                </div>
            </div>
            <div>
                <label class="block text-sm text-zinc-500 mb-1">{{ __('Postal code range') }}</label>
                <div class="flex gap-2">
                    <input type="text" maxlength="4" wire:model="postalCodeFrom" placeholder="{{ __('From') }}"
                           class="w-full rounded-lg border border-zinc-300 dark:border-zinc-600 px-3 py-2 text-sm dark:bg-zinc-700">
                    <input type="text" maxlength="4" wire:model="postalCodeTo" placeholder="{{ __('To') }}"
                           class="w-full rounded-lg border border-zinc-300 dark:border-zinc-600 px-3 py-2 text-sm dark:bg-zinc-700">
                </div>
            </div>
        </div>
        <div class="mt-4">
            <button wire:click="search" wire:loading.attr="disabled"
                    class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700 disabled:opacity-50">
                <span wire:loading.remove wire:target="search">{{ __('Search') }}</span>
                <span wire:loading wire:target="search">{{ __('Searching...') }}</span>
            </button>
        </div>
    </div>

    {{-- Top Creditors --}}
    @if($creditors && !$results)
        <div class="bg-white dark:bg-zinc-800 rounded-lg p-6 shadow-sm mb-6">
            <h3 class="text-lg font-semibold mb-4">{{ __('Top creditors') }}</h3>
            <div class="space-y-2">
                @foreach(($creditors['creditors'] ?? []) as $c)
                    @if($c['creditor'])
                        <div wire:click="selectCreditor('{{ addslashes($c['creditor']) }}')"
                             class="flex items-center justify-between py-2 px-3 rounded-lg hover:bg-zinc-50 dark:hover:bg-zinc-700 cursor-pointer">
                            <span class="text-sm font-medium">{{ $c['creditor'] }}</span>
                            <div class="text-right">
                                <span class="text-sm text-zinc-500">{{ number_format($c['count'], 0, ',', '.') }} stk</span>
                                <span class="text-sm text-zinc-400 ml-3">{{ number_format($c['total_amount'] / 1000000, 0, ',', '.') }}M DKK</span>
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>
        </div>
    @endif

    {{-- Results --}}
    @if($results)
        <div class="bg-white dark:bg-zinc-800 rounded-lg p-6 shadow-sm">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold">
                    {{ __('Results') }}: {{ number_format($results['total'] ?? 0, 0, ',', '.') }} {{ __('mortgages') }}
                </h3>
                <span class="text-sm text-zinc-500">
                    {{ __('Total') }}: {{ number_format(($results['total_amount'] ?? 0), 0, ',', '.') }} DKK
                </span>
            </div>

            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-zinc-500 border-b border-zinc-200 dark:border-zinc-700">
                        <th class="py-2 pr-4">{{ __('Address') }}</th>
                        <th class="py-2 pr-4">{{ __('Creditor') }}</th>
                        <th class="py-2 pr-4 text-right">{{ __('Amount') }}</th>
                        <th class="py-2 text-right">{{ __('Rate') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach(($results['mortgages'] ?? []) as $m)
                        <tr class="border-b border-zinc-100 dark:border-zinc-800 hover:bg-zinc-50 dark:hover:bg-zinc-800/30">
                            <td class="py-2 pr-4">
                                @if($m['property'])
                                    <span class="text-blue-600">{{ $m['property']['address'] }}, {{ $m['property']['postal_code'] }} {{ $m['property']['city'] }}</span>
                                @else
                                    <span class="text-zinc-400">—</span>
                                @endif
                            </td>
                            <td class="py-2 pr-4 text-zinc-600">{{ $m['creditor'] ?? '—' }}</td>
                            <td class="py-2 pr-4 text-right font-medium">{{ number_format($m['principal_amount'] ?? 0, 0, ',', '.') }} DKK</td>
                            <td class="py-2 text-right">{{ number_format($m['interest_rate'] ?? 0, 2, ',', '.') }}%</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            @if(($results['total'] ?? 0) > count($results['mortgages'] ?? []))
                <p class="text-sm text-zinc-400 mt-4 text-center">
                    {{ __('Showing') }} {{ count($results['mortgages'] ?? []) }} {{ __('of') }} {{ number_format($results['total'], 0, ',', '.') }}
                </p>
            @endif
        </div>
    @endif
</div>
