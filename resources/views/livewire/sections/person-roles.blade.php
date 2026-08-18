<div>
    <flux:card>
        <flux:heading size="xl" class="mb-1">{{ $personName ?? $query }}</flux:heading>
        <div class="text-sm text-zinc-500 mb-4">{{ __('Person lookup via CVR') }}</div>

        {{-- Address --}}
        @if($address)
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-4 text-sm mb-6">
                <div>
                    <div class="text-xs text-zinc-400">{{ __('Address') }}</div>
                    <div>
                        @if($address['street'] ?? null)
                            {{ $address['street'] }} {{ $address['number'] ?? '' }}
                            @if($address['floor'] ?? null), {{ $address['floor'] }}.@endif
                            @if($address['door'] ?? null) {{ $address['door'] }}@endif
                        @endif
                    </div>
                </div>
                @if($address['postal_code'] ?? null)
                    <div>
                        <div class="text-xs text-zinc-400">{{ __('City') }}</div>
                        <div>{{ $address['postal_code'] }} {{ $address['city'] ?? '' }}</div>
                    </div>
                @endif
                @if($address['municipality'] ?? null)
                    <div>
                        <div class="text-xs text-zinc-400">{{ __('Municipality') }}</div>
                        <div>{{ $address['municipality'] }}</div>
                    </div>
                @endif
            </div>
        @endif

        {{-- Properties --}}
        @if(count($properties) > 0)
            <div class="mb-6">
                <flux:heading size="lg" class="mb-3">{{ __('Properties') }}</flux:heading>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-zinc-200 dark:border-zinc-700">
                                <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('Address') }}</th>
                                <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('Area') }}</th>
                                <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('Ownership') }}</th>
                                <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('Public Valuation') }}</th>
                                <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('Latest Sale') }}</th>
                                <th class="text-left py-2 font-medium text-zinc-500">{{ __('Mortgages') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($properties as $prop)
                                @php $tx = $prop['latest_transaction'] ?? null; @endphp
                                <tr class="border-b border-zinc-100 dark:border-zinc-800">
                                    <td class="py-2 pr-4">
                                        <x-metis-link type="address" :query="($prop['address'] ?? '') . ', ' . ($prop['postal_code'] ?? '') . ' ' . ($prop['city'] ?? '')" :label="($prop['address'] ?? '-') . ', ' . ($prop['postal_code'] ?? '') . ' ' . ($prop['city'] ?? '')" />
                                        @if($prop['year_built'] ?? null)
                                            <div class="text-xs text-zinc-400">{{ __('Built') }} {{ $prop['year_built'] }}</div>
                                        @endif
                                    </td>
                                    <td class="py-2 pr-4">
                                        @if($prop['area_building'] ?? null)
                                            {{ number_format($prop['area_building'], 0, ',', '.') }} m²
                                        @elseif($prop['area_land'] ?? null)
                                            {{ number_format($prop['area_land'], 0, ',', '.') }} m²
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td class="py-2 pr-4">{{ ($prop['ownership_share'] ?? null) ? number_format($prop['ownership_share'], 0) . '%' : '-' }}</td>
                                    <td class="py-2 pr-4">
                                        @if($prop['public_valuation'] ?? null)
                                            {{ number_format($prop['public_valuation'], 0, ',', '.') }} kr
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td class="py-2 pr-4">
                                        @if($tx)
                                            {{ number_format($tx['price'], 0, ',', '.') }} kr
                                            <div class="text-xs text-zinc-400">{{ \Carbon\Carbon::parse($tx['date'])->translatedFormat('d. M Y') }}</div>
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td class="py-2">{{ ($prop['mortgage_count'] ?? 0) > 0 ? $prop['mortgage_count'] : '-' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        {{-- Company Roles --}}
        @if(count($companies) > 0)
            @php
                // Default: kun selskaber hvor personen har en AKTUEL rolle. Gamle/
                // ophørte roller støjer (Kristian 22/7) — de kan foldes ind via toggle.
                $hasCurrentRole = fn ($c) => collect($c['roles'] ?? [])->contains(fn ($r) => $r['is_current'] ?? false);
                $visibleCompanies = $showAllRoles ? $companies : array_values(array_filter($companies, $hasCurrentRole));
                $hiddenCount = count($companies) - count($visibleCompanies);
            @endphp
            {{-- 🚨 Antallet af skjulte roller staar i OVERSKRIFTEN, ikke kun paa
                 knappen nederst. Maalt 11/8: Larnaes talte 9 relationer hos
                 Resights og saa 8 hos os — det manglende selskab laa bag
                 "Vis også tidligere roller (1)", som han ikke opdagede. Han
                 konkluderede at vores data var ufuldstaendige.

                 Standarden (skjul gamle roller) er bevidst og uaendret
                 (Kristian 22/7 — de stoejer). Det der manglede var et signal
                 om at noget ER filtreret fra.

                 🪤 Ikke et saertilfaelde: 207.649 personer har mindst én
                 historisk rolle mod 65.509 uden, saa lydloes filtrering er
                 normaltilstanden for flertallet af opslag. --}}
            <flux:heading size="lg" class="mb-3">
                {{ __('Company Roles') }}
                @if(! $showAllRoles && $hiddenCount > 0)
                    <span class="text-sm font-normal text-zinc-500">
                        ({{ $hiddenCount }} {{ __('tidligere skjult') }})
                    </span>
                @endif
            </flux:heading>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200 dark:border-zinc-700">
                            <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('Company') }}</th>
                            <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('CVR') }}</th>
                            <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('Type') }}</th>
                            <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('Role') }}</th>
                            <th class="text-left py-2 font-medium text-zinc-500">{{ __('Status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($visibleCompanies as $company)
                            @php
                                // CVR's rå register-navn "EJERREGISTER" er ikke en menneskelig
                                // rolle men navnet på det legale ejer-register. Vis det læsbart.
                                $prettyRole = fn ($label) => match ($label) {
                                    'EJERREGISTER' => __('Legal ejer'),
                                    default => $label,
                                };
                                $currentRoles = collect($company['roles'] ?? [])->filter(fn ($r) => $r['is_current'] ?? false);
                                $roleLabels = $currentRoles->pluck('role_label')->filter()->map($prettyRole)->unique()->implode(', ');
                                // Reel ejer (beneficial_owner) er regulatorisk vigtig → fremhæv
                                // strukturelt via role_type (ikke skrøbelig fritekst-matchning).
                                $isBeneficialOwner = $currentRoles->contains(fn ($r) => ($r['role_type'] ?? null) === 'beneficial_owner');
                            @endphp
                            <tr class="border-b border-zinc-100 dark:border-zinc-800">
                                <td class="py-2 pr-4">
                                    <x-metis-link type="cvr" :query="$company['cvr']" :label="$company['name'] ?? $company['cvr']" />
                                </td>
                                <td class="py-2 pr-4 text-zinc-500">{{ $company['cvr'] }}</td>
                                <td class="py-2 pr-4">{{ $company['company_type'] ?? '-' }}</td>
                                <td class="py-2 pr-4">
                                    @if($isBeneficialOwner)
                                        <flux:badge size="sm" color="amber" class="mr-1">{{ __('Reel ejer') }}</flux:badge>
                                    @endif
                                    {{ $roleLabels ?: '-' }}
                                </td>
                                <td class="py-2">
                                    @if(($company['status'] ?? '') === 'NORMAL')
                                        <flux:badge size="sm" color="green">{{ __('Active') }}</flux:badge>
                                    @elseif($company['status'] ?? null)
                                        <flux:badge size="sm" color="zinc">{{ $company['status'] }}</flux:badge>
                                    @else
                                        -
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($showAllRoles && $hiddenCount === 0)
                {{-- viser allerede alt, ingen skjulte — intet at slå til/fra --}}
            @elseif($showAllRoles)
                <button wire:click="$set('showAllRoles', false)" class="mt-3 text-sm text-warm-600 hover:text-warm-700 underline">
                    {{ __('Vis kun aktuelle roller') }}
                </button>
            @elseif($hiddenCount > 0)
                <button wire:click="$set('showAllRoles', true)" class="mt-3 text-sm text-warm-600 hover:text-warm-700 underline">
                    {{ __('Vis også tidligere roller (:n)', ['n' => $hiddenCount]) }}
                </button>
            @endif
        @else
            @if($hasError)
                @include('metis::livewire.sections.partials.lookup-error', ['errorMessage' => $errorMessage])
            @else
            <p class="text-sm text-zinc-500">{{ __('No company roles found for this person.') }}</p>
            @endif
        @endif
    </flux:card>
</div>
