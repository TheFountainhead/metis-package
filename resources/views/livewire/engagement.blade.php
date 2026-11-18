<div>
    <div class="flex items-center justify-between py-2 mb-4 border-b">
        <div class="flex items-center gap-3">
            <h1 class="text-xl font-bold">
                {{ $engagement ? \TheFountainhead\Metis\Livewire\Engagements::ownerLabel($engagement) : __('Engagement') }}
            </h1>
            @if($engagement)
                <span class="text-sm text-zinc-500">{{ __(':lender pant', ['lender' => \TheFountainhead\Metis\Livewire\Engagements::lenderPossessive($meta)]) }}: {{ number_format($engagement['lender_kr'] ?? 0, 0, ',', '.') }} kr.</span>
            @endif
        </div>
        <a href="{{ route('metis.engagements') }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm border rounded-lg hover:bg-zinc-50 transition">
            ← {{ __('Alle engagementer') }}
        </a>
    </div>

    @if(! $this->hasUserToken())
        <div class="max-w-xl p-6 bg-white border rounded-xl">
            <h2 class="text-lg font-semibold mb-2">{{ __('Kun for pilotbrugere') }}</h2>
            <p class="text-sm text-zinc-600 mb-4">{{ __('Bekræft din arbejdsmail for at se engagementet.') }}</p>
            <button type="button" wire:click="$dispatch('show-email-gate')" class="px-4 py-2 text-sm font-medium text-white bg-zinc-900 rounded-lg hover:bg-zinc-700 transition">
                {{ __('Bekræft arbejdsmail') }}
            </button>
        </div>
    @elseif($unbound)
        <div class="max-w-xl p-6 text-sm border rounded-xl border-amber-200 bg-amber-50 text-amber-800">
            {{ __('Din bruger er ikke knyttet til et långiverselskab endnu. Skriv til os, så sætter vi det op.') }}
        </div>
    @elseif($notFound)
        <div class="max-w-xl p-6 text-sm border rounded-xl bg-white text-zinc-600">
            {{ __('Engagementet findes ikke blandt dine engagementer.') }}
        </div>
    @elseif($errorMessage)
        <div class="max-w-xl p-6 text-sm border rounded-xl border-amber-200 bg-amber-50 text-amber-800">
            {{ $errorMessage }}
            <button type="button" wire:click="load" class="ml-2 underline">{{ __('Prøv igen') }}</button>
        </div>
    @elseif($engagement)
        @php
            $caveat = ($meta['disclaimer'] ?? null) ?: __(\TheFountainhead\Metis\Livewire\Engagements::CAVEAT_FALLBACK);
            $kr = fn ($v) => number_format((int) $v, 0, ',', '.').' kr.';
            $lenderShort = \TheFountainhead\Metis\Services\LegalName::short((string) (($meta['lender']['name'] ?? null) ?: __('Långiver')));
            $dato = fn ($d) => $d ? \Carbon\Carbon::parse($d)->format('d.m.Y') : '—';
            $kindLabel = [
                'new_lien' => __('Ny hæftelse'), 'paid_off' => __('Indfriet'),
                'owner_registered' => __('Ny ejer registreret'), 'owner_ended' => __('Ejer udgået'),
            ];
        @endphp

        <p class="p-3 mb-6 text-xs border rounded-lg border-zinc-200 bg-zinc-50 text-zinc-600">{{ $caveat }}</p>

        {{-- ÆNDRINGER SIDEN EGEN TINGLYSNING --}}
        <section class="mb-8">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 mb-2">{{ __('Ændringer siden pantet blev tinglyst') }}</h2>
            @if(empty($engagement['changes']))
                @php $since = collect($engagement['properties'] ?? [])->pluck('own_since')->filter()->min(); @endphp
                <p class="p-4 text-sm border rounded-xl bg-white text-zinc-600">
                    {{ __('Ingen ændringer siden :dato.', ['dato' => $dato($since)]) }}
                </p>
            @else
                <ul class="border rounded-xl bg-white divide-y divide-zinc-100">
                    @foreach($engagement['changes'] as $change)
                        <li wire:key="change-{{ $loop->index }}-{{ md5(json_encode($change)) }}" class="flex items-start gap-3 px-4 py-3 text-sm">
                            <span class="mt-0.5 inline-block w-2 h-2 rounded-full {{ ($change['severity'] ?? 'low') === 'high' ? 'bg-red-500' : 'bg-zinc-400' }}" aria-hidden="true"></span>
                            <div class="flex-1">
                                <div class="font-medium">
                                    {{ $kindLabel[$change['kind']] ?? $change['kind'] }}
                                    @if($change['is_ahead'] ?? false)
                                        <span class="ml-1 text-xs text-red-700">{{ __('foranstående') }}</span>
                                    @endif
                                </div>
                                <div class="text-xs text-zinc-500">
                                    {{ $dato($change['date'] ?? null) }}
                                    @if(isset($change['amount_kr'])) · {{ $kr($change['amount_kr']) }} @endif
                                    @if(! empty($change['creditor'])) · {{ $change['creditor'] }} @endif
                                    @if(! empty($change['address'])) · {{ $change['address'] }} @endif
                                    {{-- Samme pant lyst på flere ejendomme står som ÉN ændring (registry-api #480). --}}
                                    @if((int) ($change['property_count'] ?? 0) > 1)
                                        <span title="{{ implode('; ', array_filter((array) ($change['addresses'] ?? []), 'is_string')) }}">({{ __('ændring på :n ejendomme i engagementet', ['n' => (int) $change['property_count']]) }})</span>
                                    @endif
                                    @if(! empty($change['owner']['label'])) · {{ $change['owner']['label'] }} @endif
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- PRIORITETSSTIGE PR. EJENDOM --}}
        @foreach($engagement['properties'] ?? [] as $property)
            <section class="mb-8" wire:key="property-{{ $property['id'] ?? $loop->index }}">
                <div class="flex items-baseline justify-between mb-2">
                    <h2 class="text-base font-semibold">
                        {{ $property['address'] ?? '—' }}@if($property['postal_code'] ?? null)<span class="text-zinc-500">, {{ $property['postal_code'] }} {{ $property['city'] ?? '' }}</span>@endif
                    </h2>
                    <div class="text-xs text-zinc-500">
                        BFE {{ $property['bfe'] ?? '—' }} · {{ __('Pant siden') }} {{ $dato($property['own_since'] ?? null) }}
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4 mb-2 text-sm">
                    <div class="p-3 border rounded-lg bg-white">
                        <div class="text-xs uppercase tracking-wide text-zinc-500">{{ __('Foranstående pant') }}</div>
                        {{-- null = prioriteten på mit eget pant er ukendt. Det er IKKE 0:
                             et 0 ville læses som "jeg står forrest". --}}
                        @if(($property['ahead_kr'] ?? null) === null)
                            <div class="font-semibold text-zinc-400">{{ __('ukendt (prioritet mangler)') }}</div>
                        @else
                            <div class="font-semibold tabular-nums {{ $property['ahead_kr'] > 0 ? 'text-zinc-900' : 'text-zinc-400' }}">{{ $kr($property['ahead_kr']) }}</div>
                        @endif
                    </div>
                    <div class="p-3 border rounded-lg bg-white">
                        <div class="text-xs uppercase tracking-wide text-zinc-500">{{ __('Samlet pant på ejendommen') }}</div>
                        <div class="font-semibold tabular-nums">{{ $kr($property['total_debt_kr'] ?? 0) }}</div>
                    </div>
                </div>
                <div class="overflow-x-auto border rounded-xl bg-white">
                    <table class="w-full text-sm">
                        <thead class="text-xs uppercase tracking-wide text-zinc-500 border-b">
                            <tr>
                                <th class="px-3 py-2 text-left font-medium" title="{{ __('Prioritetsnummer fra Tinglysningsretten, lavere = bedre') }}">Pri</th>
                                {{-- Status står FORREST, så den er synlig på mobil uden at rulle vandret. --}}
                                <th class="px-3 py-2 text-left font-medium"></th>
                                <th class="px-3 py-2 text-left font-medium">{{ __('Type') }}</th>
                                <th class="px-3 py-2 text-left font-medium">{{ __('Kreditor') }}</th>
                                <th class="px-3 py-2 text-left font-medium">{{ __('Underpanthaver') }}</th>
                                <th class="px-3 py-2 text-right font-medium">{{ __('Hovedstol') }}</th>
                                <th class="px-3 py-2 text-left font-medium">{{ __('Tinglyst') }}</th>
                            </tr>
                        </thead>
                            @foreach($property['ladder'] ?? [] as $row)
                                @php
                                    $rente = is_numeric($row['interest_rate'] ?? null) ? (float) $row['interest_rate'] : null;
                                    $renteType = $row['interest_rate_type'] ?? null;
                                    $udloeb = $row['maturity_date'] ?? null;
                                    // Foldud kun når der ER noget bag rækken: en sats, en forklaring på
                                    // hvorfor den mangler, eller et udløb. 'fast' uden sats er ingenting.
                                    $harDetaljer = $rente !== null || in_array($renteType, ['variabel', 'kontantlaan'], true) || $udloeb;
                                    $detaljeId = 'lien-detalje-'.($property['id'] ?? 'p').'-'.($row['id'] ?? $loop->index);
                                @endphp
                                <tbody wire:key="lien-{{ $property['id'] ?? 'p' }}-{{ $row['id'] ?? $loop->index }}" x-data="{ aaben: false }" class="border-t border-zinc-100">
                                    <tr class="{{ ($row['is_own'] ?? false) ? 'bg-wheat/60 font-medium' : '' }}">
                                        <td class="px-3 py-2 tabular-nums">
                                            @if($harDetaljer)
                                                <button type="button" @click="aaben = !aaben" :aria-expanded="aaben.toString()" aria-controls="{{ $detaljeId }}"
                                                        class="inline-flex items-center gap-1 hover:underline" title="{{ __('Vis rente og udløb') }}">
                                                    <span aria-hidden="true" class="text-zinc-400 transition-transform" :class="aaben ? 'rotate-90' : ''">&#9656;</span>
                                                    {{ $row['priority'] ?? '?' }}
                                                </button>
                                            @else
                                                {{ $row['priority'] ?? '?' }}
                                            @endif
                                        </td>
                                        <td class="px-3 py-2 text-xs whitespace-nowrap">
                                            {{-- Tekst, ikke farvede piller: rækkens tone og vægt bærer "eget pant",
                                                 og kun den foranstående status (risikoen) får farve. --}}
                                            @if($row['is_own'] ?? false)
                                                <span class="text-zinc-900">{{ $lenderShort }}</span>
                                            @elseif($row['is_ahead'] ?? false)
                                                <span class="text-red-700">{{ __('Foranstående') }}</span>
                                            @elseif($row['is_pari'] ?? false)
                                                <span class="text-zinc-600">{{ __('Sideordnet') }}</span>
                                            @elseif($row['priority_unknown'] ?? false)
                                                <span class="text-zinc-500">{{ __('Prioritet ukendt') }}</span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2 text-zinc-600">{{ $row['type'] ?? '—' }}</td>
                                        <td class="px-3 py-2">{{ $row['creditor'] ?? '—' }}</td>
                                        <td class="px-3 py-2 text-zinc-600">{{ implode(', ', $row['pledgees'] ?? []) ?: '—' }}</td>
                                        <td class="px-3 py-2 text-right tabular-nums">{{ $kr($row['amount_kr'] ?? 0) }}</td>
                                        <td class="px-3 py-2 text-zinc-600">{{ $dato($row['registered_at'] ?? null) }}</td>
                                    </tr>
                                    @if($harDetaljer)
                                        {{-- display:none inline: pakken ejer ikke værtens CSS, så x-cloak alene virker ikke. --}}
                                        <tr id="{{ $detaljeId }}" data-lien-detalje x-show="aaben" style="display:none" class="bg-zinc-50 text-xs text-zinc-600">
                                            <td colspan="7" class="px-3 py-2">
                                                <dl class="flex flex-wrap gap-x-8 gap-y-1">
                                                    <div>
                                                        <dt class="inline text-zinc-500">{{ __('Rente') }}:</dt>
                                                        <dd class="inline">
                                                            @if($rente !== null && ($row['interest_rate_nominal'] ?? false))
                                                                {{ __('rammerente') }} {{ number_format($rente, 2, ',', '.') }} % {{ __('(juridisk maksimum, ikke den faktiske rente)') }}
                                                            @elseif($rente !== null)
                                                                {{ number_format($rente, 2, ',', '.') }} %
                                                            @elseif($renteType === 'variabel')
                                                                {{ __('variabel, sats ikke oplyst') }}
                                                            @elseif($renteType === 'kontantlaan')
                                                                {{ __('kontantlån, sats ikke oplyst') }}
                                                            @else
                                                                {{ __('ikke oplyst') }}
                                                            @endif
                                                        </dd>
                                                    </div>
                                                    @if($udloeb)
                                                        <div><dt class="inline text-zinc-500">{{ __('Udløb') }}:</dt> <dd class="inline">{{ $dato($udloeb) }}</dd></div>
                                                    @endif
                                                </dl>
                                            </td>
                                        </tr>
                                    @endif
                                </tbody>
                            @endforeach
                    </table>
                </div>
            </section>
        @endforeach

        {{-- LÅNTAGERNE --}}
        <section class="mb-8">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 mb-2">{{ __('Låntager') }}</h2>
            @if($this->personOwnerCount > 0)
                <p class="p-4 mb-4 text-sm border rounded-xl bg-white text-zinc-600">
                    {{ trans_choice('Ejendommen ejes af en privatperson.|Ejendommen ejes af :count privatpersoner.', $this->personOwnerCount, ['count' => $this->personOwnerCount]) }}
                </p>
            @endif
            @foreach($this->companyOwners as $owner)
                <div class="mb-6" wire:key="owner-{{ $owner['cvr'] ?? $loop->index }}">
                    <div class="flex items-baseline gap-3 mb-2">
                        <x-metis-link type="cvr" :query="$owner['cvr'] ?? ''" class="text-base font-semibold hover:underline">
                            {{ \TheFountainhead\Metis\Services\LegalName::format((string) ($owner['name'] ?? $owner['cvr'] ?? '')) }}
                        </x-metis-link>
                        <span class="text-xs text-zinc-500">CVR {{ $owner['cvr'] ?? '—' }}@if($owner['status'] ?? null) · {{ $owner['status'] }}@endif</span>
                    </div>
                    {{-- Regnskab og roller lånes fra selskabsopslaget. `lazy="on-load"`,
                         ikke bare `lazy`: sektioner der venter på viewport hang. --}}
                    <div class="space-y-4">
                        @if(! empty($owner['cvr']))
                            <livewire:metis-company-info :query="$owner['cvr']" lazy="on-load" :key="'info-'.$owner['cvr']" />
                            <livewire:metis-company-roles :query="$owner['cvr']" lazy="on-load" :key="'roles-'.$owner['cvr']" />
                        @endif
                    </div>
                </div>
            @endforeach
        </section>
    @endif
</div>
