<div @if($enriching) wire:poll.3s="pollForUpdates" @endif>
    <flux:card>
        <flux:heading size="lg" class="mb-4">{{ __('Company Structure') }}</flux:heading>

        @if(count($owners) > 0)
            <div class="mb-4">
                <h4 class="text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-2 flex items-center gap-1.5">
                    <svg xmlns="http://www.w3.org/2000/svg" class="size-3.5 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/></svg>
                    {{ __('Owned by') }}
                </h4>
                <div class="space-y-1">
                    @foreach($owners as $owner)
                        <div>
                            <div class="flex items-center justify-between text-sm py-1 {{ ! ($owner['is_current'] ?? true) ? 'text-zinc-400' : '' }}">
                                <div class="flex items-center gap-2">
                                    @if($owner['is_company'] ?? false)
                                        <x-metis-link type="cvr" :query="$owner['cvr'] ?? ''" :label="$owner['person_name'] ?? '-'" />
                                    @else
                                        <x-metis-link type="person" :query="$owner['person_name'] ?? '-'" :label="$owner['person_name'] ?? '-'" />
                                    @endif
                                    @if(! ($owner['is_current'] ?? true))
                                        <flux:badge size="sm" color="zinc">{{ __('Historical') }}</flux:badge>
                                    @endif
                                </div>
                                @if($owner['ownership_share'] ?? null)
                                    <flux:badge size="sm" color="sky">{{ number_format($owner['ownership_share'], 0) }}%</flux:badge>
                                @endif
                            </div>
                            {{-- Show parent company's owners (one level deeper) --}}
                            @if(! empty($owner['parent_owners']))
                                <div class="ml-6 border-l-2 border-zinc-200 dark:border-zinc-700 pl-3 mt-1 space-y-1">
                                    <div class="text-xs text-zinc-400">{{ __('Owned by') }}:</div>
                                    @foreach($owner['parent_owners'] as $parentOwner)
                                        <div class="flex items-center justify-between text-sm py-0.5">
                                            @if($parentOwner['is_company'] ?? false)
                                                <x-metis-link type="cvr" :query="$parentOwner['cvr'] ?? ''" :label="$parentOwner['person_name'] ?? '-'" />
                                            @else
                                                <x-metis-link type="person" :query="$parentOwner['person_name'] ?? '-'" :label="$parentOwner['person_name'] ?? '-'" />
                                            @endif
                                            @if($parentOwner['ownership_share'] ?? null)
                                                <flux:badge size="sm" color="zinc">{{ number_format($parentOwner['ownership_share'], 0) }}%</flux:badge>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Central fokus-kort: det søgte selskab. Brugeren skal kunne se "her er jeg
             i strukturen" — owners ovenover, subsidiaries nedenunder. --}}
        @if($companyName || $query)
            <div class="my-4 -mx-2 px-3 py-2.5 bg-amber-50 dark:bg-amber-900/20 border-2 border-amber-300 dark:border-amber-700 rounded-lg">
                <div class="flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="size-4 text-amber-600 dark:text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                    <div>
                        <div class="font-semibold text-zinc-800 dark:text-zinc-100">
                            {{ $companyName ?? '—' }}
                        </div>
                        <div class="text-xs text-zinc-500 font-mono">CVR {{ $query }}</div>
                    </div>
                    <span class="ml-auto text-xs uppercase tracking-wide text-amber-700 dark:text-amber-300 font-medium">{{ __('Searched company') }}</span>
                </div>
            </div>
        @endif

        @if(count($subsidiaries) > 0)
            <div>
                <h4 class="text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-2 flex items-center gap-1.5">
                    <svg xmlns="http://www.w3.org/2000/svg" class="size-3.5 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    {{ __('Owns these subsidiaries') }}
                </h4>
                <div class="space-y-1">
                    @foreach($subsidiaries as $sub)
                        <div class="flex items-center justify-between text-sm py-1">
                            <x-metis-link type="cvr" :query="$sub['cvr']" :label="$sub['name'] ?? $sub['cvr']" />
                            @if($sub['ownership_share'] ?? null)
                                <flux:badge size="sm" color="zinc">{{ $sub['ownership_share'] }}%</flux:badge>
                            @else
                                <span class="text-zinc-500">{{ $sub['ownership_share'] ?? '' }}</span>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        @if($enriching)
            <div class="flex items-center gap-2 text-blue-500 text-sm mt-3">
                <svg class="size-3 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                {{ __('Discovering subsidiary tree...') }}
                @if($companiesFound > 0)
                    <span class="font-medium">{{ $companiesFound }} {{ __('companies') }}</span>
                @endif
            </div>
        @endif

        @if(count($owners) === 0 && count($subsidiaries) === 0 && ! $enriching)
            <p class="text-sm text-zinc-500">{{ __('No structure data found.') }}</p>
        @endif
    </flux:card>
</div>
