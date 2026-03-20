<div id="main-content" class="min-h-screen flex flex-col">
    {{-- Hero / Search Section --}}
    <div class="flex flex-col items-center justify-center {{ $result ? 'sticky top-0 z-30 bg-paper border-b border-linen py-4' : 'min-h-[70vh]' }} px-4 transition-all">
        <h1 class="font-heading text-3xl md:text-4xl font-bold text-text-primary tracking-heading mb-2">Metis</h1>
        <p class="text-text-secondary text-base mb-10">Alt hvad du behøver at vide</p>

        <div class="w-full max-w-search">
            <form wire:submit="search" role="search" aria-label="Søg i Metis">
                <div class="bg-white border border-linen rounded-3xl px-5 py-3.5 flex items-center gap-3 shadow-sm focus-within:ring-2 focus-within:ring-claret/20 transition">
                    <svg class="w-5 h-5 text-claret shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
                    </svg>
                    <input
                        wire:model="query"
                        type="text"
                        class="flex-1 bg-transparent border-none outline-none text-text-primary placeholder-text-muted text-sm focus:ring-0"
                        placeholder="Søg på person, virksomhed eller adresse..."
                        autofocus
                        aria-label="Søgefelt"
                    >
                </div>
            </form>

            @if(config('metis.turnstile.site_key'))
            <div class="cf-turnstile mt-3" data-sitekey="{{ config('metis.turnstile.site_key') }}" data-callback="onTurnstileSuccess" data-theme="light"></div>
            <script>
                function onTurnstileSuccess(token) {
                    @this.set('turnstileToken', token);
                }
            </script>
            @endif

            {{-- Suggestion Chips (only on landing, no result) --}}
            @unless($result || $error || $cprBlocked || $loading)
            <div class="flex overflow-x-auto md:flex-wrap justify-center gap-2 mt-5">
                @foreach($chips as $chip)
                    <button
                        wire:click="fillChip(@js($chip['query']))"
                        type="button"
                        class="bg-white border border-linen px-4 py-2 min-h-[44px] rounded-full text-xs text-text-secondary hover:bg-wheat/50 hover:text-text-primary transition cursor-pointer whitespace-nowrap"
                    >
                        {{ $chip['label'] }}
                    </button>
                @endforeach
            </div>
            @endunless
        </div>

        <div data-result-cache>
        {{-- Loading skeleton --}}
        @if($loading)
        <div class="w-full max-w-content mt-10 animate-pulse" aria-busy="true" aria-label="Henter resultater">
            <div class="bg-white rounded-lg p-6 border border-linen">
                <div class="h-6 bg-wheat rounded w-1/3 mb-4"></div>
                <div class="h-4 bg-wheat rounded w-2/3 mb-3"></div>
                <div class="h-4 bg-wheat rounded w-1/2"></div>
            </div>
        </div>
        @endif

        {{-- Error states --}}
        @if($error)
        <div class="w-full max-w-content mt-10" role="alert">
            <div class="bg-white rounded-lg p-6 border border-linen text-center">
                @if($errorMessage === 'permanent')
                    <p class="text-text-primary font-medium mb-2">Vi kan ikke hente data lige nu.</p>
                    <p class="text-text-secondary text-sm">Prøv igen senere eller <a href="mailto:info@frankston.io" class="text-claret underline">kontakt os</a>.</p>
                @elseif($errorMessage === 'no_results')
                    <p class="text-text-primary font-medium mb-2">Ingen resultater for "{{ $query }}".</p>
                    <p class="text-text-secondary text-sm">Prøv et andet søgeord.</p>
                @elseif($errorMessage === 'invalid_input')
                    <p class="text-text-primary font-medium mb-2">Vi kunne ikke genkende din søgning.</p>
                    <p class="text-text-secondary text-sm">Prøv et CVR-nummer, navn eller adresse.</p>
                @else
                    <p class="text-text-primary font-medium mb-2">Noget gik galt. Prøv igen om et øjeblik.</p>
                    <button wire:click="retry" class="mt-3 bg-claret text-white px-4 py-2 min-h-[44px] rounded-lg text-sm hover:bg-claret/90 transition">
                        Prøv igen
                    </button>
                @endif
            </div>
        </div>
        @endif

        {{-- CPR blocked --}}
        @if($cprBlocked)
        <div class="w-full max-w-content mt-10" role="alert">
            <div class="bg-white rounded-lg p-6 border border-linen text-center">
                <p class="text-text-primary font-medium mb-2">CPR-opslag kræver en Metis-konto.</p>
                <p class="text-text-secondary text-sm">Opret konto for at fortsætte.</p>
                <a href="#" class="inline-block mt-3 bg-teal text-white px-4 py-2 rounded-lg text-sm hover:bg-teal/90 transition">Opret konto</a>
            </div>
        </div>
        @endif

        {{-- Rate limited --}}
        @if($rateLimited)
        <div class="w-full max-w-content mt-10" role="alert" aria-live="polite">
            <div class="bg-white rounded-lg p-6 border border-linen text-center">
                <p class="text-text-primary font-medium mb-2">
                    Du har brugt dine opslag denne time.
                </p>
                @if($rateLimitResetsAt)
                <p class="text-text-secondary text-sm" x-data="{ reset: {{ $rateLimitResetsAt }} }" x-text="'Prøv igen om ' + Math.max(0, Math.ceil((reset - Date.now()/1000) / 60)) + ' minutter.'">
                </p>
                @endif
                <a href="#" class="inline-block mt-3 bg-teal text-white px-4 py-2 rounded-lg text-sm hover:bg-teal/90 transition">
                    Opret konto for flere opslag
                </a>
            </div>
        </div>
        @endif

        {{-- Company result (CVR search) --}}
        @if($result && $resultType === 'cvr')
        <div class="w-full max-w-content mt-10" aria-live="polite">
            @php $company = $result['company'] ?? []; @endphp

            <h2 class="font-heading text-2xl font-bold text-text-primary mb-1">{{ $company['name'] ?? 'Ukendt' }}</h2>
            <p class="text-sm text-text-secondary mb-6">CVR {{ $company['cvr'] ?? '' }} · <span class="text-green-700 font-semibold">{{ ($company['status'] ?? '') === 'NORMAL' ? 'Aktiv' : ($company['status'] ?? '') }}</span></p>

            <div class="bg-white border border-linen rounded-lg p-5 mb-3">
                <h3 class="text-xs font-bold text-teal uppercase tracking-wider mb-3">Virksomhed</h3>
                <dl class="grid grid-cols-[120px_1fr] gap-y-1.5 text-sm">
                    @if($company['founded'] ?? false)<dt class="text-text-muted">Stiftet</dt><dd>{{ $company['founded'] }}</dd>@endif
                    @if($company['industry'] ?? false)<dt class="text-text-muted">Branche</dt><dd>{{ $company['industry'] }}</dd>@endif
                    @if($company['type'] ?? false)<dt class="text-text-muted">Type</dt><dd>{{ $company['type'] }}</dd>@endif
                    @if($company['address'] ?? false)<dt class="text-text-muted">Adresse</dt><dd>{{ $company['address'] }}</dd>@endif
                </dl>
            </div>

            @if($persons = $result['persons'] ?? [])
            <div class="bg-white border border-linen rounded-lg p-5 mb-3">
                <h3 class="text-xs font-bold text-teal uppercase tracking-wider mb-3">Personer</h3>
                @foreach($persons as $person)
                <div class="flex justify-between items-center py-1.5 {{ !$loop->last ? 'border-b border-wheat' : '' }}">
                    <span class="text-sm">
                        <strong class="text-text-primary">{{ $person['name'] }}</strong>
                        <span class="text-text-muted">— {{ $person['role'] }}</span>
                    </span>
                    <button wire:click="crossReference('name', @js($person['name']))" class="text-claret text-xs font-semibold hover:underline">→ slå op</button>
                </div>
                @endforeach
            </div>
            @endif
        </div>
        @endif

        {{-- Company name search results --}}
        @if($result && $resultType === 'company_name')
        <div class="w-full max-w-content mt-10" aria-live="polite">
            <h2 class="font-heading text-xl font-bold text-text-primary mb-4">Virksomheder</h2>
            @foreach(array_slice($result['companies'] ?? [], 0, $visibleCompanies) as $company)
            <div class="bg-white border border-linen rounded-lg p-4 mb-2 flex justify-between items-center">
                <div>
                    <strong class="text-text-primary text-sm">{{ $company['name'] }}</strong>
                    <span class="text-text-muted text-xs ml-2">CVR {{ $company['cvr'] ?? '' }}</span>
                </div>
                <button wire:click="crossReference('cvr', @js($company['cvr'] ?? ''))" class="text-claret text-xs font-semibold hover:underline">→ slå op</button>
            </div>
            @endforeach
            @if(count($result['companies'] ?? []) > $visibleCompanies)
                <button wire:click="loadMore('companies')" class="mt-3 text-claret text-sm font-semibold hover:underline">Vis flere</button>
            @endif
        </div>
        @endif

        {{-- Person result (name search) --}}
        @if($result && $resultType === 'name')
        <div class="w-full max-w-content mt-10" aria-live="polite">
            @foreach(array_slice($result['persons'] ?? [], 0, $visiblePersons) as $person)
            <div class="mb-6">
                <h2 class="font-heading text-2xl font-bold text-text-primary mb-1">{{ $person['name'] }}</h2>

                @if($roles = $person['roles'] ?? [])
                <div class="bg-white border border-linen rounded-lg p-5 mb-3">
                    <h3 class="text-xs font-bold text-teal uppercase tracking-wider mb-3">Virksomhedsroller</h3>
                    @foreach($roles as $role)
                    <div class="flex justify-between items-center py-1.5 {{ !$loop->last ? 'border-b border-wheat' : '' }}">
                        <span class="text-sm">
                            <strong class="text-text-primary">{{ $role['company'] }}</strong>
                            <span class="text-text-muted">— {{ $role['role'] }}</span>
                            <span class="text-text-muted text-xs">CVR {{ $role['cvr'] }}</span>
                        </span>
                        <button wire:click="crossReference('cvr', @js($role['cvr']))" class="text-claret text-xs font-semibold hover:underline">→ slå op</button>
                    </div>
                    @endforeach
                </div>
                @endif
            </div>
            @endforeach
            @if(count($result['persons'] ?? []) > $visiblePersons)
                <button wire:click="loadMore('persons')" class="mt-3 text-claret text-sm font-semibold hover:underline">Vis flere</button>
            @endif
        </div>
        @endif

        {{-- Property result (address search) --}}
        @if($result && $resultType === 'address')
        <div class="w-full max-w-content mt-10" aria-live="polite">
            @php $property = $result['property'] ?? []; $valuation = $result['valuation'] ?? null; $owner = $result['owner'] ?? null; @endphp

            <h2 class="font-heading text-2xl font-bold text-text-primary mb-1">{{ $property['address'] ?? '' }}</h2>
            <p class="text-sm text-text-secondary mb-6">Matrikel: {{ $property['matrikel'] ?? '—' }} · BFE: {{ $property['bfe'] ?? '—' }}</p>

            {{-- Ejendom --}}
            <div class="bg-white border border-linen rounded-lg p-5 mb-3">
                <h3 class="text-xs font-bold text-teal uppercase tracking-wider mb-3">Ejendom</h3>
                <dl class="grid grid-cols-[120px_1fr] gap-y-1.5 text-sm">
                    @if($property['type'] ?? false)<dt class="text-text-muted">Type</dt><dd>{{ $property['type'] }}</dd>@endif
                    @if($property['area'] ?? false)<dt class="text-text-muted">Areal</dt><dd>{{ number_format($property['area'], 0, ',', '.') }} m²</dd>@endif
                    @if($property['built'] ?? false)<dt class="text-text-muted">Byggeår</dt><dd>{{ $property['built'] }}</dd>@endif
                    @if($property['municipality'] ?? false)<dt class="text-text-muted">Kommune</dt><dd>{{ $property['municipality'] }}</dd>@endif
                </dl>
            </div>

            {{-- Vurdering --}}
            @if($valuation)
            <div class="bg-white border border-linen rounded-lg p-5 mb-3">
                <h3 class="text-xs font-bold text-teal uppercase tracking-wider mb-3">Vurdering</h3>
                <dl class="grid grid-cols-[140px_1fr] gap-y-1.5 text-sm">
                    <dt class="text-text-muted">Ejendomsværdi</dt><dd>{{ number_format($valuation['property_value'], 0, ',', '.') }} kr</dd>
                    <dt class="text-text-muted">Grundværdi</dt><dd>{{ number_format($valuation['land_value'], 0, ',', '.') }} kr</dd>
                    <dt class="text-text-muted">Vurderingsår</dt><dd>{{ $valuation['year'] }}</dd>
                </dl>
            </div>
            @endif

            {{-- Vurdering error (partial failure) --}}
            @if($valuation === null && ($result['valuation_error'] ?? false))
            <div class="bg-white border border-linen rounded-lg p-5 mb-3">
                <h3 class="text-xs font-bold text-teal uppercase tracking-wider mb-3">Vurdering</h3>
                <div class="text-amber-700 bg-amber-50 rounded-lg p-3 text-sm flex items-center justify-between">
                    <span>Ejendomsdata er midlertidigt utilgængelig.</span>
                    <button wire:click="retrySection('valuation')" class="text-claret text-xs font-semibold hover:underline">Prøv igen</button>
                </div>
            </div>
            @endif

            {{-- Ejer --}}
            @if($owner)
            <div class="bg-white border border-linen rounded-lg p-5 mb-3">
                <h3 class="text-xs font-bold text-teal uppercase tracking-wider mb-3">Ejer</h3>
                <div class="flex justify-between items-center text-sm">
                    <span>{{ $owner['name'] }} · CVR {{ $owner['cvr'] ?? '' }}</span>
                    @if($owner['cvr'] ?? false)
                    <button wire:click="crossReference('cvr', @js($owner['cvr']))" class="text-claret text-xs font-semibold hover:underline">→ slå op</button>
                    @endif
                </div>
            </div>
            @endif

            {{-- Virksomheder på adressen --}}
            @if($companies = $result['companies_at_address'] ?? [])
            <div class="bg-white border border-linen rounded-lg p-5 mb-3">
                <h3 class="text-xs font-bold text-teal uppercase tracking-wider mb-3">Virksomheder på adressen</h3>
                @foreach(array_slice($companies, 0, $visibleCompanies) as $company)
                <div class="flex justify-between items-center py-1.5 {{ !$loop->last ? 'border-b border-wheat' : '' }}">
                    <span class="text-sm">
                        <strong class="text-text-primary">{{ $company['name'] }}</strong>
                        <span class="text-text-muted text-xs">CVR {{ $company['cvr'] }}</span>
                    </span>
                    <button wire:click="crossReference('cvr', @js($company['cvr']))" class="text-claret text-xs font-semibold hover:underline">→ slå op</button>
                </div>
                @endforeach
                @if(count($companies) > $visibleCompanies)
                    <button wire:click="loadMore('companies')" class="mt-3 text-claret text-sm font-semibold hover:underline">Vis flere</button>
                @endif
            </div>
            @endif
        </div>
        @endif
        </div>{{-- end data-result-cache --}}
    </div>

    {{-- Integrations Strip --}}
    @unless($result || $error || $cprBlocked)
    <div class="border-t border-linen py-10 text-center px-4">
        <p class="text-[11px] text-text-muted uppercase tracking-widest mb-2">Bygget i</p>
        <p class="text-sm text-text-secondary">
            Frankston Portefølje · M2Soft · NemComply ·
            <span class="text-claret">+ din platform via API</span>
        </p>
    </div>
    @endunless

    {{-- CTA Footer --}}
    <footer class="bg-teal py-12 px-4 text-center mt-auto">
        <h2 class="font-heading text-xl text-white font-bold mb-3">Klar til at komme i gang?</h2>
        <div class="flex justify-center gap-6 text-sm">
            <a href="#" class="text-teal-200 hover:text-white transition">Book en demo</a>
            <a href="#" class="text-teal-200 hover:text-white transition">API</a>
            <a href="mailto:info@frankston.io" class="text-teal-200 hover:text-white transition">Kontakt</a>
        </div>
    </footer>
</div>
