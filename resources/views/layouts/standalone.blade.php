<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Metis — Ejendoms-, virksomheds- og persondata</title>

    <!-- Tailwind CSS v4 CDN (play CDN for standalone) -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Leaflet CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />

    <!-- Alpine.js -->
    <script defer src="https://unpkg.com/alpinejs@3.15/dist/cdn.min.js"></script>

    <!-- Turnstile -->
    @if(config('metis.turnstile.site_key'))
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    @endif

    @livewireStyles
</head>
<body class="bg-stone-50 min-h-screen">
    <header class="bg-white border-b border-stone-200 px-6 py-3 flex items-center justify-between">
        <a href="/" class="text-xl font-serif font-bold text-stone-800">Metis</a>
        @if(config('metis.admin.enabled'))
        <a href="/admin" class="text-sm text-stone-500 hover:text-stone-700">Admin</a>
        @endif
    </header>

    <main>
        {{ $slot }}
    </main>

    <footer class="border-t border-stone-200 px-6 py-4 text-center text-xs text-stone-400">
        @include('metis::components.cookie-consent')
        <p>&copy; {{ date('Y') }} Frankston ApS</p>
    </footer>

    @livewireScripts
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
</body>
</html>
