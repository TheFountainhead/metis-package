<div x-data x-on:property-explore:download.window="window.location.href = $event.detail.url">
    <div class="flex items-center justify-between py-2 mb-4 border-b">
        <div class="flex items-center gap-3">
            <h1 class="text-xl font-bold">{{ __('Udforsk ejendomme') }}</h1>
            <span class="text-sm text-zinc-500">{{ __('Find ejendomme efter område og kriterier') }}</span>
        </div>
        @php
            $backRoute = Route::has('metis.index') ? 'metis.index' : (Route::has('metis.home') ? 'metis.home' : null);
        @endphp
        @if($backRoute)
            <a href="{{ route($backRoute) }}"
               class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm border rounded-lg hover:bg-zinc-50 transition">
                ← {{ __('Tilbage') }}
            </a>
        @endif
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-[28rem_1fr] gap-6">
        <aside class="space-y-4">
            <div class="p-4 border rounded-lg bg-white dark:bg-zinc-800 dark:border-zinc-700 space-y-4">
                <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-200 uppercase tracking-wide">{{ __('Område') }} <span class="text-red-500">*</span></h2>

                {{-- Kort med polygon-optegning (Leaflet + Leaflet.draw, CDN) --}}
                <div
                    wire:ignore
                    x-data="{
                        map: null, mapFailed: false,
                        async init() {
                            try {
                                await this.loadDeps();
                                this.initMap();
                            } catch (e) {
                                // CDN nede/blokeret/offline: fald tilbage til postnr/kommune
                                // frem for at efterlade en tom grå boks der hænger for evigt.
                                this.mapFailed = true;
                            }
                        },
                        loadDeps() {
                            return new Promise((resolve, reject) => {
                                if (window.L && window.L.Control && window.L.Control.Draw) { resolve(); return; }
                                // 8s timeout — resolver aldrig hvis onerror/onload aldrig fyrer.
                                const timer = setTimeout(() => reject(new Error('leaflet-timeout')), 8000);
                                const done = () => { if (window.L && window.L.Control && window.L.Control.Draw) { clearTimeout(timer); resolve(); } };
                                const addCss = (href) => { if (document.querySelector(`link[href='${href}']`)) return; const l = document.createElement('link'); l.rel='stylesheet'; l.href=href; document.head.appendChild(l); };
                                const addJs = (src, cb) => { const s = document.createElement('script'); s.src=src; s.onload=cb; s.onerror=() => { clearTimeout(timer); reject(new Error('leaflet-load-failed')); }; document.head.appendChild(s); };
                                addCss('https://unpkg.com/leaflet@1.9.4/dist/leaflet.css');
                                addCss('https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.css');
                                const loadDraw = () => addJs('https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.js', done);
                                if (window.L) { loadDraw(); } else { addJs('https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', loadDraw); }
                            });
                        },
                        initMap() {
                            this.map = L.map(this.$refs.drawmap, { scrollWheelZoom: false }).setView([55.6761, 12.5683], 11);
                            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(this.map);

                            const drawnItems = new L.FeatureGroup();
                            this.map.addLayer(drawnItems);

                            const drawControl = new L.Control.Draw({
                                draw: { polygon: true, marker: false, circle: false, rectangle: true, polyline: false, circlemarker: false },
                                edit: { featureGroup: drawnItems, edit: false },
                            });
                            this.map.addControl(drawControl);

                            this.map.on(L.Draw.Event.CREATED, (e) => {
                                drawnItems.clearLayers(); // kun ét område ad gangen
                                drawnItems.addLayer(e.layer);
                                const latlngs = e.layer.getLatLngs()[0].map((p) => ({ lat: p.lat, lng: p.lng }));
                                $wire.setPolygon(latlngs);
                            });

                            this.map.on(L.Draw.Event.DELETED, () => { $wire.clearPolygon(); });

                            // Når tekst-geo (postnr/kommune) overtager, rydder komponenten
                            // polygonen og beder kortet fjerne det tegnede lag.
                            Livewire.on('property-explore:clear-map', () => drawnItems.clearLayers());
                        },
                    }"
                    x-ref="drawmap"
                    class="w-full rounded border"
                    style="height: 520px; z-index: 0;"
                >
                    <template x-if="mapFailed">
                        <div class="flex items-center justify-center h-full text-xs text-zinc-500 text-center px-4">
                            {{ __('Kortet kunne ikke indlæses — brug postnummer eller kommune nedenfor.') }}
                        </div>
                    </template>
                </div>
                @if(count($polygon) >= 3)
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-emerald-600">✓ {{ __('Område optegnet (:n punkter)', ['n' => count($polygon)]) }}</span>
                        <button wire:click="clearPolygon" type="button" class="text-zinc-500 hover:text-zinc-700 underline">{{ __('Ryd') }}</button>
                    </div>
                @else
                    <p class="text-xs text-zinc-400">{{ __('Tegn et område på kortet, eller angiv postnummer/kommune nedenfor.') }}</p>
                @endif

                <div>
                    <label class="block text-xs font-medium text-zinc-600 mb-1">{{ __('Postnummer-interval') }}</label>
                    <div class="flex gap-2">
                        <input type="text" inputmode="numeric" maxlength="4" placeholder="1000"
                               wire:model.live.debounce.500ms="postalCodeFrom"
                               class="w-1/2 px-2 py-1 text-sm border rounded">
                        <input type="text" inputmode="numeric" maxlength="4" placeholder="2999"
                               wire:model.live.debounce.500ms="postalCodeTo"
                               class="w-1/2 px-2 py-1 text-sm border rounded">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-medium text-zinc-600 mb-1">{{ __('Kommunekode') }}</label>
                    <input type="text" inputmode="numeric" placeholder="{{ __('fx 101 (København)') }}"
                           wire:model.live.debounce.500ms="municipalityCode"
                           class="w-full px-2 py-1 text-sm border rounded">
                </div>

                <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-200 uppercase tracking-wide pt-2 border-t dark:border-zinc-700">{{ __('Forfin (valgfri)') }}</h2>

                <div>
                    <label class="block text-xs font-medium text-zinc-600 mb-1">{{ __('Offentlig vurdering (kr)') }}</label>
                    <div class="flex gap-2">
                        <input type="number" min="0" placeholder="{{ __('min') }}"
                               wire:model.live.debounce.500ms="valuationMin"
                               class="w-1/2 px-2 py-1 text-sm border rounded">
                        <input type="number" min="0" placeholder="{{ __('max') }}"
                               wire:model.live.debounce.500ms="valuationMax"
                               class="w-1/2 px-2 py-1 text-sm border rounded">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-medium text-zinc-600 mb-1">{{ __('Byggeår') }}</label>
                    <div class="flex gap-2">
                        <input type="number" min="1000" max="2100" placeholder="{{ __('fra') }}"
                               wire:model.live.debounce.500ms="yearBuiltFrom"
                               class="w-1/2 px-2 py-1 text-sm border rounded">
                        <input type="number" min="1000" max="2100" placeholder="{{ __('til') }}"
                               wire:model.live.debounce.500ms="yearBuiltTo"
                               class="w-1/2 px-2 py-1 text-sm border rounded">
                    </div>
                </div>

                <button wire:click="resetFilters" type="button"
                        class="w-full px-3 py-1.5 text-sm border rounded-lg hover:bg-zinc-50 transition">
                    {{ __('Nulstil') }}
                </button>
            </div>
        </aside>

        <main>
            @if($missingGeo)
                <div class="p-8 text-center text-zinc-500 border rounded-lg">
                    {{ __('Angiv et postnummer-interval eller en kommunekode for at søge.') }}
                </div>
            @elseif($loading)
                <div class="p-8 text-center text-zinc-500 border rounded-lg">
                    <span class="inline-block animate-pulse">{{ __('Henter ejendomme…') }}</span>
                </div>
            @elseif($quotaExceeded)
                <div class="p-8 text-center text-amber-600 border rounded-lg">
                    {{ __('Du har nået dagens søgekvote.') }}
                </div>
            @elseif($error)
                <div class="p-8 text-center text-red-600 border rounded-lg">{{ $error }}</div>
            @elseif($hasSearched && empty($response['data']['results'] ?? []))
                <div class="p-8 text-center text-zinc-500 border rounded-lg">
                    {{ __('Ingen ejendomme matcher dine kriterier i dette område.') }}
                </div>
            @elseif(! empty($response['data']['results'] ?? []))
                <div class="flex items-center justify-between mb-3">
                    <span class="text-sm text-zinc-500">{{ count($response['data']['results']) }} {{ __('ejendomme på denne side') }}</span>
                    @if($this->hasUserToken())
                        <button wire:click="downloadCsv" type="button"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm border rounded-lg hover:bg-zinc-50 transition">
                            ↓ {{ __('Eksportér CSV') }}
                        </button>
                    @endif
                </div>

                <div class="overflow-x-auto border rounded-lg">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b bg-zinc-50 dark:bg-zinc-800">
                                <th class="text-left py-2 px-3 font-medium text-zinc-500">{{ __('Adresse') }}</th>
                                <th class="text-left py-2 px-3 font-medium text-zinc-500">{{ __('Postnr') }}</th>
                                <th class="text-left py-2 px-3 font-medium text-zinc-500 cursor-pointer" wire:click="sortBy('year')">{{ __('Byggeår') }} ⇅</th>
                                <th class="text-right py-2 px-3 font-medium text-zinc-500">{{ __('Bygningsareal') }}</th>
                                <th class="text-right py-2 px-3 font-medium text-zinc-500">{{ __('Off. vurdering') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($response['data']['results'] as $p)
                                <tr class="border-b border-zinc-100 dark:border-zinc-800">
                                    <td class="py-2 px-3">
                                        @if($p['address'])
                                            <x-metis-link type="address" :query="trim(($p['address'] ?? '') . ', ' . ($p['postal_code'] ?? '') . ' ' . ($p['city'] ?? ''), ', ')" />
                                        @else
                                            <span class="text-zinc-400">{{ __('BFE') }} {{ $p['matrikel_id'] ?? '?' }}</span>
                                        @endif
                                    </td>
                                    <td class="py-2 px-3">{{ $p['postal_code'] ?? '-' }} {{ $p['city'] ?? '' }}</td>
                                    <td class="py-2 px-3">{{ $p['year_built'] ?? '-' }}</td>
                                    <td class="py-2 px-3 text-right">{{ $p['area_building'] ? number_format($p['area_building'], 0, ',', '.') . ' m²' : '-' }}</td>
                                    <td class="py-2 px-3 text-right">{{ $p['valuation'] ? number_format($p['valuation'], 0, ',', '.') . ' kr.' : '-' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="flex items-center justify-between mt-3">
                    <button wire:click="previousPage" @disabled(empty($cursorHistory))
                            class="px-3 py-1.5 text-sm border rounded-lg hover:bg-zinc-50 disabled:opacity-40 transition">← {{ __('Forrige') }}</button>
                    <button wire:click="nextPage" @disabled(empty($response['data']['has_more']))
                            class="px-3 py-1.5 text-sm border rounded-lg hover:bg-zinc-50 disabled:opacity-40 transition">{{ __('Næste') }} →</button>
                </div>
            @else
                <div class="p-8 text-center text-zinc-500 border rounded-lg">
                    {{ __('Angiv et område for at begynde.') }}
                </div>
            @endif
        </main>
    </div>
</div>
