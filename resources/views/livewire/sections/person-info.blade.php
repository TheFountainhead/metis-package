<div>
    <flux:card>
        <flux:heading size="lg" class="mb-4">{{ __('Person Information') }}</flux:heading>

        {{-- 🚨 FEJL-GRENEN FOERST. Sektionen viser TAL, og et tal er den mest
             overbevisende falske benaegtelse: "0 Aktive selskaber" ligner et
             resultat, ikke et mislykket opslag. --}}
        @if($hasError)
            @include('metis::livewire.sections.partials.lookup-error', ['errorMessage' => $errorMessage])
        @else
        <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
            <dt class="text-zinc-500">{{ __('CPR') }}</dt>
            <dd class="font-medium">{{ substr($query, 0, 6) . '-' . substr($query, 6) }}</dd>
            <dt class="text-zinc-500">{{ __('Properties owned') }}</dt>
            <dd>{{ $properties ? count($properties) : 0 }}</dd>
        </dl>
        @endif
    </flux:card>
</div>
