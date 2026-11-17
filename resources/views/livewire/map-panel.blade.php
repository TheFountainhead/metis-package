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
            radier: @js(\TheFountainhead\Metis\Livewire\MapPanel::NABO_RADIER),
            standardRadius: @js(\TheFountainhead\Metis\Livewire\MapPanel::NABO_STANDARD_RADIUS),
            naboSerie: 0,
            kanUdforske: @js($kanUdforske),
            minZoom: @js(\TheFountainhead\Metis\Livewire\MapPanel::UDSNIT_MIN_ZOOM),
            udsnitSerie: 0,
            udsnitTid: null,
            udsnitLytter: null,
            naboNulstil: null,
            naboKontrol: null,
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

                    // Naboerne hoerer til den forrige adresse.
                    this.naboSerie++;
                    this.skjulNaboer();
                    if (this.naboNulstil) this.naboNulstil();
                    // Flyttet af en soegning uden adresse: der er ingen adresse at hente naboer til.
                    if (!$wire.query && this.naboKontrol) {
                        this.naboKontrol.remove();
                        this.naboKontrol = null;
                    }

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
                if (!$wire.query || this.searchType !== 'address') return;
                const self = this;
                const Kontrol = L.Control.extend({
                    options: { position: 'topleft' },
                    onAdd() {
                        const boks = L.DomUtil.create('div', 'leaflet-bar');
                        boks.style.cssText = 'background:#fff;padding:6px 8px;font-size:12px;line-height:1.4;max-width:230px;';
                        const radiusValg = document.createElement('select');
                        radiusValg.setAttribute('aria-label', 'Radius for naboejendomme');
                        for (const r of self.radier) {
                            const o = document.createElement('option');
                            o.value = String(r);
                            o.textContent = r + ' m';
                            if (r === self.standardRadius) o.selected = true;
                            radiusValg.appendChild(o);
                        }
                        const knap = document.createElement('button');
                        knap.type = 'button';
                        knap.textContent = 'Vis naboejendomme';
                        knap.style.cssText = 'margin-left:6px;cursor:pointer;';
                        const status = document.createElement('div');
                        status.style.cssText = 'margin-top:4px;color:#555;';
                        boks.append(radiusValg, knap, status);

                        // Fri udforskning (kun piloter): ejendomme i det udsnit man kigger paa.
                        if (self.kanUdforske) {
                            const raekke = document.createElement('label');
                            raekke.style.cssText = 'display:block;margin-top:8px;cursor:pointer;';
                            const kryds = document.createElement('input');
                            kryds.type = 'checkbox';
                            raekke.append(kryds, document.createTextNode(' Udforsk kortet frit'));
                            const udsnitStatus = document.createElement('div');
                            udsnitStatus.style.cssText = 'margin-top:4px;color:#555;';
                            boks.append(raekke, udsnitStatus);
                            kryds.addEventListener('change', () => {
                                if (kryds.checked) {
                                    self.slaaUdsnitTil(udsnitStatus);
                                } else {
                                    self.slaaUdsnitFra(udsnitStatus);
                                }
                            });
                        }
                        L.DomEvent.disableClickPropagation(boks);

                        self.naboNulstil = () => {
                            knap.textContent = 'Vis naboejendomme';
                            status.textContent = '';
                        };

                        const hent = async () => {
                            knap.disabled = true;
                            radiusValg.disabled = true;
                            status.textContent = 'Henter ...';
                            let tekst;
                            try {
                                tekst = await self.visNaboer(Number(radiusValg.value));
                            } catch (e) {
                                self.skjulNaboer();
                                tekst = 'Naboejendomme kunne ikke vises.';
                            } finally {
                                knap.disabled = false;
                                radiusValg.disabled = false;
                            }
                            // null = kortet er flyttet imens; kontrollen er allerede nulstillet.
                            if (tekst === null) return;
                            status.textContent = tekst;
                            knap.textContent = self.harNaboer() ? 'Skjul naboejendomme' : 'Vis naboejendomme';
                        };
                        knap.addEventListener('click', () => {
                            if (self.harNaboer()) {
                                self.skjulNaboer();
                                knap.textContent = 'Vis naboejendomme';
                                status.textContent = '';
                                return;
                            }
                            hent();
                        });
                        // Ogsaa efter en fejl: et nyt radiusvalg proever igen.
                        radiusValg.addEventListener('change', () => { if (self.harNaboer() || status.textContent !== '') hent(); });
                        return boks;
                    },
                });
                this.naboKontrol = new Kontrol().addTo(this.map);
            },

            slaaUdsnitTil(udsnitStatus) {
                this.udsnitLytter = () => {
                    clearTimeout(this.udsnitTid);
                    this.udsnitTid = setTimeout(() => this.hentUdsnit(udsnitStatus), 400);
                };
                this.map.on('moveend', this.udsnitLytter);
                this.hentUdsnit(udsnitStatus);
            },

            slaaUdsnitFra(udsnitStatus) {
                if (this.udsnitLytter) this.map.off('moveend', this.udsnitLytter);
                this.udsnitLytter = null;
                clearTimeout(this.udsnitTid);
                this.udsnitSerie++;
                this.skjulUdsnit();
                udsnitStatus.textContent = '';
            },

            skjulUdsnit() {
                const lag = this.$refs.map._metisUdsnitLag;
                if (lag) {
                    this.map.removeLayer(lag);
                    this.$refs.map._metisUdsnitLag = null;
                }
            },

            async hentUdsnit(udsnitStatus) {
                const serie = ++this.udsnitSerie;
                if (this.map.getZoom() < this.minZoom) {
                    this.skjulUdsnit();
                    udsnitStatus.textContent = 'Zoom ind for at se ejendomme.';
                    return;
                }
                udsnitStatus.textContent = 'Henter ...';
                const b = this.map.getBounds();
                let svar;
                try {
                    svar = await $wire.hentUdsnit(b.getSouth(), b.getWest(), b.getNorth(), b.getEast());
                } catch (e) {
                    svar = { error: 'fejl' };
                }
                // Et nyere flyt eller en afkrydsning der er slaaet fra har overtaget.
                if (serie !== this.udsnitSerie) return;
                if (!svar || svar.error) {
                    this.skjulUdsnit();
                    const tekster = {
                        zoom_ind: 'Zoom ind for at se ejendomme.',
                        for_mange: 'For mange opslag lige nu. Flyt kortet igen om lidt.',
                        kvote: 'Dagens opslag er brugt.',
                        kraever_pilot: 'Fri udforskning er kun for pilotbrugere.',
                    };
                    udsnitStatus.textContent = tekster[svar && svar.error] || 'Ejendomme kunne ikke hentes.';
                    return;
                }
                this.skjulUdsnit();
                const lag = L.layerGroup();
                for (const n of svar.naboer) {
                    L.circleMarker([n.lat, n.lng], { radius: 5, weight: 2, color: '#0f766e', fillColor: '#5eead4', fillOpacity: 0.8 })
                        .bindPopup(this.naboPopup(n))
                        .addTo(lag);
                }
                lag.addTo(this.map);
                this.$refs.map._metisUdsnitLag = lag;
                udsnitStatus.textContent = svar.flere
                    ? svar.naboer.length + ' ejendomme vises. Der er flere her: zoom ind for at se dem alle.'
                    : svar.naboer.length + ' ejendomme i udsnittet.';
            },

            // Laget ligger paa DOM-elementet og ikke i Alpines reaktive data, ligesom selve kortet:
            // Leaflet identificerer objekter ved identitet, og en Proxy er ikke samme objekt.
            harNaboer() {
                return !!this.$refs.map._metisNaboLag;
            },

            skjulNaboer() {
                const lag = this.$refs.map._metisNaboLag;
                if (lag) {
                    this.map.removeLayer(lag);
                    this.$refs.map._metisNaboLag = null;
                }
            },

            async visNaboer(radius) {
                const serie = ++this.naboSerie;
                if (!$wire.query || this.searchType !== 'address') {
                    return 'Naboejendomme findes kun ved et opslag på en adresse.';
                }
                let svar;
                try {
                    svar = await $wire.hentNaboer($wire.query, radius);
                } catch (e) {
                    svar = { error: 'fejl' };
                }
                if (serie !== this.naboSerie) return null;
                if (!svar || svar.error) {
                    this.skjulNaboer();
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
                this.$refs.map._metisNaboLag = lag;
                if (svar.naboer.length === 0) {
                    return 'Ingen andre ejendomme inden for ' + svar.radius + ' m.';
                }
                const antal = svar.naboer.length + ' ejendomme inden for ' + svar.radius + ' m.';
                return svar.flere
                    ? antal + ' Der er flere; de nærmeste vises. Vælg en mindre radius, eller udforsk kortet.'
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
                if (n.url) {
                    const a = document.createElement('a');
                    a.href = n.url;
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
