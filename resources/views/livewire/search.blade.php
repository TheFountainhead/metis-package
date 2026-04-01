@php $hasResult = $resultType || $result || $error || $cprBlocked || $rateLimited; @endphp

<div id="main-content" class="min-h-screen flex flex-col">

    {{-- No result: centered hero --}}
    @unless($hasResult)
    <div class="flex flex-col items-center justify-center min-h-screen -mt-12 px-4">
        <div class="mb-8 flex items-center justify-center gap-3">
            <img src="/images/metis-logo.png" alt="Metis" class="w-10 h-10 md:w-12 md:h-12 -mt-1">
            <p class="text-3xl md:text-[40px] font-serif text-ink-800 font-normal tracking-tight">
                Hvad vil du vide?
            </p>
        </div>

        <div class="w-full max-w-[640px]">
            @include('metis::livewire.partials.search-input')

            @if(config('metis.turnstile.site_key'))
            <div class="cf-turnstile mt-3" data-sitekey="{{ config('metis.turnstile.site_key') }}" data-callback="onTurnstileSuccess" data-theme="light"></div>
            <script>function onTurnstileSuccess(token) { @this.set('turnstileToken', token); }</script>
            @endif

            {{-- Chips --}}
            @unless($loading)
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

        {{-- Footer --}}
        <div class="fixed bottom-0 left-0 right-0 pb-5 text-center pointer-events-none">
            <p class="text-sand-300 text-xs pointer-events-auto">
                <a href="mailto:info@frankston.io" class="hover:text-ink-700 transition-colors">Kontakt</a>
                <span class="mx-2">&middot;</span>
                <a href="#" class="hover:text-ink-700 transition-colors">API</a>
            </p>
        </div>
    </div>
    @endunless

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
                <livewire:metis-company-roles :query="$query" lazy :key="'cr-'.$query" />
                <livewire:metis-company-structure :query="$query" lazy :key="'cs-'.$query" />
                <livewire:metis-company-properties :query="$query" lazy :key="'cp-'.$query" />
                <livewire:metis-company-tax :query="$query" lazy :key="'ct-'.$query" />
            </div>
            @endif

            {{-- Address: full section components --}}
            @if($resultType === 'address' && !$result)
            <div class="space-y-6">
                <livewire:metis-map-panel :query="$query" lazy :key="'mp-'.$query" />
                <livewire:metis-address-bbr :query="$query" lazy :key="'ab-'.$query" />
                <livewire:metis-address-valuation :query="$query" lazy :key="'av-'.$query" />
                <livewire:metis-address-owners :query="$query" lazy :key="'ao-'.$query" />
                <livewire:metis-address-mortgages :query="$query" lazy :key="'am-'.$query" />
                <livewire:metis-address-transactions :query="$query" lazy :key="'at-'.$query" />
                <livewire:metis-address-comparison :query="$query" lazy :key="'ac-'.$query" />
                <livewire:metis-address-companies :query="$query" lazy :key="'acom-'.$query" />
                <livewire:metis-address-planning :query="$query" lazy :key="'ap-'.$query" />
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

                    @if($properties = $person['properties'] ?? [])
                    <div class="bg-white rounded-2xl p-5 border border-sand-200/60">
                        <h3 class="text-[11px] font-semibold text-warm-500 uppercase tracking-widest mb-3">Ejendomme via selskaber</h3>
                        @php $grouped = collect($properties)->groupBy('via_company'); @endphp
                        @foreach($grouped as $company => $props)
                            <div class="{{ !$loop->first ? 'mt-3 pt-3 border-t border-sand-100' : '' }}">
                                <div class="text-xs text-sand-300 mb-1">{{ $company }}</div>
                                @foreach($props as $prop)
                                    @php $addr = trim(($prop['address'] ?? '') . ', ' . ($prop['postal_code'] ?? '') . ' ' . ($prop['city'] ?? ''), ', '); @endphp
                                    <div class="flex justify-between items-center py-1">
                                        <span class="text-[14px] text-ink-800">{{ $addr ?: 'BFE ' . ($prop['matrikel_id'] ?? '?') }}</span>
                                        @if($addr)
                                        <button wire:click="crossReference('address', @js($addr))" class="text-warm-500 text-xs hover:text-warm-600 transition-colors">Slå op</button>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
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
