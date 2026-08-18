<div>
    <flux:card>
        <flux:heading size="lg" class="mb-4">{{ __('Transaction History') }}</flux:heading>
        @if(count($transactions) > 0)
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200 dark:border-zinc-700">
                            <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('Date') }}</th>
                            <th class="text-right py-2 pr-4 font-medium text-zinc-500">{{ __('Price') }}</th>
                            <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('Buyer') }}</th>
                            <th class="text-left py-2 font-medium text-zinc-500">{{ __('Seller') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($transactions as $tx)
                            <tr class="border-b border-zinc-100 dark:border-zinc-800">
                                <td class="py-2 pr-4">{{ $tx['date'] ?? '-' }}</td>
                                <td class="py-2 pr-4 text-right">{{ isset($tx['price']) ? number_format($tx['price'], 0, ',', '.') . ' kr.' : '-' }}</td>
                                <td class="py-2 pr-4">
                                    @if(isset($tx['buyer_cvr']))
                                        <x-metis-link type="cvr" :query="$tx['buyer_cvr']" :label="$tx['buyer'] ?? $tx['buyer_cvr']" />
                                    @else
                                        {{ $tx['buyer'] ?? '-' }}
                                    @endif
                                </td>
                                <td class="py-2">{{ $tx['seller'] ?? '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            @if($hasError)
                @include('metis::livewire.sections.partials.lookup-error', ['errorMessage' => $errorMessage])
            @else
            <p class="text-sm text-zinc-500">{{ __('No transactions found.') }}</p>
            @endif
        @endif
    </flux:card>
</div>
