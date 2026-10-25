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
        @if(Route::has('metis.metode'))
            <a href="{{ route('metis.metode') }}" data-metode-link
               class="underline decoration-sand-300 underline-offset-2 hover:decoration-ink-700">{{ __('Læs om metode og definitioner') }}</a>
        @endif
    </p>

    @if(! $this->harAdgang())
        {{-- Segmenteringen er en licensydelse for navngivne brugere. Uden en
             bekraeftet pilotkonto vises hverken filtre eller tal, og API'et
             kaldes ikke (datalaget afviser desuden selv). --}}
        <div class="max-w-xl bg-sand-50 border border-sand-200 rounded-xl p-6">
            <h2 class="text-lg font-semibold text-ink-800 mb-2">{{ __('Kun for brugere med konto') }}</h2>
            <p class="text-sm text-ink-700/70 mb-4">
                {{ __('Selskabssegmentering er forbeholdt brugere, vi har en aftale med. Log ind med din konto eller bekræft din arbejdsmail, eller skriv til support@frankston.io for adgang.') }}
            </p>
            <div class="flex flex-wrap gap-2">
                @if(Route::has('metis.login'))
                    <a href="{{ route('metis.login') }}"
                       class="inline-block px-4 py-2 text-sm font-medium text-white bg-ink-800 rounded-lg hover:bg-ink-700 transition">
                        {{ __('Log ind') }}
                    </a>
                @endif
                {{-- Piloter paa mail-listen (metis.gating.pilot_users) har ingen
                     adgangskode; de kommer ind via EmailGate, som sender
                     `email-verified` tilbage til komponenten. --}}
                <button type="button" wire:click="$dispatch('show-email-gate')"
                        class="px-4 py-2 text-sm font-medium border border-sand-200 rounded-lg text-ink-800 hover:bg-white transition">
                    {{ __('Bekræft arbejdsmail') }}
                </button>
            </div>
        </div>
    @else
    <div class="grid md:grid-cols-[260px_1fr] gap-6">

        {{-- Filtre --}}
        {{-- 🪤 `halve` holder FELTNAVNE, ikke elementer: ved morph evaluerer
             Livewire `:class` paa en KLON, hvor `$el` ikke er det levende felt.
             🪤 Gaar value fra "" til "" (halv dato i tomt felt; resten af en
             halv dato slettet) fyrer browseren INTET event, saa `maal` maaler
             ogsaa paa tast og fokus-tab og sender selv den change, der fjerner
             filteret naar feltet er tømt helt.
             🪤 x-model skriver ikke "" i et felt hvis value allerede er "", saa
             Nulstil efterlod halve segmenter: `tomHalve` tømmer dem.
             `x-effect` maaler igen naar serveren skriver en dato (iværksætter,
             tilbage/frem), saa hintet ikke haenger ved et nu helt felt. --}}
        <aside class="bg-sand-50 border border-sand-200 rounded-xl p-4 h-fit"
               x-data="{
                   halve: [],
                   synk(f) {
                       this.halve = this.halve.filter(h => h !== f.dataset.felt).concat(f.validity.badInput ? [f.dataset.felt] : []);
                   },
                   synkAlle() { this.$root.querySelectorAll('[data-felt]').forEach(f => this.synk(f)); },
                   vagt(e) {
                       if (! e.target.dataset.felt) return;
                       if (e.target.validity.badInput) e.stopPropagation();
                       this.synk(e.target);
                   },
                   maal(e) {
                       if (! e.target.dataset.felt) return;
                       const varHalv = this.halve.includes(e.target.dataset.felt);
                       this.synk(e.target);
                       if (varHalv && e.target.value === '' && ! e.target.validity.badInput) {
                           // 🪤 x-model (`.change`) skriver vaerdien paa INPUT; `change` sender kun.
                           ['input', 'change'].forEach(t => e.target.dispatchEvent(new Event(t, { bubbles: true })));
                       }
                   },
                   tomHalve() {
                       // 🪤 WebKit/Safari ignorerer value='' naar value allerede er '' (maalt
                       // 13/10-2026), og valueAsDate=null henter den GAMLE dato frem. En gyldig
                       // dato foerst nulstiller segmenterne i alle tre motorer; fyrer intet event.
                       this.$root.querySelectorAll('[data-felt]').forEach(f => {
                           if (f.validity.badInput) { f.value = '2000-01-01'; f.value = ''; }
                       });
                       this.halve = [];
                   },
                   hint(felt) {
                       const dato = String($wire.get(felt) ?? '');
                       const etiket = this.$root.querySelector(`[data-felt='${felt}']`).getAttribute('aria-label');
                       return (dato
                           ? {{ Js::from(__(':felt er ikke udfyldt helt. Tallet bruger :dato.')) }}
                           : {{ Js::from(__(':felt er ikke udfyldt helt. Tallet tæller uden denne dato.')) }}
                       ).replace(':felt', etiket).replace(':dato', dato.split('-').reverse().join('.'));
                   },
               }"
               x-effect="$wire.foundedFrom; $wire.foundedTo; $wire.senesteForhoejelseFra; $wire.senesteForhoejelseTil; $nextTick(() => synkAlle())">
            <h2 class="text-xs uppercase tracking-wide text-sand-300 mb-3">{{ __('Fordel på') }}</h2>
            <select wire:model.live="groupBy"
                    class="w-full bg-white border border-sand-200 rounded-lg px-3 py-2 text-sm text-ink-800 mb-5"
                    aria-label="{{ __('Gruppering') }}">
                @foreach($this::GRUPPERINGER as $vaerdi => $navn)
                    <option value="{{ $vaerdi }}">{{ __($navn) }}</option>
                @endforeach
            </select>

            {{-- Koncernniveau (ERST Krav 1.1). Ikke et filter: samme selskaber,
                 men et selskab med moderselskab taeller under sin koncerntop. --}}
            <h2 class="text-xs uppercase tracking-wide text-sand-300 mb-3">{{ __('Tæl') }}</h2>
            <div class="mb-5" role="radiogroup" aria-label="{{ __('Tæl selskaber eller koncerner') }}" data-niveau>
                @foreach($this::NIVEAUER as $vaerdi => $navn)
                    <label class="flex items-center gap-2 mb-1.5 cursor-pointer">
                        <input type="radio" wire:model.live="niveau" value="{{ $vaerdi }}"
                               class="border-sand-300 text-warm-500 focus:ring-warm-500/30">
                        <span class="text-sm text-ink-800">{{ __($navn) }}</span>
                    </label>
                @endforeach
                <span class="block text-xs text-ink-700/50">{{ __('Koncerner: selskaber med samme moderselskab i toppen tæller én gang.') }}</span>
            </div>

            <h2 class="text-xs uppercase tracking-wide text-sand-300 mb-3">{{ __('Afgræns') }}</h2>

            <label class="flex items-start gap-2 mb-3 cursor-pointer">
                <input type="checkbox" wire:model.live="ivaerksaetter"
                       class="mt-0.5 rounded border-sand-300 text-warm-500 focus:ring-warm-500/30">
                <span class="text-sm text-ink-800">
                    {{ __('Kun iværksættervirksomheder') }}
                    <span class="block text-xs text-ink-700/50">{{ __('Erhvervsstyrelsens definition') }}</span>
                    <span class="block text-xs text-ink-700/50">{{ __('Foreslår en stiftelsesdato :aar år tilbage. Datoen kan rettes.', ['aar' => \TheFountainhead\Metis\Livewire\CompanySegmentation::IVAERKSAETTER_AAR]) }}</span>
                </span>
            </label>

            <label class="flex items-start gap-2 mb-3 cursor-pointer">
                <input type="checkbox" wire:model.live="excludeHolding"
                       class="mt-0.5 rounded border-sand-300 text-warm-500 focus:ring-warm-500/30">
                <span class="text-sm text-ink-800">{{ __('Uden holdingselskaber') }}</span>
            </label>

            <label class="flex items-start gap-2 mb-4 cursor-pointer">
                <input type="checkbox" wire:model.live="inklOphoerte"
                       class="mt-0.5 rounded border-sand-300 text-warm-500 focus:ring-warm-500/30">
                <span class="text-sm text-ink-800">
                    {{ __('Medtag ophørte selskaber') }}
                    <span class="block text-xs text-ink-700/50">{{ __('Som standard tæller kun selskaber, der findes i CVR i dag. Sæt flueben for fx at tælle iværksættere stiftet i en periode, også dem der siden er lukket.') }}</span>
                </span>
            </label>

            <label class="block text-sm text-ink-800 mb-1">{{ __('Branche (DB25)') }}</label>
            <input wire:model.change="industryPrefix" type="text" placeholder="{{ __('fx 62') }}"
                   class="w-full bg-white border border-sand-200 rounded-lg px-3 py-2 text-sm mb-4"
                   aria-label="{{ __('Branchekode eller præfiks') }}">

            <label class="block text-sm text-ink-800 mb-1">{{ __('Investortype blandt ejerne') }}</label>
            <select wire:model.change="investortype"
                    class="w-full bg-white border border-sand-200 rounded-lg px-3 py-2 text-sm text-ink-800 mb-1"
                    aria-label="{{ __('Investortype') }}">
                <option value="">{{ __('Alle') }}</option>
                @foreach($this::INVESTORTYPER as $vaerdi => $navn)
                    <option value="{{ $vaerdi }}">{{ __($navn) }}</option>
                @endforeach
            </select>
            @if(isset($this::INVESTORTYPE_FORKLARING[$investortype]))
                <p class="text-xs text-ink-700/60 mb-4">{{ __($this::INVESTORTYPE_FORKLARING[$investortype]) }}</p>
            @else
                <div class="mb-4"></div>
            @endif

            <label class="block text-sm text-ink-800 mb-1">{{ __('Vertikal') }}</label>
            <select wire:model.change="vertikal"
                    class="w-full bg-white border border-sand-200 rounded-lg px-3 py-2 text-sm text-ink-800 mb-4"
                    aria-label="{{ __('Vertikal') }}">
                <option value="">{{ __('Alle vertikaler') }}</option>
                @foreach($this::VERTIKALER as $vaerdi => $navn)
                    <option value="{{ $vaerdi }}">{{ __($navn) }}</option>
                @endforeach
            </select>

            <label class="block text-sm text-ink-800 mb-1">{{ __('Fintech') }}</label>
            <select wire:model.change="fintech"
                    class="w-full bg-white border border-sand-200 rounded-lg px-3 py-2 text-sm text-ink-800 mb-1"
                    aria-label="{{ __('Fintech') }}">
                <option value="">{{ __('Alle') }}</option>
                @foreach($this::FINTECH as $vaerdi => $navn)
                    <option value="{{ $vaerdi }}">{{ __($navn) }}</option>
                @endforeach
            </select>
            @if(isset($this::FINTECH_FORKLARING[$fintech]))
                <p class="text-xs text-ink-700/60 mb-4">{{ __($this::FINTECH_FORKLARING[$fintech]) }}</p>
            @else
                <div class="mb-4"></div>
            @endif

            <label class="block text-sm text-ink-800 mb-1">{{ __('Region') }}</label>
            <select wire:model.change="region"
                    class="w-full bg-white border border-sand-200 rounded-lg px-3 py-2 text-sm text-ink-800 mb-4"
                    aria-label="{{ __('Region') }}">
                <option value="">{{ __('Hele landet') }}</option>
                @foreach($this::REGIONER as $vaerdi => $navn)
                    <option value="{{ $vaerdi }}">{{ __($navn) }}</option>
                @endforeach
            </select>

            <label class="block text-sm text-ink-800 mb-1">{{ __('Kommunekode') }}</label>
            <input wire:model.change="municipalityCode" type="text" placeholder="{{ __('fx 101') }}"
                   class="w-full bg-white border border-sand-200 rounded-lg px-3 py-2 text-sm mb-4"
                   aria-label="{{ __('Kommunekode') }}">

            <label class="block text-sm text-ink-800 mb-1">{{ __('Stiftet mellem') }}</label>
            {{-- 🪤 `w-full` paa to felter i samme flex-raekke kraever 200 % + gap.
                 Normalt krymper flex dem, men `type="date"` har en IBOENDE
                 min-bredde fra datovaelgeren og kan ikke krympe under den —
                 saa det hoejre felt flyder ud af rammen. `min-w-0` ophaever
                 flex-elementers default `min-width: auto` og lader dem krympe. --}}
            {{-- 🪤 HALV DATO (fx dd.03.2021): browseren giver value="" med
                 `validity.badInput` og fyrer input+change. Naaede den Livewire,
                 forsvandt filteret, og tallet sprang til alle selskaber mens
                 feltet viste "03.2021". `vagt` stopper eventet i capture-fasen,
                 saa serveren beholder den seneste hele dato. Se `halve` paa aside. --}}
            <div class="mb-4" x-on:input.capture="vagt($event)" x-on:change.capture="vagt($event)"
                 x-on:keyup.capture="maal($event)" x-on:focusout.capture="maal($event)">
                <div class="flex gap-2">
                    <input wire:model.change="foundedFrom" type="date" data-felt="foundedFrom"
                           class="flex-1 min-w-0 bg-white border border-sand-200 rounded-lg px-2 py-2 text-xs"
                           :class="halve.includes('foundedFrom') && '!border-red-500'"
                           :aria-invalid="halve.includes('foundedFrom')"
                           aria-describedby="halv-dato-{{ $this->getId() }}"
                           aria-label="{{ __('Stiftet fra') }}">
                    <input wire:model.change="foundedTo" type="date" data-felt="foundedTo"
                           class="flex-1 min-w-0 bg-white border border-sand-200 rounded-lg px-2 py-2 text-xs"
                           :class="halve.includes('foundedTo') && '!border-red-500'"
                           :aria-invalid="halve.includes('foundedTo')"
                           aria-describedby="halv-dato-{{ $this->getId() }}"
                           aria-label="{{ __('Stiftet til') }}">
                </div>
                <div id="halv-dato-{{ $this->getId() }}" role="status" aria-live="polite" class="mt-1 text-xs text-red-700">
                    <template x-for="felt in halve.filter(f => ! f.startsWith('seneste'))" :key="felt">
                        <p data-halv-dato-hint x-text="hint(felt)"></p>
                    </template>
                </div>
            </div>

            <label class="block text-sm text-ink-800 mb-1">{{ __('Årsværk') }}</label>
            <div class="flex gap-2 mb-5">
                <input wire:model.change.number="fteMin" type="number" min="0" placeholder="{{ __('min') }}"
                       class="flex-1 min-w-0 bg-white border border-sand-200 rounded-lg px-2 py-2 text-sm"
                       aria-label="{{ __('Mindste antal årsværk') }}">
                <input wire:model.change.number="fteMax" type="number" min="0" placeholder="{{ __('maks') }}"
                       class="flex-1 min-w-0 bg-white border border-sand-200 rounded-lg px-2 py-2 text-sm"
                       aria-label="{{ __('Højeste antal årsværk') }}">
            </div>

            {{-- Kapital (ERST krav 2, udviklingsstadier). Kun det CVR viser:
                 antal forhoejelser, beloeb og dato. Ingen stadie-betegnelser.
                 Med et af felterne udfyldt taeller kun selskaber, hvor
                 kapitalhistorikken er hentet; siden siger hvor mange der faldt ud. --}}
            <h2 class="text-xs uppercase tracking-wide text-sand-300 mb-3" data-kapitalfiltre>{{ __('Kapital (udviklingsstadie)') }}</h2>

            <label class="block text-sm text-ink-800 mb-1">{{ __('Kapitalforhøjelser efter stiftelse') }}</label>
            <div class="flex gap-2 mb-4">
                <input wire:model.change.number="kapitalforhoejelserMin" type="number" min="0" placeholder="{{ __('min') }}"
                       class="flex-1 min-w-0 bg-white border border-sand-200 rounded-lg px-2 py-2 text-sm"
                       aria-label="{{ __('Mindste antal kapitalforhøjelser') }}">
                <input wire:model.change.number="kapitalforhoejelserMax" type="number" min="0" placeholder="{{ __('maks') }}"
                       class="flex-1 min-w-0 bg-white border border-sand-200 rounded-lg px-2 py-2 text-sm"
                       aria-label="{{ __('Højeste antal kapitalforhøjelser') }}">
            </div>

            <label class="block text-sm text-ink-800 mb-1">{{ __('Rejst kapital i alt (kr.)') }}</label>
            <div class="flex gap-2 mb-1">
                <input wire:model.change.number="rejstMin" type="number" min="0" step="1" placeholder="{{ __('min') }}"
                       class="flex-1 min-w-0 bg-white border border-sand-200 rounded-lg px-2 py-2 text-sm"
                       aria-label="{{ __('Mindst rejst kapital i kr.') }}">
                <input wire:model.change.number="rejstMax" type="number" min="0" step="1" placeholder="{{ __('maks') }}"
                       class="flex-1 min-w-0 bg-white border border-sand-200 rounded-lg px-2 py-2 text-sm"
                       aria-label="{{ __('Højst rejst kapital i kr.') }}">
            </div>
            <p class="text-xs text-ink-700/50 mb-4">{{ __('Kendte beløb lagt sammen. Beløbet mangler for mange ældre forhøjelser, så tallet er en nedre grænse.') }}</p>

            <label class="block text-sm text-ink-800 mb-1">{{ __('Seneste kapitalforhøjelse mellem') }}</label>
            <div class="mb-1" x-on:input.capture="vagt($event)" x-on:change.capture="vagt($event)"
                 x-on:keyup.capture="maal($event)" x-on:focusout.capture="maal($event)">
                <div class="flex gap-2">
                    <input wire:model.change="senesteForhoejelseFra" type="date" data-felt="senesteForhoejelseFra"
                           class="flex-1 min-w-0 bg-white border border-sand-200 rounded-lg px-2 py-2 text-xs"
                           :class="halve.includes('senesteForhoejelseFra') && '!border-red-500'"
                           :aria-invalid="halve.includes('senesteForhoejelseFra')"
                           aria-describedby="halv-dato-kapital-{{ $this->getId() }}"
                           aria-label="{{ __('Seneste kapitalforhøjelse fra') }}">
                    <input wire:model.change="senesteForhoejelseTil" type="date" data-felt="senesteForhoejelseTil"
                           class="flex-1 min-w-0 bg-white border border-sand-200 rounded-lg px-2 py-2 text-xs"
                           :class="halve.includes('senesteForhoejelseTil') && '!border-red-500'"
                           :aria-invalid="halve.includes('senesteForhoejelseTil')"
                           aria-describedby="halv-dato-kapital-{{ $this->getId() }}"
                           aria-label="{{ __('Seneste kapitalforhøjelse til') }}">
                </div>
                <div id="halv-dato-kapital-{{ $this->getId() }}" role="status" aria-live="polite" class="mt-1 text-xs text-red-700">
                    <template x-for="felt in halve.filter(f => f.startsWith('seneste'))" :key="felt">
                        <p data-halv-dato-hint x-text="hint(felt)"></p>
                    </template>
                </div>
            </div>
            <p class="text-xs text-ink-700/50 mb-5">{{ __('Fondsforhøjelser tæller ikke. Bruges et kapitalfilter, tæller kun selskaber, hvor kapitalhistorikken er hentet.') }}</p>

            <button wire:click="nulstil" x-on:click="tomHalve()" type="button"
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
                        <p class="text-sm text-ink-700/60" data-total-etiket>
                            @if($vistNiveau === 'koncern')
                                {{ __('koncerner i alt med de valgte afgrænsninger') }}
                            @else
                                {{ __('selskaber i alt med de valgte afgrænsninger') }}
                            @endif
                        </p>
                        @if($vistNiveau === 'koncern')
                            {{-- En koncern med selskaber i to grupper taeller i begge. --}}
                            <p class="text-xs text-ink-700/60 mt-1" data-koncern-note>
                                {{ __('En koncern med selskaber i flere grupper tæller med i hver af dem, så grupperne kan give mere end totalen.') }}
                            </p>
                        @endif
                        @if($ejerdata)
                            <p class="text-xs text-ink-700/60 mt-1" data-ejerdaekning>
                                {{ __('Investortype kendes for de :med af :af selskaber i udvalget, hvor ejerne er registreret. CVR viser kun ejere med mindst 5 %.', [
                                    'med' => number_format($ejerdata['med'], 0, ',', '.'),
                                    'af' => number_format($ejerdata['af'], 0, ',', '.'),
                                ]) }}
                            </p>
                        @endif
                        @if($udenKapitalhistorik !== null)
                            <p class="text-xs text-ink-700/60 mt-1" data-kapitaldaekning>
                                {{ __('Kun selskaber, hvor kapitalhistorikken er hentet. :antal :enhed i udvalget er udeladt, fordi den ikke er hentet.', [
                                    'antal' => number_format($udenKapitalhistorik, 0, ',', '.'),
                                    // Datalaget taeller tallet paa samme niveau som totalen.
                                    'enhed' => $vistNiveau === 'koncern' ? __('koncerner') : __('selskaber'),
                                ]) }}
                            </p>
                        @endif
                        @if($udenKendtBeloeb !== null)
                            <p class="text-xs text-ink-700/60 mt-1" data-kendt-beloeb>
                                {{ __('Højst rejst kapital tæller kun selskaber, hvor alle forhøjelser har et kendt beløb i kroner. :antal :enhed er udeladt, fordi et beløb mangler eller er i en anden valuta.', [
                                    'antal' => number_format($udenKendtBeloeb, 0, ',', '.'),
                                    'enhed' => $vistNiveau === 'koncern' ? __('koncerner') : __('selskaber'),
                                ]) }}
                            </p>
                        @endif
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                    <button wire:click="hentCsv" type="button" wire:loading.attr="disabled" wire:target="hentCsv"
                            class="inline-flex items-center gap-1.5 bg-warm-500 text-white rounded-lg px-4 py-2 text-sm font-semibold hover:bg-warm-600 transition disabled:opacity-60">
                        <span wire:loading.remove wire:target="hentCsv">{{ __('Hent til Excel (CSV)') }}</span>
                        <span wire:loading wire:target="hentCsv">{{ __('Danner udtræk…') }}</span>
                    </button>
                    {{-- Selskaberne bag hele udvalget, ikke tællingerne. Højst
                         10.000 (flest årsværk først); filen siger det, hvis den
                         er afkortet. --}}
                    <button wire:click="hentUdvalgCsv" type="button" wire:loading.attr="disabled" wire:target="hentUdvalgCsv"
                            title="{{ __('Højst 10.000 selskaber, flest årsværk først') }}"
                            class="inline-flex items-center gap-1.5 border border-warm-500 text-warm-600 rounded-lg px-4 py-2 text-sm font-semibold hover:bg-warm-50 transition disabled:opacity-60">
                        <span wire:loading.remove wire:target="hentUdvalgCsv">{{ __('Hent selskabslisten (CSV)') }}</span>
                        <span wire:loading wire:target="hentUdvalgCsv">{{ __('Danner udtræk…') }}</span>
                    </button>
                    </div>
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
                                @php $noegle = (string) ($gruppe['key'] ?? ''); @endphp
                                <tr wire:key="gruppe-{{ $noegle }}"
                                    class="border-t border-sand-200 {{ $valgtGruppe === $noegle ? 'bg-sand-50' : '' }}">
                                    <td class="px-4 py-2.5 text-ink-800">
                                        @if($noegle !== '')
                                            {{-- Klik viser selskaberne bag tallet (samme filtre). --}}
                                            <button type="button" wire:click="visSelskaber(@js($noegle))"
                                                    class="text-left underline decoration-sand-300 underline-offset-2 hover:decoration-ink-700">
                                                {{ $this->etiket($gruppe) }}
                                            </button>
                                        @else
                                            {{ $this->etiket($gruppe) }}
                                        @endif
                                        @if(in_array($groupBy, $this::VIS_KODE, true))
                                            <span class="text-xs text-ink-700/40">{{ $noegle }}</span>
                                        @endif
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
                                {{-- Kun naar der heller ingen er UDEN noegle: ellers stod
                                     "ingen matcher" over en linje med dem alle (review #209). --}}
                                @if(($udenSegmentering ?? 0) === 0)
                                    <tr><td colspan="3" class="px-4 py-6 text-center text-ink-700/60">
                                        {{ __('Ingen selskaber matcher de valgte afgrænsninger.') }}
                                    </td></tr>
                                @endif
                            @endforelse

                            {{-- 🔑 Uden denne linje summer tabellen ikke til totalen, og
                                 resten er usynlig (region uden filtre: 46 %). --}}
                            @if(($udenSegmentering ?? 0) > 0)
                                <tr wire:key="gruppe-uden-noegle" class="border-t border-sand-200 text-ink-700/70" data-uden-noegle>
                                    <td class="px-4 py-2.5 italic">
                                        {{ __($this::UDEN_NOEGLE[$groupBy] ?? 'Uden gruppe') }}
                                    </td>
                                    <td class="px-4 py-2.5 text-right tabular-nums">
                                        {{ number_format($udenSegmentering, 0, ',', '.') }}
                                    </td>
                                    <td class="px-4 py-2.5 text-right tabular-nums">
                                        @if($this->andel($udenSegmentering) !== null)
                                            {{ number_format($this->andel($udenSegmentering), 1, ',', '.') }} %
                                        @else
                                            &ndash;
                                        @endif
                                    </td>
                                </tr>
                            @endif
                        </tbody>
                    </table>
                </div>

                <p class="text-xs text-ink-700/50 mt-3">
                    {{ __('Klik på et navn for at se selskaberne bag tallet. Kilde: Det Centrale Virksomhedsregister (CVR). Branchekoder er registrets egne koder efter Dansk Branchekode 2025 (DB25).') }}
                    @if(Route::has('metis.metode'))
                        <a href="{{ route('metis.metode') }}" class="underline decoration-sand-300 underline-offset-2 hover:decoration-ink-700">{{ __('Sådan er tallene dannet') }}</a>
                    @endif
                </p>

                @if($valgtGruppe !== null)
                    {{-- 🔑 Listen ligger under hele gruppetabellen (op til 100 raekker),
                         saa uden at rulle saa et klik ud som "der sker ingenting"
                         (Frederik 11/10). Noeglen skifter med gruppe og side, saa
                         x-init koerer igen ved hvert klik og hver ny side. --}}
                    <div class="mt-6 border border-sand-200 rounded-xl scroll-mt-4" id="selskabsliste"
                         wire:key="selskabsliste-{{ $valgtGruppe }}-{{ $side }}"
                         x-data x-init="$el.scrollIntoView({ behavior: 'smooth', block: 'start' })">
                        <div class="flex flex-wrap items-baseline justify-between gap-3 px-4 py-3 bg-sand-50 border-b border-sand-200 rounded-t-xl">
                            <h2 class="font-semibold text-ink-800">
                                {{ $this->valgtEtiket() }}
                                @if($selskabTotal !== null)
                                    <span class="font-normal text-ink-700/60">
                                        &middot; {{ number_format($selskabTotal, 0, ',', '.') }} {{ __('selskaber') }}
                                    </span>
                                @endif
                            </h2>
                            <div class="flex items-baseline gap-4">
                                <button type="button" wire:click="hentSelskaberCsv" wire:loading.attr="disabled" wire:target="hentSelskaberCsv"
                                        class="text-sm font-semibold text-warm-600 hover:text-warm-700 disabled:opacity-60">
                                    <span wire:loading.remove wire:target="hentSelskaberCsv">{{ __('Hent til Excel (CSV)') }}</span>
                                    <span wire:loading wire:target="hentSelskaberCsv">{{ __('Danner udtræk…') }}</span>
                                </button>
                                <button type="button" wire:click="lukSelskaber" class="text-sm text-ink-700/70 hover:text-ink-800">
                                    {{ __('Luk') }}
                                </button>
                            </div>
                        </div>

                        @if($selskabFejl)
                            <div class="p-4 text-sm text-red-800">{{ $selskabFejl }}</div>
                        @else
                            <div class="overflow-x-auto" wire:loading.class="opacity-40" wire:target="visSelskaber,gaaTilSide">
                                <table class="w-full text-sm">
                                    <thead class="text-left">
                                        <tr>
                                            <th class="px-4 py-2 font-semibold text-ink-800">{{ __('Selskab') }}</th>
                                            <th class="px-4 py-2 font-semibold text-ink-800">{{ __('CVR') }}</th>
                                            <th class="px-4 py-2 font-semibold text-ink-800">{{ __('Branche') }}</th>
                                            <th class="px-4 py-2 font-semibold text-ink-800">{{ __('Stiftet') }}</th>
                                            <th class="px-4 py-2 font-semibold text-ink-800 text-right">{{ __('Højeste årsværk') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse($selskaber as $selskab)
                                            <tr wire:key="selskab-{{ $selskab['cvr'] ?? $loop->index }}" class="border-t border-sand-200">
                                                <td class="px-4 py-2 text-ink-800">
                                                    <x-metis-link type="cvr" :query="$selskab['cvr'] ?? ''"
                                                                  class="underline decoration-sand-300 underline-offset-2 hover:decoration-ink-700">{{ $selskab['name'] ?? '' }}</x-metis-link>
                                                </td>
                                                <td class="px-4 py-2 tabular-nums text-ink-700/70">{{ $selskab['cvr'] ?? '' }}</td>
                                                <td class="px-4 py-2 text-ink-700/70">{{ $selskab['industry_description'] ?? ($selskab['industry_code'] ?? '') }}</td>
                                                <td class="px-4 py-2 tabular-nums text-ink-700/70">
                                                    {{ $this->dato($selskab['founded_date'] ?? null) }}
                                                </td>
                                                <td class="px-4 py-2 text-right tabular-nums text-ink-800">{{ $selskab['max_fte'] ?? '–' }}</td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="5" class="px-4 py-6 text-center text-ink-700/60">{{ __('Ingen selskaber i gruppen.') }}</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>

                            @if($listeAfkortet)
                                <p class="px-4 py-2 border-t border-sand-200 text-xs text-ink-700/60">
                                    {{ __('Listen viser de første 10.000 selskaber. Brug filtrene til venstre for at indsnævre gruppen.') }}
                                </p>
                            @endif

                            @if($sider > 1)
                                <div class="flex items-center justify-between gap-3 px-4 py-3 border-t border-sand-200 text-sm">
                                    <button type="button" wire:click="gaaTilSide({{ $side - 1 }})" @disabled($side <= 1)
                                            class="px-3 py-1.5 border border-sand-200 rounded-lg disabled:opacity-40">← {{ __('Forrige') }}</button>
                                    <span class="text-ink-700/70">{{ __('Side') }} {{ $side }} {{ __('af') }} {{ number_format($sider, 0, ',', '.') }}</span>
                                    <button type="button" wire:click="gaaTilSide({{ $side + 1 }})" @disabled($side >= $sider)
                                            class="px-3 py-1.5 border border-sand-200 rounded-lg disabled:opacity-40">{{ __('Næste') }} →</button>
                                </div>
                            @endif
                        @endif
                    </div>
                @endif
            @endif
        </section>
    </div>
@endif
</div>
