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

    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        sand: { 50: '#faf8f5', 100: '#f5f0e8', 200: '#ebe3d5', 300: '#d9ccb8' },
                        warm: { 400: '#c4956a', 500: '#b5805a', 600: '#a06b45' },
                        ink: { 700: '#3d3929', 800: '#2c2922', 900: '#1a1815' },
                    },
                    fontFamily: {
                        serif: ['Georgia', 'Times New Roman', 'serif'],
                        sans: ['-apple-system', 'BlinkMacSystemFont', 'Segoe UI', 'sans-serif'],
                    },
                }
            }
        }
    </script>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />

    @if(config('metis.turnstile.site_key'))
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    @endif

    <style>
        ::selection { background: rgba(197, 149, 106, 0.25); }
    </style>

    @livewireStyles
</head>
<body class="bg-sand-50 min-h-screen antialiased">
    @if(config('metis.admin.enabled'))
    <div class="fixed top-4 right-5 z-50">
        <a href="/admin" class="text-sand-300 hover:text-ink-700 text-xs transition-colors">Admin</a>
    </div>
    @endif

    <main>
        {{ $slot }}
    </main>

    @if(config('metis.gating.enabled', true))
        <livewire:metis-email-gate />
    @endif

    @include('metis::components.cookie-consent')

    @livewireScripts
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
</body>
</html>
