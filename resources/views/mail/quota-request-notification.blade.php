{{ $lead->name ?: $lead->email }} har brugt sine opslag og beder om flere.

Email: {{ $lead->email }}
Virksomhed: {{ $lead->company_name ?? 'Ikke angivet' }} (CVR {{ $lead->cvr ?? 'Ikke angivet' }})
Branche: {{ $lead->industry ?? 'Ikke angivet' }}

Forbrug: {{ $lead->lookup_count }} af {{ $lead->lookup_quota }} opslag
Første søgning: "{{ $lead->first_search_term ?? 'Ikke registreret' }}" ({{ $lead->first_search_type ?? '-' }})
Bruger siden: {{ $lead->created_at?->format('d. M Y') ?? '-' }}
Sidst aktiv: {{ $lead->last_active_at?->diffForHumans() ?? '-' }}

Åbn for flere opslag (sætter kvoten til 25):

  php artisan metis:grant-quota {{ $lead->email }} 25

Kør på metis.frankston.io. Tallet kan ændres frit.
