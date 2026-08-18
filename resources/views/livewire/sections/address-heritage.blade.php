<div>
    <flux:card>
        <flux:heading size="lg" class="mb-4">{{ __('Heritage & Protection') }}</flux:heading>
        @if($heritage)
            <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                @if(isset($heritage['protected']))
                    <dt class="text-zinc-500">{{ __('Protected') }}</dt>
                    <dd>
                        @if($heritage['protected'])
                            <span class="px-2 py-0.5 text-xs rounded-full bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200">{{ __('Yes, listed building') }}</span>
                        @else
                            {{ __('No') }}
                        @endif
                    </dd>
                @endif
                @if(isset($heritage['conservation_value']))
                    <dt class="text-zinc-500">{{ __('Conservation value') }}</dt>
                    <dd>{{ $heritage['conservation_value'] }}</dd>
                @endif
                @if(isset($heritage['designation']))
                    <dt class="text-zinc-500">{{ __('Designation') }}</dt>
                    <dd>{{ $heritage['designation'] }}</dd>
                @endif
            </dl>
        @else
            @if($hasError)
                @include('metis::livewire.sections.partials.lookup-error', ['errorMessage' => $errorMessage])
            @else
            <p class="text-sm text-zinc-500">{{ __('No heritage data found.') }}</p>
            @endif
        @endif
    </flux:card>
</div>
