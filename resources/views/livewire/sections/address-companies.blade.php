<div>
    <flux:card>
        <flux:heading size="lg" class="mb-4">{{ __('Companies at Address') }}</flux:heading>
        @if(count($companies) > 0)
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200 dark:border-zinc-700">
                            <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('Company') }}</th>
                            <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('CVR') }}</th>
                            <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('Industry') }}</th>
                            <th class="text-left py-2 font-medium text-zinc-500">{{ __('Type') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($companies as $company)
                            <tr class="border-b border-zinc-100 dark:border-zinc-800">
                                <td class="py-2 pr-4 font-medium">
                                    <x-metis-link type="cvr" :query="$company['cvr']" :label="$company['name'] ?? $company['cvr']" />
                                </td>
                                <td class="py-2 pr-4">{{ $company['cvr'] }}</td>
                                <td class="py-2 pr-4">{{ $company['industry'] ?? '-' }}</td>
                                <td class="py-2">{{ $company['type'] ?? '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            @if($hasError)
                @include('metis::livewire.sections.partials.lookup-error')
            @else
            <p class="text-sm text-zinc-500">{{ __('No companies found at this address.') }}</p>
            @endif
        @endif
    </flux:card>
</div>
