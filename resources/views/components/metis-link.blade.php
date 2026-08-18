{{-- 🪤 `$href` er NULL naar ruten ikke er registreret (embedded mode uden
     host-kald, eller URL-shadow fra host'ens egne ruter) — se MetisLink::url().
     Bundet ÉN gang: `@if($url())` + `href="{{ $url() }}"` kaldte den to gange
     pr. render, altsaa 66 opslag paa en side med 33 kaldesteder, og gav to
     chancer for at svarene divergerede.

     🚨 rawurlencode() sker i url(): rutesegmentet er `->where('query', '.*')`,
     saa Laravel efterlader `?` og `#` RAA. Browseren laeser dem som query-/
     fragment-skilletegn og Lookup::mount() faar et AFKORTET navn — intet 404,
     bare et opslag paa en anden person. Ingen dobbelt-encoding: Laravels
     RouteUrlGenerator mapper `%25` tilbage til `%`, saa de to encodings
     ophaever hinanden praecist (verificeret paa 14 inputformer). --}}
@php($href = $url())
@if($query && $href)
<a href="{{ $href }}"
   {{ $attributes->merge(['class' => 'text-blue-600 hover:underline dark:text-blue-400']) }}>
    {{ $label ?? (trim((string) $slot) !== '' ? $slot : $query) }}
</a>
@else
{{-- 🚨 SAMME tekst-fallback og SAMME attributter som <a>-grenen.
     Foer stod her `$label ?? '-'` uden merge, saa null-grenen kastede baade
     slot, query og kaldestedets egne klasser vaek: 10 af 33 kaldesteder
     sender ingen :label (4 af dem bruger slot i stedet), og 3 sender
     `class="font-medium"`/`font-semibold`. Resultatet var at et gyldigt
     selskabsnavn blev til en bar bindestreg — informationen forsvandt, ikke
     bare linket.

     Det var harmloest FOER guarden, fordi grenen kun kunne naas ved TOM
     query, hvor `-` er aerligt. Guarden sender en helt ny population herned:
     i embedded mode alle 33 paa én gang. Den oenskede degradering er
     "link bliver til ren tekst" — ikke "data bliver til en streg". --}}
<span {{ $attributes->merge(['class' => 'text-zinc-400']) }}>
    {{ $label ?? (trim((string) $slot) !== '' ? $slot : ($query ?: '-')) }}
</span>
@endif
