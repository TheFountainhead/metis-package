<form wire:submit="search" role="search" class="relative">
    <div class="bg-white rounded-2xl shadow-[0_1px_3px_rgba(0,0,0,0.08),0_8px_24px_rgba(0,0,0,0.04)] border border-sand-200/60 px-5 py-3 flex items-center gap-3 transition-all focus-within:shadow-[0_1px_3px_rgba(0,0,0,0.08),0_8px_32px_rgba(0,0,0,0.08)] focus-within:border-sand-300">
        <svg class="w-4 h-4 text-sand-300 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
        </svg>
        <input
            wire:model="query"
            type="text"
            class="flex-1 bg-transparent border-none outline-none text-ink-800 placeholder-sand-300 text-[15px] focus:ring-0"
            placeholder="Søg person, virksomhed eller adresse..."
            autofocus
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
</form>
