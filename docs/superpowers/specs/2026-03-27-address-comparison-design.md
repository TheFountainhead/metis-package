# Ejendomssammenligning i Metis

**Dato:** 27. marts 2026
**Status:** Reviewed

## Problem

Metis-brugere har brug for markedsanalyse når de slår en ejendom op. Boliger skal sammenlignes med solgte/udbudte referencer, mens erhvervs-/udlejningsejendomme har brug for lejeindtægt og profitability. Sektionen skal vise hvad der er relevant baseret på data — ikke ejendomstype.

## Løsning

Ny lazy-loaded sektion (`AddressComparison`) der viser data-drevet indhold: sammenligning hvis data findes, lejeestimat/profitability hvis det findes, begge hvis begge findes. Kompakt som default, udvidet visning som separat lazy-loaded child-komponent.

## Designprincipper (fra review)

- **Data-drevet, ikke type-drevet** — vis hvad der er data for (`@if($comparison)`, `@if($rentalEstimate)`)
- **Tynd `mount()`** — flyt conditional fetching til RegistryApi service
- **Child-komponent for udvidet visning** — holder kompakt view hurtig
- **Cache comparison results** — i RegistryApi, ikke i komponenten
- **Ingen `$filters`** — YAGNI, tilføj når det behøves

## Dataflow

```
AddressComparison.mount($query)
├── resolveAddressAnalysis($query) → rental_estimate, profitability (cached)
├── resolvePropertyComparison($query) → comparison data (cached, handles address parsing internally)
└── Render baseret på hvad der har data

AddressComparisonDetail (child, lazy-loaded on "Vis alle")
├── Modtager comparison data via prop
└── Renderer kort + cards + PDF-knap
```

## RegistryApi metoder

```php
// Ny metode — cacher resultat, håndterer address parsing internt
public function resolvePropertyComparison(string $query): ?array
{
    $parsed = $this->parseAddress($query);

    if (! $parsed || empty($parsed['zip'])) {
        return null;
    }

    $address = trim(($parsed['street'] ?? '') . ' ' . ($parsed['number'] ?? ''));
    $postalCode = $parsed['zip'];

    $cacheKey = "comparison_{$address}_{$postalCode}";

    return Cache::remember($cacheKey, 3600, fn () =>
        rescue(fn () => $this->client()
            ->post('property/compare', [
                'address' => $address,
                'postal_code' => $postalCode,
            ])->json('data'), null)
    );
}
```

## AddressComparison komponent

```php
class AddressComparison extends MetisSection
{
    public ?array $comparison = null;
    public ?array $rentalEstimate = null;
    public ?array $profitability = null;
    public bool $showDetail = false;

    protected function sectionTitle(): string
    {
        return __('Markedsanalyse');
    }

    public function mount(string $query): void
    {
        $this->query = $query;
        $api = app(RegistryApi::class);

        $analysis = $api->resolveAddressAnalysis($query);
        $this->rentalEstimate = data_get($analysis, 'property.rental_estimate');
        $this->profitability = data_get($analysis, 'property.profitability');

        $this->comparison = $api->resolvePropertyComparison($query);
    }

    public function render()
    {
        return view('metis::livewire.sections.address-comparison');
    }
}
```

Bemærk:
- Altid fetch comparison — `rescue()` + `null` håndterer ejendomme uden data
- Ingen `$propertyType` eller `detectPropertyType()` — blade checker data-presence
- `mount()` er tynd — RegistryApi håndterer parsing og caching

## AddressComparisonDetail child-komponent

```php
class AddressComparisonDetail extends MetisSection
{
    public array $comparison = [];

    protected function sectionTitle(): string
    {
        return __('Detaljeret sammenligning');
    }

    public function mount(array $comparison): void
    {
        $this->comparison = $comparison;
    }

    public function render()
    {
        return view('metis::livewire.sections.address-comparison-detail');
    }
}
```

## Blade views

### address-comparison.blade.php (kompakt)

```blade
<div>
    <flux:card>
        <flux:heading size="lg" class="mb-4">{{ __('Markedsanalyse') }}</flux:heading>

        @if ($comparison)
            {{-- Prispyramide --}}
            @if ($pyramid = data_get($comparison, 'price_pyramid'))
                <div class="grid grid-cols-3 gap-3 mb-4">
                    <div class="bg-zinc-50 rounded-lg p-3 text-center">
                        <div class="font-bold">{{ number_format($pyramid['quick_sale'], 0, ',', '.') }}</div>
                        <div class="text-xs text-zinc-400">{{ __('Hurtigt salg') }}</div>
                    </div>
                    <div class="bg-blue-50 border-blue-200 rounded-lg p-3 text-center">
                        <div class="font-bold text-blue-700">{{ number_format($pyramid['market_price'], 0, ',', '.') }}</div>
                        <div class="text-xs text-blue-500">{{ __('Markedspris') }}</div>
                    </div>
                    <div class="bg-zinc-50 rounded-lg p-3 text-center">
                        <div class="font-bold">{{ number_format($pyramid['dream_price'], 0, ',', '.') }}</div>
                        <div class="text-xs text-zinc-400">{{ __('Drømmepris') }}</div>
                    </div>
                </div>
            @endif

            {{-- Stats + kompakt tabel med top 3 --}}
            ...

            {{-- Vis alle knap --}}
            @if (! $showDetail)
                <flux:button variant="ghost" wire:click="$toggle('showDetail')" class="mt-4">
                    {{ __('Vis alle referencer') }}
                </flux:button>
            @endif

            {{-- Udvidet visning som child-komponent --}}
            @if ($showDetail)
                <livewire:metis-address-comparison-detail :comparison="$comparison" lazy />
            @endif
        @endif

        @if ($rentalEstimate || $profitability)
            {{-- Lejeestimat --}}
            @if ($rentalEstimate)
                <div class="mt-4 grid grid-cols-2 gap-3">
                    ...gns kr/m²/år, estimeret årlig leje, sample count...
                </div>
            @endif

            {{-- Profitability --}}
            @if ($profitability)
                <div class="mt-4 grid grid-cols-3 gap-3">
                    ...bruttoafkast %, LTV %, DSCR...
                </div>
            @endif
        @endif

        @if (! $comparison && ! $rentalEstimate && ! $profitability)
            <p class="text-sm text-zinc-500">{{ __('Ingen markedsdata tilgængelig') }}</p>
        @endif
    </flux:card>
</div>
```

### address-comparison-detail.blade.php (udvidet)

```blade
<div>
    {{-- Leaflet kort med markører --}}
    <flux:card class="mb-4 p-0!">
        <div wire:ignore>
            ...leaflet map med rød/blå/grøn markører...
        </div>
    </flux:card>

    {{-- Solgte reference-cards med billeder --}}
    ...3-kolonne grid...

    {{-- Udbudte reference-cards --}}
    ...3-kolonne grid...

    {{-- Download PDF --}}
    <flux:button variant="primary" :href="..." target="_blank">
        {{ __('Download PDF') }}
    </flux:button>
</div>
```

## Nye filer

| Fil | Hvad |
|---|---|
| `src/Livewire/Sections/AddressComparison.php` | Kompakt sektion (~25 linjer) |
| `src/Livewire/Sections/AddressComparisonDetail.php` | Udvidet child med kort + cards (~15 linjer) |
| `resources/views/livewire/sections/address-comparison.blade.php` | Kompakt view |
| `resources/views/livewire/sections/address-comparison-detail.blade.php` | Udvidet view med kort + billeder |

## Edits

| Fil | Ændring |
|---|---|
| `src/Services/RegistryApi.php` | Tilføj `resolvePropertyComparison()` (~15 linjer) |
| `src/MetisServiceProvider.php` | Registrér 2 komponenter |
| `resources/views/livewire/lookup.blade.php` | Tilføj 1 linje |

## Acceptance Criteria

1. **Sektion:** "Markedsanalyse" vises efter transactions i address-lookup
2. **Data-drevet:** Viser sammenligning hvis data findes, lejeestimat/profitability hvis det findes, begge hvis begge
3. **Kompakt sammenligning:** Prispyramide, stats-bar, top 3 solgte + udbudte tabel
4. **Lejeestimat:** Gns. kr/m²/år, estimeret årlig leje, sample count
5. **Profitability:** Bruttoafkast %, LTV %, DSCR
6. **Udvidet visning:** "Vis alle" → lazy-loaded child med Leaflet-kort + reference-cards + PDF
7. **Ingen data:** Viser "Ingen markedsdata tilgængelig"
8. **Lazy-loaded:** Loading-skeleton som andre sektioner
9. **Caching:** Comparison data cached i RegistryApi (1 time)
10. **Placering:** Efter transactions, før companies
