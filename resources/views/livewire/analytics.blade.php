<div class="max-w-4xl mx-auto">
    <div class="flex justify-end gap-2 mb-4">
        <a href="{{ route(Route::has('metis.index') ? 'metis.index' : 'metis.home') }}"
           class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm border rounded-lg hover:bg-zinc-50 dark:hover:bg-zinc-800 dark:border-zinc-700 transition">
            {{ __('Nyt opslag') }}
        </a>
    </div>

    <h1 class="text-2xl font-bold text-ink-800 mb-1">{{ __('Spørg om ejendomsmarkedet') }}</h1>
    <p class="text-ink-700/60 text-sm mb-6">
        {{ __('Søgefeltet finder én ejendom. Her spørger du om en hel gruppe.') }}
    </p>

    <form wire:submit="spoerg" class="mb-6">
        <div class="flex gap-2">
            <input
                wire:model="spoergsmaal"
                type="text"
                class="flex-1 bg-sand-50 border border-sand-200 rounded-lg px-4 py-3 text-sm text-ink-800 placeholder-sand-300 focus:ring-2 focus:ring-warm-500/20 focus:border-warm-500 outline-none"
                placeholder="{{ __('Fx: hvor mange erhvervsejendomme i 2100 har lån med en rente over 10 %?') }}"
                aria-label="{{ __('Dit spørgsmål') }}"
            >
            <button type="submit" wire:loading.attr="disabled"
                    class="bg-warm-500 text-white rounded-lg px-6 py-3 text-sm font-semibold hover:bg-warm-600 transition disabled:opacity-60">
                <span wire:loading.remove wire:target="spoerg">{{ __('Spørg') }}</span>
                <span wire:loading wire:target="spoerg">{{ __('Regner…') }}</span>
            </button>
        </div>
    </form>

    @if(! $svar && ! $fejl)
        <div class="mb-8">
            <p class="text-xs uppercase tracking-wide text-sand-300 mb-2">{{ __('Prøv fx') }}</p>
            <div class="flex flex-wrap gap-2">
                @foreach($eksempler as $e)
                    <button wire:click="brugEksempel(@js($e))"
                            class="text-sm border border-sand-200 rounded-full px-3 py-1.5 hover:bg-sand-50 transition text-ink-700">
                        {{ $e }}
                    </button>
                @endforeach
            </div>
        </div>
    @endif

    @if($fejl)
        <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 mb-6">
            <p class="text-sm text-amber-900 font-medium">{{ $fejl }}</p>
            @foreach(data_get($svar, 'uforstaaet', []) as $u)
                <p class="text-sm text-amber-800 mt-2">{{ $u }}</p>
            @endforeach
        </div>
    @endif

    @if($svar && isset($svar['antal']))
        {{-- 🚨 SVARET FØRST, men ALDRIG uden sit forbehold. Målt på prod 10/8:
             kun ~70 % af pantebrevene i et typisk postnummer har en kendt
             rentesats. Et præcist tal uden dækningen kan føre til en forkert
             kreditbeslutning. --}}
        <div class="bg-white border border-sand-200 rounded-xl p-6 mb-4">
            <p class="text-4xl font-bold text-ink-800">{{ number_format($svar['antal'], 0, ',', '.') }}</p>
            <p class="text-sm text-ink-700/60 mt-1">{{ __('ejendomme matcher') }}</p>

            @if($svar['forstaaet'])
                <div class="mt-4 pt-4 border-t border-sand-100">
                    <p class="text-xs uppercase tracking-wide text-sand-300 mb-2">{{ __('Sådan forstod vi spørgsmålet') }}</p>
                    <div class="flex flex-wrap gap-2">
                        @foreach($svar['forstaaet'] as $navn => $vaerdi)
                            <span class="text-sm bg-sand-50 border border-sand-200 rounded px-2.5 py-1 text-ink-700">
                                {{ ucfirst($navn) }}: <strong>{{ $vaerdi }}</strong>
                            </span>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        @php($d = $svar['daekning'] ?? [])
        @if(($d['forbehold'] ?? null))
            <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 mb-6">
                <p class="text-sm text-amber-900">
                    <strong>{{ $d['pct_med_kendt_rente'] }} %</strong>
                    {{ __('af pantebrevene i dette område har en kendt rentesats.') }}
                </p>
                <p class="text-sm text-amber-800 mt-1">
                    {{ number_format($d['variabel_rente'] ?? 0, 0, ',', '.') }} {{ __('har variabel rente og') }}
                    {{ number_format($d['kontantlaan'] ?? 0, 0, ',', '.') }} {{ __('er kontantlån — de bærer en rente, men satsen er ikke oplyst i tinglysningen. Tallet ovenfor kan derfor være for lavt.') }}
                </p>
            </div>
        @endif

        @foreach($svar['uforstaaet'] ?? [] as $u)
            <div class="bg-sand-50 border border-sand-200 rounded-lg p-3 mb-4">
                <p class="text-sm text-ink-700">{{ $u }}</p>
            </div>
        @endforeach

        @if(count($svar['ejendomme'] ?? []) > 0)
            <div class="bg-white border border-sand-200 rounded-xl overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="bg-sand-50 text-left">
                        <tr>
                            <th class="px-4 py-2 font-semibold text-ink-700">{{ __('Adresse') }}</th>
                            <th class="px-4 py-2 font-semibold text-ink-700">{{ __('Postnr') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($svar['ejendomme'] as $e)
                            <tr class="border-t border-sand-100">
                                <td class="px-4 py-2">
                                    <x-metis-link type="address"
                                        :query="trim(data_get($e, 'address').', '.data_get($e, 'postal_code'), ', ')"
                                        class="text-warm-600 hover:underline">
                                        {{ data_get($e, 'address') ?: __('Uden adresse') }}
                                    </x-metis-link>
                                </td>
                                <td class="px-4 py-2 text-ink-700/70">{{ data_get($e, 'postal_code') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @if($svar['antal'] > $svar['vist'])
                    <p class="px-4 py-3 text-xs text-ink-700/60 border-t border-sand-100">
                        {{ __('Viser') }} {{ $svar['vist'] }} {{ __('af') }} {{ number_format($svar['antal'], 0, ',', '.') }}.
                    </p>
                @endif
            </div>
        @endif
    @endif
</div>
