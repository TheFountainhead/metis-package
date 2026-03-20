<div>
    @if($layersUnavailable)
        <div class="px-4 py-2 text-sm text-amber-700 bg-amber-50 rounded">
            {{ __('Kortlag midlertidigt utilgængelige') }}
        </div>
    @endif

    <div
        wire:ignore
        x-data="{
            map: null,
            layers: @js($layers),
            lat: @js($lat),
            lng: @js($lng),
            searchType: @js($searchType),
            markers: @js($markers),
            layersUnavailable: @js($layersUnavailable),

            async init() {
                await this.loadLeaflet();
                this.initMap();

                Livewire.on('map-update', () => {
                    this.lat = $wire.lat;
                    this.lng = $wire.lng;
                    this.searchType = $wire.searchType;
                    this.markers = $wire.markers;

                    if (this.lat && this.lng) {
                        this.map.setView([this.lat, this.lng], 16);
                        this.clearMarkers();
                        this.addMarkers();
                    }
                });
            },

            initMap() {
                const lat = this.lat || 55.6761;
                const lng = this.lng || 12.5683;
                const zoom = (this.lat && this.lng) ? 16 : 7;

                const osm = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '&copy; OpenStreetMap contributors',
                    maxZoom: 19,
                });

                const satellite = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
                    attribution: '&copy; Esri',
                    maxZoom: 19,
                });

                this.map = L.map(this.$refs.map, {
                    scrollWheelZoom: false,
                    layers: [osm],
                }).setView([lat, lng], zoom);

                const baseLayers = {
                    'OpenStreetMap': osm,
                    'Satellite': satellite,
                };

                const overlays = {};
                const defaultLayerIds = this.getDefaultLayerIds();

                for (const layer of this.layers) {
                    let leafletLayer = null;

                    if (layer.type === 'wms' && layer.url && layer.layers) {
                        leafletLayer = L.tileLayer.wms(layer.url, {
                            layers: layer.layers,
                            transparent: true,
                            format: 'image/png',
                            opacity: layer.opacity || 0.7,
                        });
                    } else if (layer.type === 'raster' && layer.tile_url) {
                        leafletLayer = L.tileLayer(layer.tile_url, {
                            tms: !!layer.tms,
                            opacity: layer.opacity || 0.6,
                            maxZoom: layer.max_zoom || 18,
                        });
                    } else if (layer.tile_url) {
                        leafletLayer = L.tileLayer(layer.tile_url, {
                            opacity: layer.opacity || 0.7,
                            maxZoom: layer.max_zoom || 18,
                        });
                    } else if (layer.function_source) {
                        leafletLayer = L.tileLayer(layer.function_source, {
                            opacity: layer.opacity || 0.7,
                            maxZoom: layer.max_zoom || 18,
                        });
                    }

                    if (leafletLayer) {
                        overlays[layer.name] = leafletLayer;

                        if (defaultLayerIds.includes(layer.id)) {
                            leafletLayer.addTo(this.map);
                        }
                    }
                }

                L.control.layers(baseLayers, overlays, { position: 'topright' }).addTo(this.map);

                this.addMarkers();
            },

            getDefaultLayerIds() {
                const defaults = {
                    address: ['cadastral'],
                    cvr: ['companies'],
                    person: ['properties'],
                    company_name: ['companies'],
                };
                return defaults[this.searchType] || [];
            },

            addMarkers() {
                if (this.lat && this.lng) {
                    L.marker([this.lat, this.lng]).addTo(this.map);
                }
                for (const m of this.markers) {
                    if (m.lat && m.lng) {
                        const marker = L.marker([m.lat, m.lng]).addTo(this.map);
                        if (m.label) {
                            marker.bindPopup(m.label);
                        }
                    }
                }
            },

            clearMarkers() {
                this.map.eachLayer((layer) => {
                    if (layer instanceof L.Marker) {
                        this.map.removeLayer(layer);
                    }
                });
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
        x-ref="map"
        class="h-[200px] lg:h-full"
        style="min-height: 200px; z-index: 0;"
    ></div>

    @foreach($layers as $layer)
        <meta data-layer-name="{{ $layer['name'] ?? '' }}" />
    @endforeach
</div>
