{{-- Faelles fejl-tilstand for address-sektionerne.

     🚨 EN TOM-TILSTAND ER EN PAASTAND, IKKE EN VISNING. "Ingen ejerdata
     fundet" laeses som "ejendommen har ingen ejere" — ikke som "vi kunne
     ikke spoerge". Foer 18/8 returnerede resolveAddressAnalysis() `[]` for
     BAADE fejl og tom, saa ét 422 gav 12 falske benaegtelser paa én side.

     Partialen frem for 12 kopier: teksten skal vaere ENS paa tvaers af
     sektionerne, ellers laeser brugeren dem som forskellige problemer.

     @param string|null $errorMessage  'address_ambiguous' | 'lookup_failed'
     @param string      $hvad          hvad vi ikke kunne hente, smaa bogstaver --}}
@php($hvad = $hvad ?? __('data'))
<p class="text-sm text-zinc-500">
    @if(($errorMessage ?? null) === 'address_ambiguous')
        {{ __('Adressen kan ikke entydigt bestemmes — prøv med postnummer.') }}
    @else
        {{ __('Vi kunne ikke hente :hvad lige nu.', ['hvad' => $hvad]) }}
    @endif
</p>
<p class="text-xs text-zinc-400 mt-2">
    {{ __('Det er ikke en oplysning om ejendommen — opslaget mislykkedes.') }}
</p>
