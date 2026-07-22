@php $hasResult = $resultType || $result || $error || $cprBlocked || $rateLimited; @endphp

<div id="main-content" class="min-h-screen flex flex-col">

    {{-- Type-selection: when no mode chosen and no result --}}
    @if(! $hasResult && $searchMode === '')
    <div class="flex flex-col items-center justify-center min-h-[60vh] px-4">
        <div class="mb-10 flex items-center justify-center">
            <p class="text-3xl md:text-[40px] font-serif text-ink-800 font-normal tracking-tight">
                Hvad vil du søge på?
            </p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 w-full max-w-3xl">
            <button wire:click="setSearchMode('person')"
                    class="group p-8 bg-white border border-zinc-200 rounded-xl hover:border-blue-400 hover:shadow-md transition-all text-center">
                <div class="w-12 h-12 mx-auto mb-3 flex items-center justify-center rounded-full bg-blue-50 group-hover:bg-blue-100 transition">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                    </svg>
                </div>
                <h3 class="text-lg font-semibold text-zinc-900 mb-1">Person</h3>
                <p class="text-sm text-zinc-600">Søg efter person: roller, ejede selskaber, ejendomme</p>
            </button>

            <button wire:click="setSearchMode('company')"
                    class="group p-8 bg-white border border-zinc-200 rounded-xl hover:border-purple-400 hover:shadow-md transition-all text-center">
                <div class="w-12 h-12 mx-auto mb-3 flex items-center justify-center rounded-full bg-purple-50 group-hover:bg-purple-100 transition">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                    </svg>
                </div>
                <h3 class="text-lg font-semibold text-zinc-900 mb-1">Selskab</h3>
                <p class="text-sm text-zinc-600">Søg efter virksomhed: regnskaber, ejerstruktur, ejendomme</p>
            </button>

            <button wire:click="setSearchMode('address')"
                    class="group p-8 bg-white border border-zinc-200 rounded-xl hover:border-green-400 hover:shadow-md transition-all text-center">
                <div class="w-12 h-12 mx-auto mb-3 flex items-center justify-center rounded-full bg-green-50 group-hover:bg-green-100 transition">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                    </svg>
                </div>
                <h3 class="text-lg font-semibold text-zinc-900 mb-1">Ejendom</h3>
                <p class="text-sm text-zinc-600">Søg efter adresse: BBR, vurdering, ejere, transaktioner</p>
            </button>
        </div>

        <div class="mt-12 text-center">
            <p class="text-sm text-zinc-500 mb-3">Eller spring direkte til:</p>
            <div class="flex flex-wrap justify-center gap-2">
                <a href="{{ Route::has('metis.debt-search') ? route('metis.debt-search') : '/soeg' }}"
                   class="px-4 py-2 text-sm bg-white border border-zinc-200 rounded-full hover:border-zinc-400 hover:shadow-sm transition">
                    Søg gæld
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

    {{-- Type-locked search: mode selected, no result yet --}}
    @if(! $hasResult && $searchMode !== '')
    @php
        $modeConfig = [
            'person' => ['label' => 'person', 'placeholder' => 'Indtast personnavn...', 'color' => 'blue'],
            'company' => ['label' => 'selskab', 'placeholder' => 'Indtast selskabsnavn eller CVR...', 'color' => 'purple'],
            'address' => ['label' => 'adresse', 'placeholder' => 'Indtast adresse, fx Esrumvej 151, 3000...', 'color' => 'green'],
        ][$searchMode];
    @endphp
    <div class="flex flex-col items-center justify-center min-h-[60vh] px-4">
        <button wire:click="setSearchMode('')"
                class="mb-6 inline-flex items-center gap-1 text-sm text-zinc-600 hover:text-zinc-900">
            ← Vælg anden type
        </button>

        <div class="mb-6 flex items-center justify-center gap-3">
            <p class="text-2xl md:text-3xl font-serif text-ink-800 font-normal tracking-tight">
                Søg efter {{ $modeConfig['label'] }}
            </p>
        </div>

        <div class="w-full max-w-[640px]">
            @include('metis::livewire.partials.search-input', ['placeholder' => $modeConfig['placeholder']])

            @if(config('metis.turnstile.site_key'))
            <div class="cf-turnstile mt-3" data-sitekey="{{ config('metis.turnstile.site_key') }}" data-callback="onTurnstileSuccess" data-theme="light"></div>
            <script>function onTurnstileSuccess(token) { @this.set('turnstileToken', token); }</script>
            @endif
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
                    <h2 class="text-xl font-serif text-ink-800 mb-2">{{ $person['name'] }}</h2>
                    @if($roles = $person['roles'] ?? [])
                    <div class="bg-white rounded-2xl p-5 border border-sand-200/60">
                        <h3 class="text-[11px] font-semibold text-warm-500 uppercase tracking-widest mb-3">Roller</h3>
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
                            @if(($person['total_properties'] ?? 0) > 0 && empty($personProperties) && ! $loadingPersonProperties)
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
                    @if($personProperties && ! empty($personProperties['companies'] ?? []))
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
                                                    <button wire:click="crossReference('address', @js($prop['address']))" class="text-warm-500 text-xs hover:text-warm-600 shrink-0 ml-2">{{ __('Slå op') }}</button>
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
