@php $hasResult = $resultType || $result || $error || $cprBlocked || $rateLimited; @endphp

<div id="main-content" class="min-h-screen flex flex-col">

    {{-- Ét søgefelt. Ingen type-valg.

         🚨 Her stod to skærme: en tre-vejs menu ("Hvad vil du søge på?") og
         en type-låst søgeskærm bag den. Søgefeltet var gemt bag et valg
         brugeren ikke kan træffe kvalificeret — de ved hvad de HAR, ikke
         hvilken af vores kategorier det falder i.

         🎯 Resights-lektionen: ét felt, ingen modes. Vores opdeling kostede
         tre prod-fejl (CVR søgt som firmanavn, CPR søgt som personnavn,
         CPR på /lookup/cvr/ = 8 sektioner alle 422). Hver gang fordi
         inputtet ikke passede til den valgte mode.

         SearchDetector kender typen fra inputtets FORM. Disambiguering
         hører hjemme EFTER søgningen, ikke før. --}}
    @if(! $hasResult)
    <div class="flex flex-col items-center justify-center min-h-[60vh] px-4">
        <div class="mb-8 text-center">
            <p class="text-3xl md:text-[40px] font-serif text-ink-800 font-normal tracking-tight">
                Søg i danske selskaber og ejendomme
            </p>
            <p class="mt-3 text-sm text-sand-300">
                Person, virksomhed, CVR eller adresse — skriv hvad du har.
            </p>
        </div>

        <div class="w-full max-w-[640px]">
            @include('metis::livewire.partials.search-input')

            @if(config('metis.turnstile.site_key'))
            <div class="cf-turnstile mt-3" data-sitekey="{{ config('metis.turnstile.site_key') }}" data-callback="onTurnstileSuccess" data-theme="light"></div>
            <script>function onTurnstileSuccess(token) { @this.set('turnstileToken', token); }</script>
            @endif
        </div>

        @if(count($chips) > 0)
        <div class="mt-8 flex flex-wrap justify-center gap-2 max-w-2xl">
            @foreach($chips as $chip)
                <button wire:click="selectSuggestion(@js($chip['query']))"
                        class="px-3.5 py-1.5 text-sm text-ink-700 bg-white border border-sand-200/60 rounded-full hover:border-sand-300 hover:shadow-sm transition">
                    {{ $chip['label'] }}
                </button>
            @endforeach
        </div>
        @endif

        <div class="mt-10 text-center">
            <p class="text-sm text-sand-300 mb-3">Eller spring direkte til:</p>
            <div class="flex flex-wrap justify-center gap-2">
                <a href="{{ Route::has('metis.debt-search') ? route('metis.debt-search') : '/soeg' }}"
                   class="px-4 py-2 text-sm bg-white border border-zinc-200 rounded-full hover:border-zinc-400 hover:shadow-sm transition">
                    Søg gæld
                </a>
                {{-- 🔑 Uden en indgang her ville "spørg om noget" være usynlig:
                     ruten findes, men ingen ville finde den. --}}
                <a href="{{ Route::has('metis.analytics') ? route('metis.analytics') : '/spoerg' }}"
                   class="px-4 py-2 text-sm bg-white border border-zinc-200 rounded-full hover:border-zinc-400 hover:shadow-sm transition">
                    Spørg om markedet
                </a>
                @if(! empty(session('metis_user_token')))
                    <a href="{{ Route::has('metis.alerts') ? route('metis.alerts') : '/alerts' }}"
                       class="px-4 py-2 text-sm bg-white border border-zinc-200 rounded-full hover:border-zinc-400 hover:shadow-sm transition">
                        Mine alerts
                    </a>
                @endif
            </div>
        </div>
    </div>
    @endif

    {{-- Has result: content + sticky bottom search --}}
    @if($hasResult)
    <div class="flex-1 px-4 pt-6 pb-28">
        <div class="w-full max-w-[720px] mx-auto">

            {{-- Loading --}}
            @if($loading)
            <div class="animate-pulse">
                <div class="bg-white rounded-2xl p-6 border border-sand-200/60">
                    <div class="h-4 bg-sand-100 rounded w-1/3 mb-4"></div>
                    <div class="h-3 bg-sand-100 rounded w-2/3 mb-3"></div>
                    <div class="h-3 bg-sand-100 rounded w-1/2"></div>
                </div>
            </div>
            @endif

            {{-- Errors --}}
            @if($error)
            <div class="bg-white rounded-2xl p-6 border border-sand-200/60 text-center">
                @if($errorMessage === 'no_results')
                    @if(count($suggestions ?? []) > 0)
                        <p class="text-ink-800 mb-3">Mente du:</p>
                        <div class="space-y-2 max-w-md mx-auto">
                            @foreach($suggestions as $suggestion)
                                <button wire:click="selectSuggestion(@js($suggestion['tekst'] ?? ''))"
                                        class="block w-full text-left px-3 py-2 bg-sand-50 hover:bg-sand-100 rounded-lg border border-sand-200 transition">
                                    <span class="text-sm text-ink-800">{{ $suggestion['tekst'] ?? '' }}</span>
                                </button>
                            @endforeach
                        </div>
                    @elseif($suggestionType === 'address')
                        <p class="text-ink-800 mb-1">Vi kunne ikke finde "{{ $query }}"</p>
                        <p class="text-sand-300 text-sm">Skriv adressen med postnummer, fx Esrumvej 151, 3000.</p>
                    @else
                        <p class="text-ink-800 mb-1">Ingen resultater for "{{ $query }}"</p>
                        <p class="text-sand-300 text-sm">Prøv et andet søgeord.</p>
                    @endif
                @elseif($errorMessage === 'permanent')
                    <p class="text-ink-800 mb-1">Vi kan ikke hente data lige nu.</p>
                    <p class="text-sand-300 text-sm">Prøv igen senere.</p>
                @else
                    <p class="text-ink-800 mb-1">Noget gik galt.</p>
                    <button wire:click="retry" class="mt-2 text-warm-500 hover:text-warm-600 text-sm transition-colors">Prøv igen</button>
                @endif
            </div>
            @endif

            {{-- CPR blocked --}}
            @if($cprBlocked)
            <div class="bg-white rounded-2xl p-6 border border-sand-200/60 text-center">
                <p class="text-ink-800 mb-1">CPR-opslag kræver en Metis-konto.</p>
                <p class="text-sand-300 text-sm">Kontakt os for adgang.</p>
            </div>
            @endif

            {{-- Rate limited --}}
            @if($rateLimited)
            <div class="bg-white rounded-2xl p-6 border border-sand-200/60 text-center">
                <p class="text-ink-800 mb-1">Du har brugt dine opslag denne time.</p>
                @if($rateLimitResetsAt)
                <p class="text-sand-300 text-sm" x-data="{ reset: {{ $rateLimitResetsAt }} }" x-text="'Prøv igen om ' + Math.max(0, Math.ceil((reset - Date.now()/1000) / 60)) + ' min.'"></p>
                @endif
            </div>
            @endif

            {{-- CVR: full section components --}}
            @if($resultType === 'cvr' && !$result)
            <div class="space-y-6">
                <livewire:metis-company-info :query="$query" lazy :key="'ci-'.$query" />
                <livewire:metis-company-funding :query="$query" lazy :key="'cf-'.$query" />
                <livewire:metis-company-roles :query="$query" lazy :key="'cr-'.$query" />
                <livewire:metis-company-structure :query="$query" lazy :key="'cs-'.$query" />
                <livewire:metis-company-properties :query="$query" lazy :key="'cp-'.$query" />
                <livewire:metis-company-tinglysning :query="$query" lazy :key="'ctg-'.$query" />
            </div>
            @endif

            {{-- Address: full section components --}}
            @if($resultType === 'address' && !$result)
            <div class="space-y-6">
                {{-- Adresse-titel så man ved hvilken ejendom opslaget viser
                     (som navnet på selskabs-/personopslag). Kristian 22/7. --}}
                <h2 class="text-lg font-serif text-ink-800">{{ $query }}</h2>
                <livewire:metis-map-panel :query="$query" lazy :key="'mp-'.$query" />
                <livewire:metis-address-bbr :query="$query" lazy :key="'ab-'.$query" />
                <livewire:metis-address-valuation :query="$query" lazy :key="'av-'.$query" />
                <livewire:metis-address-skraafoto :query="$query" lazy :key="'ask-'.$query" />
                <livewire:metis-address-owners :query="$query" lazy :key="'ao-'.$query" />
                <livewire:metis-address-mortgages :query="$query" lazy :key="'am-'.$query" />
                <livewire:metis-address-transactions :query="$query" lazy :key="'at-'.$query" />
                <livewire:metis-address-similar-trades :query="$query" lazy :key="'ast-'.$query" />
                <livewire:metis-address-comparison :query="$query" lazy :key="'ac-'.$query" />
                <livewire:metis-address-companies :query="$query" lazy :key="'acom-'.$query" />
                <livewire:metis-address-planning :query="$query" lazy :key="'ap-'.$query" />
                <livewire:metis-address-energy :query="$query" lazy :key="'aen-'.$query" />
                <livewire:metis-address-heritage :query="$query" lazy :key="'ah-'.$query" />
            </div>
            @endif

            <div data-result-cache>
            {{-- Company name search --}}
            @if($result && $resultType === 'company_name')
            <div class="space-y-2">
                <h2 class="text-lg font-serif text-ink-800 mb-2">Virksomheder</h2>
                @foreach(array_slice($result['companies'] ?? [], 0, $visibleCompanies) as $company)
                <div class="bg-white rounded-2xl p-4 border border-sand-200/60 flex justify-between items-center hover:border-sand-300 transition-colors">
                    <div>
                        <span class="text-[14px] text-ink-800">{{ $company['name'] }}</span>
                        <span class="text-sand-300 text-xs ml-2">CVR {{ $company['cvr'] ?? '' }}</span>
                    </div>
                    <button wire:click="crossReference('cvr', @js($company['cvr'] ?? ''))" class="text-warm-500 text-xs hover:text-warm-600 transition-colors">Slå op</button>
                </div>
                @endforeach
                @if(count($result['companies'] ?? []) > $visibleCompanies)
                    <button wire:click="loadMore('companies')" class="mt-2 text-warm-500 text-sm hover:text-warm-600 transition-colors">Vis flere</button>
                @endif
            </div>
            @endif

            {{-- Person/name --}}
            @if($result && $resultType === 'name')
            <div class="space-y-3">
                @foreach(array_slice($result['persons'] ?? [], 0, $visiblePersons) as $person)
                <div>
                    {{-- Navn + vejen til ejerskabsgrafen (grafen bor KUN paa
                         lookup-siden; herfra var den foer helt uopnaaelig).

                         Knappen vises paa ALLE traef, ogsaa dem uden selskaber. At
                         skjule den ville kraeve et ekstra kald pr. person paa
                         soegetidspunktet, og et fravaer af knap ville i sig selv
                         laese som "denne person har ingenting" — en paastand vi ikke
                         har daekning for her. Lookup-siden har sin egen tom-tilstand
                         med CPR-noten, og den er det aerlige sted at sige det.

                         🪤 `rawurlencode` FOER route(): rute-segmentet er defineret
                         med `->where('query', '.*')`, og Laravel efterlader derfor
                         `?` og `#` RAA i URL'en. Browseren laeser dem som query- og
                         fragment-skilletegn, saa `Lookup::mount()` faar et AFKORTET
                         navn — intet 404, bare et tavst opslag paa en anden person.
                         Maalt paa prod 18/8: 1 af 2.023.590 navne har `?` (og det er
                         en tegnsaets-korruption), 0 har `#` — sjaeldent, men den
                         vaerste fejlklasse: forkert svar uden fejl. `/` (1.822 navne)
                         og `%` (125) haandterer Laravel korrekt, og encoding aendrer
                         dem ikke: verificeret at et normalt navn giver PRAECIS samme
                         URL med og uden, saa ingen dobbelt-encoding. --}}
                    {{-- 🚨 NAVN + KNAP I ET EGET HVIDT KORT, ikke loese elementer
                         paa den beige flade.

                         Tre forsoeg foer den ramte oplaegget:
                         #170  lille outline-knap hoejrestillet ved siden af navnet
                               — visuelt SVAGERE end "Vis alle ejendomme" laengere
                               nede, skoent den er sidens primaere NYE handling.
                         #171  fuld bredde + fyldt, men navn og knap svaevede paa
                               baggrunden mellem de hvide kort.
                         her   samme kort-stil som Roller/Ejer nedenfor, saa
                               personens header er ét kort paa linje med resten.

                         🪤 Farven ER rigtig og maa ikke "rettes": `warm-500` er
                         #7a1f1f (= claret), Metis' brandfarve efter
                         PropertyScope-rebrandet. Min egen mockup brugte #a8763e,
                         en okker jeg selv fandt paa i stedet for at laese
                         app.css — mockup'en var forkert, ikke koden. --}}
                    <div class="bg-white rounded-2xl p-5 border border-sand-200/60 mb-3">
                        <h2 data-testid="person-name" class="text-xl font-serif text-ink-800 mb-3">{{ $person['name'] }}</h2>
                        <x-metis-link type="person" :query="$person['name']"
                            data-testid="person-structure-link"
                            class="block w-full text-center text-sm px-4 py-2.5 bg-warm-500 text-white rounded-lg hover:bg-warm-600 transition-colors">
                            {{ __('Se selskabsstruktur') }} →
                        </x-metis-link>
                    </div>
                    @if($roles = $person['roles'] ?? [])
                    <div class="bg-white rounded-2xl p-5 border border-sand-200/60">
                        <h3 data-testid="person-roles-heading" class="text-[11px] font-semibold text-warm-500 uppercase tracking-widest mb-3">Roller</h3>
                        @foreach($roles as $role)
                        <div class="flex justify-between items-center py-2 {{ !$loop->last ? 'border-b border-sand-100' : '' }}">
                            <span class="text-[14px]">
                                <span class="text-ink-800">{{ $role['company'] }}</span>
                                <span class="text-sand-300 ml-1">{{ $role['role'] }}</span>
                            </span>
                            <button wire:click="crossReference('cvr', @js($role['cvr']))" class="text-warm-500 text-xs hover:text-warm-600 transition-colors">Slå op</button>
                        </div>
                        @endforeach
                    </div>
                    @endif

                    @if($owned = $person['owned_companies'] ?? [])
                    <div class="bg-white rounded-2xl p-5 border border-sand-200/60">
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="text-[11px] font-semibold text-warm-500 uppercase tracking-widest">
                                Ejer
                                @if($person['total_properties'] ?? 0)
                                    <span class="text-sand-300 font-normal normal-case ml-2">{{ $person['total_properties'] }} {{ __('ejendomme') }} via {{ count($owned) }} {{ __('selskaber') }}</span>
                                @endif
                            </h3>
                            @if(($person['total_properties'] ?? 0) > 0 && $personPropertiesStatus === 'pending' && ! $loadingPersonProperties)
                                <button wire:click="loadPersonProperties"
                                        class="text-xs px-3 py-1 bg-warm-500 text-white rounded hover:bg-warm-600 transition-colors">
                                    {{ __('Vis alle ejendomme') }} →
                                </button>
                            @endif
                        </div>
                        @foreach($owned as $company)
                        <div class="flex justify-between items-center py-2 {{ !$loop->last ? 'border-b border-sand-100' : '' }}">
                            <span class="text-[14px]">
                                <span class="text-ink-800">{{ $company['name'] }}</span>
                                @if($company['ownership'] ?? null)
                                    <span class="text-warm-500 ml-1">{{ number_format($company['ownership'], 0) }}%</span>
                                @endif
                                @if($company['property_count'] ?? 0)
                                    <span class="text-sand-300 text-xs ml-1">({{ $company['property_count'] }} ejd.)</span>
                                @endif
                            </span>
                            <button wire:click="crossReference('cvr', @js($company['cvr']))" class="text-warm-500 text-xs hover:text-warm-600 transition-colors">Slå op</button>
                        </div>
                        @endforeach
                    </div>
                    @endif

                    {{-- Loading indicator while aggregated portfolio fetches --}}
                    @if($loadingPersonProperties)
                        <div class="bg-white rounded-2xl p-8 border border-sand-200/60 text-center">
                            <div class="inline-block w-6 h-6 border-2 border-zinc-300 border-t-zinc-600 rounded-full animate-spin"></div>
                            <p class="mt-2 text-sm text-sand-300">{{ __('Henter ejendomsportefølje på tværs af selskaber...') }}</p>
                            <p class="text-xs text-sand-300 mt-1">{{ __('Kan tage 5-15 sekunder første gang.') }}</p>
                        </div>
                    @endif

                    {{-- Aggregated property portfolio across all owned companies --}}
                    @if($personPropertiesStatus === 'failed')
                        <div class="bg-white rounded-2xl p-6 border border-sand-200/60 text-center">
                            <p class="text-ink-800 mb-1">{{ __('Ejendomsporteføljen kunne ikke hentes.') }}</p>
                            <button wire:click="loadPersonProperties" class="text-sand-300 text-sm underline hover:text-ink-800 transition-colors">{{ __('Prøv igen') }}</button>
                        </div>
                    @endif

                    @if($personPropertiesStatus === 'permanent')
                        <div class="bg-white rounded-2xl p-6 border border-sand-200/60 text-center">
                            <p class="text-ink-800 mb-1">{{ __('Vi kan ikke hente data lige nu.') }}</p>
                            <p class="text-sand-300 text-sm">{{ __('Antal ejendomme pr. selskab står ovenfor.') }}</p>
                        </div>
                    @endif

                    {{-- 404 = navneopslaget fandt ingen person. Må ALDRIG sige
                         "ingen ejendomme": knappen vises kun når vi allerede har
                         skrevet "N ejendomme via M selskaber" ovenfor. --}}
                    @if($personPropertiesStatus === 'not_found')
                        <div class="bg-white rounded-2xl p-6 border border-sand-200/60 text-center">
                            <p class="text-ink-800 mb-1">{{ __('Vi kunne ikke slå ejendommene op på dette navn.') }}</p>
                            <p class="text-sand-300 text-sm">{{ __('Antal ejendomme pr. selskab står ovenfor.') }}</p>
                        </div>
                    @endif

                    @if($personPropertiesStatus === 'empty')
                        <div class="bg-white rounded-2xl p-6 border border-sand-200/60 text-center">
                            @if(($person['total_properties'] ?? 0) > 0)
                                {{-- Selvmodsigelse undgået: overskriften har lige
                                     sagt at der ER ejendomme. De to tal kommer fra
                                     hver sin kilde og kan divergere. --}}
                                <p class="text-ink-800 mb-1">{{ __('Ejendommene kunne ikke listes samlet.') }}</p>
                                <p class="text-sand-300 text-sm">{{ __('Antal ejendomme pr. selskab står ovenfor.') }}</p>
                            @else
                                <p class="text-ink-800">{{ __('Ingen ejendomme fundet på selskaberne.') }}</p>
                            @endif
                        </div>
                    @endif

                    @if($personPropertiesStatus === 'loaded')
                        <div class="bg-white rounded-2xl p-5 border border-sand-200/60">
                            <h3 class="text-[11px] font-semibold text-warm-500 uppercase tracking-widest mb-4">
                                {{ __('Alle ejendomme') }}
                                <span class="text-sand-300 font-normal normal-case ml-2">
                                    {{ $personProperties['summary']['total_properties'] ?? 0 }} {{ __('ejendomme') }}
                                    @if(($personProperties['summary']['total_valuation'] ?? 0) > 0)
                                        · {{ __('vurdering') }} {{ number_format($personProperties['summary']['total_valuation'] / 1_000_000, 1, ',', '.') }} {{ __('mio. kr.') }}
                                    @endif
                                </span>
                            </h3>

                            @foreach($personProperties['companies'] as $company)
                                @php $properties = $company['portfolio']['properties'] ?? []; @endphp
                                @if(! empty($properties))
                                    <div class="mb-5 last:mb-0">
                                        <div class="flex items-center gap-2 mb-2 pb-1 border-b border-sand-100">
                                            <span class="text-[13px] font-semibold text-ink-800">{{ $company['name'] }}</span>
                                            <span class="text-xs text-sand-300">{{ count($properties) }} {{ __('ejendomme') }}</span>
                                            @if($company['ownership_share'] ?? null)
                                                <span class="text-xs text-warm-500">{{ number_format($company['ownership_share'], 0) }}%</span>
                                            @endif
                                            <button wire:click="crossReference('cvr', @js($company['cvr']))" class="ml-auto text-warm-500 text-xs hover:text-warm-600">{{ __('Selskab') }} →</button>
                                        </div>
                                        @foreach($properties as $prop)
                                            <div class="flex justify-between items-start py-1.5 text-[13px]">
                                                <div class="flex-1 min-w-0">
                                                    <div class="text-ink-800 truncate">{{ $prop['address'] ?? $prop['matrikel_id'] ?? '—' }}</div>
                                                    <div class="text-xs text-sand-300">
                                                        @if($prop['building_year'] ?? null)
                                                            {{ __('opført') }} {{ $prop['building_year'] }}
                                                        @endif
                                                        @if($prop['building_area'] ?? null)
                                                            · {{ $prop['building_area'] }} m²
                                                        @endif
                                                        @if($prop['valuation'] ?? null)
                                                            · {{ __('vurdering') }} {{ number_format($prop['valuation'] / 1000, 0, ',', '.') }} t.kr.
                                                        @endif
                                                    </div>
                                                </div>
                                                @if($prop['address'] ?? null)
                                                    {{-- 🪤 Feltet hedder `postal_code` her (person-property-portfolio);
                                                         CPR-stiens `personal_properties` kalder det `zip`. Laes
                                                         payloadens egne noegler — se testens docblock for hvorfor
                                                         en adresse uden postnummer giver 12 tomme sektioner. --}}
                                                    @php
                                                        $addr = trim(($prop['address'] ?? '').', '.($prop['postal_code'] ?? '').' '.($prop['city'] ?? ''), ', ');
                                                    @endphp
                                                    <button wire:click="crossReference('address', @js($addr))" class="text-warm-500 text-xs hover:text-warm-600 shrink-0 ml-2">{{ __('Slå op') }}</button>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    @endif
                </div>
                @endforeach
            </div>
            @endif
            </div>
        </div>
    </div>

    {{-- Sticky bottom search bar --}}
    <div class="fixed bottom-0 left-0 right-0 z-30 bg-gradient-to-t from-paper via-paper to-transparent pt-6 pb-4 px-4">
        <div class="w-full max-w-[640px] mx-auto">
            @include('metis::livewire.partials.search-input')
        </div>
    </div>
    @endif
</div>
