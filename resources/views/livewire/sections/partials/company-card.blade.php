@php
    $roles = $company['roles'] ?? [];
    $currentRoles = collect($roles)->where('is_current', true);
    $pastRoles = collect($roles)->where('is_current', false);
    $ownershipRole = $currentRoles->first(fn ($r) => !empty($r['ownership_share']));
    $ownershipPct = $ownershipRole['ownership_share'] ?? null;

    // For historical: show the roles they had and when they ended
    $displayRoles = $isHistorical ? $pastRoles : $currentRoles;

    $fin = $company['financials'] ?? null;
    $latestFinancials = is_array($fin) && array_is_list($fin) ? ($fin[0] ?? null) : $fin;
    $equity = $latestFinancials['equity'] ?? null;
    $assets = $latestFinancials['assets'] ?? null;
    $profitLoss = $latestFinancials['profit_loss'] ?? null;
    $year = $latestFinancials['year'] ?? null;
@endphp
<div class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-4 {{ $isHistorical ? 'opacity-60' : '' }}">
    <div class="flex items-start justify-between gap-4">
        <div class="min-w-0">
            <div class="flex items-center gap-2 flex-wrap">
                <x-metis-link type="cvr" :query="$company['cvr']" :label="$company['name'] ?? $company['cvr']" class="font-semibold" />
                @if($isHistorical)
                    <flux:badge color="zinc" size="sm">{{ $company['status'] ?? 'Inaktiv' }}</flux:badge>
                @endif
            </div>
            <div class="flex items-center gap-2 mt-1 text-xs text-zinc-500">
                <span>CVR {{ $company['cvr'] }}</span>
                @if($company['company_type'] ?? null)
                    <span>&middot;</span>
                    <span>{{ $company['company_type'] }}</span>
                @endif
            </div>
        </div>
        @if($ownershipPct && !$isHistorical)
            <div class="text-right flex-shrink-0">
                <div class="text-lg font-semibold">{{ number_format($ownershipPct, 0) }}%</div>
                <div class="text-xs text-zinc-400">Ejerandel</div>
            </div>
        @endif
    </div>

    {{-- Roles --}}
    @if($displayRoles->count() > 0)
        <div class="flex flex-wrap gap-1.5 mt-3">
            @foreach($displayRoles as $role)
                <flux:badge size="sm" color="{{ $isHistorical ? 'zinc' : 'zinc' }}">
                    {{ $role['title'] ?? $role['role'] ?? '-' }}
                    @if($isHistorical && !empty($role['end_date']))
                        <span class="text-zinc-400 ml-1">til {{ \Carbon\Carbon::parse($role['end_date'])->format('Y') }}</span>
                    @elseif(!$isHistorical && !empty($role['start_date']))
                        <span class="text-zinc-400 ml-1">siden {{ \Carbon\Carbon::parse($role['start_date'])->format('Y') }}</span>
                    @endif
                </flux:badge>
            @endforeach
        </div>
    @endif

    {{-- Financials (only for active) --}}
    @if(!$isHistorical && $latestFinancials && ($equity !== null || $assets !== null || $profitLoss !== null))
        <div class="grid grid-cols-3 gap-4 mt-3 pt-3 border-t border-zinc-100 dark:border-zinc-800">
            @if($equity !== null)
                <div>
                    <div class="text-xs text-zinc-400">Egenkapital ({{ $year }})</div>
                    <div class="font-medium {{ $equity < 0 ? 'text-red-600' : '' }}">
                        {{ number_format($equity / 100, 0, ',', '.') }} kr.
                    </div>
                </div>
            @endif
            @if($assets !== null)
                <div>
                    <div class="text-xs text-zinc-400">Aktiver</div>
                    <div class="font-medium">{{ number_format($assets / 100, 0, ',', '.') }} kr.</div>
                </div>
            @endif
            @if($profitLoss !== null)
                <div>
                    <div class="text-xs text-zinc-400">Resultat</div>
                    <div class="font-medium {{ $profitLoss < 0 ? 'text-red-600' : 'text-green-600' }}">
                        {{ number_format($profitLoss / 100, 0, ',', '.') }} kr.
                    </div>
                </div>
            @endif
        </div>
    @endif
</div>
