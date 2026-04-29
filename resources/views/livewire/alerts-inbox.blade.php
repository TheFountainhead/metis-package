<div>
    <div class="flex items-center justify-between py-2 mb-4 border-b">
        <div class="flex items-center gap-3">
            <h1 class="text-xl font-bold">{{ __('Mine alerts') }}</h1>
            <span class="text-sm text-zinc-500">{{ __('Ændringer på ejendomme og selskaber du følger') }}</span>
        </div>
        @php
            $backRoute = Route::has('metis.index') ? 'metis.index' : (Route::has('metis.home') ? 'metis.home' : null);
        @endphp
        @if($backRoute)
            <a href="{{ route($backRoute) }}"
               class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm border rounded-lg hover:bg-zinc-50 transition">
                ← {{ __('Tilbage') }}
            </a>
        @endif
    </div>

    <div class="flex items-center gap-3 mb-4">
        <label class="flex items-center gap-1 text-sm">
            <input type="checkbox" wire:model.live="unreadOnly">
            {{ __('Kun ulæste') }}
        </label>

        <select wire:model.live="priority" class="px-2 py-1 text-sm border rounded">
            <option value="">{{ __('Alle prioriteter') }}</option>
            <option value="high">{{ __('Høj') }}</option>
            <option value="low">{{ __('Lav') }}</option>
        </select>
    </div>

    @if($loading)
        <div class="p-12 text-center text-zinc-500" wire:loading.delay.300ms>
            <div class="inline-block w-6 h-6 border-2 border-zinc-300 border-t-zinc-600 rounded-full animate-spin"></div>
            <p class="mt-2 text-sm">{{ __('Henter alerts...') }}</p>
        </div>
    @endif

    @if($error)
        <div class="p-4 bg-red-50 border border-red-200 rounded-lg text-red-700">
            ⚠️ {{ $error }}
            <button wire:click="fetch" class="ml-2 text-xs underline">{{ __('Prøv igen') }}</button>
        </div>
    @endif

    @php
        $alerts = $response['data'] ?? [];
        $hasAlerts = count($alerts) > 0;
    @endphp

    @if(! $hasAlerts && ! $loading && ! $error)
        <div class="p-12 text-center bg-white border rounded-lg">
            <h2 class="text-lg font-semibold mb-2">{{ __('Du følger ikke noget endnu') }}</h2>
            <p class="text-sm text-zinc-600 mb-4">
                {{ __('Du får besked når der tinglyses ny gæld på ejendomme eller selskaber du følger.') }}
            </p>
            <div class="flex justify-center gap-3">
                @if(Route::has('metis.home'))
                    <a href="{{ route('metis.home') }}"
                       class="px-4 py-2 text-sm bg-blue-600 text-white rounded hover:bg-blue-700">
                        {{ __('Søg ejendom eller selskab') }}
                    </a>
                @endif
                @if(Route::has('metis.debt-search'))
                    <a href="{{ route('metis.debt-search') }}"
                       class="px-4 py-2 text-sm border rounded hover:bg-zinc-50">
                        {{ __('Søg gæld') }}
                    </a>
                @endif
            </div>
        </div>
    @endif

    @if($hasAlerts)
        <ul class="bg-white border rounded-lg divide-y">
            @foreach($alerts as $alert)
                @php
                    $meta = is_array($alert['metadata'] ?? null) ? $alert['metadata'] : (json_decode($alert['metadata'] ?? '[]', true) ?: []);
                    $watchType = $alert['watchlist']['watch_type'] ?? '';
                    $watchLabel = $alert['watchlist']['display_label'] ?? $alert['watchlist']['watch_value'] ?? '';
                    $isHigh = ($alert['priority'] ?? 'low') === 'high';
                @endphp
                <li class="px-4 py-3 {{ $alert['is_read'] ? 'opacity-60' : '' }}">
                    <div class="flex justify-between items-start gap-4">
                        <div class="flex-1 min-w-0">
                            <p class="font-medium">{{ $alert['title'] ?? '' }}</p>
                            <p class="text-sm text-zinc-600 mt-1">{{ $alert['description'] ?? '' }}</p>

                            <div class="flex items-center gap-2 mt-2 text-xs text-zinc-500">
                                <span class="px-2 py-0.5 rounded {{ $isHigh ? 'bg-red-100 text-red-700' : 'bg-zinc-100' }}">
                                    {{ $isHigh ? __('Høj prioritet') : __('Information') }}
                                </span>
                                <span>{{ __('Via') }}: {{ $watchType === 'company' ? __('Selskab') : __('Ejendom') }} {{ $watchLabel }}</span>
                                <span>{{ \Carbon\Carbon::parse($alert['created_at'])->diffForHumans() }}</span>
                            </div>
                        </div>

                        <div class="flex flex-col items-end gap-2 shrink-0">
                            @if(! $alert['is_read'])
                                <button wire:click="markRead({{ $alert['id'] }})"
                                        class="text-xs px-2 py-1 border rounded hover:bg-zinc-50">
                                    {{ __('Markér læst') }}
                                </button>
                            @endif
                            @if(! empty($meta['address']))
                                <a href="/lookup/address/{{ urlencode($meta['address']) }}"
                                   class="text-xs text-blue-600 hover:underline">
                                    {{ __('Se ejendom') }} →
                                </a>
                            @endif
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>

        @if(($response['last_page'] ?? 1) > 1)
            <div class="mt-4 flex justify-center gap-2">
                @if(($response['current_page'] ?? 1) > 1)
                    <button wire:click="previousPage" class="text-sm px-3 py-1 border rounded hover:bg-zinc-50">
                        ← {{ __('Forrige') }}
                    </button>
                @endif
                <span class="text-sm py-1">
                    {{ __('Side') }} {{ $response['current_page'] }} / {{ $response['last_page'] }}
                </span>
                @if(($response['current_page'] ?? 1) < ($response['last_page'] ?? 1))
                    <button wire:click="nextPage" class="text-sm px-3 py-1 border rounded hover:bg-zinc-50">
                        {{ __('Næste') }} →
                    </button>
                @endif
            </div>
        @endif
    @endif
</div>
