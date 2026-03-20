Email: {{ $lead->email }}
Virksomhed: {{ $lead->company_name ?? 'Ikke angivet' }} (CVR {{ $lead->cvr ?? 'Ikke angivet' }})
Branche: {{ $lead->industry ?? 'Ikke angivet' }}
Første søgning: "{{ $searchTerm }}" ({{ $searchType }})
Tidspunkt: {{ now()->format('d. M Y H:i') }}
