<div id="main-content" class="min-h-screen flex flex-col">
    {{-- Hero / Search --}}
    <div class="flex flex-col items-center {{ $result ? 'pt-6 pb-4 sticky top-0 z-30 bg-surface/95 backdrop-blur-md border-b border-surface-border' : 'justify-center min-h-screen' }} px-4 transition-all duration-300">

        @unless($result)
        <div class="mb-8 text-center">
            <h1 class="text-4xl md:text-5xl font-light tracking-tight text-text-primary mb-3">Metis</h1>
            <p class="text-text-muted text-sm font-light">Ejendomme, virksomheder og personer</p>
        </div>
        @endunless

        <div class="w-full max-w-2xl">
            <form wire:submit="search" role="search" aria-label="Metis">
                <div class="relative group">
                    <div class="bg-surface-light border border-surface-border rounded-2xl px-5 py-4 flex items-center gap-3 transition-all duration-200 focus-within:border-accent/40 focus-within:bg-surface-lighter {{ $result ? 'rounded-xl py-3' : '' }}">
                        <svg class="w-4 h-4 text-text-muted shrink-0 group-focus-within:text-accent transition-colors" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
                        </svg>
                        <input
                            wire:model="query"
                            type="text"
                            class="flex-1 bg-transparent border-none outline-none text-text-primary placeholder-text-muted text-[15px] font-light focus:ring-0"
                            placeholder="Søg på person, virksomhed eller adresse..."
                            autofocus
                            aria-label="Søgefelt"
                        >
                        @if($result)
                        <button type="submit" class="text-accent hover:text-accent-hover text-sm font-medium transition-colors">Søg</button>
                        @endif
                    </div>
                </div>
            </form>

            @if(config('metis.turnstile.site_key'))
            <div class="cf-turnstile mt-3" data-sitekey="{{ config('metis.turnstile.site_key') }}" data-callback="onTurnstileSuccess" data-theme="dark"></div>
            <script>function onTurnstileSuccess(token) { @this.set('turnstileToken', token); }</script>
            @endif

            {{-- Suggestion Chips --}}
            @unless($result || $error || $cprBlocked || $loading)
            <div class="flex flex-wrap justify-center gap-2 mt-6">
                @foreach($chips as $chip)
                <button
                    wire:click="fillChip(@js($chip['query']))"
                    type="button"
                    class="border border-surface-border px-4 py-2.5 rounded-full text-[13px] text-text-secondary hover:text-text-primary hover:border-accent/30 hover:bg-surface-light transition-all duration-200"
                >
                    {{ $chip['label'] }}
                </button>
                @endforeach
            </div>
            @endunless
        </div>

        {{-- Loading --}}
        @if($loading)
        <div class="w-full max-w-2xl mt-8 space-y-3 animate-pulse">
            <div class="bg-surface-light rounded-xl p-6 border border-surface-border">
                <div class="h-5 bg-surface-lighter rounded w-1/3 mb-4"></div>
                <div class="h-3 bg-surface-lighter rounded w-2/3 mb-3"></div>
                <div class="h-3 bg-surface-lighter rounded w-1/2"></div>
            </div>
        </div>
        @endif

        {{-- Errors --}}
        @if($error)
        <div class="w-full max-w-2xl mt-8">
            <div class="bg-surface-light rounded-xl p-6 border border-surface-border text-center">
                @if($errorMessage === 'permanent')
                    <p class="text-text-primary font-medium mb-2">Vi kan ikke hente data lige nu.</p>
                    <p class="text-text-secondary text-sm">Prøv igen senere.</p>
                @elseif($errorMessage === 'no_results')
                    <p class="text-text-primary font-medium mb-2">Ingen resultater for "{{ $query }}"</p>
                    <p class="text-text-secondary text-sm">Prøv et andet søgeord.</p>
                @else
                    <p class="text-text-primary font-medium mb-2">Noget gik galt.</p>
                    <button wire:click="retry" class="mt-3 text-accent hover:text-accent-hover text-sm font-medium transition-colors">Prøv igen</button>
                @endif
            </div>
        </div>
        @endif

        {{-- CPR blocked --}}
        @if($cprBlocked)
        <div class="w-full max-w-2xl mt-8">
            <div class="bg-surface-light rounded-xl p-6 border border-surface-border text-center">
                <p class="text-text-primary font-medium mb-2">CPR-opslag kræver en Metis-konto.</p>
                <p class="text-text-secondary text-sm">Kontakt os for adgang.</p>
            </div>
        </div>
        @endif

        {{-- Rate limited --}}
        @if($rateLimited)
        <div class="w-full max-w-2xl mt-8">
            <div class="bg-surface-light rounded-xl p-6 border border-surface-border text-center">
                <p class="text-text-primary font-medium mb-2">Du har brugt dine opslag denne time.</p>
                @if($rateLimitResetsAt)
                <p class="text-text-secondary text-sm" x-data="{ reset: {{ $rateLimitResetsAt }} }" x-text="'Prøv igen om ' + Math.max(0, Math.ceil((reset - Date.now()/1000) / 60)) + ' min.'"></p>
                @endif
            </div>
        </div>
        @endif

        <div data-result-cache>
        {{-- CVR Result --}}
        @if($result && $resultType === 'cvr')
        <div class="w-full max-w-2xl mt-8 space-y-3">
            @php $company = $result['company'] ?? []; @endphp

            <div class="mb-4">
                <h2 class="text-2xl font-medium text-text-primary">{{ $company['name'] ?? 'Ukendt' }}</h2>
                <p class="text-sm text-text-muted mt-1">CVR {{ $company['cvr'] ?? '' }} · <span class="text-green-400">{{ ($company['status'] ?? '') === 'NORMAL' ? 'Aktiv' : ($company['status'] ?? '') }}</span></p>
            </div>

            <div class="bg-surface-light rounded-xl p-5 border border-surface-border">
                <h3 class="text-[11px] font-semibold text-accent uppercase tracking-widest mb-4">Virksomhed</h3>
                <dl class="grid grid-cols-[110px_1fr] gap-y-2.5 text-sm">
                    @if($company['founded'] ?? false)<dt class="text-text-muted">Stiftet</dt><dd>{{ $company['founded'] }}</dd>@endif
                    @if($company['industry'] ?? false)<dt class="text-text-muted">Branche</dt><dd>{{ $company['industry'] }}</dd>@endif
                    @if($company['type'] ?? false)<dt class="text-text-muted">Type</dt><dd>{{ $company['type'] }}</dd>@endif
                    @if($company['address'] ?? false)<dt class="text-text-muted">Adresse</dt><dd>{{ $company['address'] }}</dd>@endif
                </dl>
            </div>

            @if($persons = $result['persons'] ?? [])
            <div class="bg-surface-light rounded-xl p-5 border border-surface-border">
                <h3 class="text-[11px] font-semibold text-accent uppercase tracking-widest mb-4">Personer</h3>
                @foreach($persons as $person)
                <div class="flex justify-between items-center py-2.5 {{ !$loop->last ? 'border-b border-surface-border' : '' }}">
                    <span class="text-sm">
                        <span class="text-text-primary">{{ $person['name'] }}</span>
                        <span class="text-text-muted ml-1">{{ $person['role'] }}</span>
                    </span>
                    <button wire:click="crossReference('name', @js($person['name']))" class="text-accent text-xs hover:text-accent-hover transition-colors">Slå op</button>
                </div>
                @endforeach
            </div>
            @endif
        </div>
        @endif

        {{-- Company name search --}}
        @if($result && $resultType === 'company_name')
        <div class="w-full max-w-2xl mt-8 space-y-2">
            <h2 class="text-lg font-medium text-text-primary mb-3">Virksomheder</h2>
            @foreach(array_slice($result['companies'] ?? [], 0, $visibleCompanies) as $company)
            <div class="bg-surface-light rounded-xl p-4 border border-surface-border flex justify-between items-center hover:border-accent/20 transition-colors">
                <div>
                    <span class="text-sm text-text-primary">{{ $company['name'] }}</span>
                    <span class="text-text-muted text-xs ml-2">CVR {{ $company['cvr'] ?? '' }}</span>
                </div>
                <button wire:click="crossReference('cvr', @js($company['cvr'] ?? ''))" class="text-accent text-xs hover:text-accent-hover transition-colors">Slå op</button>
            </div>
            @endforeach
            @if(count($result['companies'] ?? []) > $visibleCompanies)
                <button wire:click="loadMore('companies')" class="mt-2 text-accent text-sm hover:text-accent-hover transition-colors">Vis flere</button>
            @endif
        </div>
        @endif

        {{-- Person/name result --}}
        @if($result && $resultType === 'name')
        <div class="w-full max-w-2xl mt-8 space-y-4">
            @foreach(array_slice($result['persons'] ?? [], 0, $visiblePersons) as $person)
            <div>
                <h2 class="text-xl font-medium text-text-primary mb-3">{{ $person['name'] }}</h2>
                @if($roles = $person['roles'] ?? [])
                <div class="bg-surface-light rounded-xl p-5 border border-surface-border">
                    <h3 class="text-[11px] font-semibold text-accent uppercase tracking-widest mb-4">Roller</h3>
                    @foreach($roles as $role)
                    <div class="flex justify-between items-center py-2.5 {{ !$loop->last ? 'border-b border-surface-border' : '' }}">
                        <span class="text-sm">
                            <span class="text-text-primary">{{ $role['company'] }}</span>
                            <span class="text-text-muted ml-1">{{ $role['role'] }}</span>
                        </span>
                        <button wire:click="crossReference('cvr', @js($role['cvr']))" class="text-accent text-xs hover:text-accent-hover transition-colors">Slå op</button>
                    </div>
                    @endforeach
                </div>
                @endif
            </div>
            @endforeach
        </div>
        @endif

        {{-- Address/property result --}}
        @if($result && $resultType === 'address')
        <div class="w-full max-w-2xl mt-8 space-y-3">
            @php $property = $result['property'] ?? []; $valuation = $result['valuation'] ?? null; $owner = $result['owner'] ?? null; @endphp

            <div class="mb-4">
                <h2 class="text-2xl font-medium text-text-primary">{{ $property['address'] ?? '' }}</h2>
                <p class="text-sm text-text-muted mt-1">{{ $property['matrikel'] ?? '' }}</p>
            </div>

            <div class="bg-surface-light rounded-xl p-5 border border-surface-border">
                <h3 class="text-[11px] font-semibold text-accent uppercase tracking-widest mb-4">Ejendom</h3>
                <dl class="grid grid-cols-[110px_1fr] gap-y-2.5 text-sm">
                    @if($property['type'] ?? false)<dt class="text-text-muted">Type</dt><dd>{{ $property['type'] }}</dd>@endif
                    @if($property['area'] ?? false)<dt class="text-text-muted">Areal</dt><dd>{{ number_format($property['area'], 0, ',', '.') }} m&sup2;</dd>@endif
                    @if($property['built'] ?? false)<dt class="text-text-muted">Byggeår</dt><dd>{{ $property['built'] }}</dd>@endif
                </dl>
            </div>

            @if($valuation)
            <div class="bg-surface-light rounded-xl p-5 border border-surface-border">
                <h3 class="text-[11px] font-semibold text-accent uppercase tracking-widest mb-4">Vurdering</h3>
                <dl class="grid grid-cols-[130px_1fr] gap-y-2.5 text-sm">
                    <dt class="text-text-muted">Ejendomsværdi</dt><dd>{{ number_format($valuation['property_value'], 0, ',', '.') }} kr</dd>
                    <dt class="text-text-muted">Grundværdi</dt><dd>{{ number_format($valuation['land_value'], 0, ',', '.') }} kr</dd>
                    <dt class="text-text-muted">År</dt><dd>{{ $valuation['year'] }}</dd>
                </dl>
            </div>
            @endif

            @if($valuation === null && ($result['valuation_error'] ?? false))
            <div class="bg-surface-light rounded-xl p-5 border border-surface-border">
                <div class="text-amber-400 text-sm flex items-center justify-between">
                    <span>Vurderingsdata midlertidigt utilgængelig.</span>
                    <button wire:click="retrySection('valuation')" class="text-accent text-xs hover:text-accent-hover transition-colors">Prøv igen</button>
                </div>
            </div>
            @endif

            @if($owner)
            <div class="bg-surface-light rounded-xl p-5 border border-surface-border">
                <h3 class="text-[11px] font-semibold text-accent uppercase tracking-widest mb-4">Ejer</h3>
                <div class="flex justify-between items-center text-sm">
                    <span>{{ $owner['name'] }}@if($owner['cvr'] ?? false) <span class="text-text-muted">CVR {{ $owner['cvr'] }}</span>@endif</span>
                    @if($owner['cvr'] ?? false)
                    <button wire:click="crossReference('cvr', @js($owner['cvr']))" class="text-accent text-xs hover:text-accent-hover transition-colors">Slå op</button>
                    @endif
                </div>
            </div>
            @endif

            @if($companies = $result['companies_at_address'] ?? [])
            <div class="bg-surface-light rounded-xl p-5 border border-surface-border">
                <h3 class="text-[11px] font-semibold text-accent uppercase tracking-widest mb-4">Virksomheder på adressen</h3>
                @foreach(array_slice($companies, 0, $visibleCompanies) as $company)
                <div class="flex justify-between items-center py-2.5 {{ !$loop->last ? 'border-b border-surface-border' : '' }}">
                    <span class="text-sm">
                        <span class="text-text-primary">{{ $company['name'] }}</span>
                        <span class="text-text-muted text-xs ml-1">CVR {{ $company['cvr'] }}</span>
                    </span>
                    <button wire:click="crossReference('cvr', @js($company['cvr']))" class="text-accent text-xs hover:text-accent-hover transition-colors">Slå op</button>
                </div>
                @endforeach
                @if(count($companies) > $visibleCompanies)
                    <button wire:click="loadMore('companies')" class="mt-2 text-accent text-sm hover:text-accent-hover transition-colors">Vis flere</button>
                @endif
            </div>
            @endif
        </div>
        @endif
        </div>
    </div>

    {{-- Footer --}}
    @unless($result || $error || $cprBlocked)
    <div class="fixed bottom-0 left-0 right-0 pb-6 pt-8 text-center bg-gradient-to-t from-surface via-surface to-transparent pointer-events-none">
        <p class="text-text-muted text-xs pointer-events-auto">
            <a href="mailto:info@frankston.io" class="hover:text-text-secondary transition-colors">Kontakt</a>
            <span class="mx-2">·</span>
            <a href="#" class="hover:text-text-secondary transition-colors">API</a>
            <span class="mx-2">·</span>
            <span>&copy; {{ date('Y') }} Frankston</span>
        </p>
    </div>
    @endunless
</div>
