{{ $lead->name ?: $lead->email }} har brugt sine opslag og beder om flere.

Email: {{ $lead->email }}
Virksomhed: {{ $lead->company_name ?? 'Ikke angivet' }} (CVR {{ $lead->cvr ?? 'Ikke angivet' }})
Branche: {{ $lead->industry ?? 'Ikke angivet' }}

Forbrug: {{ $lead->lookup_count }} af {{ $lead->lookup_quota }} opslag
Første søgning: "{{ $lead->first_search_term ?? 'Ikke registreret' }}" ({{ $lead->first_search_type ?? '-' }})
Bruger siden: {{ $lead->created_at?->format('d. M Y') ?? '-' }}
Sidst aktiv: {{ $lead->last_active_at?->diffForHumans() ?? '-' }}

Åbn for 25 opslag (ét klik, virker fra telefonen):

  {{ $godkendUrl }}

Brugeren får selv besked på mail når du har åbnet. Linket gælder 7 dage.

Skal det være et andet tal, kan du køre på metis.frankston.io:

  php artisan metis:grant-quota {{ $lead->email }} <antal>
