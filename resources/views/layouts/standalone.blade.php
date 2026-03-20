<!DOCTYPE html>
<html lang="da" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Metis</title>
    <meta name="description" content="Slå op på virksomheder, personer og ejendomme.">

    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        surface: { DEFAULT: '#1a1a1a', light: '#2a2a2a', lighter: '#353535', border: '#3a3a3a' },
                        accent: { DEFAULT: '#d4a574', hover: '#e0b88a', muted: '#b8956a' },
                        text: { primary: '#ececec', secondary: '#a0a0a0', muted: '#6b6b6b' },
                    },
                    fontFamily: {
                        sans: ['Inter', '-apple-system', 'BlinkMacSystemFont', 'Segoe UI', 'sans-serif'],
                    },
                }
            }
        }
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script defer src="https://unpkg.com/alpinejs@3.15/dist/cdn.min.js"></script>

    @if(config('metis.turnstile.site_key'))
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    @endif

    <style>
        body { font-feature-settings: 'cv02', 'cv03', 'cv04', 'cv11'; }
        ::selection { background: rgba(212, 165, 116, 0.3); }
        input::placeholder { color: #6b6b6b; }
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #3a3a3a; border-radius: 3px; }
    </style>

    @livewireStyles
</head>
<body class="bg-surface text-text-primary min-h-screen antialiased">
    @if(config('metis.admin.enabled'))
    <header class="fixed top-0 right-0 p-4 z-50">
        <a href="/admin" class="text-text-muted hover:text-text-secondary text-xs transition-colors">Admin</a>
    </header>
    @endif

    <main>
        {{ $slot }}
    </main>

    @include('metis::components.cookie-consent')

    @livewireScripts
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
</body>
</html>
