{{-- One positioned node in the ownership graph.

     Positioned absolutely by dagre (x/y/w/h come from the Alpine layout()).
     Kind-driven styling: person (dark, Resights-like), foreign (oxblood),
     reel/legal/other/subsidiary (sand card), searched (bg-2 + thick ink
     border), property (dashed, ochre name). NO left-stripe border — that
     AI-tell is banned (see spec §Node).

     `node` is an Alpine object from $graphModel (built server-side by
     OwnershipGraphBuilder::build()); every text field is bound via x-text so
     Alpine escapes it (never interpolated as raw HTML). --}}
<template x-for="node in nodes" :key="node.id">
    {{-- Card entry point (fase 2a.2, review-gap fix): hover/click open the
         singleton card (ownership-graph.blade.php's `card.node`) — wired
         HERE, not in the host JS, because the codebase convention is that
         interaction attributes live in the Blade (frame-pan and the expand
         buttons below are both wired the same way). nodeEnter/nodeLeave own
         hover-intent/grace timing; nodeClick owns the _moved-drag-guard and
         touch-vs-hover-mode branching (all in the T5 Alpine component).
         @click (not @click.stop) so it does NOT shadow the expand buttons'
         own @click.stop below — those already stop propagation before it
         would reach this handler, so a node click only ever fires nodeClick
         when the click didn't land on an expand button. --}}
    <div
        class="mgraph-node"
        :class="'mgraph-node--' + node.kind"
        :style="`left:${node.x}px; top:${node.y}px; width:${node.w}px; height:${node.h}px;`"
        @mouseenter="nodeEnter(node)"
        @mouseleave="nodeLeave()"
        @click="nodeClick(node)"
    >
        <div class="mgraph-node__name" x-text="node.label"></div>
        <div class="mgraph-node__meta" x-show="node.cvr" x-cloak>
            <span class="mgraph-node__cvr" x-text="node.cvr"></span>
        </div>
        {{-- Property meta rows: BFE + anvendelse (rows omitted when null).
             Optional chaining + ?? fallback on the x-text expressions too, not
             just the x-show guards: Alpine evaluates x-text on every node
             regardless of x-show (display:none doesn't skip evaluation), so
             person/company nodes (meta=undefined) would otherwise throw
             "Cannot read properties of undefined (reading 'bfe'/'usage')". --}}
        <div class="mgraph-node__meta" x-show="node.kind === 'property' && (node.meta?.bfe || node.meta?.usage)" x-cloak>
            <span class="mgraph-node__cvr" x-show="node.meta?.bfe" x-text="'BFE ' + (node.meta?.bfe ?? '')"></span>
            <span class="mgraph-node__usage" x-show="node.meta?.usage" x-text="node.meta?.usage ?? ''"></span>
        </div>
        {{-- Enrichment (fase 2a.2): value aggregate + signal icons.
             node.agg / node.signals are only present when OwnershipGraphBuilder
             computed them (applyEnrichment) — most nodes have neither, so every
             x-text expression below needs its own null-safe fallback: Alpine
             evaluates x-text on EVERY node regardless of x-show (display:none
             doesn't skip evaluation — the 2a.1 lesson repeated in the docblock
             above), so `node.agg.count` (no `?.`) would throw on a node with
             agg=undefined the instant it's rendered, not just when shown. --}}
        <span class="mgraph-node__agg" x-show="node.agg" x-cloak
              x-text="node.agg ? (node.agg.count + ' ejendomme · ' + fmtDKK(node.agg.value) + (node.agg.valued < node.agg.count ? ' (' + node.agg.valued + ' vurderet)' : '')) : ''"></span>
        <span class="mgraph-node__signals" x-show="node.signals?.length" x-cloak>
            <template x-for="s in (node.signals ?? [])" :key="s">
                <span class="mgraph-signal" :class="'mgraph-signal--' + s" :title="signalTitle(s)" x-text="signalGlyph(s)"></span>
            </template>
        </span>
        {{-- Expand affordances. @mousedown.stop so the frame's pan never starts;
             _expanding gives a per-node loading state until the watcher re-renders.
             Same evaluate-regardless-of-x-show reasoning as above: most nodes have
             expand=null, so the x-text expressions need their own ?? fallback.

             node.expand.capped_relations / capped_properties mean that FIELD's
             hidden count came from TOTAL-cap truncation (removeNode folding a
             cut node's count onto its parent), not the depth-cap —
             expandNode() only lifts the depth-recursion cap, so a button for
             that field would busy to '…' and never resolve. Flagged per
             field (not on the whole expand object): a parent can carry a
             legitimate, still-resolvable depth-cap relations count alongside
             a total-cap-truncated properties count, or vice versa. Render
             static mono-text instead for the capped field (x-if swaps the
             element type itself, not just its style, so no button/click
             semantics reach the DOM). --}}
        <div class="mgraph-node__expand" x-show="node.expand && (node.expand.relations || node.expand.properties)" x-cloak>
            <template x-if="node.expand?.relations">
                <button type="button" x-show="!node.expand?.capped_relations" x-data="{busy:false}"
                        @mousedown.stop @click.stop="busy = true; $wire.expandNode('sub:' + node.cvr)"
                        :disabled="busy" x-text="busy ? '…' : ('↓ ' + node.expand.relations + ' relationer')"></button>
            </template>
            <span x-show="node.expand?.relations && node.expand?.capped_relations" x-text="'↓ ' + (node.expand?.relations ?? 0) + ' relationer'"></span>

            <template x-if="node.expand?.properties">
                <button type="button" x-show="!node.expand?.capped_properties" x-data="{busy:false}"
                        @mousedown.stop @click.stop="busy = true; $wire.expandNode('props:' + node.cvr)"
                        :disabled="busy" x-text="busy ? '…' : ('+ ' + node.expand.properties + ' ejendomme')"></button>
            </template>
            <span x-show="node.expand?.properties && node.expand?.capped_properties" x-text="'+ ' + (node.expand?.properties ?? 0) + ' skjult'"></span>
        </div>
    </div>
</template>
