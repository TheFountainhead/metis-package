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
            query: @js($query),
            naboUrl: @js(route('metis.lookup', ['type' => 'address', 'query' => '__Q__'])),
            naboLag: null,
            layersUnavailable: @js($layersUnavailable),

            destroy() {
                this.$refs.map?._metisResize?.disconnect();
            },

            async init() {
                await this.loadLeaflet();
                this.initMap();

                Livewire.on('map-update', () => {
                    // Livewire.on-listeners er globale og overlever Alpine-instansen:
                    // efter dobbelt-init må den forældede instans ikke kalde setView
                    // på sit removede map.
                    if (this.$refs.map._metisLeafletMap !== this.map) return;

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
                // 🚨 INGEN DOBBELTE ANFOERSELSTEGN I DENNE BLOK — heller ikke i
                // kommentarer. Hele objektet ligger inde i x-data-attributten,
                // saa ét enkelt lukker den for tidligt.
                //
                // Maalt 10/8: en kommentar der citerede Leaflets
                // 'Map container is already initialized' knaekkede parsingen.
                // Alpine kastede en syntaksfejl, komponenten initialiserede
                // ALDRIG, kortet forsvandt, og den raa JS blev vist som synlig
                // tekst oeverst paa adresse-siden.
                //
                // lazy-komponent + wire:ignore kan koere init() to gange paa
                // SAMME DOM-element; Leaflet kaster da en 'already
                // initialized'-fejl paa anden L.map(). Fjern den tidligere
                // instans (rydder _leaflet_id og event-handlers) i stedet for
                // at crashe.
                if (this.$refs.map._metisLeafletMap) {
                    this.$refs.map._metisLeafletMap.remove();
                }

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
                this.$refs.map._metisLeafletMap = this.map;

                // Kortet kan blive bygget paa et skjult panel (en fane der ikke er aaben)
                // og har da stoerrelse 0. ResizeObserver maaler det igen naar det vises.
                if (this.$refs.map._metisResize) {
                    this.$refs.map._metisResize.disconnect();
                }
                this.$refs.map._metisResize = new ResizeObserver((poster) => {
                    const r = poster[0].contentRect;
                    if (r.width > 0 && r.height > 0) this.map.invalidateSize();
                });
                this.$refs.map._metisResize.observe(this.$refs.map);

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
                this.addNaboKontrol();
            },

            // Naboejendomme: en lille kontrol paa kortet. Alt bygges som DOM-noder med
            // textContent, fordi adresserne kommer fra API et og aldrig maa tolkes som HTML.
            addNaboKontrol() {
                if (!this.query || this.searchType !== 'address') return;
                const self = this;
                const Kontrol = L.Control.extend({
                    options: { position: 'topleft' },
                    onAdd() {
                        const boks = L.DomUtil.create('div', 'leaflet-bar');
                        boks.style.cssText = 'background:#fff;padding:6px 8px;font-size:12px;line-height:1.4;max-width:230px;';
                        const radiusValg = document.createElement('select');
                        for (const r of [100, 250, 500]) {
                            const o = document.createElement('option');
                            o.value = String(r);
                            o.textContent = r + ' m';
                            if (r === 250) o.selected = true;
                            radiusValg.appendChild(o);
                        }
                        const knap = document.createElement('button');
                        knap.type = 'button';
                        knap.textContent = 'Vis naboejendomme';
                        knap.style.cssText = 'margin-left:6px;cursor:pointer;';
                        const status = document.createElement('div');
                        status.style.cssText = 'margin-top:4px;color:#555;';
                        boks.append(radiusValg, knap, status);
                        L.DomEvent.disableClickPropagation(boks);

                        const hent = async () => {
                            knap.disabled = true;
                            status.textContent = 'Henter ...';
                            status.textContent = await self.visNaboer(Number(radiusValg.value));
                            knap.disabled = false;
                            knap.textContent = self.naboLag ? 'Skjul naboejendomme' : 'Vis naboejendomme';
                        };
                        knap.addEventListener('click', () => {
                            if (self.naboLag) {
                                self.skjulNaboer();
                                knap.textContent = 'Vis naboejendomme';
                                status.textContent = '';
                                return;
                            }
                            hent();
                        });
                        radiusValg.addEventListener('change', () => { if (self.naboLag) hent(); });
                        return boks;
                    },
                });
                new Kontrol().addTo(this.map);
            },

            skjulNaboer() {
                if (this.naboLag) {
                    this.map.removeLayer(this.naboLag);
                    this.naboLag = null;
                }
            },

            async visNaboer(radius) {
                let svar;
                try {
                    svar = await $wire.hentNaboer(this.query, radius);
                } catch (e) {
                    svar = { error: 'fejl' };
                }
                if (!svar || svar.error) {
                    const tekster = {
                        for_mange: 'For mange opslag lige nu. Prøv igen om lidt.',
                        kvote: 'Dagens opslag er brugt.',
                        ikke_godkendt: 'Opslaget er ikke godkendt i denne session.',
                        ingen_koordinater: 'Adressen har ingen koordinater.',
                    };
                    return tekster[svar && svar.error] || 'Naboejendomme kunne ikke hentes.';
                }
                this.skjulNaboer();
                const lag = L.layerGroup();
                for (const n of svar.naboer) {
                    L.circleMarker([n.lat, n.lng], { radius: 6, weight: 2, color: '#1d4ed8', fillColor: '#60a5fa', fillOpacity: 0.8 })
                        .bindPopup(this.naboPopup(n))
                        .addTo(lag);
                }
                lag.addTo(this.map);
                this.naboLag = lag;
                if (svar.naboer.length === 0) {
                    return 'Ingen andre ejendomme inden for ' + svar.radius + ' m.';
                }
                const antal = svar.naboer.length + ' ejendomme inden for ' + svar.radius + ' m.';
                return svar.flere
                    ? antal + ' Der er flere, men ikke nødvendigvis de nærmeste. Vælg en mindre radius.'
                    : antal;
            },

            naboPopup(n) {
                const boks = document.createElement('div');
                const titel = document.createElement('strong');
                titel.textContent = n.adresse || 'Ukendt adresse';
                boks.appendChild(titel);
                const by = [n.postnr, n.by].filter(Boolean).join(' ');
                const linjer = [];
                if (by) linjer.push(by);
                if (n.byggeaar) linjer.push('Byggeår: ' + n.byggeaar);
                if (n.areal) linjer.push('Bygningsareal: ' + Number(n.areal).toLocaleString('da-DK') + ' m²');
                if (n.vurdering) linjer.push('Vurdering: ' + Number(n.vurdering).toLocaleString('da-DK') + ' kr.');
                for (const tekst of linjer) {
                    const l = document.createElement('div');
                    l.textContent = tekst;
                    boks.appendChild(l);
                }
                if (n.adresse) {
                    const kode = encodeURIComponent([n.adresse, by].filter(Boolean).join(', '));
                    const a = document.createElement('a');
                    a.href = this.naboUrl.replace('__Q__', () => kode);
                    a.textContent = 'Åbn ejendom';
                    a.style.cssText = 'display:inline-block;margin-top:4px;';
                    boks.appendChild(a);
                }
                return boks;
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
