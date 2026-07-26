{{-- Ownership relations graph (fase 1+2). Free-form dagre layout in
     frankston.io editorial style: owners on top, searched company at the
     bottom, ownership flowing downward. Node positions + edge coordinates
     are computed in JS (dagre); nodes are absolutely-positioned HTML cards,
     edges are SVG lines.

     Extracted from company-structure.blade.php (Task 8) into its own
     partial so the wire:ignore graph island + its CSS are shared/reusable
     without dragging the rest of the section's Blade along. Receives the
     SAME $graph the Alpine watcher watches — a single source of truth.
     $graph is rebuilt exclusively by OwnershipGraphBuilder::build() (see
     CompanyStructure::rebuild()); no other code path may mutate it
     directly, so re-deriving it here from anything else would let the
     graph's initial x-data diverge from the watched property. One source,
     no divergence. --}}
@if(count($graph['nodes']) > 1)
    <div class="org-section-label">{{ __('Ownership structure') }}</div>

    {{-- The graph lives in a wire:ignore subtree with a STABLE wire:key
         (query only), so Livewire never re-mounts it — the user's zoom/pan
         is Alpine state that must survive an enrichment poll. Instead the
         component watches the Livewire `graphModel` property
         ($wire.$watch in init()): when a poll deepens the chain, graphModel
         changes, and refreshModel() re-lays-out in place (deferring while
         the user is mid-pan). This is the canonical Livewire→Alpine bridge
         for "react to server state without re-mounting" — no carrier node,
         no dispatch-before-listener race. --}}
    <div
        wire:ignore
        wire:key="ownership-graph-{{ $query }}"
        class="mgraph"
        x-data="ownershipGraph(@js($graph))"
    >
        <div class="mgraph-frame"
             x-ref="frame"
             @mousedown="startPan($event)"
             @mousemove.window="onPan($event)"
             @mouseup.window="endPan()"
             @wheel.prevent="onWheel($event)"
        >
            <div class="mgraph-canvas" :style="`transform:${transform}; transform-origin:0 0;`">
                {{-- Edges = one imperatively-built, trusted SVG string from
                     layout() (buildEdgesSvg), injected via x-html. Safe:
                     coords are dagre numbers, labels are escapeXml'd in JS.
                     Gives non-scaling-stroke (edges visible at low zoom) +
                     <polyline> through all routed points (no chord lying
                     about ownership on diamonds).
                     DO NOT rewrite as <template x-for> inside <svg>: Alpine
                     can't scope there (SVG has no <template>) → blank graph. --}}
                <div class="mgraph-edges-wrap" x-html="edgesSvg"></div>
                @include('metis::livewire.sections.partials.graph-node')
            </div>
        </div>
        <div class="mgraph-controls">
            <button type="button" @click="zoomBy(1.2)" aria-label="{{ __('Zoom in') }}">+</button>
            <button type="button" @click="fit()" aria-label="{{ __('Fit') }}" x-text="zoomPct + '%'"></button>
            <button type="button" @click="zoomBy(0.8)" aria-label="{{ __('Zoom out') }}">−</button>
        </div>
        {{-- Singleton hover-card (fase 2a.2): ONE card element, positioned by
             `card.{x,y}` and populated from `card.node`, reused for whichever
             node is currently hovered — not one card per node. Deliberately
             OUTSIDE .mgraph-frame/.mgraph-canvas (a sibling of .mgraph-frame,
             inside .mgraph's own x-data scope): the canvas is transformed by
             zoom/pan (`transform:${transform}`), so a card living inside it
             would scale and pan along with the graph instead of staying a
             fixed-size, always-legible overlay near the cursor.
             card.node is null when nothing is hovered — x-if unmounts the
             whole subtree then (not just x-show), so no stale card lingers
             invisibly and no field below is ever evaluated against a null
             node (same evaluate-regardless-of-x-show trap as graph-node.blade.php:
             here it's moot because x-if actually removes the DOM, not display:none). --}}
        <template x-if="card.node">
            <div class="mgraph-card" :style="`left:${card.x}px; top:${card.y}px;`"
                 @mouseenter="cardHover(true)" @mouseleave="cardHover(false)">
                <div class="mgraph-card__title" x-text="card.node.label"></div>
                {{-- @@error escapes Blade's own @error(...)/@enderror validation
                     directive — without the doubled @ Blade parses this as the
                     start of that directive (matching on the opening paren) and
                     the template fails to compile for want of an @enderror. --}}
                <img x-show="card.node.card?.streetview_url" :src="card.node.card?.streetview_url ?? ''"
                     class="mgraph-card__img" loading="lazy" @@error="$el.style.display='none'" alt="">
                <dl class="mgraph-card__rows">
                    <template x-for="row in cardRows(card.node)" :key="row.label">
                        <div><dt x-text="row.label"></dt><dd x-text="row.value"></dd></div>
                    </template>
                </dl>
                <div class="mgraph-card__links">
                    <a x-show="card.node.card?.website" :href="card.node.card?.website ?? '#'" target="_blank" rel="noopener" class="mgraph-card__link">{{ __('Website') }} ↗</a>
                    <a x-show="card.node.kind === 'property' && card.node.card?.lat" :href="skraafotoUrl(card.node)" target="_blank" rel="noopener" class="mgraph-card__link">{{ __('See oblique aerial photo') }} ↗</a>
                    <a :href="nodeUrl(card.node)" class="mgraph-card__link" x-show="nodeUrl(card.node)">{{ __('Open listing') }} →</a>
                </div>
            </div>
        </template>
    </div>
@endif

<style>
    /* ── Ownership relations graph (fase 1+2) ─────────────────────────
       Free-form dagre layout. Same editorial tokens as the org-chart.
       Node kinds carry colour; NO left-stripe (banned AI-tell). */
    .mgraph {
        --bg:#f6efe3; --bg-2:#efe6d4; --rule-strong:#b8a884; --ink:#1a1a1a; --ink-2:#3a3a3a; --ink-3:#6b6457;
        --reel:#0a5c4a; --legal:#3e5e63; --foreign:#7a1f1f; --person:#2b2333;
        --fd:"Spectral",Georgia,serif; --fb:"IBM Plex Sans",sans-serif; --fm:"IBM Plex Mono",monospace;
        position: relative;
        /* .mgraph is a flex child of .metis-org-chart (display:flex). Its only
           content is the absolutely-positioned graph, so without an explicit width
           the flex item collapses to 0 (the classic flex min-width trap). A 0-wide
           frame makes fit() bail (availW===0) → scale stays 1 → graph renders at
           100% with the owners off-screen. width:100% + min-width:0 forces the
           frame to fill the row so fit() can measure it. */
        width: 100%;
        min-width: 0;
        border: 1px solid var(--rule-strong);
        border-radius: 0.5rem;
        background: var(--bg);
        color: var(--ink);
        font-family: var(--fb);
    }
    .mgraph-frame {
        position: relative;
        width: 100%;
        height: 520px;
        overflow: hidden;
        cursor: grab;
        border-radius: 0.5rem;
    }
    .mgraph-frame:active { cursor: grabbing; }
    .mgraph-canvas { position: absolute; top: 0; left: 0; will-change: transform; }
    /* Edges: one SVG (injected via x-html) laid over the node cards. The wrap is a
       zero-size anchor at the canvas origin so the SVG shares the node coordinate
       space; the SVG itself overflows freely and never intercepts pointer events. */
    .mgraph-edges-wrap { position: absolute; top: 0; left: 0; }
    .mgraph-edges { position: absolute; top: 0; left: 0; overflow: visible; pointer-events: none; }
    /* non-scaling-stroke keeps the hairline visible even when the canvas is scaled
       down to fit a large graph (which can drop the effective scale to ~0.2). */
    .mgraph-edge-line { fill: none; stroke: var(--rule-strong); stroke-width: 1; vector-effect: non-scaling-stroke; }
    .mgraph-edge-label {
        font-family: var(--fm); font-size: 10.5px; fill: var(--ink-2);
        text-anchor: middle; dominant-baseline: middle;
        paint-order: stroke; stroke: var(--bg); stroke-width: 4px;
        font-variant-numeric: tabular-nums;
    }

    .mgraph-node {
        position: absolute; box-sizing: border-box;
        display: flex; flex-direction: column; justify-content: center;
        gap: 3px; padding: 8px 12px;
        background: var(--bg); border: 1px solid var(--rule-strong);
        overflow: hidden;
    }
    .mgraph-node__name {
        font-family: var(--fd); font-weight: 600; font-size: 14px;
        letter-spacing: -0.01em; line-height: 1.2;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .mgraph-node__meta { display: flex; align-items: baseline; gap: 6px; }
    .mgraph-node__cvr {
        font-family: var(--fm); font-size: 11px; color: var(--ink-3);
        font-variant-numeric: tabular-nums;
    }
    .mgraph-node__usage {
        font-family: var(--fm); font-size: 11px; color: var(--ink-3);
    }
    /* fase 2a.2 enrichment: value aggregate (properties rolled up onto an
       owner node) + signal icons (negative equity / newly founded / no
       financials yet). Same mono/ink-3 treatment as the other meta rows —
       enrichment reads as data, not decoration. */
    .mgraph-node__agg {
        font-family: var(--fm); font-size: 10.5px; color: var(--ink-3);
        font-variant-numeric: tabular-nums;
    }
    .mgraph-node__signals { display: flex; gap: 4px; }
    .mgraph-signal {
        font-family: var(--fm); font-size: 11px; line-height: 1;
        cursor: default;
    }
    .mgraph-signal--negative_equity { color: #7a2f2f; }
    .mgraph-signal--newly_founded { color: var(--reel); }
    .mgraph-signal--no_financials { color: var(--ink-3); }
    /* kind accents — border + a small hue on the name, no fill stripe */
    .mgraph-node--reel    { border-color: var(--reel); }
    .mgraph-node--reel    .mgraph-node__name { color: var(--reel); }
    .mgraph-node--legal   { border-color: var(--legal); }
    /* 'other' = a capped/pruned parent surfaced as a stub ("CVR <nr>", no name).
       Dashed + dimmed so it reads as an incomplete link, not a confirmed owner. */
    .mgraph-node--other {
        border-style: dashed;
        border-color: var(--rule-strong);
        opacity: 0.72;
    }
    .mgraph-node--other .mgraph-node__name { color: var(--ink-3); font-style: italic; }
    .mgraph-node--foreign { border-color: var(--foreign); }
    .mgraph-node--foreign .mgraph-node__name { color: var(--foreign); }
    .mgraph-node--searched { background: var(--bg-2); border-color: var(--ink); border-width: 1.5px; }
    .mgraph-node--person {
        background: var(--person); border-color: var(--person);
    }
    .mgraph-node--person .mgraph-node__name { color: #f6efe3; }
    .mgraph-node--person .mgraph-node__cvr  { color: #cfc7bd; }
    /* subsidiary = same sand card as legal (both are Danish CVR-registered
       companies below the searched company; kind only differs for graph
       traversal direction, not visual treatment). */
    .mgraph-node--subsidiary { border-color: var(--legal); }
    .mgraph-node--subsidiary .mgraph-node__name { color: var(--legal); }
    /* property = a BBR/matrikel unit, not a company — dashed border like
       'other' (incomplete/derived data) but its own hue so it never reads
       as a pruned owner stub. */
    .mgraph-node--property { border-style: dashed; }
    .mgraph-node--property .mgraph-node__name { color: #8a6d1f; }

    .mgraph-node__expand {
        display: flex; flex-direction: column; gap: 2px;
        margin-top: 2px;
    }
    .mgraph-node__expand button {
        align-self: flex-start;
        padding: 1px 5px;
        background: var(--bg-2); border: 1px solid var(--rule-strong);
        font-family: var(--fm); font-size: 10px; color: var(--ink-2);
        cursor: pointer; line-height: 1.4;
    }
    .mgraph-node__expand button:hover { background: var(--bg); }
    .mgraph-node__expand button:disabled { cursor: default; opacity: 0.6; }

    .mgraph-controls {
        position: absolute; right: 12px; bottom: 12px;
        display: flex; flex-direction: column; gap: 4px;
    }
    .mgraph-controls button {
        min-width: 34px; height: 30px; padding: 0 6px;
        background: var(--bg-2); border: 1px solid var(--rule-strong);
        font-family: var(--fm); font-size: 12px; color: var(--ink);
        cursor: pointer; line-height: 1;
    }
    .mgraph-controls button:hover { background: var(--bg); }

    /* Singleton hover-card (fase 2a.2). Positioned absolutely within .mgraph
       (a sibling of .mgraph-frame, NOT .mgraph-canvas — it must never scale
       or pan with the zoomed graph, see the Blade comment above the
       x-if="card.node" template). Sand-card treatment matching the node
       cards; z-index lifts it above .mgraph-frame's own stacking context
       (frame has no z-index of its own, but the card must still win against
       edges/nodes drawn inside the frame at higher paint order). */
    .mgraph-card {
        position: absolute;
        z-index: 10;
        max-width: 280px;
        padding: 10px 12px;
        background: var(--bg);
        border: 1px solid var(--rule-strong);
        border-radius: 0.25rem;
        box-shadow: 0 4px 16px rgba(26, 26, 26, 0.18);
        pointer-events: auto;
    }
    .mgraph-card__title {
        font-family: var(--fd); font-weight: 600; font-size: 14px;
        letter-spacing: -0.01em; line-height: 1.25;
        margin-bottom: 6px;
    }
    .mgraph-card__img {
        display: block;
        width: 100%;
        height: auto;
        border: 1px solid var(--rule-strong);
        margin-bottom: 6px;
    }
    .mgraph-card__rows {
        display: flex; flex-direction: column; gap: 2px;
        margin: 0 0 6px;
    }
    .mgraph-card__rows > div {
        display: flex; justify-content: space-between; gap: 10px;
        font-family: var(--fm); font-size: 11px;
    }
    .mgraph-card__rows dt { color: var(--ink-3); margin: 0; }
    .mgraph-card__rows dd {
        color: var(--ink); margin: 0;
        text-align: right; font-variant-numeric: tabular-nums;
    }
    .mgraph-card__links {
        display: flex; flex-direction: column; gap: 2px;
    }
    .mgraph-card__link {
        font-family: var(--fm); font-size: 11px;
        color: var(--reel);
        text-decoration: none;
    }
    .mgraph-card__link:hover { text-decoration: underline; }

    .mgraph-note {
        margin-top: 8px;
        font-family: var(--fm);
        font-size: 11px;
        line-height: 1.4;
        color: var(--ink-3);
    }

    @media (prefers-reduced-motion: reduce) {
        .mgraph-canvas { will-change: auto; }
    }
</style>
