<div>
    <flux:card>
        <flux:heading size="lg" class="mb-4">{{ __('Owners') }}</flux:heading>
        @if(count($owners) > 0)
            <div class="space-y-2">
                @foreach($owners as $owner)
                    @if($owner['is_current'] ?? false)
                        <div class="flex items-center justify-between text-sm py-1">
                            <div>
                                @if(($owner['type'] ?? '') === 'company' && isset($owner['identifier']))
                                    <x-metis-link type="cvr" :query="$owner['identifier']" :label="$owner['name'] ?? $owner['identifier']" />
                                @elseif(($owner['name'] ?? null) && ! str_contains(mb_strtolower($owner['name']), 'beskyttelse'))
                                    <x-metis-link type="person" :query="$owner['name']" class="font-medium" />
                                @elseif($owner['name'] ?? null)
                                    <span class="font-medium">{{ $owner['name'] }}</span>
                                @else
                                    <span class="font-medium">-</span>
                                @endif
                            </div>
                            <span class="text-zinc-500">{{ $owner['share'] ?? '' }}</span>
                        </div>
                    @endif
                @endforeach
            </div>
        @else
            @if($hasError)
                @include('metis::livewire.sections.partials.lookup-error')
            @else
            <p class="text-sm text-zinc-500">{{ __('No owner data found.') }}</p>
            @endif
        @endif
    </flux:card>
</div>
