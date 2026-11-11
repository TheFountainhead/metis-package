<div>
    <div class="flex items-center justify-between py-2 mb-4 border-b">
        <div class="flex items-center gap-3">
            <h1 class="text-xl font-bold">{{ __('Overvågede selskaber') }}</h1>
            <span class="text-sm text-zinc-500">{{ __('Selskaber du følger, med deres ejendomme') }}</span>
        </div>
        @php
            $backRoute = Route::has('metis.index') ? 'metis.index' : (Route::has('metis.home') ? 'metis.home' : null);
        @endphp
        @if($backRoute)
            <a href="{{ route($backRoute) }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm border rounded-lg hover:bg-zinc-50 transition">
                ← {{ __('Tilbage') }}
            </a>
        @endif
    </div>

    @if(! $this->hasUserToken())
        {{-- Uden pilot vises ALDRIG en tom liste: tomhed ville læses som "du følger ingen". --}}
        <div class="max-w-xl p-6 bg-white border rounded-xl">
            <h2 class="text-lg font-semibold mb-2">{{ __('Kun for pilotbrugere') }}</h2>
            <p class="text-sm text-zinc-600 mb-4">
                {{ __('Bekræft din arbejdsmail for at se de selskaber, du følger.') }}
            </p>
            <button type="button" wire:click="$dispatch('show-email-gate')"
                    class="px-4 py-2 text-sm font-medium text-white bg-zinc-900 rounded-lg hover:bg-zinc-700 transition">
                {{ __('Bekræft arbejdsmail') }}
            </button>
        </div>
    @elseif($errorMessage)
        <div class="max-w-xl p-6 text-sm border rounded-xl border-amber-200 bg-amber-50 text-amber-900">
            {{ $errorMessage }}
            <button type="button" wire:click="load" class="ml-2 underline">{{ __('Prøv igen') }}</button>
        </div>
    @elseif($rows === [])
        <div class="max-w-xl p-6 text-sm border rounded-xl bg-white text-zinc-600">
            {{ __('Du følger ingen selskaber endnu. Klik "Følg" på et selskabs side for at tilføje det her.') }}
        </div>
    @elseif($rows !== null)
        <p class="mb-3 text-sm text-zinc-600">
            {{ trans_choice(':n selskab|:n selskaber', count($rows), ['n' => count($rows)]) }}
            · {{ __('Ejendomme tæller kun nuværende ejerskab og ikke ejendomme, der er nedlagt i Matriklen.') }}
        </p>
        <div class="overflow-x-auto bg-white border rounded-xl">
            <table class="w-full text-sm">
                <thead class="text-left text-xs uppercase text-zinc-500 border-b">
                    <tr>
                        <th class="px-4 py-2">{{ __('Selskab') }}</th>
                        <th class="px-4 py-2">{{ __('CVR') }}</th>
                        <th class="px-4 py-2">{{ __('Status') }}</th>
                        <th class="px-4 py-2 text-right">{{ __('Ejendomme direkte') }}</th>
                        <th class="px-4 py-2 text-right">{{ __('Ejendomme i koncernen') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $row)
                        <tr class="border-b last:border-0" wire:key="watched-{{ $row['watchlist_id'] ?? $row['cvr'] }}">
                            <td class="px-4 py-2">
                                <x-metis-link type="cvr" :query="$row['cvr'] ?? ''" class="font-medium hover:underline">
                                    {{ filled($row['name'] ?? null) ? \TheFountainhead\Metis\Services\LegalName::format((string) $row['name']) : __('Ukendt selskab') }}
                                </x-metis-link>
                            </td>
                            <td class="px-4 py-2 text-zinc-600 tabular-nums">{{ $row['cvr'] ?? '—' }}</td>
                            <td class="px-4 py-2">
                                @php $status = $row['status'] ?? null; @endphp
                                @if($status === null)
                                    <span class="text-zinc-400">—</span>
                                @elseif(strtoupper($status) === 'NORMAL')
                                    <span class="text-zinc-600">{{ __('Aktiv') }}</span>
                                @else
                                    <span class="px-1.5 py-0.5 text-xs rounded bg-amber-100 text-amber-900">{{ $status }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ number_format((int) ($row['properties_direct'] ?? 0), 0, ',', '.') }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ number_format((int) ($row['properties_group'] ?? 0), 0, ',', '.') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
