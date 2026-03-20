<div>
    <div class="flex items-center justify-between py-2 mb-4 border-b">
        <flux:heading size="xl">{{ __('Metis') }}</flux:heading>
    </div>

    <div class="max-w-2xl">
        {{-- Search field --}}
        <form wire:submit="lookup" class="mb-8">
            <flux:field>
                <flux:label>{{ __('Look up CVR, CPR, company name, or address') }}</flux:label>
                <div class="relative">
                    <div class="flex gap-2">
                        <div class="relative flex-1">
                            <input type="text"
                                wire:model.live.debounce.300ms="search"
                                placeholder="{{ __('e.g. 12345678, 010190-1234, or Bredgade 40...') }}"
                                class="block w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm shadow-sm placeholder:text-zinc-400 focus:border-zinc-400 focus:outline-none focus:ring-1 focus:ring-zinc-400 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                                autofocus />

                            @if(count($addressSuggestions) > 0)
                                <ul class="absolute z-50 left-0 right-0 mt-1 bg-white border border-zinc-200 rounded-lg shadow-lg max-h-48 overflow-y-auto dark:bg-zinc-800 dark:border-zinc-600">
                                    @foreach($addressSuggestions as $index => $suggestion)
                                        <li wire:click="selectAddress({{ $index }})"
                                            class="px-3 py-2 text-sm cursor-pointer hover:bg-zinc-100 dark:hover:bg-zinc-700 dark:text-white">
                                            {{ $suggestion['tekst'] }}
                                        </li>
                                    @endforeach
                                </ul>
                            @endif

                        </div>

                        <flux:button type="submit" variant="primary" icon="magnifying-glass">
                            {{ __('Look up') }}
                        </flux:button>
                    </div>

                    {{-- Detected type badge with override --}}
                    @if($detectedType)
                        <div class="flex items-center gap-2 mt-2">
                            <span class="text-xs text-zinc-500">{{ __('Detected') }}:</span>
                            @foreach(['cvr', 'cpr', 'company', 'address'] as $t)
                                <button type="button"
                                    wire:click="setType('{{ $t }}')"
                                    class="px-2 py-0.5 text-xs rounded-full border transition-colors
                                        {{ ($overrideType ?? $detectedType) === $t
                                            ? 'bg-zinc-800 text-white border-zinc-800 dark:bg-zinc-200 dark:text-zinc-800'
                                            : 'bg-white text-zinc-500 border-zinc-300 hover:border-zinc-400 dark:bg-zinc-800 dark:text-zinc-400 dark:border-zinc-600' }}">
                                    {{ match($t) { 'address' => __('Address'), 'company' => __('Company'), default => strtoupper($t) } }}
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>
            </flux:field>
        </form>

        {{-- Company search results --}}
        @if($companySearchDone)
            <div class="mb-8">
                <flux:heading size="lg" class="mb-3">{{ __('Company search results') }}</flux:heading>
                @if(count($companySuggestions) > 0)
                    <div class="space-y-1">
                        @foreach($companySuggestions as $company)
                            <button type="button"
                                wire:click="selectCompany('{{ $company['cvr'] }}')"
                                class="w-full flex items-center justify-between px-4 py-3 rounded-lg border border-zinc-200 dark:border-zinc-700 hover:bg-zinc-50 dark:hover:bg-zinc-800 transition-colors text-left">
                                <div>
                                    <div class="text-sm font-medium text-zinc-900 dark:text-white">{{ $company['name'] }}</div>
                                    <div class="text-xs text-zinc-500 mt-0.5">CVR {{ $company['cvr'] }} · {{ $company['company_type'] }}</div>
                                </div>
                                <div class="flex items-center gap-2">
                                    @if(($company['status'] ?? 'NORMAL') !== 'NORMAL')
                                        <flux:badge size="sm" color="zinc">{{ $company['status'] }}</flux:badge>
                                    @else
                                        <flux:badge size="sm" color="green">{{ __('Active') }}</flux:badge>
                                    @endif
                                    <svg class="size-4 text-zinc-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                                    </svg>
                                </div>
                            </button>
                        @endforeach
                    </div>
                @else
                    <p class="text-sm text-zinc-500">{{ __('No companies found matching') }} "{{ $search }}"</p>
                @endif
            </div>
        @endif

        {{-- Recent lookups --}}
        @if($lookups->count() > 0)
            <div>
                <flux:heading size="lg" class="mb-3">{{ __('Recent lookups') }}</flux:heading>
                <div class="space-y-1">
                    @foreach($lookups as $lookup)
                        <a href="{{ route('metis.lookup', ['type' => $lookup->search_type, 'query' => $lookup->search_term]) }}"
                           class="flex items-center justify-between px-3 py-2 rounded-lg hover:bg-zinc-50 dark:hover:bg-zinc-800 transition-colors group">
                            <div class="flex items-center gap-3">
                                <span class="px-2 py-0.5 text-xs rounded-full bg-zinc-100 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300 uppercase">
                                    {{ $lookup->search_type === 'address' ? __('Adr') : strtoupper($lookup->search_type) }}
                                </span>
                                <span class="text-sm font-medium text-zinc-900 dark:text-white">
                                    {{ $lookup->search_term }}
                                </span>
                            </div>
                            <span class="text-xs text-zinc-400">{{ $lookup->created_at->diffForHumans() }}</span>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</div>
