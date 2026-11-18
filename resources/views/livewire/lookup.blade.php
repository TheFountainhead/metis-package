<div>
    {{-- Header: "Tilbage" (til forrige visning) + "Nyt opslag" (start forfra).
         Selskabs-CVR + navn vises i CompanyInfo-kortet; Metis-logo i app-layout. --}}
    <div class="flex justify-end gap-2 mb-4" x-data="{ canGoBack: window.history.length > 1 }">
        <button type="button" x-show="canGoBack" @click="window.history.back()"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm border rounded-lg hover:bg-zinc-50 dark:hover:bg-zinc-800 dark:border-zinc-700 transition">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
            {{ __('Tilbage') }}
        </button>
        <a href="{{ route(Route::has('metis.index') ? 'metis.index' : 'metis.home') }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm border rounded-lg hover:bg-zinc-50 dark:hover:bg-zinc-800 dark:border-zinc-700 transition">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" /></svg>
            {{ __('New lookup') }}
        </a>
    </div>

    @if($ufuldstaendigAdresse)
        {{-- 🚨 ADRESSE UDEN POSTNUMMER. Sektionerne maa IKKE renderes: de er
             `lazy` og ville hver isaer kalde registry-api med den ufuldstaendige
             adresse — praecis de 422'er guarden findes for at undgaa (Flare
             #9104992). Samme grund som ved kvote-gaten nedenfor: et flag alene
             stopper ikke et `lazy`-kald.

             Og "ingen data" maa ALDRIG staa her: vi ved ikke om ejendommen har
             pantebreve — vi ved ikke hvilken ejendom brugeren mener. --}}
        <div class="max-w-2xl mx-auto py-12">
            <h2 class="text-lg font-bold text-ink-800 mb-2">{{ __('Adressen mangler et postnummer') }}</h2>
            <p class="text-sm text-ink-600 mb-6">
                {{ __('Den samme vejadresse findes flere steder i landet, så vi kan ikke se hvilken ejendom du mener. Vælg den rigtige nedenfor.') }}
            </p>

            @if(count($forslag) > 0)
                <ul class="divide-y border rounded-lg dark:divide-zinc-700 dark:border-zinc-700">
                    {{-- Formen er allerede renset i Lookup::mount(): kun raekker
                         med en reel `tekst` naar hertil, saa en fejl-array fra
                         API'et ikke kan blive til opdigtede adresse-links. --}}
                    @foreach($forslag as $f)
                        <li>
                            <x-metis-link type="address" :query="$f['tekst']"
                                class="block px-4 py-3 text-sm hover:bg-zinc-50 dark:hover:bg-zinc-800 transition" />
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="text-sm text-ink-500">
                    {{ __('Vi fandt ingen forslag til den adresse. Prøv at søge igen med postnummer.') }}
                </p>
            @endif
        </div>
    @elseif($kraeverLogin)
        {{-- 🚨 Person- og CPR-opslag kraever tilmelding (Frederik 6/10-2026).
             Sektionerne maa IKKE renderes, af samme grund som ved kvote-gaten
             nedenfor. Dialogen er den samme `metis-email-gate`, aabnet af
             `show-email-gate` fra `mount()`. --}}
        <div class="max-w-2xl mx-auto text-center py-16">
            <h2 class="text-lg font-bold text-ink-800 mb-2">{{ __('Personopslag kræver at du er tilmeldt') }}</h2>
            <p class="text-sm text-ink-600 mb-4">{{ __('Tilmeld dig med navn og arbejdsmail for at se personopslag.') }}</p>
            <button type="button" wire:click="$dispatch('show-email-gate')" class="text-sm text-warm-500 hover:text-warm-600 transition-colors">{{ __('Tilmeld dig') }}</button>
        </div>
    @elseif($gated)
        {{-- 🚨 Kvote-gaten ramte. Sektionerne maa IKKE renderes: de er `lazy`,
             saa hver af dem ville selv hente sine data via en Livewire-POST og
             udlevere praecis det indhold gaten skal beskytte. Et skjult
             `<div hidden>` var ikke nok — kaldene ville stadig ske.

             Selve gate-dialogen ligger i standalone-layoutet
             (`<livewire:metis-email-gate />`) og aabnes af `show-email-gate`,
             som `mount()` udsender. --}}
        <div class="max-w-2xl mx-auto text-center py-16">
            <h2 class="text-lg font-bold text-ink-800 mb-2">{{ __('Du har brugt dine gratis opslag') }}</h2>
            <p class="text-sm text-ink-600">{{ __('Indtast din mail for at fortsætte.') }}</p>
        </div>
    @else
    {{-- Følg-knap kun for selskaber med gyldigt CVR og kun når brugeren må følge
         (LookupAccess::kanFoelge — samme regel som /overvaagede-selskaber). Knappen henter sin
         status efter indlæsning (wire:init), så den ikke blokerer siden; den er IKKE lazy, fordi
         #[Locked] + lazy brækker rehydreringen (compound 28/7-2026). --}}
    @php
        $foelgCvr = $type === 'cvr' ? preg_replace('/\D/', '', (string) $query) : '';
        $visFoelg = strlen($foelgCvr) === 8 && app(\TheFountainhead\Metis\Services\LookupAccess::class)->kanFoelge();
    @endphp
    <div class="flex items-start justify-between gap-4">
        <livewire:metis-lookup-title :type="$type" :query="$query" lazy :key="'title-'.$type.'-'.$query" />
        {{-- Liste-knappen: samme adgangsregel som Følg (brugerens eget token). Selskaber og ejendomme, ikke personer. --}}
        @php $visListe = in_array($type, ['cvr', 'address'], true) && ($type !== 'cvr' || strlen($foelgCvr) === 8) && app(\TheFountainhead\Metis\Services\LookupAccess::class)->kanFoelge(); @endphp
        @if($visFoelg || $visListe)
            <div class="flex items-start gap-2 shrink-0">
                @if($visListe)
                    <livewire:metis-list-button :type="$type" :query="$query" :key="'liste-'.$type.'-'.$query" />
                @endif
                @if($visFoelg)
                    <livewire:metis-follow-button watch-type="company" :watch-value="$foelgCvr" :key="'follow-company-'.$foelgCvr" />
                @endif
            </div>
        @endif
    </div>

    {{-- Graf-sektionerne (company-/person-structure) renderes UDEN FOR
         max-w-7xl-kolonnen som full-bleed: ejerskabsgrafen er det eneste
         element der reelt er begrænset af kolonnebredden (Frederiks ønske
         27/7 — "vandret format så man kan se mere af en virksomhedsstruktur").
         Tekstsektioner beholder kolonnen af læsbarhedshensyn. --}}
    <div class="space-y-6">
        @if($type === 'cvr')
            <div class="max-w-7xl space-y-6">
                <livewire:metis-company-overview :query="$query" lazy="on-load" />
                <livewire:metis-company-info :query="$query" lazy="on-load" />
                <livewire:metis-company-funding :query="$query" lazy="on-load" />
                <livewire:metis-company-roles :query="$query" lazy="on-load" />
            </div>
            <livewire:metis-company-structure :query="$query" lazy="on-load" />
            <div class="max-w-7xl space-y-6">
                <livewire:metis-company-koncern :query="$query" lazy="on-load" />
                <livewire:metis-company-relations :query="$query" lazy="on-load" />
                <livewire:metis-company-properties :query="$query" lazy="on-load" />
                <livewire:metis-company-tinglysning :query="$query" lazy="on-load" />
            </div>
        {{-- Ingen cpr-gren: CPR-opslag er lukket (9/10-2026), mount() sender til forsiden. --}}
        @elseif($type === 'person')
            <livewire:metis-person-structure :query="$query" lazy="on-load" />
            <livewire:metis-person-roles :query="$query" lazy="on-load" />
        {{-- 🚨 `lazy="on-load"`, ikke bare `lazy`: almindelig lazy venter paa
             VIEWPORTEN, saa sektioner under skaermkanten hang for evigt paa
             "Henter data" (maalt 11/8: 5 haengende uden scroll, 0 efter).
             🪤 Attributten overskriver `#[Lazy(isolate: false)]` paa klassen. --}}
        @elseif($type === 'address')
            {{-- Faner: man hopper mellem sektionerne i stedet for at scrolle en lang side.
                 ALLE sektioner renderes stadig og er lazy="on-load", saa fanerne aendrer IKKE
                 hvad der hentes eller hvornaar, kun hvad der er synligt. Skjult panel =
                 `style="display:none"` + x-show (x-cloak virker ikke uden vaertens CSS).
                 Siden kraever allerede Livewire+Alpine (alle sektioner er lazy), saa
                 display:none uden Alpine gaar ikke ud over noget der virkede uden JS.
                 Overblik er IKKE skjult server-side, saa et Alpine-nedbrud stadig viser den. --}}
            @php
                $faner = [
                    'overblik' => __('Overblik'),
                    'bbr' => __('BBR'),
                    'ejerforhold' => __('Ejerforhold'),
                    'tinglysning' => __('Tinglysning'),
                    'handler' => __('Handler'),
                    'omraade' => __('Område'),
                ];
            @endphp
            <div x-data="{
                    fane: 'overblik',
                    faner: @js(array_keys($faner)),
                    init() { this.fraHash(); },
                    fraHash() {
                        const h = window.location.hash.replace('#', '');
                        if (this.faner.includes(h)) this.skift(h);
                    },
                    skift(f) { this.fane = f; },
                    vaelg(f, fokus = false) {
                        this.skift(f);
                        history.replaceState(history.state, '', '#' + f);
                        this.$nextTick(() => {
                            const knap = document.getElementById('fane-' + f);
                            if (fokus) knap?.focus();
                            // Et kort panel efter et langt: faneraekken maa ikke havne over skaermkanten.
                            knap?.scrollIntoView({ block: 'nearest' });
                        });
                    },
                    flyt(retning) {
                        const i = (this.faner.indexOf(this.fane) + retning + this.faner.length) % this.faner.length;
                        this.vaelg(this.faner[i], true);
                    },
                }" @hashchange.window="fraHash()" wire:ignore.self class="space-y-6">
                <div role="tablist" aria-label="{{ __('Ejendomsoplysninger') }}"
                     class="flex gap-1 overflow-x-auto border-b border-zinc-200 dark:border-zinc-700"
                     @keydown.arrow-right.prevent="flyt(1)" @keydown.arrow-left.prevent="flyt(-1)"
                     @keydown.home.prevent="vaelg(faner[0], true)" @keydown.end.prevent="vaelg(faner[faner.length - 1], true)">
                    @foreach($faner as $id => $navn)
                        <button type="button" role="tab" id="fane-{{ $id }}"
                                aria-controls="panel-{{ $id }}"
                                :aria-selected="fane === '{{ $id }}' ? 'true' : 'false'"
                                :tabindex="fane === '{{ $id }}' ? 0 : -1"
                                @click="vaelg('{{ $id }}')"
                                :class="fane === '{{ $id }}' ? 'border-ink-800 text-ink-800 font-semibold' : 'border-transparent text-ink-600 hover:text-ink-800'"
                                class="px-4 py-2 text-sm whitespace-nowrap border-b-2 transition">
                            {{ $navn }}
                        </button>
                    @endforeach
                </div>

                <div role="tabpanel" id="panel-overblik" aria-labelledby="fane-overblik"
                     x-show="fane === 'overblik'" tabindex="0" wire:ignore.self class="space-y-6">
                    <livewire:metis-map-panel :query="$query" lazy="on-load" />
                    <livewire:metis-address-valuation :query="$query" lazy="on-load" />
                    <livewire:metis-address-skraafoto :query="$query" lazy="on-load" />
                </div>
                <div role="tabpanel" id="panel-bbr" aria-labelledby="fane-bbr"
                     x-show="fane === 'bbr'" style="display:none" tabindex="0" wire:ignore.self class="space-y-6">
                    <livewire:metis-address-bbr :query="$query" lazy="on-load" />
                    <livewire:metis-address-energy :query="$query" lazy="on-load" />
                </div>
                <div role="tabpanel" id="panel-ejerforhold" aria-labelledby="fane-ejerforhold"
                     x-show="fane === 'ejerforhold'" style="display:none" tabindex="0" wire:ignore.self class="space-y-6">
                    <livewire:metis-address-owners :query="$query" lazy="on-load" />
                    <livewire:metis-address-companies :query="$query" lazy="on-load" />
                </div>
                <div role="tabpanel" id="panel-tinglysning" aria-labelledby="fane-tinglysning"
                     x-show="fane === 'tinglysning'" style="display:none" tabindex="0" wire:ignore.self class="space-y-6">
                    <livewire:metis-address-mortgages :query="$query" lazy="on-load" />
                    <livewire:metis-address-transactions :query="$query" lazy="on-load" />
                </div>
                <div role="tabpanel" id="panel-handler" aria-labelledby="fane-handler"
                     x-show="fane === 'handler'" style="display:none" tabindex="0" wire:ignore.self class="space-y-6">
                    <livewire:metis-address-similar-trades :query="$query" lazy="on-load" />
                    <livewire:metis-address-comparison :query="$query" lazy="on-load" />
                </div>
                <div role="tabpanel" id="panel-omraade" aria-labelledby="fane-omraade"
                     x-show="fane === 'omraade'" style="display:none" tabindex="0" wire:ignore.self class="space-y-6">
                    <livewire:metis-address-planning :query="$query" lazy="on-load" />
                    <livewire:metis-address-heritage :query="$query" lazy="on-load" />
                </div>
            </div>
        @endif
        {{-- Brugerens egne noter på selskabet eller ejendommen. Ikke lazy: #[Locked] + lazy brækker rehydreringen;
             noterne hentes efter indlæsning (wire:init), så siden ikke venter. --}}
        @if($visListe ?? false)
            <livewire:metis-notes :type="$type" :query="$query" :key="'noter-'.$type.'-'.$query" />
        @endif
    </div>
    @endif
</div>
