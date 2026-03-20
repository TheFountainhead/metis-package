<div>
    <flux:card>
        <flux:heading size="lg" class="mb-4">{{ __('Tax Records') }}</flux:heading>
        @if(count($records) > 0)
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200 dark:border-zinc-700">
                            <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('Year') }}</th>
                            <th class="text-right py-2 pr-4 font-medium text-zinc-500">{{ __('Taxable Income') }}</th>
                            <th class="text-right py-2 font-medium text-zinc-500">{{ __('Corporate Tax') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($records as $record)
                            <tr class="border-b border-zinc-100 dark:border-zinc-800">
                                <td class="py-2 pr-4">{{ $record['income_year'] ?? '-' }}</td>
                                <td class="py-2 pr-4 text-right">{{ isset($record['taxable_income']) ? number_format($record['taxable_income'], 0, ',', '.') . ' kr.' : '-' }}</td>
                                <td class="py-2 text-right">{{ isset($record['corporate_tax']) ? number_format($record['corporate_tax'], 0, ',', '.') . ' kr.' : '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="text-sm text-zinc-500">{{ __('No tax records found.') }}</p>
        @endif
    </flux:card>
</div>
