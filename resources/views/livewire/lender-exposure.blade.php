<div>
    <div class="flex items-center justify-between py-2 mb-4 border-b">
        <div class="flex items-center gap-3">
            <h1 class="text-xl font-bold">{{ __('Långiver-eksponering') }}</h1>
            <span class="text-sm text-zinc-500">{{ __('Hvor har en långiver pant?') }}</span>
        </div>
        @php
            $backRoute = Route::has('metis.index') ? 'metis.index' : (Route::has('metis.home') ? 'metis.home' : null);
        @endphp
        @if($backRoute)
            <a href="{{ route($backRoute) }}"
               class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm border rounded-lg hover:bg-zinc-50 transition">
                ← {{ __('Tilbage') }}
            </a>
        @endif
    </div>

    <form wire:submit="search" class="mb-6">
        <label for="lender-cvr" class="block text-xs font-medium text-zinc-600 mb-1">
            {{ __('Långiverens CVR-nummer') }}
        </label>
        <div class="flex gap-2 max-w-md">
            <input id="lender-cvr" type="text" inputmode="numeric" maxlength="8"
                   wire:model="cvr" placeholder="35050027"
                   class="flex-1 px-3 py-2 text-sm border rounded-lg">
            {{-- wire:loading.attr disabled lukker kun vinduet EFTER request-start;
                 search() er derfor selv idempotent (reset foerst, ingen akkumulering). --}}
            <button type="submit"
                    wire:loading.attr="disabled" wire:target="search"
                    class="px-4 py-2 text-sm font-medium text-white bg-zinc-900 rounded-lg hover:bg-zinc-700 disabled:opacity-50 transition">
                <span wire:loading.remove wire:target="search">{{ __('Søg') }}</span>
                <span wire:loading wire:target="search">{{ __('Søger…') }}</span>
            </button>
        </div>
    </form>

    @if($errorMessage)
        <div class="p-4 mb-6 text-sm border rounded-lg border-amber-200 bg-amber-50 text-amber-900">
            {{ $errorMessage }}
        </div>
    @endif

    @if($exposure)
        @php
            $rows = $this->byProperty;
            $hasExposure = ($exposure['total_kr'] ?? 0) > 0;
        @endphp

        @if(! $hasExposure)
            {{-- "Ingen eksponering" er et gyldigt og meningsfuldt svar i en
                 kreditvurdering — ikke en fejl, og ikke en tom side. --}}
            <div class="p-6 text-sm border rounded-xl bg-white text-zinc-600">
                {{ __('Ingen tinglyst pant fundet for CVR :cvr.', ['cvr' => $exposure['cvr'] ?? '']) }}
            </div>
        @else
            <div class="mb-2">
                <h2 class="text-lg font-semibold">{{ $this->lenderLabel }}</h2>
                <p class="text-xs text-zinc-500">CVR {{ $exposure['cvr'] }}</p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-4">
                <div class="p-4 border rounded-xl bg-white">
                    <div class="text-xs uppercase tracking-wide text-zinc-500">{{ __('Samlet pant') }}</div>
                    <div class="mt-1 text-2xl font-bold tabular-nums">
                        {{ number_format($exposure['total_kr'], 0, ',', '.') }} kr.
                    </div>
                </div>
                <div class="p-4 border rounded-xl bg-white">
                    <div class="text-xs uppercase tracking-wide text-zinc-500">{{ __('Pantebreve') }}</div>
                    <div class="mt-1 text-2xl font-bold tabular-nums">{{ number_format($exposure['documents'], 0, ',', '.') }}</div>
                </div>
                <div class="p-4 border rounded-xl bg-white">
                    <div class="text-xs uppercase tracking-wide text-zinc-500">{{ __('Ejendomme') }}</div>
                    <div class="mt-1 text-2xl font-bold tabular-nums">{{ number_format($exposure['properties'], 0, ',', '.') }}</div>
                </div>
            </div>

            {{-- ⚠️ Forbeholdet staar SAMMEN med tallet, ikke i en fodnote. Summen
                 er hvad panthaveren staar ANFOERT for i Tinglysningen; de kan
                 optraede paa vegne af andre kreditorer. Uden det laeses tallet
                 som en paastand om deres balance. --}}
            @if($disclaimer = data_get($exposure, 'meta.disclaimer'))
                <p class="p-3 mb-6 text-xs border rounded-lg border-zinc-200 bg-zinc-50 text-zinc-600">
                    {{ $disclaimer }}
                    @if($source = data_get($exposure, 'meta.source'))
                        <span class="block mt-1 text-zinc-500">{{ __('Kilde') }}: {{ $source }}</span>
                    @endif
                </p>
            @endif

            <div class="overflow-x-auto border rounded-xl bg-white">
                <table class="w-full text-sm">
                    <thead class="text-xs uppercase tracking-wide text-zinc-500 border-b">
                        <tr>
                            <th class="px-4 py-2 text-left font-medium">{{ __('Ejendom') }}</th>
                            <th class="px-4 py-2 text-left font-medium">BFE</th>
                            <th class="px-4 py-2 text-right font-medium">{{ __('Pantebreve') }}</th>
                            <th class="px-4 py-2 text-right font-medium">{{ __('Beløb') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100">
                        @foreach($rows as $row)
                            <tr wire:key="exposure-{{ $row['bfe'] ?? $loop->index }}">
                                <td class="px-4 py-2">
                                    {{ $row['address'] ?? '—' }}
                                    @if($row['postal_code'] ?? null)
                                        <span class="text-zinc-500">, {{ $row['postal_code'] }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-zinc-500 tabular-nums">{{ $row['bfe'] ?? '—' }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $row['documents'] }}</td>
                                <td class="px-4 py-2 text-right tabular-nums font-medium">
                                    {{ number_format($row['amount_kr'], 0, ',', '.') }} kr.
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if($exposure['truncated'] ?? false)
                {{-- Summen ovenfor er ALTID fuld; kun raekkelisten er afkortet.
                     Uden denne linje ville de to se ud til at modsige hinanden. --}}
                <p class="mt-2 text-xs text-zinc-500">
                    {{ __('Listen er afkortet. Totalen ovenfor dækker alle pantebreve.') }}
                </p>
            @endif
        @endif
    @endif
</div>
