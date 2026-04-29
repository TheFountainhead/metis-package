<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Metis</title>
    <meta name="description" content="Ejendomme, virksomheder og personer.">
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
</head>
<body class="bg-paper min-h-screen antialiased" x-data="{ sidebarOpen: false }">

    <div class="flex min-h-screen">

        @include('metis::partials.sidebar')

        <div class="flex-1 flex flex-col min-w-0 lg:ml-64">

            <header class="sticky top-0 z-30 bg-paper/90 backdrop-blur-sm border-b border-sand-200/60 px-4 py-3 lg:px-8 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <button @click="sidebarOpen = true"
                            class="lg:hidden p-1 hover:bg-sand-100 rounded">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        </svg>
                    </button>
                    @yield('breadcrumb')
                </div>

                @if(config('metis.admin.enabled'))
                    <a href="/admin" class="text-text-muted hover:text-text-primary text-xs transition-colors">Admin</a>
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
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
</body>
</html>
