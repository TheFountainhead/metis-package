<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Metis</title>
    <meta name="description" content="Ejendomme, virksomheder og personer.">

    {{-- 🚨 Resultatsider maa ikke indekseres.

         Metis viser navngivne personers roller, selskabers ejerstruktur og
         laangiveres kreditrisiko med adresser og beloeb. Kilderne er
         offentlige, men et Google-indeks er en ny udgivelse: det goer et
         opslag man skal foretage til noget der dukker op af sig selv.

         🪤 MAALT PAA PROD 6/8: `robots.txt` havde `Disallow: /*?q=`, saa
         soegeresultater var daekket. Men laangiver-siden bruger `?cvr=` og
         var daekket af HVERKEN robots.txt ELLER et meta-tag.

         🪤 Host-appen har et `noindex` i sin egen layout.blade.php — men
         `grep` finder NUL konsumenter af den fil. Det er doed kode. Den
         layout der faktisk serverer prod er DENNE, og den manglede tagget.
         Beskyttelsen laa altsaa ét sted hvor den ikke virkede.

         🚨 MAALT PAA PROD 9/8 — TREDJE gang samme beskyttelse rammer ved
         siden af. Betingelsen var `! empty(request()->query())`, altsaa "har
         URL'en en query-streng?". Men `/lookup/cpr/4006033395` baerer
         opslaget i STIEN, ikke efter `?`. Betingelsen var falsk, tagget blev
         udeladt, og CPR-siden var indekserbar. Verificeret ved at tilfoeje
         `?x=1` til samme URL: da dukkede tagget op.

         Googlebot havde hentet 35 unikke CPR-URL'er, 269 requests, alle 200.

         🔑 DERFOR ER DEFAULTEN VENDT OM. Foer skulle en side kvalificere sig
         til beskyttelse; nu skal den kvalificere sig til at UNDVAERE den.
         Enhver ny rute er daekket fra foedslen, og den der vil indeksere en
         side skal skrive den paa listen bevidst. En glemt rute fejler nu mod
         beskyttelse i stedet for mod eksponering.

         🪤 Listen er RUTENAVNE, ikke stier. En sti-liste ville skulle holdes
         synkron med hver URL-aendring; rutenavne foelger med ruten. --}}
    @php
        // Kun disse sider er rent indhold uden persondata. Alt andet: noindex.
        $indekserbar = in_array(request()->route()?->getName(), [
            'metis.home',
        ], true) && empty(request()->query());
    @endphp
    @unless($indekserbar)
        <meta name="robots" content="noindex, nofollow">
    @endunless
    <link rel="icon" href="/favicon.ico" sizes="32x32">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="192x192" href="/icon-192.png">

    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />

    @if(config('metis.turnstile.site_key'))
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    @endif

    <style>
        ::selection { background: rgba(197, 149, 106, 0.25); }
    </style>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    @fluxAppearance
</head>
<body class="bg-paper min-h-screen antialiased" x-data="{ sidebarOpen: false }">

    <div class="flex min-h-screen">

        @include('metis::partials.sidebar')

        <div class="flex-1 flex flex-col min-w-0 lg:ml-64">

            <header class="sticky top-0 z-30 bg-paper/90 backdrop-blur-sm border-b border-zinc-200 px-4 py-3 lg:px-8 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <button @click="sidebarOpen = true"
                            class="lg:hidden p-1 hover:bg-zinc-100 rounded">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        </svg>
                    </button>
                    @yield('breadcrumb')
                </div>

                @if(config('metis.admin.enabled'))
                    @if(session('metis_admin_authenticated'))<a href="/admin" class="text-text-muted hover:text-text-primary text-xs transition-colors">Admin</a>@endif
                @endif
            </header>

            <main class="flex-1 px-4 py-6 lg:px-8 lg:py-8">
                {{ $slot }}
            </main>
        </div>
    </div>

    @if(config('metis.gating.enabled', true))
        <livewire:metis-email-gate />
    @endif

    @include('metis::components.cookie-consent')

    @livewireScripts
    {{-- Flux' JavaScript. Standalone-mode pages (Lookup::render → this layout when
         config('metis.mode')==='standalone') use <flux:modal>/<flux:flyout> (e.g.
         the tinglysning mortgage drawer), which compile to x-data="fluxModal(...)".
         Without this, fluxModal is undefined → an uncaught ReferenceError during
         Alpine's init kills the whole bootstrap, so every Alpine component after
         the flux modal in the DOM (including the ownership graph) never hydrates.
         Flux CSS is already imported in app.css; only the JS was missing. --}}
    @fluxScripts
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
</body>
</html>
