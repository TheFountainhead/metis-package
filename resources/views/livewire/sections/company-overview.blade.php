<div class="space-y-4">
    {{-- KPI tiles --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <div class="bg-white rounded-xl border border-zinc-200 p-4">
            <div class="text-xs text-zinc-500 uppercase tracking-wide mb-1">{{ __('Properties') }}</div>
            <div class="text-2xl font-semibold text-zinc-900">{{ number_format($propertyCount, 0, ',', '.') }}</div>
        </div>
        <div class="bg-white rounded-xl border border-zinc-200 p-4">
            <div class="text-xs text-zinc-500 uppercase tracking-wide mb-1">{{ __('Total valuation') }}</div>
            <div class="text-2xl font-semibold text-zinc-900">
                @if($totalValuation > 0)
                    {{ number_format($totalValuation / 1_000_000, 1, ',', '.') }} {{ __('mio. kr') }}
                @else
                    <span class="text-zinc-400">—</span>
                @endif
            </div>
        </div>
        <div class="bg-white rounded-xl border border-zinc-200 p-4">
            <div class="text-xs text-zinc-500 uppercase tracking-wide mb-1">{{ __('Total mortgage debt') }}</div>
            <div class="text-2xl font-semibold text-zinc-900">
                @if($totalDebt > 0)
                    {{ number_format($totalDebt / 1_000_000, 1, ',', '.') }} {{ __('mio. kr') }}
                @else
                    <span class="text-zinc-400">—</span>
                @endif
            </div>
            @if($totalValuation > 0 && $totalDebt > 0)
                <div class="text-xs text-zinc-500 mt-1">{{ __('LTV') }}: {{ number_format(($totalDebt / $totalValuation) * 100, 0) }}%</div>
            @endif
        </div>
        <div class="bg-white rounded-xl border border-zinc-200 p-4">
            <div class="text-xs text-zinc-500 uppercase tracking-wide mb-1">{{ __('Employees') }}</div>
            <div class="text-2xl font-semibold text-zinc-900">
                {{ $employees !== null ? number_format($employees, 0, ',', '.') : '—' }}
            </div>
            @if($companyName)
                <div class="text-xs text-zinc-500 mt-1 truncate" title="{{ $companyName }}">{{ $companyName }}</div>
            @endif
        </div>
    </div>

    {{-- Map + charts row --}}
    @if(count($mapPins) > 0 || count($usageDistribution) > 0 || count($financialHistory) > 0)
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-3">
            {{-- Mini-map (2/3 width) --}}
            @if(count($mapPins) > 0)
                <div class="lg:col-span-2 bg-white rounded-xl border border-zinc-200 p-4">
                    <div class="text-xs text-zinc-500 uppercase tracking-wide mb-3">{{ __('Property map') }}</div>
                    <div
                        x-data='companyOverviewMap(@json($mapPins))'
                        x-init="init()"
                        wire:ignore
                        class="w-full rounded-lg overflow-hidden"
                        style="height: 300px;"
                    >
                        <div x-ref="map" class="w-full h-full"></div>
                    </div>
                </div>
            @endif

            {{-- Pie chart (1/3 width) --}}
            @if(count($usageDistribution) > 0)
                <div class="bg-white rounded-xl border border-zinc-200 p-4">
                    <div class="text-xs text-zinc-500 uppercase tracking-wide mb-3">{{ __('Property type') }}</div>
                    <div
                        x-data='companyOverviewPie(@json($usageDistribution))'
                        x-init="init()"
                        wire:ignore
                        style="height: 280px;"
                    >
                        <canvas x-ref="canvas"></canvas>
                    </div>
                </div>
            @endif
        </div>

        {{-- Financial history bars (full width below) --}}
        @if(count($financialHistory) > 0)
            <div class="bg-white rounded-xl border border-zinc-200 p-4">
                <div class="text-xs text-zinc-500 uppercase tracking-wide mb-3">{{ __('Financial history (3 years)') }}</div>
                <div
                    x-data='companyOverviewFinancials(@json($financialHistory))'
                    x-init="init()"
                    wire:ignore
                    style="height: 260px;"
                >
                    <canvas x-ref="canvas"></canvas>
                </div>
            </div>
        @endif
    @endif

    {{-- Listed addresses (sub-list of pins) --}}
    @if(count($mapPins) > 0)
        <div class="bg-white rounded-xl border border-zinc-200 p-4">
            <div class="text-xs text-zinc-500 uppercase tracking-wide mb-2">{{ __('Addresses on map') }}</div>
            <div class="text-sm text-zinc-700 space-y-1">
                @foreach(array_slice($mapPins, 0, 6) as $pin)
                    <div class="truncate">• {{ $pin['address'] }}</div>
                @endforeach
                @if(count($mapPins) > 6)
                    <div class="text-zinc-500 text-xs">+ {{ count($mapPins) - 6 }} {{ __('more') }}</div>
                @endif
            </div>
        </div>
    @endif

    @once
        @push('scripts')
            <script>
                // Lazy-load Leaflet + Chart.js once via CDN. Idempotent — checks window globals first.
                window.metisLoadLeaflet = window.metisLoadLeaflet || (async () => {
                    if (window.L) return window.L;
                    await new Promise((resolve, reject) => {
                        const css = document.createElement('link');
                        css.rel = 'stylesheet';
                        css.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
                        document.head.appendChild(css);
                        const s = document.createElement('script');
                        s.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
                        s.onload = resolve;
                        s.onerror = reject;
                        document.head.appendChild(s);
                    });
                    return window.L;
                });

                window.metisLoadChartjs = window.metisLoadChartjs || (async () => {
                    if (window.Chart) return window.Chart;
                    await new Promise((resolve, reject) => {
                        const s = document.createElement('script');
                        s.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js';
                        s.onload = resolve;
                        s.onerror = reject;
                        document.head.appendChild(s);
                    });
                    return window.Chart;
                });

                document.addEventListener('alpine:init', () => {
                    Alpine.data('companyOverviewMap', (pins) => ({
                        pins,
                        async init() {
                            const L = await window.metisLoadLeaflet();
                            if (!this.pins.length) return;
                            const map = L.map(this.$refs.map, { scrollWheelZoom: false });
                            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                                attribution: '© OpenStreetMap',
                                maxZoom: 18,
                            }).addTo(map);
                            const markers = this.pins.map(p =>
                                L.marker([p.lat, p.lng]).bindPopup(p.address).addTo(map)
                            );
                            const group = L.featureGroup(markers);
                            map.fitBounds(group.getBounds().pad(0.2));
                        },
                    }));

                    Alpine.data('companyOverviewPie', (distribution) => ({
                        distribution,
                        async init() {
                            const Chart = await window.metisLoadChartjs();
                            const labels = Object.keys(this.distribution);
                            const data = Object.values(this.distribution);
                            const palette = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#64748b'];
                            new Chart(this.$refs.canvas, {
                                type: 'doughnut',
                                data: {
                                    labels,
                                    datasets: [{ data, backgroundColor: palette.slice(0, labels.length) }],
                                },
                                options: {
                                    responsive: true,
                                    maintainAspectRatio: false,
                                    plugins: { legend: { position: 'bottom', labels: { font: { size: 11 } } } },
                                },
                            });
                        },
                    }));

                    Alpine.data('companyOverviewFinancials', (history) => ({
                        history,
                        async init() {
                            const Chart = await window.metisLoadChartjs();
                            const labels = this.history.map(h => h.year);
                            const equity = this.history.map(h => (h.equity ?? 0) / 1_000_000);
                            const assets = this.history.map(h => (h.assets ?? 0) / 1_000_000);
                            new Chart(this.$refs.canvas, {
                                type: 'bar',
                                data: {
                                    labels,
                                    datasets: [
                                        { label: 'Egenkapital (mio. kr)', data: equity, backgroundColor: '#3b82f6' },
                                        { label: 'Aktiver (mio. kr)', data: assets, backgroundColor: '#10b981' },
                                    ],
                                },
                                options: {
                                    responsive: true,
                                    maintainAspectRatio: false,
                                    plugins: { legend: { position: 'bottom', labels: { font: { size: 11 } } } },
                                    scales: { y: { beginAtZero: true, ticks: { font: { size: 11 } } } },
                                },
                            });
                        },
                    }));
                });
            </script>
        @endpush
    @endonce
</div>
