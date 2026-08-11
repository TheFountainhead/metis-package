{{-- 🪤 Layoutet er en KOMPONENT (`{{ $slot }}`), ikke `@yield` — med
     @extends/@section giver den 500 ("Undefined variable $slot"). Og
     `<x-metis::...>` virker ikke: pakken registrerer et VIEW-namespace, ikke
     et komponent-namespace (alle andre steder naas layoutet via Livewires
     `->layout('metis::layouts.standalone')`). `@component` tager view-navnet
     direkte og virker derfor her. --}}
@component('metis::layouts.standalone')
    <div class="max-w-lg mx-auto py-16 px-4 text-center">
        <flux:card>
            <flux:heading size="lg" class="mb-2">{{ __('Der er åbnet for flere opslag') }}</flux:heading>

            <p class="text-sm text-zinc-500 mb-4">
                {{ $lead->email }}
            </p>

            <p class="text-sm text-zinc-600 dark:text-zinc-300">
                {{ __('Kvote hævet fra') }} {{ $foer }} {{ __('til') }}
                <span class="font-medium text-zinc-900 dark:text-white">{{ $lead->lookup_quota }}</span>
                {{ __('opslag.') }}
            </p>

            <p class="text-xs text-zinc-400 mt-4">
                {{ __('Brugeren har fået besked på mail. Forbrug indtil nu:') }}
                {{ $lead->lookup_count }} {{ __('opslag.') }}
            </p>
        </flux:card>
    </div>
@endcomponent
