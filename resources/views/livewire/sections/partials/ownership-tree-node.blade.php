{{-- Recursive ownership-tree node. Renders one owner (company or person) and,
     indented beneath it via a connector line, the owners of that company.
     Mirrors the CVR click-through hierarchy: each company appears under the
     company it owns, with its own owners nested below — never a flat,
     depth-sorted sibling list. Expects: $node (with nested 'children'),
     $depth (0 at the top-level tree roots), $expandedOwners. --}}
<div class="metis-tree-node">
    <div class="metis-tree-row">
        @if($depth > 0)
            <span class="metis-tree-connector" aria-hidden="true"></span>
        @endif
        <div class="metis-tree-card">
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
    </div>

    @if(count($node['children'] ?? []) > 0)
        <div class="metis-tree-children">
            @foreach($node['children'] as $child)
                @include('metis::livewire.sections.partials.ownership-tree-node', [
                    'node' => $child,
                    'depth' => $depth + 1,
                    'expandedOwners' => $expandedOwners,
                ])
            @endforeach
        </div>
    @endif
</div>
