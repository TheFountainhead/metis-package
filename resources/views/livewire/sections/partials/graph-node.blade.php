{{-- One positioned node in the ownership graph.

     Positioned absolutely by dagre (x/y/w/h come from the Alpine layout()).
     Kind-driven styling: person (dark, Resights-like), foreign (oxblood),
     reel/legal/other (sand card), searched (bg-2 + thick ink border). NO
     left-stripe border — that AI-tell is banned (see spec §Node).

     `node` is an Alpine object from ownershipGraphData(); every text field is
     bound via x-text so Alpine escapes it (never interpolated as raw HTML). --}}
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
    </div>
</template>
