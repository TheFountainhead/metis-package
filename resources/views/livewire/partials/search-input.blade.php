<form wire:submit="search" role="search" class="relative">
    <div class="bg-white rounded-2xl shadow-[0_1px_3px_rgba(0,0,0,0.08),0_8px_24px_rgba(0,0,0,0.04)] border border-sand-200/60 px-5 py-3 flex items-center gap-3 transition-all focus-within:shadow-[0_1px_3px_rgba(0,0,0,0.08),0_8px_32px_rgba(0,0,0,0.08)] focus-within:border-sand-300">
        <svg class="w-4 h-4 text-sand-300 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
        </svg>
        <input
            wire:model.live.debounce.300ms="query"
            wire:keydown.enter="search"
            type="text"
            class="flex-1 bg-transparent border-none outline-none text-ink-800 placeholder-sand-300 text-[15px] focus:ring-0"
            placeholder="Søg person, virksomhed eller adresse..."
            autofocus
            autocomplete="off"
        >
        @if($resultType || $result)
        <button type="button" wire:click="clearSearch" class="text-sand-300 hover:text-ink-700 transition-colors shrink-0" title="{{ __('New search') }}">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M18 6L6 18M6 6l12 12"/>
            </svg>
        </button>
        @endif
        <button type="submit" class="text-warm-500 hover:text-warm-600 text-sm font-medium transition-colors shrink-0">
            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M5 12h14M12 5l7 7-7 7"/>
            </svg>
        </button>
    </div>

    {{-- Address autocomplete dropdown --}}
    @if(count($suggestions) > 0)
    <div class="absolute left-0 right-0 top-full mt-1 bg-white rounded-xl border border-sand-200 shadow-lg z-50 overflow-hidden">
        @foreach($suggestions as $suggestion)
        <button
            type="button"
            wire:click="selectSuggestion(@js($suggestion['tekst'] ?? ''))"
            class="w-full text-left px-5 py-2.5 text-sm text-ink-800 hover:bg-sand-50 transition-colors flex items-center gap-3 {{ !$loop->last ? 'border-b border-sand-100' : '' }}"
        >
            <svg class="w-3.5 h-3.5 text-sand-300 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/>
            </svg>
            {{ $suggestion['tekst'] ?? '' }}
        </button>
        @endforeach
    </div>
    @endif
</form>
