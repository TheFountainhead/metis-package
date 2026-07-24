{{-- Recursive ownership-tree node. Renders one owner (company or person) and,
     indented beneath it, the owners of that company. Mirrors the CVR
     click-through hierarchy: each company appears under the company it owns,
     with its own owners nested below. Expects: $node (with nested 'children'),
     $depth (0 at the top-level owners), $expandedOwners. --}}
<div class="metis-tree-node" style="margin-left: {{ $depth * 1.25 }}rem">
    <div class="flex items-center gap-2 py-1">
        <span class="text-zinc-300 dark:text-zinc-600 select-none">{{ $depth > 0 ? '└─' : '' }}</span>
        @include('metis::livewire.sections.partials.owner-card', [
            'owner' => $node,
            'badgeColor' => ($node['owner_kind'] ?? 'other') === 'reel' ? 'emerald' : (($node['owner_kind'] ?? 'other') === 'legal' ? 'sky' : 'zinc'),
            'expandedOwners' => $expandedOwners,
        ])
        @if($node['foreign'] ?? false)
            <span class="text-xs text-zinc-500">{{ __('foreign owner') }}</span>
        @endif
        @if($node['cycle'] ?? false)
            <span class="text-xs text-amber-600">{{ __('circular ownership') }}</span>
        @endif
        @if($node['enriching'] ?? false)
            <span class="text-xs text-blue-500">{{ __('loading...') }}</span>
        @endif
    </div>

    @foreach($node['children'] ?? [] as $child)
        @include('metis::livewire.sections.partials.ownership-tree-node', [
            'node' => $child,
            'depth' => $depth + 1,
            'expandedOwners' => $expandedOwners,
        ])
    @endforeach
</div>
