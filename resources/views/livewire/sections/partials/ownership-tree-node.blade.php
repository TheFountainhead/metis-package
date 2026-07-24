{{-- Frankston-style org-chart node. Renders one owner (company or person) as
     a card, and — nested beneath it in a <ul> — the owners of THAT company,
     recursively. The whole tree is built top-down with the searched company
     as the single render-root and its owners as children (see
     company-structure.blade.php), then the entire .org container is flipped
     with scaleY(-1) so owners end up rendered at the TOP and the searched
     company at the BOTTOM, while each .card counter-flips so its text stays
     upright. Classic CSS ::before/::after connectors (see the <style> block
     in company-structure.blade.php) draw the tree lines — no margin-left
     indentation.
     Expects: $node (with nested 'children'), $searched (bool, true only for
     the synthetic root wrapping the searched company). --}}
@php
    $isCompany = $node['is_company'] ?? false;
    $isForeign = $node['foreign'] ?? false;
    $ownerKind = $node['owner_kind'] ?? null;
    // Foreign takes precedence over owner_kind for both color and label: a
    // foreign co-owner (e.g. Standout Capital II AB) can still carry
    // owner_kind=legal, but it must render as "Udenlandsk"/oxblood, not teal.
    $kindColor = match (true) {
        $isForeign => '#7a1f1f',
        $ownerKind === 'reel' => '#0a5c4a',
        default => '#3e5e63',
    };
    $kindLabel = match (true) {
        $isForeign => __('Udenlandsk'),
        $ownerKind === 'reel' => __('Reel ejer'),
        $ownerKind === 'legal' => __('Legal ejer'),
        default => __('Ejer'),
    };
    $drilldownType = $isCompany ? 'cvr' : 'person';
    $drilldownQuery = $isCompany ? ($node['cvr'] ?? '') : ($node['person_name'] ?? '');
@endphp
<li>
    <div class="node">
        <div class="card {{ ($searched ?? false) ? 'searched' : '' }}">
            <div class="cname">
                @if(! ($searched ?? false) && $drilldownQuery)
                    <x-metis-link :type="$drilldownType" :query="$drilldownQuery" :label="$node['person_name'] ?? '-'" />
                @else
                    {{ $node['person_name'] ?? '-' }}
                @endif
            </div>
            <div class="cmeta">
                @if($searched ?? false)
                    <span class="ckind you">{{ __('Søgt selskab') }}</span>
                @else
                    <span class="ckind" style="--m: {{ $kindColor }}">{{ $kindLabel }}</span>
                    @if($node['ownership_share'] ?? null)
                        <span class="cshare">{{ number_format($node['ownership_share'], 2, ',', '.') }} %</span>
                    @endif
                @endif
            </div>
            @if($node['cycle'] ?? false)
                <div class="cflag">{{ __('circular ownership') }}</div>
            @endif
            @if($node['enriching'] ?? false)
                <div class="cflag">{{ __('loading...') }}</div>
            @endif
        </div>
    </div>

    @if(count($node['children'] ?? []) > 0)
        <ul>
            @foreach($node['children'] as $child)
                @include('metis::livewire.sections.partials.ownership-tree-node', [
                    'node' => $child,
                    'searched' => false,
                ])
            @endforeach
        </ul>
    @endif
</li>
