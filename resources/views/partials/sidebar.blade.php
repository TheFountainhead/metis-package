@php
    $currentRoute = Route::currentRouteName() ?? '';
    $hasUserToken = ! empty(session('metis_user_token'));

    $navSections = [
        [
            'label' => null,
            'items' => [
                ['route' => 'metis.home', 'label' => __('Hjem'), 'icon' => 'home'],
                ['route' => 'metis.debt-search', 'label' => __('Søg gæld'), 'icon' => 'search-debt'],
                ['route' => 'metis.alerts', 'label' => __('Mine alerts'), 'icon' => 'bell', 'requires_token' => true],
            ],
        ],
        [
            'label' => __('Søg efter type'),
            'items' => [
                ['route' => 'metis.home', 'label' => __('Person'), 'icon' => 'user', 'query' => ['mode' => 'person']],
                ['route' => 'metis.home', 'label' => __('Selskab'), 'icon' => 'building', 'query' => ['mode' => 'company']],
                ['route' => 'metis.home', 'label' => __('Ejendom'), 'icon' => 'map-pin', 'query' => ['mode' => 'address']],
            ],
        ],
    ];
@endphp

<aside x-cloak
       :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
       class="fixed inset-y-0 left-0 z-40 w-64 bg-white border-r border-zinc-200 flex flex-col transition-transform duration-200 lg:translate-x-0">

    <div class="px-5 py-4 border-b border-zinc-200 flex items-center justify-between">
        <a href="{{ Route::has('metis.home') ? route('metis.home') : '/' }}"
           class="text-2xl font-serif text-zinc-900 tracking-tight">
            Metis
        </a>
        <button @click="sidebarOpen = false"
                class="lg:hidden p-1 hover:bg-zinc-100 rounded">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>
    </div>

    <nav class="flex-1 px-3 py-4 space-y-6 overflow-y-auto">
        @foreach($navSections as $section)
            <div>
                @if($section['label'])
                    <h3 class="px-3 mb-2 text-[11px] font-medium text-zinc-400 uppercase tracking-wider">
                        {{ $section['label'] }}
                    </h3>
                @endif
                <ul class="space-y-0.5">
                    @foreach($section['items'] as $item)
                        @php
                            $isCurrent = $currentRoute === $item['route']
                                && empty($item['query'])
                                && ! ($section['label'] === __('Søg efter type'));
                            $needsToken = $item['requires_token'] ?? false;
                            $isDisabled = $needsToken && ! $hasUserToken;
                            $href = Route::has($item['route'])
                                ? route($item['route'], $item['query'] ?? [])
                                : '#';
                        @endphp
                        <li>
                            <a href="{{ $href }}"
                               @if($isDisabled) onclick="alert('{{ __('Indtast din personal token først via Mine alerts.') }}'); return false;" @endif
                               class="flex items-center gap-3 px-3 py-2 rounded text-sm transition-colors
                                      {{ $isCurrent
                                         ? 'bg-blue-100 text-blue-700 font-medium'
                                         : 'text-zinc-700 hover:bg-zinc-50' }}
                                      {{ $isDisabled ? 'opacity-50 cursor-not-allowed' : '' }}">
                                <span class="w-5 h-5 inline-flex items-center justify-center text-zinc-400">
                                    @switch($item['icon'])
                                        @case('home')
                                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" /></svg>
                                            @break
                                        @case('search-debt')
                                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                            @break
                                        @case('bell')
                                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" /></svg>
                                            @break
                                        @case('user')
                                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" /></svg>
                                            @break
                                        @case('building')
                                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" /></svg>
                                            @break
                                        @case('map-pin')
                                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                                            @break
                                    @endswitch
                                </span>
                                <span>{{ $item['label'] }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </nav>

    <div class="px-3 py-3 border-t border-zinc-200 text-xs text-zinc-400 space-y-1">
        @if($hasUserToken)
            <div class="px-3 py-2 bg-blue-50 border border-blue-200 rounded text-blue-700">
                ✓ {{ __('Personal token aktiv') }}
            </div>
        @endif
        <p class="px-3 text-[11px]">{{ __('Åben beta') }} · <a href="https://frankston.io/metis" class="hover:underline">frankston.io</a></p>
    </div>
</aside>

<div x-show="sidebarOpen"
     @click="sidebarOpen = false"
     x-cloak
     class="fixed inset-0 z-30 bg-black/40 lg:hidden"></div>
