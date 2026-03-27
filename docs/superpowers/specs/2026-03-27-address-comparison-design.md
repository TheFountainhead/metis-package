# Ejendomssammenligning i Metis

**Dato:** 27. marts 2026
**Status:** Draft

## Problem

Metis-brugere har brug for markedssammenligning når de slår en ejendom op — men typen af analyse afhænger af ejendomstypen. Boliger skal sammenlignes med solgte/udbudte referencer, mens erhvervs-/udlejningsejendomme har brug for lejeindtægt- og profitability-analyse.

## Løsning

Én ny lazy-loaded sektion (`AddressComparison`) i Metis' address-lookup der auto-detecter ejendomstype via BBR-anvendelseskode og viser den relevante analyse. Starter kompakt, kan udvides.

## Auto-detect logik

BBR-data fra `resolveAddressAnalysis()` indeholder `bbr.usage_code`. Mapping:

| BBR anvendelseskode | Type | Primær visning |
|---|---|---|
| 110-150 (beboelse) | Bolig | Sammenligning (solgte + udbudte + prispyramide) |
| 210-290 (erhverv) | Erhverv | Lejeindtægt + profitability + DSCR |
| 310-390 (blandet) | Udlejning | Lejeindtægt + profitability + sammenligning |
| Ukendt/manglende | Fallback | Begge dele collapsed |

## Dataflow

```
AddressComparison.mount($query)
├── parseAddress($query) → address + postal_code
├── resolveAddressAnalysis($query) → bbr.usage_code, rental_estimate, profitability
├── IF bolig/blandet: compareProperty(address, postal_code) → solgte, udbudte, prispyramide
└── Render baseret på ejendomstype
```

Datakilder:
- **Boligsammenligning:** `POST /v1/property/compare` (allerede deployed)
- **Lejeindtægt + profitability:** Allerede i `resolveAddressAnalysis()` response (`rental_estimate`, `profitability`)

## Visning

### Kompakt visning (default, alle typer)

**Bolig:**
- Prispyramide (3 bokse: hurtigt salg / markedspris / drømmepris)
- Stats-bar: gns. kr/m², median, liggedage, datakvalitet
- Kompakt tabel: top 3 solgte + top 3 udbudte (adresse, m², pris, kr/m²)

**Erhverv:**
- Lejeestimat: gns. kr/m²/år, estimeret årlig leje, sample count
- Profitability: bruttoafkast %, LTV %, DSCR, vurdering
- Ingen sammenligning (ikke relevant for erhverv)

**Udlejning/blandet:**
- Lejeestimat + profitability (som erhverv)
- Plus: kompakt prispyramide + top 3 handler

**Fallback (ukendt type):**
- Begge dele vist collapsed med "Vis sammenligning" / "Vis lejeestimat" knapper

### Udvidet visning (klik "Vis alle")

**Bolig/udlejning:**
- Leaflet-kort med farvede markører (rød subject, blå solgte, grøn udbudte)
- Reference-cards med billeder (solgte + udbudte)
- "Download PDF"-knap

**Erhverv:**
- Detaljeret profitability-analyse
- Lejesammenligning med markedsdata

## Nye filer

| Fil | Hvad |
|---|---|
| `src/Livewire/Sections/AddressComparison.php` | Sektion med auto-detect + `$expanded` toggle |
| `resources/views/livewire/sections/address-comparison.blade.php` | Kompakt + udvidet view |

## Edits

| Fil | Ændring |
|---|---|
| `src/Services/RegistryApi.php` | Tilføj `compareProperty()` metode |
| `src/MetisServiceProvider.php` | Registrér `metis-address-comparison` |
| `resources/views/livewire/lookup.blade.php` | Tilføj `<livewire:metis-address-comparison>` efter transactions |

## RegistryApi metode

```php
public function compareProperty(string $address, string $postalCode, array $filters = []): ?array
{
    return rescue(fn () => $this->client()->post('property/compare', array_filter([
        'address' => $address,
        'postal_code' => $postalCode,
        'filters' => ! empty($filters) ? $filters : null,
    ]))->json('data'), null);
}
```

## AddressComparison komponent

```php
class AddressComparison extends MetisSection
{
    public ?array $comparison = null;
    public ?array $rentalEstimate = null;
    public ?array $profitability = null;
    public ?string $propertyType = null; // 'residential', 'commercial', 'mixed', 'unknown'
    public bool $expanded = false;

    protected function sectionTitle(): string
    {
        return __('Markedsanalyse');
    }

    public function mount(string $query): void
    {
        $this->query = $query;
        $api = app(RegistryApi::class);

        // Get BBR + rental + profitability from existing analysis
        $analysis = $api->resolveAddressAnalysis($query);
        $usageCode = data_get($analysis, 'property.bbr.usage_code');
        $this->propertyType = $this->detectPropertyType($usageCode);
        $this->rentalEstimate = data_get($analysis, 'property.rental_estimate');
        $this->profitability = data_get($analysis, 'property.profitability');

        // Fetch comparison data for residential/mixed
        if (in_array($this->propertyType, ['residential', 'mixed', 'unknown'])) {
            $parsed = $api->parseAddress($query);
            if ($parsed) {
                $this->comparison = $api->compareProperty(
                    trim(($parsed['street'] ?? '') . ' ' . ($parsed['number'] ?? '')),
                    $parsed['zip'] ?? '',
                );
            }
        }
    }

    public function expand(): void
    {
        $this->expanded = true;
    }

    protected function detectPropertyType(?int $usageCode): string
    {
        if (! $usageCode) {
            return 'unknown';
        }

        return match (true) {
            $usageCode >= 110 && $usageCode <= 150 => 'residential',
            $usageCode >= 210 && $usageCode <= 290 => 'commercial',
            $usageCode >= 310 && $usageCode <= 390 => 'mixed',
            default => 'unknown',
        };
    }
}
```

## Placering i lookup.blade.php

```blade
@elseif($type === 'address')
    <livewire:metis-map-panel :query="$query" lazy />
    <livewire:metis-address-bbr :query="$query" lazy />
    <livewire:metis-address-valuation :query="$query" lazy />
    <livewire:metis-address-owners :query="$query" lazy />
    <livewire:metis-address-mortgages :query="$query" lazy />
    <livewire:metis-address-transactions :query="$query" lazy />
    <livewire:metis-address-comparison :query="$query" lazy />  {{-- NY --}}
    <livewire:metis-address-companies :query="$query" lazy />
    <livewire:metis-address-planning :query="$query" lazy />
    <livewire:metis-address-heritage :query="$query" lazy />
@endif
```

## Acceptance Criteria

1. **Auto-detect:** Ejendomstype detekteres korrekt fra BBR-anvendelseskode
2. **Bolig:** Viser prispyramide, stats, top 3 solgte/udbudte i kompakt visning
3. **Erhverv:** Viser lejeestimat + profitability (bruttoafkast, LTV, DSCR)
4. **Udlejning/blandet:** Viser lejeestimat + profitability + sammenligning
5. **Ukendt:** Viser begge dele collapsed med toggle-knapper
6. **Udvidet visning:** Leaflet-kort + reference-cards med billeder
7. **PDF:** Download-knap i udvidet visning (kun bolig/blandet)
8. **Lazy-loaded:** Vises med loading-skeleton som andre sektioner
9. **Sektionens titel:** "Markedsanalyse"
10. **Placering:** Efter transactions, før companies i address-lookup
11. **Kompakt tabel:** Top 3 solgte + top 3 udbudte med adresse, m², pris, kr/m²
12. **Profitability:** Bruttoafkast beregnet som (årlig leje / salgspris) × 100
