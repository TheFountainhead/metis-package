@php
    $hasChildren = ! empty($node['children']);
    $size = $treeSize ?? 'normal';
    $isCompact = $size === 'compact';
    $siblings = $siblingCount ?? 1;
@endphp
<div class="flex flex-col items-center">
    {{-- Horizontal connector: two halves per node, creating exact span between first/last sibling centers --}}
    @if($siblings > 1)
        <div class="flex self-stretch h-px">
            <div class="flex-1 {{ ($isFirst ?? false) ? '' : 'bg-zinc-300 dark:bg-zinc-600' }}"></div>
            <div class="flex-1 {{ ($isLast ?? false) ? '' : 'bg-zinc-300 dark:bg-zinc-600' }}"></div>
        </div>
    @endif
    {{-- Vertical connector with ownership badge --}}
    <div class="relative flex flex-col items-center">
        <div class="w-px {{ ($node['ownership_share'] ?? null) ? 'h-7' : 'h-3' }} bg-zinc-300 dark:bg-zinc-600"></div>
        @if($node['ownership_share'] ?? null)
            <div class="absolute top-0 left-1/2 -translate-x-1/2 {{ $isCompact ? 'px-2 py-0.5 text-xs' : 'px-2.5 py-0.5 text-sm' }} font-bold text-sky-700 dark:text-sky-300 bg-sky-100 dark:bg-sky-900/40 rounded-full border border-sky-300 dark:border-sky-700 whitespace-nowrap z-10">{{ number_format($node['ownership_share'], 0) }}%</div>
        @endif
    </div>
    <div class="rounded border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 {{ $isCompact ? 'px-1.5 py-1' : 'px-2.5 py-1.5' }} text-center {{ $isCompact ? 'min-w-[80px] max-w-[160px]' : 'min-w-[100px] max-w-[180px]' }}">
        <x-metis-link type="cvr" :query="$node['cvr']" :label="$node['name'] ?? $node['cvr']" class="{{ $isCompact ? 'text-[5px] leading-tight' : 'text-[7px]' }} break-words text-zinc-500 dark:text-zinc-400" />
    </div>

    @if($hasChildren)
        <div class="w-px h-3 bg-zinc-300 dark:bg-zinc-600"></div>
        <div class="flex">
            @foreach($node['children'] as $child)
                @include('metis::livewire.sections.partials.subsidiary-node', [
                    'node' => $child,
                    'treeSize' => $size,
                    'isFirst' => $loop->first,
                    'isLast' => $loop->last,
                    'siblingCount' => $loop->count,
                ])
            @endforeach
        </div>
    @endif
</div>
