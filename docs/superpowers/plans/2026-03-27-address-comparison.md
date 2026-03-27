# Metis Address Comparison Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a "Markedsanalyse" section to Metis address lookups showing property comparison + rental/profitability data, with expandable detail view (map + cards + PDF).

**Architecture:** Two Livewire components: `AddressComparison` (compact section, always loaded) and `AddressComparisonDetail` (lazy child with map/cards/PDF, loaded on "Vis alle"). Both extend `MetisSection`. Data fetched via `RegistryApi::resolvePropertyComparison()` (cached). Data-driven rendering — no type dispatch.

**Tech Stack:** Laravel 11, Livewire 3, Flux UI, Leaflet 1.9.4 (CDN), Tailwind CSS

**Spec:** `docs/superpowers/specs/2026-03-27-address-comparison-design.md`

**Codebase patterns:**
- Sections extend `MetisSection` with `#[Lazy]`, implement `sectionTitle()`, `mount($query)`, `render()`
- Data via `app(RegistryApi::class)` singleton, results cached in service
- Views namespaced `metis::livewire.sections.*`
- Components registered in `MetisServiceProvider::registerLivewireComponents()`
- Lookup router in `resources/views/livewire/lookup.blade.php`

---

### Task 1: RegistryApi — Add resolvePropertyComparison()

**Files:**
- Modify: `src/Services/RegistryApi.php`

- [ ] **Step 1: Read existing RegistryApi.php to understand client() and caching pattern**

Look at `resolveAddressAnalysis()` for the cache pattern.

- [ ] **Step 2: Add resolvePropertyComparison() method**

```php
public function resolvePropertyComparison(string $query): ?array
{
    $parsed = $this->parseAddress($query);

    if (! $parsed || empty($parsed['zip'])) {
        return null;
    }

    $address = trim(($parsed['street'] ?? '') . ' ' . ($parsed['number'] ?? ''));
    $postalCode = $parsed['zip'];

    $cacheKey = "metis_comparison_{$postalCode}_{$address}";

    return Cache::remember($cacheKey, 3600, fn () =>
        rescue(fn () => $this->client()
            ->post('property/compare', [
                'address' => $address,
                'postal_code' => $postalCode,
            ])->json('data'), null)
    );
}
```

Add `use Illuminate\Support\Facades\Cache;` if not already imported.

- [ ] **Step 3: Commit**

```bash
cd /Users/Frederik/metis-package
git add src/Services/RegistryApi.php
git commit -m "feat: add resolvePropertyComparison() to RegistryApi"
```

---

### Task 2: AddressComparison Section (compact)

**Files:**
- Create: `src/Livewire/Sections/AddressComparison.php`
- Create: `resources/views/livewire/sections/address-comparison.blade.php`
- Modify: `src/MetisServiceProvider.php`
- Modify: `resources/views/livewire/lookup.blade.php`

- [ ] **Step 1: Read existing section for pattern**

Read `src/Livewire/Sections/AddressValuation.php` and its blade view `resources/views/livewire/sections/address-valuation.blade.php` to understand the exact pattern.

- [ ] **Step 2: Create AddressComparison.php**

```php
<?php

namespace TheFountainhead\Metis\Livewire\Sections;

use TheFountainhead\Metis\Services\RegistryApi;

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

- [ ] **Step 3: Create blade view**

`resources/views/livewire/sections/address-comparison.blade.php`:

```blade
<div>
    <flux:card>
        <flux:heading size="lg" class="mb-4">{{ __('Markedsanalyse') }}</flux:heading>

        @if ($comparison)
            @if ($pyramid = data_get($comparison, 'price_pyramid'))
                <div class="grid grid-cols-3 gap-3 mb-4">
                    <div class="bg-zinc-50 dark:bg-zinc-800 rounded-lg p-3 text-center">
                        <div class="font-bold">{{ number_format($pyramid['quick_sale'], 0, ',', '.') }}</div>
                        <div class="text-xs text-zinc-400">{{ __('Hurtigt salg') }}</div>
                    </div>
                    <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-700 rounded-lg p-3 text-center">
                        <div class="font-bold text-blue-700 dark:text-blue-400">{{ number_format($pyramid['market_price'], 0, ',', '.') }}</div>
                        <div class="text-xs text-blue-500">{{ __('Markedspris') }}</div>
                    </div>
                    <div class="bg-zinc-50 dark:bg-zinc-800 rounded-lg p-3 text-center">
                        <div class="font-bold">{{ number_format($pyramid['dream_price'], 0, ',', '.') }}</div>
                        <div class="text-xs text-zinc-400">{{ __('Drømmepris') }}</div>
                    </div>
                </div>
            @endif

            @if ($stats = data_get($comparison, 'market_stats'))
                <div class="grid grid-cols-4 gap-3 mb-4">
                    <div class="text-center">
                        <div class="font-bold">{{ number_format($stats['avg_sqm_price_sold'] ?? 0, 0, ',', '.') }}</div>
                        <div class="text-xs text-zinc-400">{{ __('Gns. kr/m²') }}</div>
                    </div>
                    <div class="text-center">
                        <div class="font-bold">{{ number_format($stats['median_sqm_price_sold'] ?? 0, 0, ',', '.') }}</div>
                        <div class="text-xs text-zinc-400">{{ __('Median kr/m²') }}</div>
                    </div>
                    <div class="text-center">
                        <div class="font-bold">{{ $stats['avg_days_on_market'] ?? '-' }}</div>
                        <div class="text-xs text-zinc-400">{{ __('Liggedage') }}</div>
                    </div>
                    <div class="text-center">
                        @php
                            $confidence = $stats['confidence'] ?? 'unknown';
                            $color = match($confidence) { 'high' => 'green', 'medium' => 'yellow', 'low' => 'red', default => 'zinc' };
                        @endphp
                        <flux:badge color="{{ $color }}">{{ ucfirst($confidence) }}</flux:badge>
                        <div class="text-xs text-zinc-400 mt-1">{{ $stats['sample_count_sold'] ?? 0 }} {{ __('handler') }}</div>
                    </div>
                </div>
            @endif

            @php
                $soldRefs = collect(data_get($comparison, 'sold_references', []))->take(3);
                $listedRefs = collect(data_get($comparison, 'listed_references', []))->take(3);
            @endphp

            @if ($soldRefs->isNotEmpty())
                <div class="mb-3">
                    <div class="text-xs font-semibold text-zinc-400 uppercase mb-2">{{ __('Seneste handler') }}</div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b text-left text-zinc-400 text-xs">
                                    <th class="pb-1">{{ __('Adresse') }}</th>
                                    <th class="pb-1 text-right">{{ __('m²') }}</th>
                                    <th class="pb-1 text-right">{{ __('Kr/m²') }}</th>
                                    <th class="pb-1 text-right">{{ __('Pris') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($soldRefs as $ref)
                                    <tr class="border-b border-zinc-100 dark:border-zinc-700">
                                        <td class="py-1.5">{{ $ref['address'] }}</td>
                                        <td class="text-right">{{ $ref['size'] ?? '-' }}</td>
                                        <td class="text-right">{{ number_format($ref['sqm_price'] ?? 0, 0, ',', '.') }}</td>
                                        <td class="text-right font-medium">{{ number_format(($ref['price'] ?? 0) / 1000000, 1, ',', '.') }}M</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            @if ($listedRefs->isNotEmpty())
                <div class="mb-3">
                    <div class="text-xs font-semibold text-zinc-400 uppercase mb-2">{{ __('Udbudte') }}</div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b text-left text-zinc-400 text-xs">
                                    <th class="pb-1">{{ __('Adresse') }}</th>
                                    <th class="pb-1 text-right">{{ __('m²') }}</th>
                                    <th class="pb-1 text-right">{{ __('Dage') }}</th>
                                    <th class="pb-1 text-right">{{ __('Pris') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($listedRefs as $ref)
                                    <tr class="border-b border-zinc-100 dark:border-zinc-700">
                                        <td class="py-1.5">{{ $ref['address'] }}</td>
                                        <td class="text-right">{{ $ref['size'] ?? '-' }}</td>
                                        <td class="text-right">{{ $ref['days_for_sale'] ?? '-' }}</td>
                                        <td class="text-right font-medium text-green-700 dark:text-green-400">{{ number_format(($ref['price'] ?? 0) / 1000000, 1, ',', '.') }}M</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            @if (! $showDetail)
                <flux:button variant="ghost" wire:click="$toggle('showDetail')" class="mt-2">
                    {{ __('Vis alle referencer') }}
                </flux:button>
            @endif

            @if ($showDetail)
                <div class="mt-4">
                    <livewire:metis-address-comparison-detail :comparison="$comparison" lazy />
                </div>
            @endif
        @endif

        @if ($rentalEstimate)
            <div class="{{ $comparison ? 'mt-6 pt-4 border-t border-zinc-200 dark:border-zinc-700' : '' }}">
                <div class="text-xs font-semibold text-zinc-400 uppercase mb-2">{{ __('Lejeestimat') }}</div>
                <div class="grid grid-cols-3 gap-3">
                    <div class="bg-zinc-50 dark:bg-zinc-800 rounded-lg p-3 text-center">
                        <div class="font-bold">{{ number_format($rentalEstimate['avg_rent_per_sqm'] ?? 0, 0, ',', '.') }}</div>
                        <div class="text-xs text-zinc-400">{{ __('Kr/m²/år') }}</div>
                    </div>
                    <div class="bg-zinc-50 dark:bg-zinc-800 rounded-lg p-3 text-center">
                        <div class="font-bold">{{ number_format($rentalEstimate['estimated_annual_rent'] ?? 0, 0, ',', '.') }}</div>
                        <div class="text-xs text-zinc-400">{{ __('Årlig leje') }}</div>
                    </div>
                    <div class="bg-zinc-50 dark:bg-zinc-800 rounded-lg p-3 text-center">
                        <div class="font-bold">{{ $rentalEstimate['sample_count'] ?? '-' }}</div>
                        <div class="text-xs text-zinc-400">{{ __('Datapunkter') }}</div>
                    </div>
                </div>
            </div>
        @endif

        @if ($profitability)
            <div class="mt-4">
                <div class="text-xs font-semibold text-zinc-400 uppercase mb-2">{{ __('Rentabilitet') }}</div>
                <div class="grid grid-cols-3 gap-3">
                    @if ($profitability['gross_yield'] ?? null)
                        <div class="bg-zinc-50 dark:bg-zinc-800 rounded-lg p-3 text-center">
                            <div class="font-bold">{{ $profitability['gross_yield'] }}%</div>
                            <div class="text-xs text-zinc-400">{{ __('Bruttoafkast') }}</div>
                        </div>
                    @endif
                    @if ($profitability['ltv'] ?? null)
                        <div class="bg-zinc-50 dark:bg-zinc-800 rounded-lg p-3 text-center">
                            <div class="font-bold">{{ $profitability['ltv'] }}%</div>
                            <div class="text-xs text-zinc-400">{{ __('LTV') }}</div>
                        </div>
                    @endif
                    @if ($profitability['estimated_dscr'] ?? null)
                        <div class="bg-zinc-50 dark:bg-zinc-800 rounded-lg p-3 text-center">
                            <div class="font-bold">{{ $profitability['estimated_dscr'] }}</div>
                            <div class="text-xs text-zinc-400">{{ __('DSCR') }}</div>
                        </div>
                    @endif
                </div>
            </div>
        @endif

        @if (! $comparison && ! $rentalEstimate && ! $profitability)
            <p class="text-sm text-zinc-500">{{ __('Ingen markedsdata tilgængelig') }}</p>
        @endif
    </flux:card>
</div>
```

- [ ] **Step 4: Register in MetisServiceProvider**

In `src/MetisServiceProvider.php`, inside `registerLivewireComponents()`, add:

```php
Livewire::component('metis-address-comparison', \TheFountainhead\Metis\Livewire\Sections\AddressComparison::class);
```

- [ ] **Step 5: Add to lookup.blade.php**

In `resources/views/livewire/lookup.blade.php`, inside the `@elseif($type === 'address')` block, add after `metis-address-transactions`:

```blade
<livewire:metis-address-comparison :query="$query" lazy />
```

- [ ] **Step 6: Commit**

```bash
cd /Users/Frederik/metis-package
git add src/Livewire/Sections/AddressComparison.php resources/views/livewire/sections/address-comparison.blade.php src/MetisServiceProvider.php resources/views/livewire/lookup.blade.php
git commit -m "feat: add AddressComparison section with compact view"
```

---

### Task 3: AddressComparisonDetail Child Component (expanded)

**Files:**
- Create: `src/Livewire/Sections/AddressComparisonDetail.php`
- Create: `resources/views/livewire/sections/address-comparison-detail.blade.php`
- Modify: `src/MetisServiceProvider.php`

- [ ] **Step 1: Create AddressComparisonDetail.php**

```php
<?php

namespace TheFountainhead\Metis\Livewire\Sections;

use Livewire\Attributes\Lazy;
use Livewire\Component;

#[Lazy]
class AddressComparisonDetail extends Component
{
    public array $comparison = [];

    public function mount(array $comparison): void
    {
        $this->comparison = $comparison;
    }

    public function placeholder(): string
    {
        return <<<'HTML'
        <div class="animate-pulse space-y-4">
            <div class="h-[300px] bg-zinc-100 dark:bg-zinc-800 rounded-lg"></div>
            <div class="grid grid-cols-3 gap-4">
                <div class="h-48 bg-zinc-100 dark:bg-zinc-800 rounded-lg"></div>
                <div class="h-48 bg-zinc-100 dark:bg-zinc-800 rounded-lg"></div>
                <div class="h-48 bg-zinc-100 dark:bg-zinc-800 rounded-lg"></div>
            </div>
        </div>
        HTML;
    }

    public function render()
    {
        return view('metis::livewire.sections.address-comparison-detail');
    }
}
```

- [ ] **Step 2: Create blade view with Leaflet map + cards**

`resources/views/livewire/sections/address-comparison-detail.blade.php`:

Follow the existing Leaflet pattern from the property page map. Include:
- Leaflet CDN loaded in Alpine `init()` (same pattern as existing map in Metis)
- Red marker for subject, blue for sold, green for listed
- Satellite + street tile layers with layer control
- `wire:ignore` wrapper
- Reference cards in 3-column grid (sold + listed)
- Each card: image (from comparison data), address, size, price, kr/m², date/days
- PDF download link at bottom: `<flux:button :href="'https://registry-api.frankston.io/api/v1/property/compare/pdf?...' " target="_blank">`

Read `resources/views/livewire/sections/address-bbr.blade.php` or similar for the Flux card styling pattern used in Metis sections.

- [ ] **Step 3: Register in MetisServiceProvider**

Add to `registerLivewireComponents()`:

```php
Livewire::component('metis-address-comparison-detail', \TheFountainhead\Metis\Livewire\Sections\AddressComparisonDetail::class);
```

- [ ] **Step 4: Commit**

```bash
cd /Users/Frederik/metis-package
git add src/Livewire/Sections/AddressComparisonDetail.php resources/views/livewire/sections/address-comparison-detail.blade.php src/MetisServiceProvider.php
git commit -m "feat: add AddressComparisonDetail child component with map + cards + PDF"
```

---

### Task 4: Deploy & Test

- [ ] **Step 1: Push package**

```bash
cd /Users/Frederik/metis-package && git push
```

- [ ] **Step 2: Update package in standalone app**

```bash
cd /Users/Frederik/metis && composer update thefountainhead/metis-package
```

- [ ] **Step 3: Push standalone app**

```bash
cd /Users/Frederik/metis && git add composer.lock && git commit -m "chore: update metis-package" && git push
```

- [ ] **Step 4: Verify on metis.frankston.io**

Search for an address (e.g., "Dalsgaardsvej 15, 2930 Klampenborg") and verify:
- Markedsanalyse section appears after transactions
- Price pyramid shows (if comparison data exists)
- Stats bar shows avg kr/m², median, liggedage
- Top 3 sold + listed tables render
- "Vis alle" button loads detail component
- Detail shows map + cards
- PDF download works
