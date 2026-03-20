<div>
    <flux:card>
        <flux:heading size="lg" class="mb-4">{{ __('Management & Roles') }}</flux:heading>
        @php
            $currentRoles = collect($roles)->filter(fn ($r) => $r['is_current'] ?? false)->values();
            $historicalRoles = collect($roles)->filter(fn ($r) => ! ($r['is_current'] ?? false))->values();
            $showCurrent = $currentRoles->isNotEmpty();
        @endphp

        @if(count($roles) > 0)
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200 dark:border-zinc-700">
                            <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('Name') }}</th>
                            <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('Role') }}</th>
                            <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('Ownership') }}</th>
                            <th class="text-left py-2 font-medium text-zinc-500">{{ __('Since') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($currentRoles as $role)
                            @php
                                $name = $role['person_name'] ?? $role['parent_company_name'] ?? '-';
                                $companyCvr = $role['participant_cvr'] ?? $role['parent_company_cvr'] ?? null;
                            @endphp
                            <tr class="border-b border-zinc-100 dark:border-zinc-800">
                                <td class="py-2 pr-4">
                                    @if($companyCvr)
                                        <x-metis-link type="cvr" :query="$companyCvr" :label="$name" />
                                    @elseif($role['is_company'] ?? false)
                                        <x-metis-link type="cvr" :query="$name" :label="$name" />
                                    @else
                                        <x-metis-link type="person" :query="$name" :label="$name" />
                                    @endif
                                </td>
                                <td class="py-2 pr-4">{{ $role['role_label'] ?? $role['title'] ?? '-' }}</td>
                                <td class="py-2 pr-4">{{ ($role['ownership_share'] ?? null) ? $role['ownership_share'] . '%' : '-' }}</td>
                                <td class="py-2">{{ ($role['start_date'] ?? null) ? \Carbon\Carbon::parse($role['start_date'])->translatedFormat('M Y') : '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Historical roles: show if no current roles, or in collapsible section --}}
            @if($historicalRoles->isNotEmpty())
                <div class="mt-4 pt-4 border-t border-zinc-100 dark:border-zinc-800" x-data="{ open: {{ $showCurrent ? 'false' : 'true' }} }">
                    @if($showCurrent)
                        <div class="flex items-center justify-between cursor-pointer" @click="open = !open">
                            <div class="text-xs text-zinc-400 uppercase">{{ __('Historical Roles') }} ({{ $historicalRoles->count() }})</div>
                            <flux:icon name="chevron-down" class="size-4 text-zinc-400 transition-transform" ::class="open && 'rotate-180'" />
                        </div>
                    @else
                        <div class="text-xs text-zinc-400 uppercase mb-2">{{ __('Historical Roles') }}</div>
                    @endif
                    <div x-show="open" x-collapse>
                        <table class="w-full text-sm mt-2">
                            <tbody>
                                @foreach($historicalRoles as $role)
                                    @php
                                        $name = $role['person_name'] ?? $role['parent_company_name'] ?? '-';
                                        $companyCvr = $role['participant_cvr'] ?? $role['parent_company_cvr'] ?? null;
                                    @endphp
                                    <tr class="border-b border-zinc-100 dark:border-zinc-800 text-zinc-400">
                                        <td class="py-2 pr-4">
                                            @if($companyCvr)
                                                <x-metis-link type="cvr" :query="$companyCvr" :label="$name" />
                                            @elseif($role['is_company'] ?? false)
                                                <x-metis-link type="cvr" :query="$name" :label="$name" />
                                            @else
                                                <x-metis-link type="person" :query="$name" :label="$name" />
                                            @endif
                                        </td>
                                        <td class="py-2 pr-4">{{ $role['role_label'] ?? $role['title'] ?? '-' }}</td>
                                        <td class="py-2 pr-4">{{ ($role['ownership_share'] ?? null) ? $role['ownership_share'] . '%' : '-' }}</td>
                                        <td class="py-2">{{ ($role['start_date'] ?? null) ? \Carbon\Carbon::parse($role['start_date'])->translatedFormat('M Y') : '-' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        @else
            <p class="text-sm text-zinc-500">{{ __('No roles found.') }}</p>
        @endif
    </flux:card>
</div>
