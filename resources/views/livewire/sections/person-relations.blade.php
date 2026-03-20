<div>
    <flux:card>
        <flux:heading size="lg" class="mb-1">Relationer</flux:heading>
        <flux:subheading class="mb-4">Andre personer registreret i samme selskaber</flux:subheading>

        @if(count($relations) > 0)
            <div class="space-y-4">
                @foreach($relations as $person)
                    <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-4 {{ !$person['is_current'] ? 'opacity-60' : '' }}">
                        <div class="flex items-center gap-2 mb-3">
                            <div class="size-8 rounded-full bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center flex-shrink-0">
                                <flux:icon.user class="size-4 text-zinc-500" />
                            </div>
                            <flux:heading size="sm">{{ $person['name'] }}</flux:heading>
                            @if($person['is_current'])
                                <flux:badge size="sm" color="green">Aktiv</flux:badge>
                            @else
                                <flux:badge size="sm" color="zinc">Tidligere</flux:badge>
                            @endif
                            @php
                                $grouped = collect($person['companies'])->groupBy('company_name');
                            @endphp
                            <span class="text-xs text-zinc-400">{{ $grouped->count() }} fælles {{ $grouped->count() === 1 ? 'selskab' : 'selskaber' }}</span>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b border-zinc-200 dark:border-zinc-700">
                                        <th class="text-left py-1.5 pr-3 font-medium text-zinc-500 text-xs">Selskab</th>
                                        <th class="text-left py-1.5 pr-3 font-medium text-zinc-500 text-xs">Roller</th>
                                        <th class="text-right py-1.5 pr-3 font-medium text-zinc-500 text-xs">Ejerandel</th>
                                        <th class="text-left py-1.5 font-medium text-zinc-500 text-xs">Siden</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($grouped as $companyName => $roles)
                                        @php
                                            $firstRole = $roles->first();
                                            $maxShare = $roles->max('ownership_share');
                                            $earliestDate = $roles->pluck('start_date')->filter()->sort()->first();
                                            $anyCurrent = $roles->contains('is_current', true);
                                        @endphp
                                        <tr class="border-b border-zinc-100 dark:border-zinc-800 {{ !$anyCurrent ? 'opacity-50' : '' }}">
                                            <td class="py-1.5 pr-3">
                                                <x-metis-link type="cvr" :query="$firstRole['cvr']" :label="$companyName" />
                                            </td>
                                            <td class="py-1.5 pr-3">
                                                <div class="flex flex-wrap gap-1">
                                                    @foreach($roles->pluck('role_label')->unique() as $role)
                                                        <flux:badge size="sm">{{ $role }}</flux:badge>
                                                    @endforeach
                                                </div>
                                            </td>
                                            <td class="py-1.5 pr-3 text-right">
                                                {{ $maxShare ? number_format($maxShare, 0) . '%' : '—' }}
                                            </td>
                                            <td class="py-1.5 text-xs text-zinc-500">
                                                {{ $earliestDate ?? '—' }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <p class="text-sm text-zinc-500">Ingen relaterede personer fundet.</p>
        @endif
    </flux:card>
</div>
