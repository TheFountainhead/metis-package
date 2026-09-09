<div class="max-w-5xl mx-auto">
    <div class="flex justify-end gap-2 mb-4">
        <a href="{{ route(Route::has('metis.index') ? 'metis.index' : 'metis.home') }}"
           class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm border rounded-lg hover:bg-zinc-50 dark:hover:bg-zinc-800 dark:border-zinc-700 transition">
            {{ __('Nyt opslag') }}
        </a>
    </div>

    <h1 class="text-2xl font-bold text-ink-800 mb-1">{{ __('Selskabssegmentering') }}</h1>
    <p class="text-ink-700/60 text-sm mb-6">
        {{ __('Afgræns en population af danske selskaber og se den fordelt på kommune, branche eller selskabsform.') }}
    </p>

    <div class="grid md:grid-cols-[260px_1fr] gap-6">

        {{-- Filtre --}}
        <aside class="bg-sand-50 border border-sand-200 rounded-xl p-4 h-fit">
            <h2 class="text-xs uppercase tracking-wide text-sand-300 mb-3">{{ __('Fordel på') }}</h2>
            <select wire:model.live="groupBy" wire:change="segmentér"
                    class="w-full bg-white border border-sand-200 rounded-lg px-3 py-2 text-sm text-ink-800 mb-5"
                    aria-label="{{ __('Gruppering') }}">
                @foreach($this::GRUPPERINGER as $vaerdi => $navn)
                    <option value="{{ $vaerdi }}">{{ __($navn) }}</option>
                @endforeach
            </select>

            <h2 class="text-xs uppercase tracking-wide text-sand-300 mb-3">{{ __('Afgræns') }}</h2>

            <label class="flex items-start gap-2 mb-3 cursor-pointer">
                <input type="checkbox" wire:model.live="ivaerksaetter" wire:change="segmentér"
                       class="mt-0.5 rounded border-sand-300 text-warm-500 focus:ring-warm-500/30">
                <span class="text-sm text-ink-800">
                    {{ __('Kun iværksættervirksomheder') }}
                    <span class="block text-xs text-ink-700/50">{{ __('Erhvervsstyrelsens definition') }}</span>
                </span>
            </label>

            <label class="flex items-start gap-2 mb-4 cursor-pointer">
                <input type="checkbox" wire:model.live="excludeHolding" wire:change="segmentér"
                       class="mt-0.5 rounded border-sand-300 text-warm-500 focus:ring-warm-500/30">
                <span class="text-sm text-ink-800">{{ __('Uden holdingselskaber') }}</span>
            </label>

            <label class="block text-sm text-ink-800 mb-1">{{ __('Branche (DB07)') }}</label>
            <input wire:model.blur="industryPrefix" wire:change="segmentér" type="text" placeholder="{{ __('fx 62') }}"
                   class="w-full bg-white border border-sand-200 rounded-lg px-3 py-2 text-sm mb-4"
                   aria-label="{{ __('Branchekode eller præfiks') }}">

            <label class="block text-sm text-ink-800 mb-1">{{ __('Kommunekode') }}</label>
            <input wire:model.blur="municipalityCode" wire:change="segmentér" type="text" placeholder="{{ __('fx 101') }}"
                   class="w-full bg-white border border-sand-200 rounded-lg px-3 py-2 text-sm mb-4"
                   aria-label="{{ __('Kommunekode') }}">

            <label class="block text-sm text-ink-800 mb-1">{{ __('Stiftet mellem') }}</label>
            <div class="flex gap-2 mb-4">
                <input wire:model.blur="foundedFrom" wire:change="segmentér" type="date"
                       class="w-full bg-white border border-sand-200 rounded-lg px-2 py-2 text-xs"
                       aria-label="{{ __('Stiftet fra') }}">
                <input wire:model.blur="foundedTo" wire:change="segmentér" type="date"
                       class="w-full bg-white border border-sand-200 rounded-lg px-2 py-2 text-xs"
                       aria-label="{{ __('Stiftet til') }}">
            </div>

            <label class="block text-sm text-ink-800 mb-1">{{ __('Årsværk') }}</label>
            <div class="flex gap-2 mb-5">
                <input wire:model.blur="fteMin" wire:change="segmentér" type="number" min="0" placeholder="{{ __('min') }}"
                       class="w-full bg-white border border-sand-200 rounded-lg px-2 py-2 text-sm"
                       aria-label="{{ __('Mindste antal årsværk') }}">
                <input wire:model.blur="fteMax" wire:change="segmentér" type="number" min="0" placeholder="{{ __('maks') }}"
                       class="w-full bg-white border border-sand-200 rounded-lg px-2 py-2 text-sm"
                       aria-label="{{ __('Højeste antal årsværk') }}">
            </div>

            <button wire:click="nulstil" type="button"
                    class="w-full text-sm border border-sand-200 rounded-lg px-3 py-2 hover:bg-white transition text-ink-700">
                {{ __('Nulstil filtre') }}
            </button>
        </aside>

        {{-- Resultat --}}
        <section>
            @if($fejl)
                <div class="bg-red-50 border border-red-200 rounded-xl p-4 text-sm text-red-800">{{ $fejl }}</div>
            @endif

            @if($harSoegt && ! $fejl)
                <div class="flex flex-wrap items-baseline justify-between gap-3 mb-4">
                    <div>
                        <p class="text-3xl font-bold text-ink-800" wire:loading.class="opacity-40">
                            {{ number_format($total ?? 0, 0, ',', '.') }}
                        </p>
                        <p class="text-sm text-ink-700/60">
                            {{ __('selskaber i alt med de valgte afgrænsninger') }}
                        </p>
                    </div>

                    <button wire:click="hentCsv" type="button" wire:loading.attr="disabled" wire:target="hentCsv"
                            class="inline-flex items-center gap-1.5 bg-warm-500 text-white rounded-lg px-4 py-2 text-sm font-semibold hover:bg-warm-600 transition disabled:opacity-60">
                        <span wire:loading.remove wire:target="hentCsv">{{ __('Hent til Excel (CSV)') }}</span>
                        <span wire:loading wire:target="hentCsv">{{ __('Danner udtræk…') }}</span>
                    </button>
                </div>

                {{-- 🔑 Listen er de 100 største grupper; totalen ovenfor er hele
                     populationen. Uden den sætning kunne en afkortet liste
                     læses som facit. --}}
                <p class="text-xs text-ink-700/50 mb-3">
                    {{ __('Viser de største grupper. Totalen ovenfor dækker hele den filtrerede population.') }}
                </p>

                <div class="overflow-x-auto border border-sand-200 rounded-xl">
                    <table class="w-full text-sm">
                        <thead class="bg-sand-50 text-left">
                            <tr>
                                <th class="px-4 py-2.5 font-semibold text-ink-800">{{ __($this::GRUPPERINGER[$groupBy] ?? 'Gruppe') }}</th>
                                <th class="px-4 py-2.5 font-semibold text-ink-800 text-right">{{ __('Antal') }}</th>
                                <th class="px-4 py-2.5 font-semibold text-ink-800 text-right">{{ __('Andel') }}</th>
                            </tr>
                        </thead>
                        <tbody wire:loading.class="opacity-40">
                            @forelse($grupper as $gruppe)
                                <tr class="border-t border-sand-200">
                                    <td class="px-4 py-2.5 text-ink-800">
                                        {{ $this->etiket($gruppe) }}
                                        <span class="text-xs text-ink-700/40">{{ $gruppe['key'] ?? '' }}</span>
                                    </td>
                                    <td class="px-4 py-2.5 text-right tabular-nums text-ink-800">
                                        {{ number_format($gruppe['count'] ?? 0, 0, ',', '.') }}
                                    </td>
                                    <td class="px-4 py-2.5 text-right tabular-nums text-ink-700/60">
                                        @if($this->andel($gruppe['count'] ?? 0) !== null)
                                            {{ number_format($this->andel($gruppe['count'] ?? 0), 1, ',', '.') }} %
                                        @else
                                            &ndash;
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="px-4 py-6 text-center text-ink-700/60">
                                    {{ __('Ingen selskaber matcher de valgte afgrænsninger.') }}
                                </td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <p class="text-xs text-ink-700/50 mt-3">
                    {{ __('Kilde: Det Centrale Virksomhedsregister (CVR). Branchekoder er registrets egne DB07-koder.') }}
                </p>
            @endif
        </section>
    </div>
</div>
