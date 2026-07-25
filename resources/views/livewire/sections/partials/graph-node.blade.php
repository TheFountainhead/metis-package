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
    <div
        class="mgraph-node"
        :class="'mgraph-node--' + node.kind"
        :style="`left:${node.x}px; top:${node.y}px; width:${node.w}px; height:${node.h}px;`"
    >
        <div class="mgraph-node__name" x-text="node.label"></div>
        <div class="mgraph-node__meta" x-show="node.cvr" x-cloak>
            <span class="mgraph-node__cvr" x-text="node.cvr"></span>
        </div>
        {{-- Property meta rows: BFE + anvendelse (rows omitted when null) --}}
        <div class="mgraph-node__meta" x-show="node.kind === 'property'" x-cloak>
            <span class="mgraph-node__cvr" x-show="node.meta?.bfe" x-text="'BFE ' + node.meta.bfe"></span>
            <span class="mgraph-node__usage" x-show="node.meta?.usage" x-text="node.meta.usage"></span>
        </div>
        {{-- Expand affordances. @mousedown.stop so the frame's pan never starts;
             _expanding gives a per-node loading state until the watcher re-renders. --}}
        <div class="mgraph-node__expand" x-show="node.expand && (node.expand.relations || node.expand.properties)" x-cloak>
            <button type="button" x-show="node.expand?.relations" x-data="{busy:false}"
                    @mousedown.stop @click.stop="busy = true; $wire.expandNode('sub:' + node.cvr)"
                    :disabled="busy" x-text="busy ? '…' : ('↓ ' + node.expand.relations + ' relationer')"></button>
            <button type="button" x-show="node.expand?.properties" x-data="{busy:false}"
                    @mousedown.stop @click.stop="busy = true; $wire.expandNode('props:' + node.cvr)"
                    :disabled="busy" x-text="busy ? '…' : ('+ ' + node.expand.properties + ' ejendomme')"></button>
        </div>
    </div>
</template>
