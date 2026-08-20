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
                    @foreach($forslag as $f)
                        @php $tekst = is_array($f) ? ($f['tekst'] ?? '') : $f; @endphp
                        @if($tekst)
                            <li>
                                <a href="{{ route('metis.lookup', ['type' => 'address', 'query' => $tekst]) }}"
                                   class="block px-4 py-3 text-sm hover:bg-zinc-50 dark:hover:bg-zinc-800 transition">
                                    {{ $tekst }}
                                </a>
                            </li>
                        @endif
                    @endforeach
                </ul>
            @else
                <p class="text-sm text-ink-500">
                    {{ __('Vi fandt ingen forslag til den adresse. Prøv at søge igen med postnummer.') }}
                </p>
            @endif
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
    <livewire:metis-lookup-title :type="$type" :query="$query" lazy />

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
                <livewire:metis-company-relations :query="$query" lazy="on-load" />
                <livewire:metis-company-properties :query="$query" lazy="on-load" />
                <livewire:metis-company-tinglysning :query="$query" lazy="on-load" />
            </div>
        @elseif($type === 'cpr')
            <div class="max-w-7xl space-y-6">
                <livewire:metis-person-summary :query="$query" lazy="on-load" />
            </div>
            <livewire:metis-person-structure :query="$query" lazy="on-load" />
            <div class="max-w-7xl space-y-6">
                <livewire:metis-person-companies :query="$query" lazy="on-load" />
                <livewire:metis-person-info :query="$query" lazy="on-load" />
                <livewire:metis-person-properties :query="$query" lazy="on-load" />
                <livewire:metis-person-relations :query="$query" lazy="on-load" />
            </div>
        @elseif($type === 'person')
            <livewire:metis-person-structure :query="$query" source="name" lazy="on-load" />
            <livewire:metis-person-roles :query="$query" lazy="on-load" />
        {{-- 🚨 `lazy="on-load"`, ikke bare `lazy`: almindelig lazy venter paa
             VIEWPORTEN, saa sektioner under skaermkanten hang for evigt paa
             "Henter data" (maalt 11/8: 5 haengende uden scroll, 0 efter).
             🪤 Attributten overskriver `#[Lazy(isolate: false)]` paa klassen. --}}
        @elseif($type === 'address')
            <livewire:metis-map-panel :query="$query" lazy="on-load" />
            <livewire:metis-address-bbr :query="$query" lazy="on-load" />
            <livewire:metis-address-valuation :query="$query" lazy="on-load" />
            <livewire:metis-address-skraafoto :query="$query" lazy="on-load" />
            <livewire:metis-address-owners :query="$query" lazy="on-load" />
            <livewire:metis-address-mortgages :query="$query" lazy="on-load" />
            <livewire:metis-address-transactions :query="$query" lazy="on-load" />
            <livewire:metis-address-similar-trades :query="$query" lazy="on-load" />
            <livewire:metis-address-comparison :query="$query" lazy="on-load" />
            <livewire:metis-address-companies :query="$query" lazy="on-load" />
            <livewire:metis-address-planning :query="$query" lazy="on-load" />
            <livewire:metis-address-energy :query="$query" lazy="on-load" />
            <livewire:metis-address-heritage :query="$query" lazy="on-load" />
        @endif
    </div>
    @endif
</div>
