<div @if($enriching) wire:poll.3s="pollForUpdates" @endif>
    <flux:card>
        <flux:heading size="lg" class="mb-4">{{ __('Company Structure') }}</flux:heading>

        @if(count($owners) > 0)
            <div class="mb-4">
                <h4 class="text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-2">{{ __('Owners') }}</h4>
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

        @if(count($subsidiaries) > 0)
            <div>
                <h4 class="text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-2">{{ __('Subsidiaries') }}</h4>
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

        @if(count($owners) === 0 && count($subsidiaries) === 0)
            <p class="text-sm text-zinc-500">{{ __('No structure data found.') }}</p>
        @endif
    </flux:card>
</div>
