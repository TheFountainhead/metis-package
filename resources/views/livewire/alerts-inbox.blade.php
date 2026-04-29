<div>
    <div class="flex items-center justify-between py-2 mb-4 border-b">
        <div class="flex items-center gap-3">
            <h1 class="text-xl font-bold">{{ __('Mine alerts') }}</h1>
            <span class="text-sm text-zinc-500">{{ __('Ændringer på ejendomme og selskaber du følger') }}</span>
        </div>
        @php
            $backRoute = Route::has('metis.index') ? 'metis.index' : (Route::has('metis.home') ? 'metis.home' : null);
        @endphp
        <div class="flex items-center gap-2">
            @if($this->hasUserToken())
                <button wire:click="clearToken"
                        class="text-xs px-2 py-1 border border-zinc-300 rounded hover:bg-zinc-50">
                    {{ __('Log ud') }}
                </button>
            @endif
            @if($backRoute)
                <a href="{{ route($backRoute) }}"
                   class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm border rounded-lg hover:bg-zinc-50 transition">
                    ← {{ __('Tilbage') }}
                </a>
            @endif
        </div>
    </div>

    @if(! $this->hasUserToken())
        <div class="max-w-xl mx-auto p-8 bg-white border rounded-lg">
            <h2 class="text-lg font-semibold mb-2">{{ __('Indtast din personal token') }}</h2>
            <p class="text-sm text-zinc-600 mb-4">
                {{ __('Pilot-bruger? Indtast den token du har modtaget for at se dine alerts og fulgte entiteter. Tokenen gemmes kun i din session.') }}
            </p>

            <form wire:submit.prevent="setToken">
                <div class="mb-3">
                    <input type="password"
                           wire:model.defer="tokenInput"
                           placeholder="13|abc123..."
                           autocomplete="off"
                           autofocus
                           class="w-full px-3 py-2 border rounded font-mono text-sm
                                  {{ $tokenError ? 'border-red-300' : 'border-zinc-300' }}">
                    @if($tokenError)
                        <p class="text-xs text-red-600 mt-1">
                            {{ __('Ugyldigt token-format. Forventer fx 13|abc123...') }}
                        </p>
                    @endif
                </div>
                <div class="flex justify-end gap-2">
                    <button type="submit" class="px-4 py-2 text-sm bg-blue-600 text-white rounded hover:bg-blue-700">
                        {{ __('Aktivér') }}
                    </button>
                </div>
            </form>

            <p class="text-xs text-zinc-400 mt-4">
                {{ __('Tokenen tilhører dig personligt og må ikke deles. Logger automatisk ud når sessionen lukker.') }}
            </p>
        </div>
    @else

    @php
        $watches = $watchlists ?? [];
        $watchCount = count($watches);
        $propertyCount = collect($watches)->where('watch_type', 'property')->count();
        $companyCount = collect($watches)->where('watch_type', 'company')->count();
    @endphp

    @if($watchCount > 0)
        <div class="mb-4 p-4 bg-blue-50 border border-blue-200 rounded-lg dark:bg-blue-900/20 dark:border-blue-800">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-blue-900 dark:text-blue-200">
                        {{ __('Du følger') }} <strong>{{ $watchCount }}</strong> {{ __('entiteter') }}
                        <span class="text-xs font-normal text-blue-700 dark:text-blue-300">
                            ({{ $propertyCount }} {{ __('ejendomme') }}, {{ $companyCount }} {{ __('selskaber') }})
                        </span>
                    </p>
                    <p class="text-xs text-blue-700 dark:text-blue-300 mt-1">
                        {{ __('Alerts dukker op her når der tinglyses ny gæld eller ændringer på dem.') }}
                    </p>
                </div>
                <button wire:click="toggleWatchlists"
                        class="text-xs px-3 py-1 border border-blue-300 rounded hover:bg-blue-100 dark:border-blue-700 dark:hover:bg-blue-900/40">
                    {{ $showWatchlists ? __('Skjul liste') : __('Vis alle') }}
                </button>
            </div>

            @if($showWatchlists)
                <div class="mt-4 max-h-96 overflow-y-auto bg-white border rounded">
                    <table class="w-full text-sm">
                        <thead class="text-xs text-zinc-500 bg-zinc-50">
                            <tr>
                                <th class="text-left px-3 py-2 w-24">{{ __('Type') }}</th>
                                <th class="text-left px-3 py-2">{{ __('Følger') }}</th>
                                <th class="text-right px-3 py-2 w-20"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($watches as $w)
                                @php
                                    $isCompany = $w['watch_type'] === 'company';
                                    $detail = $isCompany
                                        ? 'CVR '.$w['watch_value']
                                        : null;
                                @endphp
                                <tr class="border-t">
                                    <td class="px-3 py-2">
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs
                                                     {{ $isCompany ? 'bg-purple-100 text-purple-700' : 'bg-green-100 text-green-700' }}">
                                            {{ $isCompany ? __('Selskab') : __('Ejendom') }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-2">
                                        <div>{{ $w['display_label'] ?? '—' }}</div>
                                        @if($detail)
                                            <div class="text-xs text-zinc-500 font-mono">{{ $detail }}</div>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 text-right">
                                        <button wire:click="unfollow({{ $w['id'] }})"
                                                wire:confirm="{{ __('Stop med at følge?') }}"
                                                class="text-xs text-red-600 hover:underline">
                                            {{ __('Stop') }}
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif

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
            @if($watchCount > 0)
                <h2 class="text-lg font-semibold mb-2">{{ __('Ingen alerts endnu') }}</h2>
                <p class="text-sm text-zinc-600 mb-4">
                    {{ __('Du følger') }} <strong>{{ $watchCount }}</strong> {{ __('entiteter, men der er ikke tinglyst ændringer på dem siden du startede.') }}<br>
                    {{ __('Alerts dukker op automatisk her — typisk indenfor få timer når en pant-ændring sker.') }}
                </p>
            @else
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
            @endif
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

    @endif
</div>
