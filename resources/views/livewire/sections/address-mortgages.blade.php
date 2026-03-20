<div>
    <flux:card>
        <flux:heading size="lg" class="mb-4">{{ __('Mortgages') }}</flux:heading>
        @if(count($mortgages) > 0)
            @if($totalDebt)
                <p class="text-sm text-zinc-500 mb-3">{{ __('Total debt') }}: <span class="font-medium text-zinc-900 dark:text-white">{{ number_format($totalDebt, 0, ',', '.') }} kr.</span></p>
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
        @else
            <p class="text-sm text-zinc-500">{{ __('No mortgages found.') }}</p>
        @endif
    </flux:card>
</div>
