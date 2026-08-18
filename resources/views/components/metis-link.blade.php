{{-- 🪤 `$url` kommer fra MetisLink::url() og er NULL naar ruten ikke er
     registreret (embedded mode uden host-kald, eller URL-shadow fra host'ens
     egne ruter). Samme inaktive visning som ved tom query: et lydloest link
     til forsiden ville vaere vaerre end ingen link.

     🚨 rawurlencode() sker i url(): rutesegmentet er `->where('query', '.*')`,
     saa Laravel efterlader `?` og `#` RAA. Browseren laeser dem som query-/
     fragment-skilletegn og Lookup::mount() faar et AFKORTET navn — intet 404,
     bare et opslag paa en anden person. Verificeret paa prod at adresser
     (komma) og CVR-numre naar frem uaendret gennem encodingen. --}}
@if($query && $url())
<a href="{{ $url() }}"
   {{ $attributes->merge(['class' => 'text-blue-600 hover:underline dark:text-blue-400']) }}>
    {{ $label ?? (trim((string) $slot) !== '' ? $slot : $query) }}
</a>
@else
<span class="text-zinc-400">{{ $label ?? '-' }}</span>
@endif
