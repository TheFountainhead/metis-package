{{-- Faelles fejl-tilstand for address-sektionerne.

     🚨 EN TOM-TILSTAND ER EN PAASTAND, IKKE EN VISNING. "Ingen ejerdata
     fundet" laeses som "ejendommen har ingen ejere" — ikke som "vi kunne
     ikke spoerge". Foer 18/8 returnerede resolveAddressAnalysis() `[]` for
     BAADE fejl og tom, saa ét 422 gav 12 falske benaegtelser paa én side.

     🔑 ALLE 12 sektioner bruger den, ikke tre. Review paapegede at 3 aerlige
     sektioner ved siden af 9 uaerlige goer de 9 MERE trovaerdige: siden
     demonstrerer da at systemet KAN skelne, saa "No building data found."
     ved siden af en eksplicit fejlbesked laeses som en positiv konstatering
     ("opslaget lykkedes, der er bare ingen bygning"). Konsistent forkert er
     mindre farligt end selektivt rigtigt.

     🪤 INGEN `:hvad`-parameter. Et oversat fragment indsat i en oversat
     skabelon er et i18n-antimoenster: oversaetteren ser kun `:hvad` og kan
     hverken boeje eller saette artikel. Sektioner der skal vaere specifikke
     bruger i stedet $forsikring nedenfor — én hel saetning, oversaetbar.

     @param string|null $errorMessage  'address_ambiguous' | 'lookup_failed'
     @param string|null $forsikring    sektionsspecifik "det betyder IKKE at ..."-linje.
                                       Pantebreve bruger den, fordi gaeldfrihed er den
                                       farlige slutning netop dér. --}}
<p class="text-sm text-zinc-500">
    @if(($errorMessage ?? null) === 'address_ambiguous')
        {{ __('Adressen kan ikke entydigt bestemmes — prøv med postnummer.') }}
    @else
        {{ __('Vi kunne ikke få svar fra kilden.') }}
    @endif
</p>
<p class="text-xs text-zinc-400 mt-2">
    {{ ($forsikring ?? null) ?: __('Det er ikke en oplysning om ejendommen') }} — {{ __('opslaget lykkedes ikke.') }}
</p>
