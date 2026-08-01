<div @if($streaming) wire:poll.5s="pollForUpdates" @endif>
    <flux:card>
        <flux:heading size="lg" class="mb-4">{{ __('Tinglysning') }}</flux:heading>

        @if($hasError)
            @include('metis::livewire.sections.partials.error-state', [
                'message' => $errorMessage,
            ])
        @else
            {{-- tree_meta summary --}}
            @if($treeMeta)
                <div class="mb-5 flex flex-wrap gap-x-6 gap-y-2 text-sm">
                    <span class="text-zinc-500">
                        {{ __('Selskaber i koncern') }}:
                        <span class="font-medium text-zinc-900 dark:text-white">{{ $treeMeta['total_descendant_companies'] ?? 0 }}</span>
                    </span>
                    {{-- "Ejendomme" alene kolliderer med KPI'ens tal øverst på
                         siden, som tæller HELE porteføljen fra EJF. Dette tal
                         tæller kun det vi kan slå hæftelser op på (tree-index).
                         Begge er korrekte; samme navn fik dem til at se ud som
                         en fejl, og så mister brugeren tilliden til dem begge. --}}
                    <span class="text-zinc-500" title="{{ __('Antal ejendomme vi har kunnet slå op i Tinglysningen. Kan være færre end selskabets samlede portefølje.') }}">
                        {{ __('Ejendomme med tinglysningsopslag') }}:
                        <span class="font-medium text-zinc-900 dark:text-white">{{ $treeMeta['total_properties'] ?? 0 }}</span>
                    </span>
                    <span class="text-zinc-500">
                        {{ __('Pantebreve') }}:
                        <span class="font-medium text-zinc-900 dark:text-white">{{ $treeMeta['total_mortgages'] ?? 0 }}</span>
                    </span>
                    @if(($treeMeta['total_principal_amount'] ?? 0) > 0)
                        <span class="text-zinc-500">
                            {{ __('Hovedstol total') }}:
                            <span class="font-medium text-zinc-900 dark:text-white">{{ number_format($treeMeta['total_principal_amount'] / 100, 0, ',', '.') }} kr.</span>
                        </span>
                    @endif
                    @if(isset($treeMeta['weighted_ltv']))
                        <span class="text-zinc-500">
                            {{ __('Vægtet LTV') }}:
                            <span class="font-medium text-zinc-900 dark:text-white">{{ number_format($treeMeta['weighted_ltv'] * 100, 0) }}%</span>
                        </span>
                    @endif
                </div>

                {{-- Dækningsforbehold.

                     Tallene ovenfor ser komplette ud, også når vi kun har
                     undersøgt en brøkdel. AKACIETORVET viste "Ejendomme: 4,
                     Pantebreve: 1" hvor 3 af de 4 aldrig var slået op — og
                     de tre bærer 79,9 mio. i hæftelser.

                     Tallet er ikke forkert. Det er ufuldstændigt uden at
                     sige det. Vises kun når der faktisk mangler noget. --}}
                @php($cov = $treeMeta['coverage'] ?? null)
                @if($cov && ! ($cov['all_properties_answered'] ?? true) && (($cov['properties_total'] ?? 0) > 0 || ($cov['ejf_known_total'] ?? 0) > 0))
                    <div class="mb-5 rounded-lg border border-sand-200/60 bg-white px-4 py-3 text-sm dark:border-zinc-700 dark:bg-zinc-900">
                        <p class="text-ink-800 dark:text-zinc-100">
                            @php($known = max($cov['properties_total'] ?? 0, $cov['ejf_known_total'] ?? 0))
                            {{ __(':answered af :total ejendomme undersøgt.', [
                                'answered' => $cov['properties_answered'] ?? 0,
                                'total' => $known,
                            ]) }}
                            {{-- EJF kender ejendomme vi aldrig har haft. Uden denne linje
                                 ville teksten sige "2 af 2 undersøgt" om et grundlag hvor
                                 en fjerdedel manglede — en beroligelse, ikke et forbehold. --}}
                            @if(($cov['properties_missing_vs_ejf'] ?? 0) > 0)
                                {{ __(':n ejendomme kender vi slet ikke — de står i EJF, men mangler i vores data.', ['n' => $cov['properties_missing_vs_ejf']]) }}
                            @endif
                            @if(($cov['properties_blocked'] ?? 0) > 0)
                                {{ __(':n mangler adressedata og kan ikke slås op.', ['n' => $cov['properties_blocked']]) }}
                            @endif
                            @if(($cov['properties_pending'] ?? 0) > 0)
                                {{ __(':n er endnu ikke hentet.', ['n' => $cov['properties_pending']]) }}
                            @endif
                        </p>
                        <p class="mt-1 text-sand-300 dark:text-zinc-400">
                            {{ __('Tallene ovenfor dækker kun det undersøgte. Fravær af hæftelser er ikke et udsagn om at der ingen er.') }}
                            @if(! empty($cov['oldest_answer_at']))
                                {{ __('Ældste opslag: :date.', ['date' => \Illuminate\Support\Carbon::parse($cov['oldest_answer_at'])->isoFormat('D. MMM YYYY')]) }}
                            @endif
                        </p>
                    </div>
                @endif
            @endif

            {{-- filter-bar (Q5 + Q8) --}}
            <div class="mb-5 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3 p-3 border rounded-lg border-zinc-200 dark:border-zinc-700 bg-zinc-50/50 dark:bg-zinc-800/30">
                <div>
                    <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-300 mb-1">{{ __('Status') }}</label>
                    <select wire:model.live="status"
                            class="w-full px-2 py-1.5 text-sm border rounded border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900">
                        <option value="active">{{ __('Aktive') }}</option>
                        <option value="inactive">{{ __('Aflyste') }}</option>
                        <option value="all">{{ __('Alle') }}</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-300 mb-1">{{ __('Sortér') }}</label>
                    <select wire:model.live="sortBy"
                            class="w-full px-2 py-1.5 text-sm border rounded border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900">
                        <option value="principal_amount_desc">{{ __('Hovedstol (højest)') }}</option>
                        <option value="tinglysning_date_desc">{{ __('Tinglyst (nyest)') }}</option>
                        <option value="ltv_desc">{{ __('LTV (højest)') }}</option>
                        <option value="address_asc">{{ __('Adresse (A-Z)') }}</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-300 mb-1">{{ __('Hovedstol (kr)') }}</label>
                    <div class="flex gap-1">
                        <input type="number" min="0" step="100000"
                               wire:model.live.debounce.500ms="minAmount"
                               placeholder="min"
                               class="w-1/2 px-2 py-1.5 text-sm border rounded border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900">
                        <input type="number" min="0" step="100000"
                               wire:model.live.debounce.500ms="maxAmount"
                               placeholder="max"
                               class="w-1/2 px-2 py-1.5 text-sm border rounded border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-300 mb-1">{{ __('Træ-dybde') }}</label>
                    <select wire:model.live="treeDepthState"
                            class="w-full px-2 py-1.5 text-sm border rounded border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900">
                        <option value="1">{{ __('Root + 1 niveau') }}</option>
                        <option value="full">{{ __('Hele træet') }}</option>
                    </select>
                </div>

                <div class="md:col-span-2 lg:col-span-3">
                    <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-300 mb-1">{{ __('Pantebrevs-typer') }}</label>
                    <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs">
                        @foreach([
                            'privatpantebrev' => __('Privat'),
                            'realkreditpantebrev' => __('Realkredit'),
                            'ejerpantebrev' => __('Ejer'),
                            'afgiftspantebrev' => __('Afgift'),
                        ] as $value => $label)
                            <label class="inline-flex items-center gap-1.5 cursor-pointer">
                                <input type="checkbox"
                                       wire:model.live="mortgageTypeFilter"
                                       value="{{ $value }}"
                                       class="rounded border-zinc-300 dark:border-zinc-600">
                                <span>{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="flex items-end gap-2">
                    <button type="button"
                            wire:click="clearFilters"
                            class="flex-1 px-3 py-1.5 text-xs border rounded hover:bg-white dark:hover:bg-zinc-700 border-zinc-200 dark:border-zinc-700">
                        {{ __('Nulstil filtre') }}
                    </button>
                    <button type="button"
                            wire:click="exportXlsx"
                            wire:loading.attr="disabled"
                            wire:target="exportXlsx"
                            @if(! $treeMeta) disabled @endif
                            class="flex-1 px-3 py-1.5 text-xs border rounded bg-white dark:bg-zinc-900 hover:bg-zinc-50 dark:hover:bg-zinc-800 border-zinc-300 dark:border-zinc-600 disabled:opacity-50 disabled:cursor-not-allowed"
                            title="{{ __('Eksportér til XLSX (Oversigt + Tier-breakdown + Pantebreve)') }}">
                        <span wire:loading.remove wire:target="exportXlsx">{{ __('Eksportér XLSX') }}</span>
                        <span wire:loading wire:target="exportXlsx">{{ __('Eksporterer...') }}</span>
                    </button>
                </div>
            </div>

            @if(session()->has('metis_tinglysning_export_error'))
                <div class="mb-3 px-3 py-2 text-xs rounded border border-amber-300 bg-amber-50 text-amber-800 dark:bg-amber-900/30 dark:border-amber-700 dark:text-amber-200" role="alert">
                    {{ session('metis_tinglysning_export_error') }}
                </div>
            @endif

            {{-- streaming progress --}}
            @if($streaming)
                <div class="flex items-center gap-2 text-blue-600 text-xs mb-4 px-1" aria-live="polite" aria-busy="true">
                    <svg class="size-3.5 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <span>{{ __('Henter pantebreve') }}: {{ $deliveredSoFar }} / {{ $totalExpected }}</span>
                </div>
            @endif

            {{-- tier breakdown table --}}
            @if(count($tierBreakdown) > 0)
                <div class="mb-6">
                    <h3 class="text-sm font-medium text-zinc-700 dark:text-zinc-200 mb-2">{{ __('Tier breakdown') }}</h3>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-zinc-200 dark:border-zinc-700">
                                    <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('Selskab') }}</th>
                                    <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('CVR') }}</th>
                                    <th class="text-right py-2 pr-4 font-medium text-zinc-500">{{ __('Niveau') }}</th>
                                    <th class="text-right py-2 pr-4 font-medium text-zinc-500">{{ __('Ejendomme') }}</th>
                                    <th class="text-right py-2 pr-4 font-medium text-zinc-500">{{ __('Pantebreve') }}</th>
                                    <th class="text-right py-2 pr-4 font-medium text-zinc-500">{{ __('Hovedstol') }}</th>
                                    <th class="text-right py-2 font-medium text-zinc-500">{{ __('Vægtet LTV') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($tierBreakdown as $tier)
                                    <tr class="border-b border-zinc-100 dark:border-zinc-800">
                                        <td class="py-2 pr-4">{{ $tier['name'] ?? '-' }}</td>
                                        <td class="py-2 pr-4 text-zinc-500 text-xs">{{ $tier['cvr'] ?? '-' }}</td>
                                        <td class="py-2 pr-4 text-right">{{ $tier['depth'] ?? 0 }}</td>
                                        <td class="py-2 pr-4 text-right">{{ $tier['property_count'] ?? 0 }}</td>
                                        <td class="py-2 pr-4 text-right">{{ $tier['mortgage_count'] ?? 0 }}</td>
                                        <td class="py-2 pr-4 text-right">
                                            @if(($tier['principal_amount'] ?? 0) > 0)
                                                {{ number_format($tier['principal_amount'] / 100, 0, ',', '.') }} kr.
                                            @else
                                                <span class="text-zinc-300">-</span>
                                            @endif
                                        </td>
                                        <td class="py-2 text-right">
                                            @if(isset($tier['weighted_ltv']))
                                                {{ number_format($tier['weighted_ltv'] * 100, 0) }}%
                                            @else
                                                <span class="text-zinc-300">-</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            {{-- panthavere: de faktiske långivere bag ejerpantebrevene --}}
            @if(count($this->lenders) > 0)
                <div class="mb-6">
                    <h3 class="text-sm font-medium text-zinc-700 dark:text-zinc-200 mb-2">{{ __('Panthavere') }}</h3>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-zinc-200 dark:border-zinc-700">
                                    <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('Panthaver') }}</th>
                                    <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('CVR') }}</th>
                                    <th class="text-right py-2 font-medium text-zinc-500">{{ __('Beløb') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($this->lenders as $lender)
                                    <tr class="border-b border-zinc-100 dark:border-zinc-800">
                                        <td class="py-2 pr-4">{{ $lender['name'] }}</td>
                                        <td class="py-2 pr-4 text-zinc-500 text-xs">
                                            @if($lender['cvr'])
                                                <a href="{{ route('metis.lookup', ['type' => 'company', 'query' => $lender['cvr']]) }}"
                                                   class="hover:underline">{{ $lender['cvr'] }}</a>
                                            @else
                                                <span class="text-zinc-300">-</span>
                                            @endif
                                        </td>
                                        <td class="py-2 text-right">
                                            @if($lender['amount'] > 0)
                                                {{ number_format($lender['amount'] / 100, 0, ',', '.') }} kr.
                                            @else
                                                <span class="text-zinc-300">-</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    {{-- Forbeholdet brugeren bad om: underpanthaveren kan optræde
                         på vegne af andre kreditorer, så navnet er den tinglyste
                         panthaver — ikke nødvendigvis den endelige långiver. --}}
                    <p class="mt-2 text-xs text-zinc-500">
                        {{ __('Underpanthavere fra Tinglysningen. En panthaver kan repræsentere andre kreditorer.') }}
                    </p>
                </div>
            @endif

            {{-- mortgages flat-list --}}
            @if(count($mortgages) > 0 || $streaming)
                <h3 class="text-sm font-medium text-zinc-700 dark:text-zinc-200 mb-2">{{ __('Pantebreve') }}</h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-zinc-200 dark:border-zinc-700">
                                <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('Adresse') }}</th>
                                <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('Ejer') }}</th>
                                <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('Type') }}</th>
                                <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('Kreditor') }}</th>
                                <th class="text-center py-2 pr-4 font-medium text-zinc-500" title="{{ __('Prioritetsnummer fra Tinglysningsretten, lavere = bedre sikkerhedsposition') }}">{{ __('Pri') }}</th>
                                <th class="text-right py-2 pr-4 font-medium text-zinc-500">{{ __('Hovedstol') }}</th>
                                <th class="text-right py-2 pr-4 font-medium text-zinc-500">{{ __('LTV') }}</th>
                                <th class="text-left py-2 font-medium text-zinc-500">{{ __('Tinglyst') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($mortgages as $m)
                                <tr class="border-b border-zinc-100 dark:border-zinc-800 cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-800/40 transition"
                                    wire:key="mortgage-{{ $m['id'] ?? $loop->index }}"
                                    wire:click="$set('openMortgageId', {{ (int) ($m['id'] ?? 0) }})">
                                    <td class="py-2 pr-4">
                                        @if($m['address'] ?? null)
                                            <span class="text-zinc-700 dark:text-zinc-200">{{ $m['address'] }}</span>
                                            @if($m['is_sampant'] ?? false)
                                                <span class="ml-1.5 inline-block px-1.5 py-0.5 text-[10px] font-medium bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300 rounded">{{ __('Sampant') }}</span>
                                            @endif
                                        @else
                                            <span class="text-zinc-300">-</span>
                                        @endif
                                    </td>
                                    <td class="py-2 pr-4">
                                        @if($m['owner_company']['cvr'] ?? null)
                                            <span class="text-zinc-700 dark:text-zinc-200">{{ $m['owner_company']['name'] ?? $m['owner_company']['cvr'] }}</span>
                                        @else
                                            <span class="text-zinc-300">-</span>
                                        @endif
                                    </td>
                                    {{-- 🚨 Afgiftspantebreve misbruger HaeftelseType: den siger 'ejerpantebrev',
                                         men dokumentet ER et afgiftspantebrev. Tinglysningen markerer det i et
                                         SEPARAT felt (TinglysningAfgiftOverfoerselIndikator), som API'et allerede
                                         beregner og sender som is_afgiftspantebrev — visningen brugte det bare aldrig.

                                         Målt i prod på Akacietorvet 2A: 319.660 og 176.638 kr har begge
                                         HaeftelseType='ejerpantebrev' MEN AfgiftIndikator='true'. Resights viser
                                         dem korrekt som Afgiftspantebrev.

                                         Det betyder noget, fordi et afgiftspantebrev IKKE er gæld — det overfører
                                         en betalt tinglysningsafgift til et nyt pant. Vist som ejerpantebrev
                                         ligner det en gældspost. --}}
                                    <td class="py-2 pr-4 text-zinc-600 dark:text-zinc-300 text-xs">
                                        @if($m['is_afgiftspantebrev'] ?? false)
                                            <span title="{{ __('Overfører en betalt tinglysningsafgift til et nyt pant — ikke gæld i sig selv') }}">{{ __('Afgiftspantebrev') }}</span>
                                        @else
                                            {{ $m['mortgage_type'] ?? '-' }}
                                        @endif
                                    </td>
                                    <td class="py-2 pr-4 text-zinc-600 dark:text-zinc-300 text-xs">{{ $m['creditor'] ?? '-' }}</td>
                                    <td class="py-2 pr-4 text-center text-zinc-700 dark:text-zinc-200 text-xs font-medium tabular-nums">
                                        @if($m['priority'] ?? null)
                                            {{ $m['priority'] }}
                                        @else
                                            <span class="text-zinc-300">-</span>
                                        @endif
                                    </td>
                                    <td class="py-2 pr-4 text-right">
                                        @if(($m['principal_amount'] ?? 0) > 0)
                                            {{ number_format($m['principal_amount'] / 100, 0, ',', '.') }} kr.
                                        @else
                                            <span class="text-zinc-300">-</span>
                                        @endif
                                    </td>
                                    <td class="py-2 pr-4 text-right">
                                        @if(isset($m['ltv']['value']))
                                            <span title="{{ $m['ltv']['method'] ?? '' }}">{{ number_format($m['ltv']['value'] * 100, 0) }}%</span>
                                        @else
                                            <span class="text-zinc-300">-</span>
                                        @endif
                                    </td>
                                    <td class="py-2 text-zinc-500 text-xs">{{ $m['registration_date'] ?? '-' }}</td>
                                </tr>
                            @endforeach
                            @if($streaming)
                                @for($i = 0; $i < 3; $i++)
                                    @include('metis::livewire.sections.partials.skeleton-row', ['columns' => 8])
                                @endfor
                            @endif
                        </tbody>
                    </table>
                </div>
            @elseif(! $streaming)
                @if($status !== 'active' || count($mortgageTypeFilter) > 0 || $minAmount !== null || $maxAmount !== null)
                    <div class="py-6 text-sm">
                        <p class="text-zinc-500 mb-2">{{ __('Ingen pantebreve matcher dine filtre.') }}</p>
                        <button type="button"
                                wire:click="clearFilters"
                                class="px-3 py-1.5 text-xs border rounded hover:bg-zinc-50 dark:hover:bg-zinc-800 border-zinc-200 dark:border-zinc-700">
                            {{ __('Nulstil filtre') }}
                        </button>
                    </div>
                @else
                    <p class="text-sm text-zinc-500">{{ __('Ingen pantebreve fundet for denne koncern.') }}</p>
                @endif
            @endif

            {{-- Flyout drawer (Q7) — Flux modal with Alpine arrow-key nav --}}
            <flux:modal name="ting-mortgage-drawer"
                        variant="flyout"
                        wire:model="openMortgageId"
                        class="w-[600px]">
                <div x-data="{ navigate(dir) { $wire.navigateDrawer(dir) } }"
                     x-on:keydown.up.window.prevent="navigate('prev')"
                     x-on:keydown.down.window.prevent="navigate('next')"
                     class="space-y-5">
                    @php($drawer = $this->openMortgage)
                    @if($drawer)
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <flux:heading size="lg">
                                    {{ $drawer['address'] ?? __('Pantebrev') }}
                                </flux:heading>
                                <p class="text-xs text-zinc-500 mt-0.5">
                                    {{ ucfirst($drawer['mortgage_type'] ?? '') }}
                                    @if($drawer['priority'] ?? null)
                                        · {{ __('Prioritet') }} {{ $drawer['priority'] }}
                                    @endif
                                </p>
                            </div>
                            <button type="button"
                                    x-data
                                    x-on:click="
                                        const url = new URL(window.location);
                                        url.searchParams.set('ting_mortgage', '{{ $drawer['id'] ?? '' }}');
                                        navigator.clipboard.writeText(url.toString());
                                        $el.textContent = '{{ __('Kopieret!') }}';
                                        setTimeout(() => { $el.textContent = '{{ __('Kopiér link') }}' }, 1500);
                                    "
                                    class="px-2 py-1 text-xs border rounded hover:bg-zinc-50 dark:hover:bg-zinc-800 border-zinc-200 dark:border-zinc-700">
                                {{ __('Kopiér link') }}
                            </button>
                        </div>

                        <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                            <dt class="text-zinc-500">{{ __('Hovedstol') }}</dt>
                            <dd class="text-right font-medium">
                                @if(($drawer['principal_amount'] ?? 0) > 0)
                                    {{ number_format($drawer['principal_amount'] / 100, 0, ',', '.') }} kr.
                                @else
                                    -
                                @endif
                            </dd>

                            <dt class="text-zinc-500">{{ __('Kreditor') }}</dt>
                            <dd class="text-right">{{ $drawer['creditor'] ?? '-' }}</dd>

                            <dt class="text-zinc-500">{{ __('Debitor') }}</dt>
                            <dd class="text-right">{{ $drawer['debitor'] ?? '-' }}</dd>

                            <dt class="text-zinc-500">{{ __('Tinglyst') }}</dt>
                            <dd class="text-right">{{ $drawer['registration_date'] ?? '-' }}</dd>

                            @if(isset($drawer['ltv']['value']))
                                <dt class="text-zinc-500">{{ __('LTV') }}</dt>
                                <dd class="text-right">
                                    {{ number_format($drawer['ltv']['value'] * 100, 0) }}%
                                    <span class="text-xs text-zinc-400 ml-1">({{ $drawer['ltv']['method'] ?? '' }})</span>
                                </dd>
                            @endif
                        </dl>

                        @if(($drawer['address'] ?? null) || ($drawer['bfe'] ?? null))
                            <div class="border-t border-zinc-200 dark:border-zinc-700 pt-3">
                                <h4 class="text-xs font-semibold text-zinc-700 dark:text-zinc-200 mb-1.5 uppercase tracking-wide">{{ __('Ejendom') }}</h4>
                                <p class="text-sm">
                                    @if($drawer['address'] ?? null)
                                        <x-metis-link type="address" :query="$drawer['address']" />
                                    @else
                                        -
                                    @endif
                                </p>
                                @if($drawer['bfe'] ?? null)
                                    <p class="text-xs text-zinc-500">BFE: {{ $drawer['bfe'] }}</p>
                                @endif
                            </div>
                        @endif

                        @if($drawer['owner_company']['cvr'] ?? null)
                            <div class="border-t border-zinc-200 dark:border-zinc-700 pt-3">
                                <h4 class="text-xs font-semibold text-zinc-700 dark:text-zinc-200 mb-1.5 uppercase tracking-wide">{{ __('Ejer-selskab') }}</h4>
                                <x-metis-link type="cvr" :query="$drawer['owner_company']['cvr']">
                                    {{ $drawer['owner_company']['name'] ?? $drawer['owner_company']['cvr'] }}
                                </x-metis-link>
                            </div>
                        @endif

                        <div class="border-t border-zinc-200 dark:border-zinc-700 pt-3">
                            <h4 class="text-xs font-semibold text-zinc-700 dark:text-zinc-200 mb-1.5 uppercase tracking-wide">{{ __('Ændringshistorik') }}</h4>
                            @if($changeHistory === null)
                                <p class="text-xs text-zinc-400">{{ __('Loader...') }}</p>
                            @elseif(count($changeHistory) === 0)
                                <p class="text-xs text-zinc-500">{{ __('Ingen ændringer registreret siden tracking startede.') }}</p>
                            @else
                                <ul class="space-y-1 text-xs">
                                    @foreach($changeHistory as $event)
                                        <li class="flex justify-between gap-2">
                                            <span>{{ $event['kind'] ?? '' }}</span>
                                            <span class="text-zinc-400">{{ $event['detected_at'] ?? '' }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>

                        {{-- PDF placeholder — Sprint 2 --}}
                        @if($drawer['tinglysning_pdf_url'] ?? null)
                            <div class="border-t border-zinc-200 dark:border-zinc-700 pt-3">
                                <a href="{{ $drawer['tinglysning_pdf_url'] }}"
                                   target="_blank"
                                   class="inline-flex items-center gap-1.5 text-sm text-blue-600 hover:text-blue-700">
                                    {{ __('Åbn pantebrev (PDF)') }} →
                                </a>
                            </div>
                        @endif

                        <div class="flex justify-between border-t border-zinc-200 dark:border-zinc-700 pt-3 text-xs text-zinc-400">
                            <span>{{ __('Brug pil-op/pil-ned for at navigere') }}</span>
                        </div>
                    @endif
                </div>
            </flux:modal>
        @endif
    </flux:card>
</div>
