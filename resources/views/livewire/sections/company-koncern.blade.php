<div>
    @if($kunneIkkeHentes)
        <flux:card>
            <flux:heading size="lg" class="mb-1">{{ __('Koncern') }}</flux:heading>
            <p class="text-sm text-zinc-600">{{ __('Koncernoplysningerne kunne ikke hentes lige nu.') }}</p>
            <p class="text-xs text-zinc-400 mt-1">{{ __('Det betyder ikke, at selskabet står alene.') }}</p>
        </flux:card>
    @elseif($koncern)
        @php
            $top = (array) ($koncern['top'] ?? []);
            $topNavn = ($top['navn'] ?? null) ?: ($top['cvr'] ?? '');
            $erTop = (bool) ($koncern['er_top'] ?? false);
            $selskaber = array_values(array_filter((array) ($koncern['selskaber'] ?? []), fn ($s) => is_array($s) && filled($s['cvr'] ?? null)));
            $iAlt = (int) ($koncern['selskaber_i_alt'] ?? 0);
            $ejd = (int) ($koncern['ejendomme_i_alt'] ?? 0);
            $antalTekst = trans_choice('{1}:count selskab|[2,*]:count selskaber', $iAlt, ['count' => $iAlt])
                .' '.__('og').' '.trans_choice('{0}:count ejendomme|{1}:count ejendom|[2,*]:count ejendomme', $ejd, ['count' => $ejd]);
            $forbehold = array_filter([
                ($koncern['afkortet'] ?? false) ? __('Ejerkæden er længere, end vi følger. Det viste er kun en del af koncernen.') : null,
                ($koncern['ring'] ?? false) ? __('Selskaberne ejer hinanden i ring, så toppen er valgt teknisk.') : null,
                ! ($koncern['sikker'] ?? true) ? __('Mindst ét led i kæden bygger på en ejerandel i intervallet 50 til 66,66 %, hvor ingen anden ejer har 50 % eller mere.') : null,
            ]);
        @endphp
        <flux:card>
            <flux:heading size="lg" class="mb-1">{{ __('Koncern') }}</flux:heading>
            <p class="text-sm text-zinc-600 mb-3">
                @if($erTop)
                    {{ __('Selskabet står i toppen af en koncern med :antal.', ['antal' => $antalTekst]) }}
                @else
                    {{ __('Selskabet indgår i koncernen under') }}
                    <x-metis-link type="cvr" :query="$top['cvr'] ?? ''" :label="$topNavn" />
                    ({{ $antalTekst }}).
                @endif
            </p>

            {{-- Forbehold: koncernen er en afgrænsning efter en regel, ikke en udtømmende sandhed. --}}
            @if($forbehold)
                <ul class="text-xs text-amber-700 bg-amber-50 rounded px-3 py-2 mb-3 space-y-1">
                    @foreach($forbehold as $tekst)
                        <li data-koncern-forbehold>{{ $tekst }}</li>
                    @endforeach
                </ul>
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
                                <x-metis-link type="cvr" :query="$top['cvr'] ?? ''" :label="$topNavn" />
                                <span class="ml-1 text-xs text-zinc-400">{{ __('top') }}</span>
                                @if($erTop)<span class="ml-1 text-xs text-zinc-400">· {{ __('dette selskab') }}</span>@endif
                            </td>
                            <td class="py-2 pr-4 text-right">0</td>
                            <td class="py-2 text-right">{{ (int) ($top['ejendomme'] ?? 0) }}</td>
                        </tr>
                        @foreach($selskaber as $s)
                            <tr wire:key="koncern-{{ $s['cvr'] }}" class="border-b border-zinc-100 dark:border-zinc-800" data-koncern-raekke>
                                <td class="py-2 pr-4">
                                    <x-metis-link type="cvr" :query="$s['cvr']" :label="($s['navn'] ?? null) ?: $s['cvr']" />
                                    @if($s['cvr'] === ($koncern['cvr'] ?? null))<span class="ml-1 text-xs text-zinc-400">{{ __('dette selskab') }}</span>@endif
                                </td>
                                <td class="py-2 pr-4 text-right">{{ (int) ($s['led_fra_top'] ?? 0) }}</td>
                                <td class="py-2 text-right">{{ (int) ($s['ejendomme'] ?? 0) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($iAlt - 1 > count($selskaber))
                <p class="text-xs text-zinc-400 mt-2">+ {{ trans_choice('{1}:count selskab mere|[2,*]:count flere selskaber', $iAlt - 1 - count($selskaber), ['count' => $iAlt - 1 - count($selskaber)]) }}</p>
            @endif
            <p class="text-xs text-zinc-400 mt-3">
                {{ __('Koncernen følger dansk selskabsejerskab: en ejer med mindst to tredjedele, eller 50 til 66,66 % når ingen anden ejer har 50 % eller mere. Personers øvrige selskaber indgår ikke. Ejendomme er dem, koncernens selskaber selv ejer; porteføljen længere nede tæller bredere (enhver ejerandel) og kan derfor vise et andet tal. Forbeholdene gælder dette selskabs egen kæde til toppen. Kilde: CVR.') }}
            </p>
        </flux:card>
    @endif
</div>
