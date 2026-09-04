<div>
    <div class="flex items-center justify-between py-2 mb-4 border-b">
        <div class="flex items-center gap-3">
            <h1 class="text-xl font-bold">{{ __('Mine engagementer') }}</h1>
            <span class="text-sm text-zinc-500">{{ __('Hvor har jeg pant, og hvad er der sket siden?') }}</span>
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
        {{-- Siden findes kun for pilotbrugere med et bundet långiverselskab.
             Uden token vises ALDRIG en tom liste: tomhed ville læses som "ingen pant". --}}
        <div class="max-w-xl p-6 bg-white border rounded-xl">
            <h2 class="text-lg font-semibold mb-2">{{ __('Kun for pilotbrugere') }}</h2>
            <p class="text-sm text-zinc-600 mb-4">
                {{ __('Bekræft din arbejdsmail for at se de engagementer, dit selskab står med i Tinglysningen.') }}
            </p>
            <button type="button" wire:click="$dispatch('show-email-gate')"
                    class="px-4 py-2 text-sm font-medium text-white bg-zinc-900 rounded-lg hover:bg-zinc-700 transition">
                {{ __('Bekræft arbejdsmail') }}
            </button>
        </div>
    @elseif($unbound)
        <div class="max-w-xl p-6 text-sm border rounded-xl border-amber-200 bg-amber-50 text-amber-900">
            {{ __('Din bruger er ikke knyttet til et långiverselskab endnu. Skriv til os, så sætter vi det op.') }}
        </div>
    @elseif($errorMessage)
        <div class="max-w-xl p-6 text-sm border rounded-xl border-amber-200 bg-amber-50 text-amber-900">
            {{ $errorMessage }}
            <button type="button" wire:click="load" class="ml-2 underline">{{ __('Prøv igen') }}</button>
        </div>
    @elseif($rows !== null)
        @php
            $totals = $meta['totals'] ?? [];
            $measured = ! empty($meta['measured_at']) ? \Carbon\Carbon::parse($meta['measured_at'])->format('d.m.Y H:i') : null;
            $caveat = $meta['disclaimer'] ?? \TheFountainhead\Metis\Livewire\Engagements::CAVEAT_FALLBACK;
        @endphp

        <div class="mb-2">
            <h2 class="text-lg font-semibold">{{ $this->lenderLabel }}</h2>
            <p class="text-xs text-zinc-500">
                CVR {{ $meta['lender']['cvr'] ?? '' }}
                @if($measured) · {{ __('Målt') }} {{ $measured }} @endif
            </p>
        </div>

        @if($rows === [])
            {{-- Nul engagementer er et gyldigt svar, ikke en fejl og ikke en tom side. --}}
            <div class="p-6 text-sm border rounded-xl bg-white text-zinc-600">
                {{ __('Ingen tinglyst pant registreret for :navn pr. :dato.', ['navn' => $this->lenderLabel, 'dato' => $measured ?? '—']) }}
            </div>
        @else
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-4">
                <div class="p-4 border rounded-xl bg-white">
                    <div class="text-xs uppercase tracking-wide text-zinc-500">{{ __('Mit pant') }}</div>
                    <div class="mt-1 text-2xl font-bold tabular-nums">{{ number_format($totals['lender_kr'] ?? 0, 0, ',', '.') }} kr.</div>
                </div>
                <div class="p-4 border rounded-xl bg-white">
                    <div class="text-xs uppercase tracking-wide text-zinc-500">{{ __('Engagementer') }}</div>
                    <div class="mt-1 text-2xl font-bold tabular-nums">{{ $totals['engagements'] ?? count($rows) }}</div>
                </div>
                <div class="p-4 border rounded-xl bg-white">
                    <div class="text-xs uppercase tracking-wide text-zinc-500">{{ __('Ejendomme') }}</div>
                    <div class="mt-1 text-2xl font-bold tabular-nums">{{ $totals['properties'] ?? 0 }}</div>
                </div>
                <div class="p-4 border rounded-xl bg-white">
                    <div class="text-xs uppercase tracking-wide text-zinc-500">{{ __('Pantebreve') }}</div>
                    <div class="mt-1 text-2xl font-bold tabular-nums">{{ $totals['documents'] ?? 0 }}</div>
                </div>
            </div>

            <p class="p-3 mb-6 text-xs border rounded-lg border-zinc-200 bg-zinc-50 text-zinc-600">
                {{ $caveat }}
                @if($source = $meta['source'] ?? null)
                    <span class="block mt-1 text-zinc-500">{{ __('Kilde') }}: {{ $source }}</span>
                @endif
            </p>

            <div class="overflow-x-auto border rounded-xl bg-white">
                <table class="w-full text-sm">
                    <thead class="text-xs uppercase tracking-wide text-zinc-500 border-b">
                        <tr>
                            <th class="px-4 py-2 text-left font-medium">{{ __('Låntager') }}</th>
                            <th class="px-4 py-2 text-right font-medium">{{ __('Ejd.') }}</th>
                            <th class="px-4 py-2 text-right font-medium">
                                <button type="button" wire:click="sortBy('lender_kr')" class="hover:underline {{ $sort === 'lender_kr' ? 'text-zinc-900' : '' }}">{{ __('Mit pant') }}</button>
                            </th>
                            <th class="px-4 py-2 text-right font-medium">
                                <button type="button" wire:click="sortBy('worst_ahead_kr')" class="hover:underline {{ $sort === 'worst_ahead_kr' ? 'text-zinc-900' : '' }}">{{ __('Foran mig') }}</button>
                            </th>
                            <th class="px-4 py-2 text-right font-medium">
                                <button type="button" wire:click="sortBy('total_debt_kr')" class="hover:underline {{ $sort === 'total_debt_kr' ? 'text-zinc-900' : '' }}">{{ __('Samlet pant') }}</button>
                            </th>
                            <th class="px-4 py-2 text-left font-medium">
                                <button type="button" wire:click="sortBy('latest_change_at')" class="hover:underline {{ $sort === 'latest_change_at' ? 'text-zinc-900' : '' }}">{{ __('Seneste ændring') }}</button>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100">
                        @foreach($this->sorted as $row)
                            <tr wire:key="engagement-{{ md5($row['key']) }}" class="{{ ($row['has_changes_since_own'] ?? false) ? 'bg-amber-50/40' : '' }}">
                                <td class="px-4 py-2">
                                    <a href="{{ route('metis.engagement', ['key' => $row['key']]) }}" class="font-medium hover:underline">
                                        {{ \TheFountainhead\Metis\Livewire\Engagements::ownerLabel($row) }}
                                    </a>
                                    @if(in_array('owner_missing', $row['data_quality'] ?? [], true))
                                        <span class="ml-1 text-[10px] uppercase text-amber-700" title="{{ __('Ejerforholdet er ikke hentet endnu') }}">{{ __('ejer ikke hentet') }}</span>
                                    @endif
                                    <div class="text-xs text-zinc-500">
                                        {{ collect($row['properties'] ?? [])->pluck('address')->filter()->take(2)->implode('; ') }}
                                    </div>
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ count($row['properties'] ?? []) }}</td>
                                <td class="px-4 py-2 text-right tabular-nums font-medium">{{ number_format($row['lender_kr'] ?? 0, 0, ',', '.') }} kr.</td>
                                <td class="px-4 py-2 text-right tabular-nums {{ ($row['worst_ahead_kr'] ?? 0) > 0 ? 'text-zinc-900' : 'text-zinc-400' }}">
                                    @if(($row['worst_ahead_kr'] ?? null) === null)
                                        <span class="text-zinc-400">{{ __('ukendt') }}</span>
                                    @else
                                        {{ number_format($row['worst_ahead_kr'], 0, ',', '.') }} kr.
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ number_format($row['total_debt_kr'] ?? 0, 0, ',', '.') }} kr.</td>
                                <td class="px-4 py-2">
                                    @if($row['latest_change_at'] ?? null)
                                        <span class="inline-flex items-center gap-1 text-amber-800">
                                            {{ \Carbon\Carbon::parse($row['latest_change_at'])->format('d.m.Y') }}
                                        </span>
                                    @else
                                        <span class="text-zinc-400">{{ __('ingen') }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endif
</div>
