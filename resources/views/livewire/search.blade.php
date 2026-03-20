<div id="main-content" class="min-h-screen flex flex-col">
    {{-- Centered Search --}}
    <div class="flex flex-col items-center {{ $result ? 'pt-5 pb-4 sticky top-0 z-30 bg-sand-50/95 backdrop-blur-sm' : 'justify-center min-h-screen -mt-12' }} px-4 transition-all duration-300">

        @unless($result)
        <div class="mb-8 flex items-center justify-center gap-3">
            <img src="/images/metis-logo.png" alt="Metis" class="w-10 h-10 md:w-12 md:h-12 -mt-1">
            <p class="text-3xl md:text-[40px] font-serif text-ink-800 font-normal tracking-tight">
                Hvad vil du vide?
            </p>
        </div>
        @endunless

        <div class="w-full max-w-[640px]">
            <form wire:submit="search" role="search">
                <div class="bg-white rounded-2xl {{ $result ? 'rounded-xl' : 'shadow-[0_1px_3px_rgba(0,0,0,0.08),0_8px_24px_rgba(0,0,0,0.04)]' }} border border-sand-200/60 px-5 {{ $result ? 'py-3' : 'py-4' }} flex items-center gap-3 transition-all focus-within:shadow-[0_1px_3px_rgba(0,0,0,0.08),0_8px_32px_rgba(0,0,0,0.08)] focus-within:border-sand-300">
                    <svg class="w-4 h-4 text-sand-300 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
                    </svg>
                    <input
                        wire:model="query"
                        type="text"
                        class="flex-1 bg-transparent border-none outline-none text-ink-800 placeholder-sand-300 text-[15px] focus:ring-0"
                        placeholder="Søg person, virksomhed eller adresse..."
                        autofocus
                    >
                    @if($result)
                    <button type="submit" class="text-warm-500 hover:text-warm-600 text-sm font-medium transition-colors">Søg</button>
                    @endif
                </div>
            </form>

            @if(config('metis.turnstile.site_key'))
            <div class="cf-turnstile mt-3" data-sitekey="{{ config('metis.turnstile.site_key') }}" data-callback="onTurnstileSuccess" data-theme="light"></div>
            <script>function onTurnstileSuccess(token) { @this.set('turnstileToken', token); }</script>
            @endif

            {{-- Chips --}}
            @unless($result || $error || $cprBlocked || $loading)
            <div class="flex flex-wrap justify-center gap-2 mt-5">
                @foreach($chips as $chip)
                <button
                    wire:click="fillChip(@js($chip['query']))"
                    type="button"
                    class="border border-sand-200 bg-white px-4 py-2 rounded-full text-[13px] text-ink-700/70 hover:text-ink-800 hover:border-sand-300 hover:shadow-sm transition-all"
                >
                    {{ $chip['label'] }}
                </button>
                @endforeach
            </div>
            @endunless
        </div>

        {{-- Loading --}}
        @if($loading)
        <div class="w-full max-w-[640px] mt-8 animate-pulse">
            <div class="bg-white rounded-2xl p-6 border border-sand-200/60">
                <div class="h-4 bg-sand-100 rounded w-1/3 mb-4"></div>
                <div class="h-3 bg-sand-100 rounded w-2/3 mb-3"></div>
                <div class="h-3 bg-sand-100 rounded w-1/2"></div>
            </div>
        </div>
        @endif

        {{-- Errors --}}
        @if($error)
        <div class="w-full max-w-[640px] mt-8">
            <div class="bg-white rounded-2xl p-6 border border-sand-200/60 text-center">
                @if($errorMessage === 'no_results')
                    <p class="text-ink-800 mb-1">Ingen resultater for "{{ $query }}"</p>
                    <p class="text-sand-300 text-sm">Prøv et andet søgeord.</p>
                @elseif($errorMessage === 'permanent')
                    <p class="text-ink-800 mb-1">Vi kan ikke hente data lige nu.</p>
                    <p class="text-sand-300 text-sm">Prøv igen senere.</p>
                @else
                    <p class="text-ink-800 mb-1">Noget gik galt.</p>
                    <button wire:click="retry" class="mt-2 text-warm-500 hover:text-warm-600 text-sm transition-colors">Prøv igen</button>
                @endif
            </div>
        </div>
        @endif

        {{-- CPR blocked --}}
        @if($cprBlocked)
        <div class="w-full max-w-[640px] mt-8">
            <div class="bg-white rounded-2xl p-6 border border-sand-200/60 text-center">
                <p class="text-ink-800 mb-1">CPR-opslag kræver en Metis-konto.</p>
                <p class="text-sand-300 text-sm">Kontakt os for adgang.</p>
            </div>
        </div>
        @endif

        {{-- Rate limited --}}
        @if($rateLimited)
        <div class="w-full max-w-[640px] mt-8">
            <div class="bg-white rounded-2xl p-6 border border-sand-200/60 text-center">
                <p class="text-ink-800 mb-1">Du har brugt dine opslag denne time.</p>
                @if($rateLimitResetsAt)
                <p class="text-sand-300 text-sm" x-data="{ reset: {{ $rateLimitResetsAt }} }" x-text="'Prøv igen om ' + Math.max(0, Math.ceil((reset - Date.now()/1000) / 60)) + ' min.'"></p>
                @endif
            </div>
        </div>
        @endif

        <div data-result-cache>
        {{-- CVR Result --}}
        @if($result && $resultType === 'cvr')
        <div class="w-full max-w-[640px] mt-6 space-y-3">
            @php $company = $result['company'] ?? []; @endphp

            <div class="mb-2">
                <h2 class="text-2xl font-serif text-ink-800">{{ $company['name'] ?? 'Ukendt' }}</h2>
                <p class="text-sm text-sand-300 mt-1">CVR {{ $company['cvr'] ?? '' }} · <span class="text-green-600">{{ ($company['status'] ?? '') === 'NORMAL' ? 'Aktiv' : ($company['status'] ?? '') }}</span></p>
            </div>

            <div class="bg-white rounded-2xl p-5 border border-sand-200/60">
                <h3 class="text-[11px] font-semibold text-warm-500 uppercase tracking-widest mb-3">Virksomhed</h3>
                <dl class="grid grid-cols-[110px_1fr] gap-y-2 text-[14px]">
                    @if($company['founded'] ?? false)<dt class="text-sand-300">Stiftet</dt><dd class="text-ink-800">{{ $company['founded'] }}</dd>@endif
                    @if($company['industry'] ?? false)<dt class="text-sand-300">Branche</dt><dd class="text-ink-800">{{ $company['industry'] }}</dd>@endif
                    @if($company['type'] ?? false)<dt class="text-sand-300">Type</dt><dd class="text-ink-800">{{ $company['type'] }}</dd>@endif
                    @if($company['address'] ?? false)<dt class="text-sand-300">Adresse</dt><dd class="text-ink-800">{{ $company['address'] }}</dd>@endif
                </dl>
            </div>

            @if($persons = $result['persons'] ?? [])
            <div class="bg-white rounded-2xl p-5 border border-sand-200/60">
                <h3 class="text-[11px] font-semibold text-warm-500 uppercase tracking-widest mb-3">Personer</h3>
                @foreach($persons as $person)
                <div class="flex justify-between items-center py-2 {{ !$loop->last ? 'border-b border-sand-100' : '' }}">
                    <span class="text-[14px]">
                        <span class="text-ink-800">{{ $person['name'] }}</span>
                        <span class="text-sand-300 ml-1">{{ $person['role'] }}</span>
                    </span>
                    <button wire:click="crossReference('name', @js($person['name']))" class="text-warm-500 text-xs hover:text-warm-600 transition-colors">Slå op</button>
                </div>
                @endforeach
            </div>
            @endif
        </div>
        @endif

        {{-- Company name search --}}
        @if($result && $resultType === 'company_name')
        <div class="w-full max-w-[640px] mt-6 space-y-2">
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
        <div class="w-full max-w-[640px] mt-6 space-y-3">
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
            </div>
            @endforeach
        </div>
        @endif

        {{-- Address/property --}}
        @if($result && $resultType === 'address')
        <div class="w-full max-w-[640px] mt-6 space-y-3">
            @php $property = $result['property'] ?? []; $valuation = $result['valuation'] ?? null; $owner = $result['owner'] ?? null; @endphp

            <div class="mb-2">
                <h2 class="text-2xl font-serif text-ink-800">{{ $property['address'] ?? '' }}</h2>
                <p class="text-sm text-sand-300 mt-1">{{ $property['matrikel'] ?? '' }}</p>
            </div>

            <div class="bg-white rounded-2xl p-5 border border-sand-200/60">
                <h3 class="text-[11px] font-semibold text-warm-500 uppercase tracking-widest mb-3">Ejendom</h3>
                <dl class="grid grid-cols-[110px_1fr] gap-y-2 text-[14px]">
                    @if($property['type'] ?? false)<dt class="text-sand-300">Type</dt><dd class="text-ink-800">{{ $property['type'] }}</dd>@endif
                    @if($property['area'] ?? false)<dt class="text-sand-300">Areal</dt><dd class="text-ink-800">{{ number_format($property['area'], 0, ',', '.') }} m&sup2;</dd>@endif
                    @if($property['built'] ?? false)<dt class="text-sand-300">Byggeår</dt><dd class="text-ink-800">{{ $property['built'] }}</dd>@endif
                </dl>
            </div>

            @if($valuation)
            <div class="bg-white rounded-2xl p-5 border border-sand-200/60">
                <h3 class="text-[11px] font-semibold text-warm-500 uppercase tracking-widest mb-3">Vurdering</h3>
                <dl class="grid grid-cols-[130px_1fr] gap-y-2 text-[14px]">
                    <dt class="text-sand-300">Ejendomsværdi</dt><dd class="text-ink-800">{{ number_format($valuation['property_value'], 0, ',', '.') }} kr</dd>
                    <dt class="text-sand-300">Grundværdi</dt><dd class="text-ink-800">{{ number_format($valuation['land_value'], 0, ',', '.') }} kr</dd>
                    <dt class="text-sand-300">År</dt><dd class="text-ink-800">{{ $valuation['year'] }}</dd>
                </dl>
            </div>
            @endif

            @if($valuation === null && ($result['valuation_error'] ?? false))
            <div class="bg-white rounded-2xl p-5 border border-sand-200/60">
                <div class="text-amber-600 text-sm flex items-center justify-between">
                    <span>Vurderingsdata midlertidigt utilgængelig.</span>
                    <button wire:click="retrySection('valuation')" class="text-warm-500 text-xs hover:text-warm-600 transition-colors">Prøv igen</button>
                </div>
            </div>
            @endif

            @if($owner)
            <div class="bg-white rounded-2xl p-5 border border-sand-200/60">
                <h3 class="text-[11px] font-semibold text-warm-500 uppercase tracking-widest mb-3">Ejer</h3>
                <div class="flex justify-between items-center text-[14px]">
                    <span class="text-ink-800">{{ $owner['name'] }}@if($owner['cvr'] ?? false) <span class="text-sand-300">CVR {{ $owner['cvr'] }}</span>@endif</span>
                    @if($owner['cvr'] ?? false)
                    <button wire:click="crossReference('cvr', @js($owner['cvr']))" class="text-warm-500 text-xs hover:text-warm-600 transition-colors">Slå op</button>
                    @endif
                </div>
            </div>
            @endif

            @if($companies = $result['companies_at_address'] ?? [])
            <div class="bg-white rounded-2xl p-5 border border-sand-200/60">
                <h3 class="text-[11px] font-semibold text-warm-500 uppercase tracking-widest mb-3">Virksomheder på adressen</h3>
                @foreach(array_slice($companies, 0, $visibleCompanies) as $company)
                <div class="flex justify-between items-center py-2 {{ !$loop->last ? 'border-b border-sand-100' : '' }}">
                    <span class="text-[14px]">
                        <span class="text-ink-800">{{ $company['name'] }}</span>
                        <span class="text-sand-300 text-xs ml-1">CVR {{ $company['cvr'] }}</span>
                    </span>
                    <button wire:click="crossReference('cvr', @js($company['cvr']))" class="text-warm-500 text-xs hover:text-warm-600 transition-colors">Slå op</button>
                </div>
                @endforeach
                @if(count($companies) > $visibleCompanies)
                    <button wire:click="loadMore('companies')" class="mt-2 text-warm-500 text-sm hover:text-warm-600 transition-colors">Vis flere</button>
                @endif
            </div>
            @endif
        </div>
        @endif
        </div>
    </div>

    {{-- Footer --}}
    @unless($result || $error || $cprBlocked)
    <div class="fixed bottom-0 left-0 right-0 pb-5 text-center pointer-events-none">
        <p class="text-sand-300 text-xs pointer-events-auto">
            <a href="mailto:info@frankston.io" class="hover:text-ink-700 transition-colors">Kontakt</a>
            <span class="mx-2">&middot;</span>
            <a href="#" class="hover:text-ink-700 transition-colors">API</a>
        </p>
    </div>
    @endunless
</div>
