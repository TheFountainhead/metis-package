<div>
    @if($koncern)
        @php
            $top = $koncern['top'];
            $erTop = (bool) ($koncern['er_top'] ?? false);
            $selskaber = $koncern['selskaber'] ?? [];
            $iAlt = (int) $koncern['selskaber_i_alt'];
            $ejd = (int) ($koncern['ejendomme_i_alt'] ?? 0);
        @endphp
        <flux:card>
            <flux:heading size="lg" class="mb-1">{{ __('Koncern') }}</flux:heading>
            <p class="text-sm text-zinc-600 mb-3">
                @if($erTop)
                    {{ __('Selskabet står i toppen af en koncern med :n selskaber og :e ejendomme.', ['n' => $iAlt, 'e' => $ejd]) }}
                @else
                    {{ __('Selskabet indgår i koncernen under') }}
                    <x-metis-link type="cvr" :query="$top['cvr']" :label="$top['navn'] ?: $top['cvr']" />:
                    {{ __(':n selskaber og :e ejendomme.', ['n' => $iAlt, 'e' => $ejd]) }}
                @endif
            </p>

            {{-- Forbehold: koncernen er en afgrænsning efter en regel, ikke en udtømmende sandhed. --}}
            @if(! ($koncern['sikker'] ?? true) || ($koncern['ring'] ?? false) || ($koncern['afkortet'] ?? false))
                <p class="text-xs text-amber-700 bg-amber-50 rounded px-3 py-2 mb-3">
                    @if($koncern['afkortet'] ?? false)
                        {{ __('Ejerkæden er længere, end vi følger. Det viste er kun en del af koncernen.') }}
                    @elseif($koncern['ring'] ?? false)
                        {{ __('Selskaberne ejer hinanden i ring, så toppen er valgt teknisk.') }}
                    @else
                        {{ __('Mindst ét led i kæden bygger på en ejerandel på præcis 50 %.') }}
                    @endif
                </p>
            @endif

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200 dark:border-zinc-700">
                            <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('Selskab') }}</th>
                            <th class="text-right py-2 pr-4 font-medium text-zinc-500" title="{{ __('Antal led fra koncernens top') }}">{{ __('Led fra top') }}</th>
                            <th class="text-right py-2 font-medium text-zinc-500" title="{{ __('Ejendomme selskabet selv ejer') }}">{{ __('Ejendomme') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="border-b border-zinc-100 dark:border-zinc-800" data-koncern-raekke>
                            <td class="py-2 pr-4">
                                <x-metis-link type="cvr" :query="$top['cvr']" :label="$top['navn'] ?: $top['cvr']" />
                                <span class="ml-1 text-xs text-zinc-400">{{ __('top') }}</span>
                                @if($erTop)<span class="ml-1 text-xs text-zinc-400">· {{ __('dette selskab') }}</span>@endif
                            </td>
                            <td class="py-2 pr-4 text-right">0</td>
                            <td class="py-2 text-right">{{ (int) ($top['ejendomme'] ?? 0) }}</td>
                        </tr>
                        @foreach($selskaber as $s)
                            <tr wire:key="koncern-{{ $s['cvr'] }}" class="border-b border-zinc-100 dark:border-zinc-800" data-koncern-raekke>
                                <td class="py-2 pr-4">
                                    <x-metis-link type="cvr" :query="$s['cvr']" :label="$s['navn'] ?: $s['cvr']" />
                                    @if($s['cvr'] === $koncern['cvr'])<span class="ml-1 text-xs text-zinc-400">{{ __('dette selskab') }}</span>@endif
                                </td>
                                <td class="py-2 pr-4 text-right">{{ (int) $s['led_fra_top'] }}</td>
                                <td class="py-2 text-right">{{ (int) $s['ejendomme'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($iAlt - 1 > count($selskaber))
                <p class="text-xs text-zinc-400 mt-2">{{ __('+ :n flere selskaber', ['n' => $iAlt - 1 - count($selskaber)]) }}</p>
            @endif
            <p class="text-xs text-zinc-400 mt-3">
                {{ __('Koncernen følger dansk selskabsejerskab på mindst to tredjedele (eller 50 % uden en anden ejer med 50 %). Personers øvrige selskaber indgår ikke. Kilde: CVR.') }}
            </p>
        </flux:card>
    @endif
</div>
