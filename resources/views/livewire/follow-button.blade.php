<div class="inline-flex flex-col items-end gap-1">
    <button wire:click="toggle"
            wire:loading.attr="disabled"
            wire:target="toggle"
            class="px-3 py-1.5 text-sm border rounded transition
                   {{ $isFollowed
                       ? 'bg-blue-50 border-blue-300 text-blue-700 hover:bg-red-50 hover:border-red-300 hover:text-red-700'
                       : 'bg-white border-zinc-300 hover:bg-zinc-50' }}
                   disabled:opacity-50 disabled:cursor-not-allowed">
        <span wire:loading.remove wire:target="toggle">
            @if($isFollowed)
                {{ __('Følger') }}
            @else
                {{ __('Følg') }}
            @endif
        </span>
        <span wire:loading wire:target="toggle">
            ⏳
        </span>
    </button>

    @if($error)
        <span class="text-xs text-red-600">{{ $error }}</span>
    @endif
</div>
