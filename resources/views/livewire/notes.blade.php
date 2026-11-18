<div wire:init="hent" data-noter class="mt-6 p-5 bg-white border border-zinc-200 rounded-xl">
    <h2 class="text-lg font-semibold mb-1">{{ __('Noter') }}</h2>
    <p class="text-xs text-zinc-500 mb-3">{{ __('Dine egne noter. De er kun synlige for dig.') }}</p>

    <form wire:submit="gem" class="mb-4">
        <textarea wire:model="tekst" rows="2" maxlength="{{ \TheFountainhead\Metis\Livewire\Notes::MAKS_TEGN }}" aria-label="{{ __('Ny note') }}"
                  placeholder="{{ __('Skriv en note') }}" class="w-full px-3 py-2 text-sm border border-zinc-300 rounded-lg"></textarea>
        <button type="submit" wire:loading.attr="disabled" class="mt-1 px-3 py-1.5 text-sm border border-zinc-300 rounded hover:bg-zinc-50">{{ __('Gem note') }}</button>
    </form>

    @if($fejl)
        <p class="mb-3 text-sm text-red-600">{{ $fejl }} @if($noter === null)<button type="button" wire:click="hent" class="underline">{{ __('Prøv igen') }}</button>@endif</p>
    @endif

    @if($noter === [])
        <p class="text-sm text-zinc-500">{{ __('Ingen noter endnu.') }}</p>
    @elseif($noter !== null)
        <ul class="space-y-3">
            @foreach($noter as $n)
                <li wire:key="note-{{ $n['id'] }}" data-note class="text-sm border-t border-zinc-100 pt-3">
                    @if($retterId === $n['id'])
                        <form wire:submit="gemRettelse">
                            <textarea wire:model="retterTekst" rows="3" maxlength="{{ \TheFountainhead\Metis\Livewire\Notes::MAKS_TEGN }}" aria-label="{{ __('Ret note') }}"
                                      class="w-full px-3 py-2 border border-zinc-300 rounded-lg"></textarea>
                            <button type="submit" class="mt-1 px-3 py-1 border border-zinc-300 rounded hover:bg-zinc-50">{{ __('Gem') }}</button>
                            <button type="button" wire:click="fortryd" class="mt-1 px-3 py-1 text-zinc-500 underline">{{ __('Fortryd') }}</button>
                        </form>
                    @else
                        {{-- Brugerens egen tekst: altid escaped, linjeskift bevaret med CSS. --}}
                        <p class="whitespace-pre-line break-words text-zinc-800">{{ $n['body'] }}</p>
                        <p class="mt-1 text-xs text-zinc-500">
                            @if($n['created_at'])
                                {{ rescue(fn () => \Illuminate\Support\Carbon::parse($n['created_at'])->timezone('Europe/Copenhagen')->format('d.m.Y H:i'), '', false) }} ·
                            @endif
                            <button type="button" wire:click="ret({{ $n['id'] }})" class="underline">{{ __('Ret') }}</button> ·
                            <button type="button" wire:click="slet({{ $n['id'] }})" wire:confirm="{{ __('Slet noten? Det kan ikke fortrydes.') }}" class="underline">{{ __('Slet') }}</button>
                        </p>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>
