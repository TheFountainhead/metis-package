<div>
    <flux:card>
        <flux:heading size="lg" class="mb-4">{{ __('Mortgages') }}</flux:heading>
        @if(count($mortgages) > 0)
            @if($totalDebt)
                <div class="flex flex-wrap items-center gap-2 mb-3">
                    <p class="text-sm text-zinc-500">{{ __('Total debt') }}: <span class="font-medium text-zinc-900 dark:text-white">{{ number_format($totalDebt, 0, ',', '.') }} kr.</span></p>
                    @if(!is_null($ltv))
                        @php
                            $ltvColor = match (true) {
                                $ltv < 60 => 'green',
                                $ltv <= 80 => 'yellow',
                                default => 'red',
                            };
                        @endphp
                        <flux:badge color="{{ $ltvColor }}" size="sm">LTV {{ number_format($ltv, 1, ',', '.') }}%</flux:badge>
                        <span class="text-xs text-zinc-400">{{ __('vs. public valuation') }}</span>
                    @endif
                </div>
            @endif
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200 dark:border-zinc-700">
                            <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('Creditor') }}</th>
                            <th class="text-right py-2 pr-4 font-medium text-zinc-500">{{ __('Principal') }}</th>
                            <th class="text-right py-2 pr-4 font-medium text-zinc-500">{{ __('Rate') }}</th>
                            <th class="text-left py-2 font-medium text-zinc-500">{{ __('Maturity') }}</th>
                        </tr>
                    </thead>
                        @foreach($mortgages as $m)
                            @php
                                // Fold ud: det Tinglysningen oplyser om lånet selv (registry-api `detaljer`).
                                // Kun linjer med indhold vises; en række uden nogen er ikke klikbar.
                                $d = is_array($m['detaljer'] ?? null) ? $m['detaljer'] : [];
                                $linjer = [];
                                if (filled($d['prioritet'] ?? null)) { $linjer[__('Prioritet')] = (string) $d['prioritet']; }
                                if (filled($d['tinglyst'] ?? null)) { $linjer[__('Tinglyst')] = \Illuminate\Support\Carbon::parse($d['tinglyst'])->format('d.m.Y'); }
                                if (filled($m['type'] ?? null)) { $linjer[__('Type')] = ucfirst((string) $m['type']); }
                                if (filled($d['laantype'] ?? null)) {
                                    $linjer[__('Lånetype')] = ['kontantlaan' => __('Kontantlån'), 'obligationslaan' => __('Obligationslån'), 'indekslaan' => __('Indekslån')][$d['laantype']] ?? ucfirst((string) $d['laantype']);
                                }
                                $ref = is_array($d['referencerente'] ?? null) ? $d['referencerente'] : null;
                                if ($ref && filled($ref['navn'] ?? null)) {
                                    $tillaeg = $ref['tillaeg'] ?? null;
                                    $linjer[__('Rente')] = __('variabel') . ', ' . $ref['navn']
                                        . (is_numeric($tillaeg) && (float) $tillaeg != 0.0 ? ' ' . ($tillaeg < 0 ? '−' : '+') . ' ' . number_format(abs((float) $tillaeg), 2, ',', '.') . ' %' : '');
                                } elseif (filled($d['rentetype'] ?? null)) {
                                    $linjer[__('Rente')] = $d['rentetype'] === 'fast' ? __('fast') : __('variabel');
                                }
                                $vilkaar = array_filter((array) ($d['saerlige_vilkaar'] ?? []), 'is_string');
                                if ($vilkaar) { $linjer[__('Særlige lånevilkår')] = implode(', ', array_map(fn ($v) => ucfirst(str_replace('_', ' ', $v)), $vilkaar)); }
                                if (is_numeric($d['tinglysningsafgift_kr'] ?? null)) { $linjer[__('Tinglysningsafgift')] = number_format((float) $d['tinglysningsafgift_kr'], 0, ',', '.') . ' kr.'; }
                                if (is_numeric($d['ogsaa_lyst_paa'] ?? null) && (int) $d['ogsaa_lyst_paa'] > 0) {
                                    $linjer[__('Også lyst på')] = trans_choice('{1} :n anden ejendom|[2,*] :n andre ejendomme', (int) $d['ogsaa_lyst_paa'], ['n' => (int) $d['ogsaa_lyst_paa']]);
                                }
                                if ((int) ($d['respekterer'] ?? 0) > 0) { $linjer[__('Respekterer')] = trans_choice('{1} :n foranstående rettighed|[2,*] :n foranstående rettigheder', (int) $d['respekterer'], ['n' => (int) $d['respekterer']]); }
                                $detaljeId = 'pant-detalje-' . $loop->index;
                            @endphp
                            <tbody x-data="{ aaben: false }" class="border-b border-zinc-100 dark:border-zinc-800">
                            <tr>
                                <td class="py-2 pr-4">
                                    @if($linjer)
                                        <button type="button" @click="aaben = !aaben" :aria-expanded="aaben ? 'true' : 'false'" aria-controls="{{ $detaljeId }}"
                                                class="inline-flex items-center gap-1.5 text-left hover:underline">
                                            <span class="text-zinc-400 text-xs" x-text="aaben ? '▾' : '▸'">▸</span>
                                            {{ $m['creditor'] ?? '-' }}
                                        </button>
                                    @else
                                        {{ $m['creditor'] ?? '-' }}
                                    @endif
                                </td>
                                <td class="py-2 pr-4 text-right">{{ isset($m['principal']) ? number_format($m['principal'], 0, ',', '.') . ' kr.' : '-' }}</td>
                                <td class="py-2 pr-4 text-right">{{ isset($m['interest_rate']) ? number_format($m['interest_rate'], 2, ',', '.') . '%' : '-' }}</td>
                                {{-- 🪤 "ikke oplyst", ikke "-": i en gældstabel læses en
                                     bindestreg som "ingen udløbsdato" — altså et stående
                                     lån. Sandheden er at Tinglysningen ikke HAR feltet
                                     (undersøgt 11/8: tomt på 100% af 1,27 mio. pantebreve;
                                     hverken `ssl/ejdsummarisk` eller e-TL's egen
                                     `model:PantebrevFastEjendom` har et udløbsfelt).
                                     Renten beholder bevidst sin bindestreg — den mangler
                                     kun på 30,1% og findes for resten, så dér er tomheden
                                     en egenskab ved det enkelte pantebrev. --}}
                                <td class="py-2">
                                    @if($m['maturity_date'] ?? null)
                                        {{ $m['maturity_date'] }}
                                    @else
                                        <span class="text-zinc-400">{{ __('ikke oplyst') }}</span>
                                    @endif
                                </td>
                            </tr>
                            @if($linjer)
                                <tr id="{{ $detaljeId }}" data-pant-detalje x-show="aaben" style="display:none">
                                    <td colspan="4" class="pb-3 pt-0">
                                        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-1 text-sm bg-zinc-50 dark:bg-zinc-800/50 rounded-lg px-4 py-3">
                                            @foreach($linjer as $navn => $vaerdi)
                                                <div class="flex justify-between gap-4">
                                                    <dt class="text-zinc-500">{{ $navn }}</dt>
                                                    <dd class="text-right text-zinc-900 dark:text-white">{{ $vaerdi }}</dd>
                                                </div>
                                            @endforeach
                                        </dl>
                                    </td>
                                </tr>
                            @endif
                            </tbody>
                        @endforeach
                </table>
            </div>
            <p class="text-xs text-zinc-400 mt-3">{{ __('Registered principal is not the same as current outstanding debt.') }}</p>
            {{-- Uden denne linje flytter "ikke oplyst" blot tvetydigheden: brugeren
                 ved stadig ikke, om det er OS eller KILDEN der mangler noget. --}}
            <p class="text-xs text-zinc-400 mt-1">{{ __('Tinglysningen oplyser ikke udløbsdato på pantebreve.') }}</p>
        @elseif($hasError)
            {{-- 🔑 Bruger FAELLES-partialen, men med sin egen forsikringslinje:
                 "gaeldfri" er den farlige slutning netop her. Foer stod hele
                 blokken inlinet, og de to tekster begyndte allerede at drive
                 fra hinanden (review-fund 18/8). --}}
            @include('metis::livewire.sections.partials.lookup-error', [
                'errorMessage' => $errorMessage,
                'forsikring' => __('Det betyder ikke at ejendommen er gældfri'),
            ])
        @elseif(! $erUndersoegt)
            {{-- 🚨 "Ingen pantebreve fundet" ville være en FALSK PÅSTAND OM
                 FRAVÆR her: vi har ikke kigget endnu. Målt 10/8 blev 1.105.049
                 ejendomme crawl-klare på én gang efter adresse-backfillen, og
                 crawlen tager ~60 døgn fordi Tinglysningen rate-limiter til
                 12,3 opslag/min. I hele den periode ville u-crawlede ejendomme
                 se gældfri ud — det værste en kreditvurdering kan bygge på. --}}
            <p class="text-sm text-zinc-500">
                {{ __('Gældsoplysninger er ikke hentet for denne ejendom endnu.') }}
            </p>
            <p class="text-xs text-zinc-400 mt-2">
                {{ __('Det betyder ikke at ejendommen er gældfri — vi har blot ikke undersøgt den.') }}
            </p>
        @else
            <p class="text-sm text-zinc-500">{{ __('No mortgages found.') }}</p>
        @endif
    </flux:card>
</div>
