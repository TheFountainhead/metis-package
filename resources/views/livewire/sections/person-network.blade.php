<div>
    @if(count($ownershipTree) > 0 || count($ownershipStandalone) > 0 || count($boardPositions) > 0)

        {{-- SECTION 1: Ownership Structure --}}
        @if(count($ownershipTree) > 0 || count($ownershipStandalone) > 0)
            <flux:card class="mb-4">
                <flux:heading size="lg" class="mb-1">Ejerstruktur</flux:heading>
                <flux:subheading class="mb-4">Selskaber med ejerandel</flux:subheading>

                {{-- Org chart trees --}}
                @if(count($ownershipTree) > 0)
                    @php
                        // Count total nodes to determine sizing
                        $countNodes = null;
                        $countNodes = function($nodes) use (&$countNodes) {
                            $count = count($nodes);
                            foreach ($nodes as $node) {
                                $count += $countNodes($node['children'] ?? $node['subsidiaries'] ?? []);
                            }
                            return $count;
                        };
                        $totalNodes = 0;
                        foreach ($ownershipTree as $tree) {
                            $totalNodes += 1 + count($tree['owners'] ?? []) + $countNodes($tree['subsidiaries'] ?? []);
                        }
                        // Sizing: compact for large trees, normal for small
                        $treeSize = $totalNodes > 10 ? 'compact' : ($totalNodes > 5 ? 'medium' : 'normal');
                    @endphp

                    <div class="space-y-8 overflow-x-auto pb-4">
                        @foreach($ownershipTree as $parent)
                            <div class="flex flex-col items-center min-w-fit">
                                {{-- Owners above --}}
                                @if(count($parent['owners']) > 0)
                                    <div class="flex justify-center gap-3 flex-wrap mb-0">
                                        @foreach($parent['owners'] as $owner)
                                            <div class="flex flex-col items-center">
                                                <div class="rounded border-2 border-zinc-300 dark:border-zinc-600 bg-zinc-50 dark:bg-zinc-800 {{ $treeSize === 'compact' ? 'px-2 py-1' : 'px-3 py-2' }} text-center {{ $treeSize === 'compact' ? 'min-w-[80px] max-w-[120px]' : 'min-w-[120px] max-w-[180px]' }}">
                                                    <div class="{{ $treeSize === 'compact' ? 'text-[10px]' : 'text-xs' }} font-semibold truncate">{{ $owner['person_name'] }}</div>
                                                </div>
                                                <div class="relative flex flex-col items-center">
                                                    <div class="w-px {{ ($owner['ownership_share'] ?? null) ? 'h-7' : 'h-3' }} bg-zinc-300 dark:bg-zinc-600"></div>
                                                    @if($owner['ownership_share'] ?? null)
                                                        <div class="absolute top-0 left-1/2 -translate-x-1/2 {{ $treeSize === 'compact' ? 'px-2 py-0.5 text-xs' : 'px-2.5 py-0.5 text-sm' }} font-bold text-sky-700 dark:text-sky-300 bg-sky-100 dark:bg-sky-900/40 rounded-full border border-sky-300 dark:border-sky-700 whitespace-nowrap z-10">{{ number_format($owner['ownership_share'], 0) }}%</div>
                                                    @endif
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>

                                    @if(count($parent['owners']) > 1)
                                        <div class="h-px bg-zinc-300 dark:bg-zinc-600 mb-0" style="width: {{ min(count($parent['owners']) * 120, 400) }}px; max-width: 90%;"></div>
                                    @endif
                                    <div class="w-px h-3 bg-zinc-300 dark:bg-zinc-600"></div>
                                @endif

                                {{-- Parent company --}}
                                <div class="rounded border-2 border-sky-400 dark:border-sky-600 bg-sky-50 dark:bg-sky-900/20 {{ $treeSize === 'compact' ? 'px-2 py-1.5' : 'px-4 py-2.5' }} text-center {{ $treeSize === 'compact' ? 'min-w-[100px] max-w-[160px]' : 'min-w-[140px] max-w-[220px]' }}">
                                    <x-metis-link type="cvr" :query="$parent['cvr']" :label="$parent['name']" class="font-semibold {{ $treeSize === 'compact' ? 'text-[9px]' : 'text-xs' }} break-words" />
                                    @if($parent['company_type'])
                                        <div class="{{ $treeSize === 'compact' ? 'text-[9px]' : 'text-[10px]' }} text-zinc-400 mt-0.5">{{ $parent['company_type'] }}</div>
                                    @endif
                                </div>

                                {{-- Subsidiaries below (recursive tree) --}}
                                @if(count($parent['subsidiaries']) > 0)
                                    <div class="w-px h-3 bg-zinc-300 dark:bg-zinc-600"></div>

                                    <div class="flex">
                                        @foreach($parent['subsidiaries'] as $sub)
                                            @include('metis::livewire.sections.partials.subsidiary-node', [
                                                'node' => $sub,
                                                'treeSize' => $treeSize,
                                                'isFirst' => $loop->first,
                                                'isLast' => $loop->last,
                                                'siblingCount' => $loop->count,
                                            ])
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif

                {{-- Standalone ownership companies (no tree structure) --}}
                @if(count($ownershipStandalone) > 0)
                    <div class="{{ count($ownershipTree) > 0 ? 'mt-6 pt-4 border-t border-zinc-200 dark:border-zinc-700' : '' }}">
                        @if(count($ownershipTree) > 0)
                            <div class="text-xs text-zinc-400 uppercase mb-3">Øvrige ejerselskaber</div>
                        @endif
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
                            @foreach($ownershipStandalone as $co)
                                <div class="flex items-center gap-3 text-sm bg-zinc-50 dark:bg-zinc-800 rounded-lg px-4 py-3 border border-zinc-200 dark:border-zinc-700">
                                    <div class="size-8 rounded-lg bg-white dark:bg-zinc-700 border border-zinc-200 dark:border-zinc-600 flex items-center justify-center flex-shrink-0">
                                        <flux:icon.building-office-2 class="size-4 text-zinc-400" />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <x-metis-link type="cvr" :query="$co['cvr']" :label="$co['name']" />
                                        <div class="flex items-center gap-2 mt-0.5">
                                            @if($co['ownership_share'])
                                                <span class="text-xs font-medium text-sky-600 dark:text-sky-400">{{ number_format($co['ownership_share'], 0) }}%</span>
                                            @endif
                                            @if($co['company_type'])
                                                <span class="text-xs text-zinc-400">{{ $co['company_type'] }}</span>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </flux:card>
        @endif

        {{-- SECTION 2: Board & Management Positions --}}
        @if(count($boardPositions) > 0)
            <flux:card>
                <flux:heading size="lg" class="mb-1">Bestyrelser & ledelse</flux:heading>
                <flux:subheading class="mb-4">Selskaber uden direkte ejerandel</flux:subheading>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-zinc-200 dark:border-zinc-700">
                                <th class="text-left py-2 pr-4 font-medium text-zinc-500">Selskab</th>
                                <th class="text-left py-2 pr-4 font-medium text-zinc-500">Rolle</th>
                                <th class="text-left py-2 pr-4 font-medium text-zinc-500">Type</th>
                                <th class="text-left py-2 font-medium text-zinc-500">Siden</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($boardPositions as $pos)
                                <tr class="border-b border-zinc-100 dark:border-zinc-800">
                                    <td class="py-2 pr-4">
                                        <x-metis-link type="cvr" :query="$pos['cvr']" :label="$pos['name']" />
                                    </td>
                                    <td class="py-2 pr-4">
                                        <flux:badge size="sm">{{ $pos['role'] }}</flux:badge>
                                    </td>
                                    <td class="py-2 pr-4 text-zinc-400">{{ $pos['company_type'] ?? '-' }}</td>
                                    <td class="py-2 text-zinc-500">
                                        {{ $pos['start_date'] ? \Carbon\Carbon::parse($pos['start_date'])->format('Y') : '-' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </flux:card>
        @endif

    @else
        <flux:card>
            <flux:heading size="lg" class="mb-4">Selskabsstruktur</flux:heading>
            <p class="text-sm text-zinc-500">Ingen aktive selskaber fundet.</p>
        </flux:card>
    @endif
</div>
