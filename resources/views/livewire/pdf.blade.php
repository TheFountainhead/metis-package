<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: sans-serif; font-size: 12px; color: #1a1a1a; margin: 40px; }
        h1 { font-size: 24px; margin-bottom: 4px; }
        h2 { font-size: 16px; margin-top: 24px; margin-bottom: 8px; border-bottom: 1px solid #e5e5e5; padding-bottom: 4px; }
        .meta { color: #888; font-size: 11px; margin-bottom: 24px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        th { text-align: left; padding: 6px 8px; border-bottom: 2px solid #e5e5e5; color: #666; font-size: 11px; }
        td { padding: 6px 8px; border-bottom: 1px solid #f0f0f0; }
        .text-right { text-align: right; }
        .label { color: #666; }
        dl { display: grid; grid-template-columns: 140px 1fr; gap: 4px 16px; }
        dt { color: #666; }
        dd { margin: 0; }
        .empty { color: #999; font-style: italic; }
    </style>
</head>
<body>
    <h1>Metis · {{ $type === 'address' ? __('Address') : strtoupper($type) }}</h1>
    <p class="meta">{{ $query }} &middot; {{ now()->format('d-m-Y H:i') }}</p>

    @if($type === 'cvr')
        @php
            $opslagsfejl = $data['company'] === null || isset($data['company']['error']);

            $company = $data['company']['companies'][0] ?? null;
            $roles = $company['roles'] ?? [];
            $structure = $data['structure'] ?? [];
            $portfolio = $data['portfolio']['portfolio'] ?? null;
            $tax = $data['tax']['records'] ?? [];
        @endphp

        @if($opslagsfejl)
            {{-- 🚨 SAMME FALSKE BENAEGTELSE SOM ADRESSE-GRENEN — men VAERRE.
                 Adresse-sektionerne er `@if` uden `@else`, saa en fejl gav et
                 TAVST dokument. Her har hver sektion et `@else`, saa en total
                 upstream-fejl printer en BEKRAEFTENDE benaegtelse: "No roles
                 found.", "No companies found." — en positiv paastand om et
                 selskab eller en person, produceret naar vi intet ved.

                 Maalt 20/8: fejl, aegte tom og `rescue()`-null gav
                 BYTE-IDENTISK dokument.

                 🪤 `rescue()` uden fallback giver `null`, saa fejl har TO
                 former: `null` og `['error' => …]`. Begge er fejl. --}}
            <h2>{{ __('Opslaget kunne ikke udføres') }}</h2>
            <p class="empty">{{ __('Vi kunne ikke få svar fra kilden.') }}</p>
            <p class="label">
                {{ __('Fraværet af data er IKKE en oplysning — opslaget blev ikke udført.') }}
            </p>
        @else

        <h2>{{ __('Company Information') }}</h2>
        @if($company)
            <dl>
                <dt>{{ __('Name') }}</dt><dd>{{ $company['name'] ?? '-' }}</dd>
                <dt>{{ __('CVR') }}</dt><dd>{{ $query }}</dd>
                <dt>{{ __('Type') }}</dt><dd>{{ $company['company_type'] ?? '-' }}</dd>
                <dt>{{ __('Status') }}</dt><dd>{{ $company['status'] ?? '-' }}</dd>
            </dl>
        @else
            <p class="empty">{{ __('No data found.') }}</p>
        @endif

        <h2>{{ __('Management & Roles') }}</h2>
        @if(count($roles) > 0)
            <table>
                <thead><tr><th>{{ __('Name') }}</th><th>{{ __('Role') }}</th><th>{{ __('Ownership') }}</th><th>{{ __('Since') }}</th></tr></thead>
                <tbody>
                    @foreach($roles as $role)
                        @if($role['is_current'] ?? false)
                            <tr>
                                <td>{{ $role['person_name'] ?? '-' }}</td>
                                <td>{{ $role['role_label'] ?? $role['title'] ?? '-' }}</td>
                                <td>{{ $role['ownership_share'] ?? '-' }}</td>
                                <td>{{ $role['start_date'] ?? '-' }}</td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
        @else
            <p class="empty">{{ __('No roles found.') }}</p>
        @endif

        @if($portfolio && count($portfolio['properties'] ?? []) > 0)
            <h2>{{ __('Property Portfolio') }}</h2>
            <table>
                <thead><tr><th>{{ __('Address') }}</th><th class="text-right">{{ __('Valuation') }}</th><th class="text-right">{{ __('Area') }}</th></tr></thead>
                <tbody>
                    @foreach($portfolio['properties'] as $prop)
                        <tr>
                            <td>{{ ($prop['address'] ?? '') . ', ' . ($prop['postal_code'] ?? '') . ' ' . ($prop['city'] ?? '') }}</td>
                            <td class="text-right">{{ isset($prop['valuation']) ? number_format($prop['valuation'], 0, ',', '.') . ' kr.' : '-' }}</td>
                            <td class="text-right">{{ isset($prop['total_area']) ? $prop['total_area'] . ' m²' : '-' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        @if(count($tax) > 0)
            <h2>{{ __('Tax Records') }}</h2>
            <table>
                <thead><tr><th>{{ __('Year') }}</th><th class="text-right">{{ __('Taxable Income') }}</th><th class="text-right">{{ __('Corporate Tax') }}</th></tr></thead>
                <tbody>
                    @foreach($tax as $record)
                        <tr>
                            <td>{{ $record['income_year'] ?? '-' }}</td>
                            <td class="text-right">{{ isset($record['taxable_income']) ? number_format($record['taxable_income'], 0, ',', '.') . ' kr.' : '-' }}</td>
                            <td class="text-right">{{ isset($record['corporate_tax']) ? number_format($record['corporate_tax'], 0, ',', '.') . ' kr.' : '-' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
        @endif

    @elseif($type === 'cpr')
        @php
            $opslagsfejl = ($data['properties'] ?? null) === null
                || isset($data['properties']['error'])
                || ($data['companies'] ?? null) === null
                || isset($data['companies']['error']);

            $properties = $data['properties']['properties'] ?? [];
            $companies = $data['companies']['companies'] ?? [];
        @endphp

        @if($opslagsfejl)
            {{-- 🚨 SAMME FALSKE BENAEGTELSE SOM ADRESSE-GRENEN — men VAERRE.
                 Adresse-sektionerne er `@if` uden `@else`, saa en fejl gav et
                 TAVST dokument. Her har hver sektion et `@else`, saa en total
                 upstream-fejl printer en BEKRAEFTENDE benaegtelse: "No roles
                 found.", "No companies found." — en positiv paastand om et
                 selskab eller en person, produceret naar vi intet ved.

                 Maalt 20/8: fejl, aegte tom og `rescue()`-null gav
                 BYTE-IDENTISK dokument.

                 🪤 `rescue()` uden fallback giver `null`, saa fejl har TO
                 former: `null` og `['error' => …]`. Begge er fejl. --}}
            <h2>{{ __('Opslaget kunne ikke udføres') }}</h2>
            <p class="empty">{{ __('Vi kunne ikke få svar fra kilden.') }}</p>
            <p class="label">
                {{ __('Fraværet af data er IKKE en oplysning — opslaget blev ikke udført.') }}
            </p>
        @else

        <h2>{{ __('Owned Properties') }}</h2>
        @if(count($properties) > 0)
            <table>
                <thead><tr><th>{{ __('Address') }}</th><th class="text-right">{{ __('Valuation') }}</th><th class="text-right">{{ __('Area') }}</th></tr></thead>
                <tbody>
                    @foreach($properties as $prop)
                        <tr>
                            <td>{{ ($prop['street'] ?? '') . ' ' . ($prop['number'] ?? '') . ', ' . ($prop['zip'] ?? '') . ' ' . ($prop['city'] ?? '') }}</td>
                            <td class="text-right">{{ isset($prop['public_valuation']) ? number_format($prop['public_valuation'], 0, ',', '.') . ' kr.' : '-' }}</td>
                            <td class="text-right">{{ isset($prop['area_building']) ? $prop['area_building'] . ' m²' : '-' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p class="empty">{{ __('No properties found.') }}</p>
        @endif

        <h2>{{ __('Companies & Ownership') }}</h2>
        @if(count($companies) > 0)
            <table>
                <thead><tr><th>{{ __('Company') }}</th><th>{{ __('CVR') }}</th><th>{{ __('Role') }}</th><th class="text-right">{{ __('Ownership') }}</th><th class="text-right">{{ __('Equity') }}</th><th class="text-right">{{ __('Result') }}</th></tr></thead>
                <tbody>
                    @foreach($companies as $c)
                        @php
                            $cRoles = collect($c['roles'] ?? [])->where('is_current', true);
                            $cOwnership = $cRoles->first(fn ($r) => !empty($r['ownership_share']));
                            $cFinRaw = $c['financials'] ?? null;
                            $cFinancials = is_array($cFinRaw) && array_is_list($cFinRaw) ? ($cFinRaw[0] ?? null) : $cFinRaw;
                        @endphp
                        <tr>
                            <td>{{ $c['name'] ?? '-' }}</td>
                            <td>{{ $c['cvr'] ?? '-' }}</td>
                            <td>{{ $cRoles->first()['title'] ?? $cRoles->first()['role'] ?? '-' }}</td>
                            <td class="text-right">{{ $cOwnership ? number_format($cOwnership['ownership_share'], 0) . '%' : '-' }}</td>
                            <td class="text-right">{{ $cFinancials && isset($cFinancials['equity']) ? number_format($cFinancials['equity'] / 100, 0, ',', '.') . ' kr.' : '-' }}</td>
                            <td class="text-right">{{ $cFinancials && isset($cFinancials['profit_loss']) ? number_format($cFinancials['profit_loss'] / 100, 0, ',', '.') . ' kr.' : '-' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p class="empty">{{ __('No companies found.') }}</p>
        @endif
        @endif

    @elseif($type === 'address')
        @php
            $opslagsfejl = $data['analysis']['error'] ?? null;
            $prop = $data['analysis']['property'] ?? [];
            $bbr = $prop['bbr'] ?? null;
            $valuation = $prop['valuation'] ?? null;
            $owners = $prop['owners'] ?? [];
            $mortgages = $prop['mortgages'] ?? [];
            $transactions = $prop['transactions'] ?? [];
            $companies = $prop['companies_at_address'] ?? [];
        @endphp

        @if($opslagsfejl)
            {{-- 🚨 EN PDF FORLADER HUSET. Uden denne gren faldt ALLE sektioner
                 tavst igennem (de er `@if` uden `@else`), og dokumentet blev
                 byte-identisk med et vellykket opslag paa en ejendom uden
                 data: header, adresse, tidsstempel, sidefod. Et tomt dokument
                 der ser faerdigt ud.

                 Det laeses som "ingen ejere, ingen pantebreve" — altsaa
                 GAELDFRIHED — i en sagsmappe hvor ingen kan se at opslaget
                 fejlede. Samme falske benaegtelse som de 12 sektioner fik
                 lukket 18/8, paa den ene flade hvor den overlever og
                 videresendes.

                 🔑 Samme to beskeder som `partials/lookup-error.blade.php`,
                 saa skaerm og print ikke driver fra hinanden. --}}
            <h2>{{ __('Opslaget kunne ikke udføres') }}</h2>
            <p class="empty">
                @if($opslagsfejl === 'address_ambiguous')
                    {{ __('Adressen kan ikke entydigt bestemmes — prøv med postnummer.') }}
                @else
                    {{ __('Vi kunne ikke få svar fra kilden.') }}
                @endif
            </p>
            <p class="label">
                {{ __('Dokumentet indeholder derfor ingen oplysninger om ejendommen. Fraværet af data er IKKE en oplysning om ejendommen.') }}
            </p>
        @elseif(empty($prop))
            {{-- 🪤 OG DEN AEGTE TOMME TILSTAND. Fanget ved at SE dokumentet:
                 efter fejlgrenen kunne 422 skelnes fra "ingen data", men den
                 TOMME gav stadig kun header + sidefod. Opslaget LYKKEDES, og
                 det skal staa — ellers kan modtageren ikke skelne "vi spurgte,
                 der er intet" fra "noget gik galt".

                 Min egen test beviste kun at de to udfald var FORSKELLIGE.
                 En ulighed er et svagere krav end to sande udsagn. --}}
            <h2>{{ __('Opslaget blev udført') }}</h2>
            <p class="empty">{{ __('Vi fandt ingen registrerede oplysninger på adressen.') }}</p>
        @else

        @if($prop['street_view_url'] ?? null)
            <div style="margin-bottom: 16px;">
                <img src="{{ $prop['street_view_url'] }}" alt="{{ __('Street View') }}" style="width: 100%; max-height: 250px; object-fit: cover; border-radius: 4px;" />
            </div>
        @endif

        @if($bbr)
            <h2>{{ __('Building Data (BBR)') }}</h2>
            <dl>
                @if(isset($bbr['total_area']))<dt>{{ __('Total area') }}</dt><dd>{{ $bbr['total_area'] }} m²</dd>@endif
                @if(isset($bbr['building_year']))<dt>{{ __('Year built') }}</dt><dd>{{ $bbr['building_year'] }}</dd>@endif
                @if(isset($bbr['usage_text']))<dt>{{ __('Usage') }}</dt><dd>{{ $bbr['usage_text'] }}</dd>@endif
                @if(isset($bbr['wall_material']))<dt>{{ __('Wall material') }}</dt><dd>{{ $bbr['wall_material'] }}</dd>@endif
                @if(isset($bbr['roof_material']))<dt>{{ __('Roof material') }}</dt><dd>{{ $bbr['roof_material'] }}</dd>@endif
            </dl>
        @endif

        @if($valuation)
            <h2>{{ __('Property Valuation') }}</h2>
            <dl>
                <dt>{{ __('Property value') }}</dt><dd>{{ number_format($valuation['estimated_value'] ?? 0, 0, ',', '.') }} kr.</dd>
                @if(isset($valuation['land_value']))<dt>{{ __('Land value') }}</dt><dd>{{ number_format($valuation['land_value'], 0, ',', '.') }} kr.</dd>@endif
                @if(isset($valuation['date']))<dt>{{ __('Date') }}</dt><dd>{{ $valuation['date'] }}</dd>@endif
            </dl>
        @endif

        @if(count($owners) > 0)
            <h2>{{ __('Owners') }}</h2>
            @foreach($owners as $owner)
                @if($owner['is_current'] ?? false)
                    <p>{{ $owner['name'] ?? '-' }} {{ $owner['share'] ? '(' . $owner['share'] . ')' : '' }}</p>
                @endif
            @endforeach
        @endif

        @if(count($mortgages) > 0)
            <h2>{{ __('Mortgages') }}</h2>
            @if(isset($prop['total_debt']))
                <p class="label">{{ __('Total debt') }}: {{ number_format($prop['total_debt'], 0, ',', '.') }} kr.</p>
            @endif
            <table>
                <thead><tr><th>{{ __('Creditor') }}</th><th class="text-right">{{ __('Principal') }}</th><th class="text-right">{{ __('Rate') }}</th><th>{{ __('Maturity') }}</th></tr></thead>
                <tbody>
                    @foreach($mortgages as $m)
                        <tr>
                            <td>{{ $m['creditor'] ?? '-' }}</td>
                            <td class="text-right">{{ isset($m['principal']) ? number_format($m['principal'], 0, ',', '.') . ' kr.' : '-' }}</td>
                            <td class="text-right">{{ isset($m['interest_rate']) ? number_format($m['interest_rate'], 2, ',', '.') . '%' : '-' }}</td>
                            {{-- Samme begrundelse som i pantesektionen: Tinglysningen HAR
                                 ikke feltet, saa en bindestreg ville laeses som "staaende
                                 laan". Vigtigere her end paa skaermen — en PDF forlader
                                 huset og kan laeses uden vores oevrige kontekst. --}}
                            <td>{{ $m['maturity_date'] ?? __('ikke oplyst') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p class="label">{{ __('Tinglysningen oplyser ikke udløbsdato på pantebreve.') }}</p>
        @endif

        @if(count($transactions) > 0)
            <h2>{{ __('Transaction History') }}</h2>
            <table>
                <thead><tr><th>{{ __('Date') }}</th><th class="text-right">{{ __('Price') }}</th><th>{{ __('Buyer') }}</th><th>{{ __('Seller') }}</th></tr></thead>
                <tbody>
                    @foreach($transactions as $tx)
                        <tr>
                            <td>{{ $tx['date'] ?? '-' }}</td>
                            <td class="text-right">{{ isset($tx['price']) ? number_format($tx['price'], 0, ',', '.') . ' kr.' : '-' }}</td>
                            <td>{{ $tx['buyer'] ?? '-' }}</td>
                            <td>{{ $tx['seller'] ?? '-' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        @if(count($companies) > 0)
            <h2>{{ __('Companies at Address') }}</h2>
            <table>
                <thead><tr><th>{{ __('Company') }}</th><th>{{ __('CVR') }}</th><th>{{ __('Industry') }}</th></tr></thead>
                <tbody>
                    @foreach($companies as $c)
                        <tr>
                            <td>{{ $c['name'] ?? '-' }}</td>
                            <td>{{ $c['cvr'] ?? '-' }}</td>
                            <td>{{ $c['industry'] ?? '-' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
        @endif
    @endif

    <p style="margin-top: 32px; color: #999; font-size: 10px; border-top: 1px solid #e5e5e5; padding-top: 8px;">
        {{ __('Generated by Metis') }} &middot; {{ config('app.name') }} &middot; {{ now()->format('d-m-Y H:i') }}
    </p>
</body>
</html>
