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
                    <div wire:ignore class="w-full rounded-lg overflow-hidden" style="height: 300px;">
                        <div data-overview-map class="w-full h-full"></div>
                    </div>
                </div>
            @endif

            {{-- Pie chart (1/3 width) --}}
            @if(count($usageDistribution) > 0)
                <div class="bg-white rounded-xl border border-zinc-200 p-4">
                    <div class="text-xs text-zinc-500 uppercase tracking-wide mb-3">{{ __('Property type') }}</div>
                    <div wire:ignore style="height: 280px;">
                        <canvas data-overview-pie></canvas>
                    </div>
                </div>
            @endif
        </div>

        {{-- Financial history bars (full width below) --}}
        @if(count($financialHistory) > 0)
            <div class="bg-white rounded-xl border border-zinc-200 p-4">
                <div class="text-xs text-zinc-500 uppercase tracking-wide mb-3">{{ __('Financial history (3 years)') }}</div>
                <div wire:ignore style="height: 260px;">
                    <canvas data-overview-fin></canvas>
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

    {{-- @push når ikke layoutets stack fra lazy sections — @script kører ved init --}}
    @script
    <script>
        (async () => {
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

            const root = $wire.$el;
            const palette = ['#7a1f1f', '#0a5c4a', '#c8553d', '#b8a884', '#6b6457', '#1a1a1a'];

            const mapEl = root.querySelector('[data-overview-map]');
            const pins = $wire.mapPins ?? [];
            if (mapEl && pins.length) {
                const L = await window.metisLoadLeaflet();
                const map = L.map(mapEl, { scrollWheelZoom: false });
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '© OpenStreetMap',
                    maxZoom: 18,
                }).addTo(map);
                const markers = pins.map(p => L.marker([p.lat, p.lng]).bindPopup(p.address).addTo(map));
                map.fitBounds(L.featureGroup(markers).getBounds().pad(0.2));
            }

            const pieEl = root.querySelector('[data-overview-pie]');
            const distribution = $wire.usageDistribution ?? {};
            if (pieEl && Object.keys(distribution).length) {
                const Chart = await window.metisLoadChartjs();
                const labels = Object.keys(distribution);
                new Chart(pieEl, {
                    type: 'doughnut',
                    data: { labels, datasets: [{ data: Object.values(distribution), backgroundColor: labels.map((_, i) => palette[i % palette.length]) }] },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { position: 'bottom', labels: { font: { size: 11 } } } },
                    },
                });
            }

            const finEl = root.querySelector('[data-overview-fin]');
            const history = $wire.financialHistory ?? [];
            if (finEl && history.length) {
                const Chart = await window.metisLoadChartjs();
                new Chart(finEl, {
                    type: 'bar',
                    data: {
                        labels: history.map(h => h.year),
                        datasets: [
                            { label: 'Egenkapital (mio. kr)', data: history.map(h => (h.equity ?? 0) / 1_000_000), backgroundColor: '#7a1f1f' },
                            { label: 'Aktiver (mio. kr)', data: history.map(h => (h.assets ?? 0) / 1_000_000), backgroundColor: '#0a5c4a' },
                        ],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { position: 'bottom', labels: { font: { size: 11 } } } },
                        scales: { y: { beginAtZero: true, ticks: { font: { size: 11 } } } },
                    },
                });
            }
        })();
    </script>
    @endscript
</div>
