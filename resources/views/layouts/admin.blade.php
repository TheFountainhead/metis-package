<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Metis Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://unpkg.com/alpinejs@3.15/dist/cdn.min.js"></script>
    @livewireStyles
</head>
<body class="bg-stone-50">
    <div class="flex min-h-screen">
        <!-- Sidebar -->
        <nav class="w-48 bg-white border-r border-stone-200 p-4">
            <a href="/" class="text-lg font-serif font-bold text-stone-800 block mb-6">Metis</a>
            <ul class="space-y-2">
                <li><a href="{{ route('metis.admin.dashboard') }}" class="block px-3 py-2 rounded text-sm hover:bg-stone-100">Dashboard</a></li>
                <li><a href="{{ route('metis.admin.leads') }}" class="block px-3 py-2 rounded text-sm hover:bg-stone-100">Leads</a></li>
                <li><a href="{{ route('metis.admin.logs') }}" class="block px-3 py-2 rounded text-sm hover:bg-stone-100">Logs</a></li>
            </ul>
            <form method="POST" action="{{ route('metis.admin.logout') }}" class="mt-8">
                @csrf
                <button class="text-sm text-stone-400 hover:text-stone-600">Log ud</button>
            </form>
        </nav>

        <!-- Content -->
        <main class="flex-1 p-8">
            {{ $slot }}
        </main>
    </div>
    @livewireScripts
</body>
</html>
