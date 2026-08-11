{{ $lead->company_name ?: $lead->email }} har brugt sine opslag og beder om flere.

Email: {{ $lead->email }}
Virksomhed: {{ $lead->company_name ?? 'Ikke angivet' }} (CVR {{ $lead->cvr ?? 'ikke angivet' }})
Forbrug: {{ $lead->lookup_count }} af {{ $lead->lookup_quota }} opslag
Første søgning: {{ $lead->first_search_term ?? 'Ikke registreret' }}
Bruger siden: {{ $lead->created_at?->format('d.m.Y') ?? '-' }}
Sidst aktiv: {{ $lead->last_active_at?->diffForHumans() ?? '-' }}

Åbn for 25 opslag:

{{ $godkendUrl }}

{{ $lead->email }} får selv besked når du har åbnet. Linket gælder 7 dage.

Skal det være et andet tal, kan du køre på metis.frankston.io:

  php artisan metis:grant-quota {{ $lead->email }} <antal>
