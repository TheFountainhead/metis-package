<div x-data x-on:debt-search:download.window="window.location.href = $event.detail.url">
    <div class="flex items-center justify-between py-2 mb-4 border-b">
        <div class="flex items-center gap-3">
            <h1 class="text-xl font-bold">{{ __('Søg gæld') }}</h1>
            <span class="text-sm text-zinc-500">{{ __('Spørg Metis om ejendomme efter gælds-kriterier') }}</span>
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

    <div class="grid grid-cols-1 lg:grid-cols-[20rem_1fr] gap-6">
        <aside class="space-y-4">
            <div class="p-4 border rounded-lg bg-white dark:bg-zinc-800 dark:border-zinc-700 space-y-4">
                <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-200 uppercase tracking-wide">{{ __('Filtre') }}</h2>

                <div>
                    <label class="block text-xs font-medium text-zinc-600 mb-1">{{ __('Rente (%)') }}</label>
                    <div class="flex gap-2">
                        <input type="number" step="0.1" min="0" max="100"
                               wire:model.live.debounce.500ms="minRate"
                               class="w-1/2 px-2 py-1 text-sm border rounded">
                        <input type="number" step="0.1" min="0" max="100"
                               wire:model.live.debounce.500ms="maxRate"
                               class="w-1/2 px-2 py-1 text-sm border rounded">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-medium text-zinc-600 mb-1">{{ __('Ejer') }}</label>
                    <select wire:model.live="ownerType" class="w-full px-2 py-1 text-sm border rounded">
                        <option value="company">{{ __('Selskab') }}</option>
                        <option value="any">{{ __('Alle (kræver tilladelse)') }}</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-medium text-zinc-600 mb-1">{{ __('Gælds-type') }}</label>
                    <select wire:model.live="debtType" class="w-full px-2 py-1 text-sm border rounded">
                        <option value="">{{ __('Alle typer') }}</option>
                        <option value="realkreditpantebrev">{{ __('Realkreditpantebrev') }}</option>
                        <option value="ejerpantebrev">{{ __('Ejerpantebrev') }}</option>
                        <option value="privatPantebrev">{{ __('Privat pantebrev') }}</option>
                        <option value="skadesloesbrev">{{ __('Skadesløsbrev') }}</option>
                        <option value="udlaeg">{{ __('Udlæg') }}</option>
                        <option value="anden">{{ __('Anden') }}</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-medium text-zinc-600 mb-1">{{ __('Postnummer (fra/til)') }}</label>
                    <div class="flex gap-2">
                        <input type="text" maxlength="4" pattern="\d{4}"
                               wire:model.live.debounce.500ms="postalCodeFrom"
                               placeholder="fra" class="w-1/2 px-2 py-1 text-sm border rounded">
                        <input type="text" maxlength="4" pattern="\d{4}"
                               wire:model.live.debounce.500ms="postalCodeTo"
                               placeholder="til" class="w-1/2 px-2 py-1 text-sm border rounded">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-medium text-zinc-600 mb-1">{{ __('Gæld (kr)') }}</label>
                    {{-- Rasmus-feedback 2026-05-25: vis tusind-separator mens bruger skriver.
                         Display-input bærer formateret tekst; hidden number-input synker
                         raw integer til Livewire (max 16 cifre = ~10 billiarder). --}}
                    <div class="flex gap-2"
                         x-data="{
                             format(v) {
                                 const digits = String(v ?? '').replace(/\D/g, '');
                                 if (digits === '') return '';
                                 return Number(digits).toLocaleString('da-DK');
                             },
                             parse(v) {
                                 const digits = String(v ?? '').replace(/\D/g, '').slice(0, 16);
                                 return digits === '' ? null : Number(digits);
                             },
                         }">
                        <input type="text" inputmode="numeric"
                               x-data="{ display: $wire.minAmount === null ? '' : Number($wire.minAmount).toLocaleString('da-DK') }"
                               x-model="display"
                               @input="display = format($event.target.value); $wire.minAmount = parse(display)"
                               placeholder="min" class="w-1/2 px-2 py-1 text-sm border rounded">
                        <input type="text" inputmode="numeric"
                               x-data="{ display: $wire.maxAmount === null ? '' : Number($wire.maxAmount).toLocaleString('da-DK') }"
                               x-model="display"
                               @input="display = format($event.target.value); $wire.maxAmount = parse(display)"
                               placeholder="max" class="w-1/2 px-2 py-1 text-sm border rounded">
                    </div>
                </div>

                <div>
                    {{-- Rasmus-feedback 2026-05-25: "meget vigtig" — alder af pant
                         er proxy for built-up equity og refinansieringsvinduer. --}}
                    <label class="block text-xs font-medium text-zinc-600 mb-1">{{ __('Gæld oprettet (dato)') }}</label>
                    <div class="flex gap-2">
                        <input type="date"
                               wire:model.live.debounce.500ms="registeredFrom"
                               class="w-1/2 px-2 py-1 text-sm border rounded"
                               aria-label="{{ __('Fra-dato') }}">
                        <input type="date"
                               wire:model.live.debounce.500ms="registeredTo"
                               class="w-1/2 px-2 py-1 text-sm border rounded"
                               aria-label="{{ __('Til-dato') }}">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-medium text-zinc-600 mb-1">{{ __('Kreditor indeholder') }}</label>
                    <input type="text" minlength="3" maxlength="100"
                           wire:model.live.debounce.500ms="creditorContains"
                           placeholder="{{ __('min. 3 tegn') }}"
                           class="w-full px-2 py-1 text-sm border rounded">
                </div>

                <label class="flex items-start gap-2 text-xs text-zinc-600 dark:text-zinc-300 cursor-pointer">
                    <input type="checkbox" wire:model.live="hideNominalRates" class="mt-0.5">
                    <span>
                        {{ __('Skjul rammerenter') }}
                        <span class="block text-[11px] text-zinc-400 mt-0.5">{{ __('Ejerpantebreve med pålydende 10/15/20 % er juridiske rammer, sjældent den faktiske rente.') }}</span>
                    </span>
                </label>

                @if($hasSearched)
                    <button type="button" wire:click="resetFilters"
                            class="w-full px-3 py-1.5 text-xs border rounded hover:bg-zinc-50">
                        {{ __('Nulstil filtre') }}
                    </button>
                @endif
            </div>

            <div class="px-4 py-3 text-xs text-zinc-500 bg-zinc-50 dark:bg-zinc-800 rounded-lg border dark:border-zinc-700">
                {{ __('Dækker over halvdelen af landets ejendomme, og dækningen vokser dagligt. Resultater er ikke udtømmende.') }}
            </div>
        </aside>

        <main class="space-y-6">
            @if($loading)
                <div class="p-12 text-center text-zinc-500" wire:loading.delay.300ms>
                    <div class="inline-block w-6 h-6 border-2 border-zinc-300 border-t-zinc-600 rounded-full animate-spin"></div>
                    <p class="mt-2 text-sm">{{ __('Søger...') }}</p>
                </div>
            @endif

            @if(! $hasSearched && ! $loading)
                <div class="p-12 text-center bg-white dark:bg-zinc-800 border rounded-lg dark:border-zinc-700">
                    <h2 class="text-lg font-semibold mb-2">{{ __('Hvad leder du efter?') }}</h2>
                    <p class="text-sm text-zinc-600 mb-4">{{ __('Justér filtrene til venstre, eller prøv et eksempel:') }}</p>
                    <ul class="text-sm text-zinc-700 space-y-1">
                        <li>{{ __('Realkreditpantebreve over 5 mio kr i 2000 Frederiksberg') }}</li>
                        <li>{{ __('Ejerpantebreve med rente over 15%') }}</li>
                        <li>{{ __('Lån fra HKL Pantebrevs Invest') }}</li>
                    </ul>
                </div>
            @endif

            @if($error)
                <div class="p-4 bg-red-50 border border-red-200 rounded-lg text-red-700 dark:bg-red-900/30 dark:border-red-800 dark:text-red-300">
                    {{ $error }}
                    <button wire:click="search" class="ml-2 text-xs underline">{{ __('Prøv igen') }}</button>
                </div>
            @endif

            @if($quotaExceeded)
                <div class="p-4 bg-amber-50 border border-amber-200 rounded-lg text-amber-700 dark:bg-amber-900/30 dark:border-amber-800 dark:text-amber-300">
                    {{ __('Du har nået dagens søgekvote. Tilbagestilles ved midnat.') }}
                </div>
            @endif

            @if($response && ! $loading && ! $error)
                @php
                    $summary = $response['summary'] ?? [];
                    $creditors = $response['creditors'] ?? [];
                    $results = $response['results'] ?? [];
                    $pagination = $response['pagination'] ?? [];
                @endphp

                @if(($summary['n_loans'] ?? 0) === 0)
                    <div class="p-8 text-center bg-white dark:bg-zinc-800 border rounded-lg dark:border-zinc-700">
                        <p class="text-sm text-zinc-600">{{ __('Ingen lån matcher dine filtre.') }}</p>
                        <button wire:click="resetFilters" class="mt-2 text-xs underline">{{ __('Nulstil filtre') }}</button>
                    </div>
                @else
                    @php
                        // Store sums som mia når over 1.000 mio, ellers mio, så
                        // en rådgiver kan læse tallet i stedet for at tælle cifre.
                        $totalKr = (int) ($summary['total_principal_kr'] ?? 0);
                        if ($totalKr >= 1_000_000_000) {
                            $debtValue = number_format($totalKr / 1_000_000_000, 1, ',', '.');
                            $debtUnit = 'mia';
                        } else {
                            $debtValue = number_format($totalKr / 1_000_000, 1, ',', '.');
                            $debtUnit = 'mio';
                        }
                    @endphp
                    <section class="grid grid-cols-2 md:grid-cols-4 gap-3">
                        <div class="p-4 bg-white dark:bg-zinc-800 border rounded-lg dark:border-zinc-700">
                            <p class="text-xs text-zinc-500">{{ __('Lån') }}</p>
                            <p class="text-xl font-semibold tabular-nums">{{ number_format($summary['n_loans'] ?? 0, 0, ',', '.') }}</p>
                        </div>
                        <div class="p-4 bg-white dark:bg-zinc-800 border rounded-lg dark:border-zinc-700">
                            <p class="text-xs text-zinc-500">{{ __('Ejendomme') }}</p>
                            <p class="text-xl font-semibold tabular-nums">{{ number_format($summary['n_properties'] ?? 0, 0, ',', '.') }}</p>
                        </div>
                        <div class="p-4 bg-white dark:bg-zinc-800 border rounded-lg dark:border-zinc-700">
                            <p class="text-xs text-zinc-500">{{ __('Selskaber') }}</p>
                            <p class="text-xl font-semibold tabular-nums">{{ number_format($summary['n_companies'] ?? 0, 0, ',', '.') }}</p>
                        </div>
                        <div class="p-4 bg-white dark:bg-zinc-800 border rounded-lg dark:border-zinc-700">
                            <p class="text-xs text-zinc-500">{{ __('Tinglyst gæld') }}</p>
                            <p class="text-xl font-semibold tabular-nums whitespace-nowrap">{{ $debtValue }} <span class="text-sm font-normal text-zinc-500">{{ $debtUnit }}</span></p>
                        </div>
                    </section>

                    @if(count($creditors) > 0)
                        <section class="bg-white dark:bg-zinc-800 border rounded-lg dark:border-zinc-700 overflow-hidden">
                            <div class="px-4 py-2 border-b dark:border-zinc-700 flex justify-between items-center">
                                <h3 class="text-sm font-semibold">{{ __('Top kreditorer') }}</h3>
                                <span class="inline-flex items-center gap-1.5 text-xs px-3 py-1 rounded border border-dashed border-zinc-300 text-zinc-400 dark:border-zinc-600 dark:text-zinc-500 cursor-default"
                                      title="{{ __('Eksport af leads til regneark kommer som en del af en kommende Pro-plan') }}">
                                    {{ __('Eksportér CSV') }}
                                    <span class="text-[10px] uppercase tracking-wide font-medium px-1.5 py-0.5 rounded bg-zinc-100 text-zinc-500 dark:bg-zinc-700 dark:text-zinc-300">{{ __('kommer snart') }}</span>
                                </span>
                            </div>
                            <table class="w-full text-sm">
                                <thead class="text-xs text-zinc-500 bg-zinc-50 dark:bg-zinc-900">
                                    <tr>
                                        <th class="text-left px-4 py-2">{{ __('Kreditor') }}</th>
                                        <th class="text-right px-4 py-2">{{ __('Antal') }}</th>
                                        <th class="text-right px-4 py-2">{{ __('Snit-rente') }}</th>
                                        <th class="text-right px-4 py-2">{{ __('Samlet gæld') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($creditors as $c)
                                        <tr class="border-t dark:border-zinc-700">
                                            <td class="px-4 py-2">{{ $c['creditor'] ?? '' }}</td>
                                            <td class="px-4 py-2 text-right tabular-nums">{{ number_format($c['n_loans'] ?? 0, 0, ',', '.') }}</td>
                                            <td class="px-4 py-2 text-right tabular-nums">{{ number_format($c['avg_rate'] ?? 0, 2, ',', '.') }}%</td>
                                            <td class="px-4 py-2 text-right tabular-nums">{{ number_format($c['total_principal_kr'] ?? 0, 0, ',', '.') }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </section>
                    @endif

                    @if(count($results) > 0)
                        <section class="bg-white dark:bg-zinc-800 border rounded-lg dark:border-zinc-700 overflow-hidden">
                            <div class="px-4 py-2 border-b dark:border-zinc-700 flex items-center justify-between gap-3 flex-wrap">
                                <h3 class="text-sm font-semibold">{{ __('Adresser') }}</h3>
                                <div class="flex items-center gap-1.5 text-xs">
                                    <span class="text-zinc-500 mr-0.5">{{ __('Sortér') }}:</span>
                                    @foreach(['rate' => __('Rente'), 'amount' => __('Beløb'), 'date' => __('Dato')] as $col => $label)
                                        @php $active = str_starts_with($sort, $col.'_'); @endphp
                                        <button type="button" wire:click="sortBy('{{ $col }}')"
                                                class="px-2 py-1 rounded border {{ $active ? 'bg-zinc-800 text-white border-zinc-800 dark:bg-zinc-100 dark:text-zinc-900' : 'border-zinc-300 text-zinc-600 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-300' }}">
                                            {{ $label }}@if($active) {{ str_ends_with($sort, '_desc') ? '↓' : '↑' }}@endif
                                        </button>
                                    @endforeach
                                </div>
                            </div>
                            <ul class="divide-y dark:divide-zinc-700">
                                @foreach($results as $r)
                                    @php
                                        $addr = trim(($r['property']['address'] ?? '').', '.($r['property']['postal_code'] ?? ''), ', ');
                                        $detailUrl = \TheFountainhead\Metis\View\Components\MetisLink::urlFor('address', $addr);
                                    @endphp
                                    <li class="px-4 py-3 hover:bg-zinc-50 dark:hover:bg-zinc-700/30 transition">
                                        <div class="flex justify-between items-start gap-4">
                                            <div class="flex-1 min-w-0">
                                                @if($detailUrl)
                                                    <a href="{{ $detailUrl }}" class="font-medium text-blue-600 hover:text-blue-800 hover:underline dark:text-blue-400">
                                                        {{ $r['property']['address'] ?? '—' }}, {{ $r['property']['postal_code'] ?? '' }}
                                                    </a>
                                                @else
                                                    <p class="font-medium">{{ $r['property']['address'] ?? '—' }}, {{ $r['property']['postal_code'] ?? '' }}</p>
                                                @endif
                                                <p class="text-xs text-zinc-500 mt-0.5">
                                                    @foreach($r['owners'] ?? [] as $o)
                                                        <span>{{ $o['name'] ?? '' }}@if(($o['cvr'] ?? false)) ({{ $o['cvr'] }})@endif</span>@if(! $loop->last), @endif
                                                    @endforeach
                                                </p>
                                            </div>
                                            <div class="text-right shrink-0">
                                                <p class="text-sm font-semibold tabular-nums">
                                                    {{ number_format($r['debt']['interest_rate'] ?? 0, 2, ',', '.') }}%
                                                    @if($r['debt']['is_nominal_rate'] ?? false)
                                                        <span class="inline-block align-middle ml-1 text-[10px] font-medium px-1.5 py-0.5 rounded bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300"
                                                              title="{{ __('Pålydende rammerente på ejerpantebrev, ikke nødvendigvis den faktisk betalte rente') }}">
                                                            {{ __('rammerente') }}
                                                        </span>
                                                    @endif
                                                </p>
                                                <p class="text-xs text-zinc-500 tabular-nums">{{ number_format($r['debt']['principal_amount_kr'] ?? 0, 0, ',', '.') }} kr</p>
                                                <p class="text-xs text-zinc-400">{{ $r['debt']['type'] ?? '' }}</p>
                                            </div>
                                        </div>
                                        @if(($r['debt']['creditor'] ?? null))
                                            <p class="text-xs text-zinc-500 mt-1">{{ __('Kreditor') }}: {{ $r['debt']['creditor'] }}</p>
                                        @endif
                                        @if($detailUrl)
                                            <p class="text-[11px] text-zinc-400 mt-1">→ {{ __('Klik på adressen for ejer-detaljer, BBR, valuation, transaktioner og pantebreve') }}</p>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                            @if(($pagination['sample'] ?? false) && ($pagination['has_more'] ?? false))
                                {{-- Sample-tilstand: brugeren ser de 10 øverste + det fulde
                                     antal match, men får ikke hele lead-listen. Fuld adgang
                                     er en kommerciel aftale (håndhæves server-side). --}}
                                <div class="px-4 py-4 border-t dark:border-zinc-700 text-center">
                                    <p class="text-sm text-zinc-600 dark:text-zinc-300">
                                        {{ __('Viser de :n med højest gæld ud af', ['n' => count($response['results'] ?? [])]) }}
                                        <span class="font-semibold tabular-nums">{{ number_format($summary['n_properties'] ?? 0, 0, ',', '.') }}</span>
                                        {{ __('ejendomme.') }}
                                    </p>
                                    <a href="mailto:kontakt@frankston.io?subject=Metis%20gældssøgning%20—%20fuld%20adgang"
                                       class="inline-block mt-2 text-sm font-medium text-warm-600 hover:text-warm-700 underline">
                                        {{ __('Kontakt os for fuld adgang til hele listen') }}
                                    </a>
                                </div>
                            @elseif(count($cursorHistory) > 0 || ($pagination['has_more'] ?? false))
                                <div class="px-4 py-3 border-t dark:border-zinc-700 flex justify-between items-center">
                                    @if(count($cursorHistory) > 0)
                                        <button wire:click="previousPage" class="text-sm px-4 py-1.5 border rounded hover:bg-zinc-50">
                                            ← {{ __('Forrige side') }}
                                        </button>
                                    @else
                                        <span></span>
                                    @endif

                                    @if($pagination['has_more'] ?? false)
                                        <button wire:click="nextPage" class="text-sm px-4 py-1.5 border rounded hover:bg-zinc-50">
                                            {{ __('Næste side') }} →
                                        </button>
                                    @endif
                                </div>
                            @endif
                        </section>
                    @endif
                @endif
            @endif
        </main>
    </div>
</div>
