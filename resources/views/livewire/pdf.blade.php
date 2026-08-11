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
            $company = $data['company']['companies'][0] ?? null;
            $roles = $company['roles'] ?? [];
            $structure = $data['structure'] ?? [];
            $portfolio = $data['portfolio']['portfolio'] ?? null;
            $tax = $data['tax']['records'] ?? [];
        @endphp

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

    @elseif($type === 'cpr')
        @php
            $properties = $data['properties']['properties'] ?? [];
            $companies = $data['companies']['companies'] ?? [];
        @endphp

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

    @elseif($type === 'address')
        @php
            $prop = $data['analysis']['property'] ?? [];
            $bbr = $prop['bbr'] ?? null;
            $valuation = $prop['valuation'] ?? null;
            $owners = $prop['owners'] ?? [];
            $mortgages = $prop['mortgages'] ?? [];
            $transactions = $prop['transactions'] ?? [];
            $companies = $prop['companies_at_address'] ?? [];
        @endphp

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

    <p style="margin-top: 32px; color: #999; font-size: 10px; border-top: 1px solid #e5e5e5; padding-top: 8px;">
        {{ __('Generated by Metis') }} &middot; {{ config('app.name') }} &middot; {{ now()->format('d-m-Y H:i') }}
    </p>
</body>
</html>
