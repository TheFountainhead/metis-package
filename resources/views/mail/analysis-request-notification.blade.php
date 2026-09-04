<!doctype html>
<html lang="da">
<body style="font-family: Helvetica, Arial, sans-serif; font-size: 14px; color: #1a1a1a;">
<p>Ny analysebestilling i Metis.</p>
<table cellpadding="4" style="border-collapse: collapse; font-size: 14px;">
    <tr><td style="color:#666;">Fra</td><td>{{ $request->name ?: '' }} {{ $request->company_name ? '('.$request->company_name.')' : '' }} &lt;{{ $request->email }}&gt;</td></tr>
    @if($request->phone)<tr><td style="color:#666;">Telefon</td><td>{{ $request->phone }}</td></tr>@endif
    <tr><td style="color:#666;">Formål</td><td>{{ $request->purposeLabel() }}</td></tr>
    @if($request->area)<tr><td style="color:#666;">Område</td><td>{{ $request->area }}</td></tr>@endif
    <tr><td style="color:#666;">Modtaget</td><td>{{ $request->created_at?->format('d.m.Y H:i') }}</td></tr>
</table>
<p style="white-space: pre-wrap; border-left: 3px solid #ddd; padding-left: 12px;">{{ $request->question }}</p>
<p style="color:#666;">Bestilling nr. {{ $request->id }}. Svar med tilbud direkte til afsenderen; formålet vurderes før levering (tinglysningslovens § 50 c).</p>
</body>
</html>
