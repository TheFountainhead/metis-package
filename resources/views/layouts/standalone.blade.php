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
<body class="bg-paper min-h-screen antialiased">
    @if(config('metis.admin.enabled'))
    <div class="fixed top-4 right-5 z-50">
        <a href="/admin" class="text-text-muted hover:text-text-primary text-xs transition-colors">Admin</a>
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
