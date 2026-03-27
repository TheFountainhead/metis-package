<div>
    @php
        $subject = $comparison['subject'] ?? [];
        $soldRefs = $comparison['sold_references'] ?? [];
        $listedRefs = $comparison['listed_references'] ?? [];
        $allMarkers = [];

        if (($subject['lat'] ?? null) && ($subject['lng'] ?? null)) {
            $allMarkers[] = ['lat' => $subject['lat'], 'lng' => $subject['lng'], 'color' => 'red', 'label' => $subject['address'] ?? ''];
        }
        foreach ($soldRefs as $ref) {
            if (($ref['lat'] ?? null) && ($ref['lng'] ?? null)) {
                $allMarkers[] = ['lat' => $ref['lat'], 'lng' => $ref['lng'], 'color' => 'blue', 'label' => ($ref['address'] ?? '') . ' — ' . number_format($ref['price'] ?? 0, 0, ',', '.') . ' DKK'];
            }
        }
        foreach ($listedRefs as $ref) {
            if (($ref['lat'] ?? null) && ($ref['lng'] ?? null)) {
                $allMarkers[] = ['lat' => $ref['lat'], 'lng' => $ref['lng'], 'color' => 'green', 'label' => ($ref['address'] ?? '') . ' — ' . number_format($ref['price'] ?? 0, 0, ',', '.') . ' DKK'];
            }
        }
    @endphp

    <div
        wire:ignore
        x-data="{
            map: null,
            markers: @js($allMarkers),

            async init() {
                await this.loadLeaflet();
                this.initMap();
            },

            initMap() {
                const defaultLat = 55.6761;
                const defaultLng = 12.5683;

                const osm = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '&copy; OpenStreetMap contributors',
                    maxZoom: 19,
                });

                const satellite = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
                    attribution: '&copy; Esri',
                    maxZoom: 19,
                });

                this.map = L.map(this.$refs.detailMap, {
                    scrollWheelZoom: false,
                    layers: [osm],
                }).setView([defaultLat, defaultLng], 7);

                L.control.layers(
                    { 'OpenStreetMap': osm, 'Satellite': satellite },
                    {},
                    { position: 'topright' }
                ).addTo(this.map);

                const iconUrl = 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/';
                const bounds = [];

                for (const m of this.markers) {
                    const icon = new L.Icon({
                        iconUrl: iconUrl + 'marker-icon-2x-' + m.color + '.png',
                        shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/images/marker-shadow.png',
                        iconSize: [25, 41],
                        iconAnchor: [12, 41],
                        popupAnchor: [1, -34],
                        shadowSize: [41, 41],
                    });

                    const marker = L.marker([m.lat, m.lng], { icon }).addTo(this.map);
                    if (m.label) {
                        marker.bindPopup(m.label);
                    }
                    bounds.push([m.lat, m.lng]);
                }

                if (bounds.length > 0) {
                    this.map.fitBounds(bounds, { padding: [30, 30], maxZoom: 16 });
                }
            },

            loadLeaflet() {
                return new Promise((resolve) => {
                    if (window.L) { resolve(); return; }
                    const css = document.createElement('link');
                    css.rel = 'stylesheet';
                    css.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
                    document.head.appendChild(css);
                    const js = document.createElement('script');
                    js.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
                    js.onload = () => resolve();
                    document.head.appendChild(js);
                });
            }
        }"
        x-ref="detailMap"
        class="h-[300px] rounded-lg"
        style="z-index: 0;"
    ></div>

    @if (count($soldRefs) > 0)
        <div class="mt-4">
            <div class="text-xs font-semibold text-zinc-400 uppercase mb-2">{{ __('Solgte referencer') }} ({{ count($soldRefs) }})</div>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($soldRefs as $ref)
                    <div class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden">
                        @if ($ref['image_url'] ?? null)
                            <img src="{{ $ref['image_url'] }}" alt="{{ $ref['address'] }}" class="w-full h-32 object-cover">
                        @else
                            <div class="w-full h-32 bg-zinc-100 dark:bg-zinc-700 flex items-center justify-center text-zinc-400">
                                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 0h.008v.008h-.008V7.5z" /></svg>
                            </div>
                        @endif
                        <div class="p-3">
                            <div class="font-semibold text-sm">{{ $ref['address'] }}</div>
                            <div class="text-xs text-zinc-500 mb-1">{{ $ref['postal_code'] ?? '' }} {{ $ref['city'] ?? '' }}</div>
                            <div class="text-xs space-y-0.5">
                                <div class="flex justify-between"><span class="text-zinc-400">{{ __('Areal') }}</span><span>{{ $ref['size'] ?? '-' }} m&sup2;</span></div>
                                <div class="flex justify-between"><span class="text-zinc-400">{{ __('Kr/m²') }}</span><span>{{ number_format($ref['sqm_price'] ?? 0, 0, ',', '.') }}</span></div>
                                <div class="flex justify-between"><span class="text-zinc-400">{{ __('Solgt') }}</span><span>{{ isset($ref['sold_date']) ? \Carbon\Carbon::parse($ref['sold_date'])->format('d. M Y') : '-' }}</span></div>
                                @if ($ref['owner_age'] ?? null)
                                    <div class="flex justify-between text-xs">
                                        <span class="text-zinc-500">{{ __('Køber') }}</span>
                                        <span>{{ $ref['owner_age'] }} {{ __('år') }}</span>
                                    </div>
                                @elseif (($ref['owner_type'] ?? null) === 'company')
                                    <div class="flex justify-between text-xs">
                                        <span class="text-zinc-500">{{ __('Køber') }}</span>
                                        <span>{{ __('Selskab') }}</span>
                                    </div>
                                @endif
                            </div>
                            <div class="mt-2 pt-2 border-t border-zinc-100 dark:border-zinc-700 font-bold text-sm">
                                {{ number_format($ref['price'] ?? 0, 0, ',', '.') }} DKK
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if (count($listedRefs) > 0)
        <div class="mt-4">
            <div class="text-xs font-semibold text-zinc-400 uppercase mb-2">{{ __('Udbudte referencer') }} ({{ count($listedRefs) }})</div>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($listedRefs as $ref)
                    <div class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden">
                        @if ($ref['image_url'] ?? null)
                            <img src="{{ $ref['image_url'] }}" alt="{{ $ref['address'] }}" class="w-full h-32 object-cover">
                        @else
                            <div class="w-full h-32 bg-zinc-100 dark:bg-zinc-700 flex items-center justify-center text-zinc-400">
                                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 0h.008v.008h-.008V7.5z" /></svg>
                            </div>
                        @endif
                        <div class="p-3">
                            <div class="font-semibold text-sm">{{ $ref['address'] }}</div>
                            <div class="text-xs text-zinc-500 mb-1">{{ $ref['postal_code'] ?? '' }} {{ $ref['city'] ?? '' }}</div>
                            <div class="text-xs space-y-0.5">
                                <div class="flex justify-between"><span class="text-zinc-400">{{ __('Areal') }}</span><span>{{ $ref['size'] ?? '-' }} m&sup2;</span></div>
                                <div class="flex justify-between"><span class="text-zinc-400">{{ __('Kr/m²') }}</span><span>{{ number_format($ref['sqm_price'] ?? 0, 0, ',', '.') }}</span></div>
                                <div class="flex justify-between"><span class="text-zinc-400">{{ __('Liggedage') }}</span><span>{{ $ref['days_for_sale'] ?? '-' }}</span></div>
                            </div>
                            <div class="mt-2 pt-2 border-t border-zinc-100 dark:border-zinc-700 font-bold text-sm text-green-700 dark:text-green-400">
                                {{ number_format($ref['price'] ?? 0, 0, ',', '.') }} DKK
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <div class="mt-4 text-center text-xs text-zinc-400">
        {{ __('PDF-rapport kan genereres via M2soft') }}
    </div>
</div>
