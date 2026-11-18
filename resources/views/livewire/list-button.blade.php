<div class="relative inline-block text-left" wire:init="hent" x-data="{ aaben: false }" @keydown.escape.window="aaben = false" @click.outside="aaben = false">
    <button type="button" @click="aaben = !aaben" aria-haspopup="true" :aria-expanded="aaben ? 'true' : 'false'"
            class="px-3 py-1.5 text-sm border rounded transition {{ $this->antalValgt() > 0 ? 'bg-blue-50 border-blue-300 text-blue-700' : 'bg-white border-zinc-300 hover:bg-zinc-50' }}">
        {{ __('Liste') }}@if($this->antalValgt() > 0) <span data-liste-antal>({{ $this->antalValgt() }})</span>@endif
    </button>

    <div x-show="aaben" x-cloak style="display:none" data-liste-menu
         class="absolute right-0 z-30 mt-1 w-72 p-3 bg-white border border-zinc-200 rounded-lg shadow-lg text-sm">
        @if($lister === null)
            <p class="text-zinc-500">{{ $fejl ?? __('Henter dine lister') }}</p>
            @if($fejl)
                <button type="button" wire:click="hent" class="mt-1 underline">{{ __('Prøv igen') }}</button>
            @endif
        @else
            @if($lister === [])
                <p class="mb-2 text-zinc-600">{{ __('Du har ingen lister endnu. Opret den første her.') }}</p>
            @else
                <ul class="mb-2 max-h-60 overflow-y-auto">
                    @foreach($lister as $l)
                        <li wire:key="liste-{{ $l['id'] }}">
                            <label class="flex items-center gap-2 py-1 cursor-pointer">
                                <input type="checkbox" @checked($l['contains']) wire:click="skift({{ $l['id'] }})" wire:loading.attr="disabled" class="rounded border-zinc-300">
                                <span class="truncate">{{ $l['name'] }}</span>
                            </label>
                        </li>
                    @endforeach
                </ul>
            @endif

            <form wire:submit="opret" class="flex gap-2 pt-2 border-t border-zinc-100">
                <input type="text" wire:model="nytNavn" maxlength="80" placeholder="{{ __('Ny liste') }}" aria-label="{{ __('Navn på ny liste') }}"
                       class="flex-1 min-w-0 px-2 py-1 border border-zinc-300 rounded">
                <button type="submit" wire:loading.attr="disabled" class="px-2 py-1 border border-zinc-300 rounded hover:bg-zinc-50">{{ __('Opret') }}</button>
            </form>

            @if($fejl)
                <p class="mt-2 text-xs text-red-600">{{ $fejl }}</p>
            @endif

            @if(Route::has('metis.lists'))
                <a href="{{ route('metis.lists') }}" class="block mt-2 text-xs text-zinc-500 underline">{{ __('Se alle lister') }}</a>
            @endif
        @endif
    </div>
</div>
