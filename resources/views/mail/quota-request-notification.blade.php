{{-- 🪤 Bordlayout og inline styles — IKKE flexbox/grid/klasser. Gmail og
     Outlook stripper <style>-blokke, saa alt der skal overleve skal staa
     direkte paa elementet. Samme grund til at knappen er en <a> med padding
     frem for et <button>: knapper renderes ikke paalideligt i mailklienter. --}}
<div style="font-family: Helvetica, Arial, sans-serif; font-size: 14px; color: #18181b; line-height: 1.5;">

    <p style="margin: 0 0 16px;">
        <strong>{{ $lead->company_name ?: $lead->email }}</strong> har brugt sine opslag og beder om flere.
    </p>

    <table cellpadding="0" cellspacing="0" border="0" style="margin: 0 0 20px; font-size: 14px;">
        <tr>
            <td style="padding: 2px 16px 2px 0; color: #71717a;">Email</td>
            <td style="padding: 2px 0;">{{ $lead->email }}</td>
        </tr>
        <tr>
            <td style="padding: 2px 16px 2px 0; color: #71717a;">Virksomhed</td>
            <td style="padding: 2px 0;">{{ $lead->company_name ?? 'Ikke angivet' }} (CVR {{ $lead->cvr ?? 'ikke angivet' }})</td>
        </tr>
        <tr>
            <td style="padding: 2px 16px 2px 0; color: #71717a;">Forbrug</td>
            <td style="padding: 2px 0;">{{ $lead->lookup_count }} af {{ $lead->lookup_quota }} opslag</td>
        </tr>
        <tr>
            <td style="padding: 2px 16px 2px 0; color: #71717a;">Første søgning</td>
            <td style="padding: 2px 0;">{{ $lead->first_search_term ?? 'Ikke registreret' }}</td>
        </tr>
        <tr>
            <td style="padding: 2px 16px 2px 0; color: #71717a;">Bruger siden</td>
            <td style="padding: 2px 0;">{{ $lead->created_at?->format('d.m.Y') ?? '-' }}</td>
        </tr>
        <tr>
            <td style="padding: 2px 16px 2px 0; color: #71717a;">Sidst aktiv</td>
            <td style="padding: 2px 0;">{{ $lead->last_active_at?->diffForHumans() ?? '-' }}</td>
        </tr>
    </table>

    {{-- Knappen. Bordet omkring den giver Outlook noget at justere efter. --}}
    <table cellpadding="0" cellspacing="0" border="0" style="margin: 0 0 16px;">
        <tr>
            <td style="background-color: #18181b; border-radius: 6px;">
                <a href="{{ $godkendUrl }}"
                   style="display: inline-block; padding: 11px 22px; color: #ffffff; text-decoration: none; font-size: 14px; font-family: Helvetica, Arial, sans-serif;">
                    Åbn for 25 opslag
                </a>
            </td>
        </tr>
    </table>

    <p style="margin: 0 0 16px; font-size: 13px; color: #71717a;">
        {{ $lead->email }} får selv besked når du har åbnet. Linket gælder 7 dage.
    </p>

    <p style="margin: 0; font-size: 13px; color: #71717a;">
        Skal det være et andet tal, kan du køre på metis.frankston.io:<br>
        <code style="font-size: 12px;">php artisan metis:grant-quota {{ $lead->email }} &lt;antal&gt;</code>
    </p>

</div>
