Ny analysebestilling i Metis (nr. {{ $request->id }})

Fra: {{ $request->name ?: '' }} {{ $request->company_name ? '('.$request->company_name.')' : '' }} <{{ $request->email }}>
@if($request->phone)
Telefon: {{ $request->phone }}
@endif
Formål: {{ $request->purposeLabel() }}
@if($request->area)
Område: {{ $request->area }}
@endif
Modtaget: {{ $request->created_at?->format('d.m.Y H:i') }}

Spørgsmål:
{{ $request->question }}

Svar med tilbud direkte til afsenderen; formålet vurderes før levering (tinglysningslovens § 50 c).
