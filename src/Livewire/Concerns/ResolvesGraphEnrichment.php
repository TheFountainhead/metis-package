<?php

namespace TheFountainhead\Metis\Livewire\Concerns;

use TheFountainhead\Metis\Services\BbrUsageCategory;
use TheFountainhead\Metis\Services\OwnershipGraphBuilder;
use TheFountainhead\Metis\Services\RegistryApi;

/**
 * Graf-berigelse, delt af CompanyStructure (fase 2a) og PersonStructure
 * (fase 2b). Extracted in Task 8 from CompanyStructure, where all of it was
 * written and prod-verified first — the 2a suite passing UNCHANGED against
 * this trait is the proof the extraction was behaviour-preserving.
 *
 * Why a trait and not a service class: every method here reads or writes
 * `$this->enrichmentData` and `$this->graphModel`, which are component STATE
 * with a Livewire lifecycle (protected ⇒ lost on hydration; public ⇒ wire
 * payload). A service would have to take both in and hand both back on every
 * call — an object whose entire interface is the component's own state is the
 * component's behaviour, not a collaborator. The one genuinely
 * component-specific input (which portfolio rows are in scope) is an abstract
 * hook rather than a constructor argument, so each component answers it from
 * whatever shape its own $propertyData happens to have.
 *
 * The consuming component must declare:
 *   protected array $enrichmentData = ['companies' => [], 'properties' => []];
 *   public array $graphModel = ['nodes' => [], 'edges' => []];
 *
 * KONTRAKT: ALLE beløb i enrichment/card-shapen er HELE KRONER — see
 * companyEnrichmentFromInfo()'s docblock for the source-dependent unit rule
 * that makes that true.
 */
trait ResolvesGraphEnrichment
{
    /**
     * The portfolio rows this component holds, as a PLAIN LIST of raw rows
     * (matrikel_id/latitude/longitude/…). The two components store them very
     * differently — 2a keeps one ['list' => …, 'usage' => …] wrapper for the
     * searched company, 2b keeps a cvr => list map across several companies —
     * so the flattening lives with whoever owns the shape.
     *
     * Used ONLY for streetview coordinates. The properties/batch call keys off
     * the GRAPH, never off this list; see enrichmentMatrikelIds().
     *
     * @return list<array>
     */
    abstract protected function enrichmentPropertyRows(): array;

    /**
     * Shared fetch body for loadEnrichment()/refreshEnrichmentData(): pools
     * company-info for every enrichable cvr in the graph, batches property
     * enrichment for the property nodes in the graph, and layers streetview
     * URLs on top. Callers decide what a total failure means (loadEnrichment
     * flips to 'failed'; refreshEnrichmentData swallows it) — this method
     * itself lets exceptions propagate.
     */
    protected function fetchEnrichmentData(): void
    {
        $cvrs = $this->enrichmentCvrs();

        $this->resolveEnrichmentFrom(
            $cvrs === [] ? [] : app(RegistryApi::class)->fetchCompanyInfosPooled($cvrs),
        );
    }

    /**
     * CACHE-ONLY resolution, for recovery inside an INTERACTIVE request.
     * Reads whatever the 24h company-info cache still holds and issues no
     * pooled fetch for the rest — see RegistryApi::fetchCompanyInfosCached().
     * The properties/batch call still goes out: it is ONE request whose size
     * is bounded by the graph caps, not by the number of companies.
     *
     * Returns whether every enrichable cvr in the graph was recovered. A false
     * means the caller should hand the phase back to its poll loop rather than
     * force-fetching the remainder here.
     */
    protected function fetchEnrichmentDataFromCache(): bool
    {
        $cvrs = $this->enrichmentCvrs();
        $companies = $cvrs === [] ? [] : app(RegistryApi::class)->fetchCompanyInfosCached($cvrs);

        $this->resolveEnrichmentFrom($companies);

        return count($companies) === count($cvrs);
    }

    /**
     * The shared tail of both fetch paths: reduce the company payloads, batch
     * the in-graph properties, layer streetview URLs. Only where the company
     * payloads CAME from differs between the two.
     *
     * @param  array<string, array|null>  $companies
     */
    protected function resolveEnrichmentFrom(array $companies): void
    {
        $this->enrichmentData['companies'] = collect($companies)
            ->filter()
            ->map(fn ($company) => $this->companyEnrichmentFromInfo($company))
            ->all();

        $matrikelIds = $this->enrichmentMatrikelIds();
        $batch = $matrikelIds === [] ? [] : (rescue(fn () => app(RegistryApi::class)->fetchPropertiesBatch($matrikelIds), null) ?? []);
        $this->enrichmentData['properties'] = $this->propertyEnrichmentFromBatch($batch);
        $this->attachStreetviewUrls();
    }

    /**
     * The cvrs to pool company-info for: ENRICHABLE_KINDS nodes IN THE GRAPH.
     *
     * Mirrors the kind-gate in OwnershipGraphBuilder::applyEnrichment(), so
     * person/foreign nodes and 'other' orphan-parent stubs never trigger a
     * pool request whose card/signals would be discarded anyway. In a person
     * graph that gate is also what keeps 'person:root' (and the CPR behind it)
     * out of the pool entirely: the person node's kind is 'person' and it
     * carries no cvr, so it fails the filter twice over.
     *
     * @return list<string>
     */
    protected function enrichmentCvrs(): array
    {
        return collect($this->graphModel['nodes'] ?? [])
            ->filter(fn ($node) => in_array($node['kind'] ?? null, OwnershipGraphBuilder::ENRICHABLE_KINDS, true))
            ->pluck('cvr')->filter()->map(fn ($cvr) => (string) $cvr)->unique()->values()->all();
    }

    /**
     * The matrikel-ids to batch-enrich: PROPERTY NODES IN THE GRAPH, never the
     * union of the fetched portfolio lists.
     *
     * 🚨 Reading this off the graph rather than off $propertyData is the whole
     * point. The caps (properties_per_company, total_nodes) decide which
     * properties are actually drawn; a property behind the cap has no node, so
     * its batch entry could never be shown. On a person with several
     * property-heavy companies the portfolio lists run to hundreds of rows
     * while the graph draws a handful — batching the lists would ask the API
     * about every one of them on every enrichment pass, and again on every
     * recovery pass, for data that is discarded on arrival.
     *
     * The node id is 'bfe:'.$matrikel_id (OwnershipGraphBuilder::addProperties),
     * so the prefix is stripped back off here rather than kept in a parallel
     * field: one id format, derived in one place.
     *
     * @return list<string>
     */
    protected function enrichmentMatrikelIds(): array
    {
        return collect($this->graphModel['nodes'] ?? [])
            ->filter(fn ($node) => ($node['kind'] ?? null) === 'property')
            ->pluck('id')
            ->filter(fn ($id) => is_string($id) && str_starts_with($id, 'bfe:'))
            ->map(fn ($id) => substr($id, 4))
            ->filter(fn ($mid) => $mid !== '')
            ->unique()->values()->all();
    }

    /**
     * Reduces a company-info payload to the builder's flat enrichment shape.
     * financials arrives newest-first from the API (CompanyOverview.php's
     * assumption) but is NOT trusted blindly here — sorted explicitly by
     * `year` so an unsorted/out-of-order payload still resolves to the
     * actual latest fiscal year, not just array index 0.
     *
     * KONTRAKT: ALLE beløb i enrichment/card-shapen (dette array, og alt der
     * flyder derfra ind i graphModel/node.card) er HELE KRONER — aldrig
     * t.DKK/tusinder, aldrig mio. registry-api's financials-rækker er
     * kilde-afhængige i enhed (company-info.blade.php's $toTdkk er den
     * autoritative regel, prod-verificeret 26/7 mod Lars Horsbøl Holding
     * 40072772): rækker med source=pdf er i t.DKK, alle andre (API) er
     * allerede HELE KRONER. Konverteringen sker HER, ved kilden — én gang,
     * aldrig længere nede i kæden (builder, Blade, JS). En ubetinget *1000
     * gjorde API-rækker 1000× for høje (92.438.600 kr. vist som "92.438,6
     * mio. kr."); ingen konvertering gjorde pdf-rækker 1000× for lave
     * (2.527 t.DKK vist som "3 tkr."). Ejendoms-beløb
     * (valuation/latest_sale_price, se propertyEnrichmentFromBatch()
     * nedenfor) er altid hele kroner fra kilden — ingen konvertering der.
     *
     * Pinned from BOTH components (CompanyStructureTest + PersonStructureTest
     * each carry the 92_438_600 / 2_527_000 pair), so an edit that "fixes" the
     * unit for one caller cannot pass on the strength of the other's silence.
     */
    protected function companyEnrichmentFromInfo(array $company): array
    {
        $latest = collect($company['financials'] ?? [])->sortByDesc('year')->first();

        $toKroner = fn ($value) => $value === null ? null : (
            ($latest['source'] ?? '') === 'pdf' ? (int) round($value * 1000) : (int) $value
        );

        return [
            'equity' => $toKroner($latest['equity'] ?? null),
            'result' => $toKroner($latest['profit_loss'] ?? null),
            'fiscal_year' => $latest['year'] ?? null,
            'employees' => $company['employees'] ?? null,
            'website' => data_get($company, 'contact.website'),
            'founded_date' => $company['founded_date'] ?? null,
            'industry' => $company['industry'] ?? null,
        ];
    }

    /**
     * Full per-property enrichment map from a properties/batch response:
     * usage (via BbrUsageCategory, same primary-building selection as
     * usageMapFor()) + latest_transaction date/price + valuation.
     * Streetview URLs are NOT built here — they need portfolio lat/lng,
     * which this batch response does not carry; attachStreetviewUrls() layers
     * those in afterwards, keyed by the same matrikel_id.
     *
     * Unlike companyEnrichmentFromInfo()'s equity/result (t.DKK → kroner,
     * see that method's docblock), latest_sale_price/valuation are ALREADY
     * hele kroner straight from properties/batch — verified against the
     * existing prod-verbatim fixture (260000/534000-scale values, matching
     * the KONTRAKT that this whole enrichment/card shape is hele kroner
     * throughout). No *1000 conversion here.
     */
    protected function propertyEnrichmentFromBatch(array $batch): array
    {
        return collect($batch)->mapWithKeys(function ($p) {
            $buildings = collect($p['bbr']['buildings'] ?? []);
            // Primær bygning: største areal blandt ikke-småbygninger (9xx-koder =
            // garager/udhuse); fallback = største uanset kode.
            $primary = $buildings->filter(fn ($b) => (int) ($b['usage'] ?? 0) < 900)->sortByDesc('total_area')->first()
                ?? $buildings->sortByDesc('total_area')->first();

            return [(string) ($p['matrikel_id'] ?? '') => [
                'usage' => $primary ? BbrUsageCategory::label($primary['usage'] ?? null) : null,
                // Verified verbatim against a real prod registry-api payload
                // (read-only curl, 2026-07-26): latest_transaction is
                // {"transaction_type","transaction_date","registration_date","price"}
                // — the date field is `transaction_date`, NOT `date`.
                'latest_sale_date' => $p['latest_transaction']['transaction_date'] ?? null,
                'latest_sale_price' => $p['latest_transaction']['price'] ?? null,
                'valuation' => $p['valuation']['estimated_value'] ?? null,
            ]];
        })->all();
    }

    /**
     * Streetview URLs per property: built only when BOTH the portfolio row
     * carries lat/lng AND the google_maps_api_key config is set — otherwise
     * omitted entirely (the builder's array_filter drops null card fields).
     * Keyed onto the SAME enrichment['properties'][matrikel_id] map
     * propertyEnrichmentFromBatch() produced, so a property with no batch
     * entry at all still gets a streetview_url if it has coordinates.
     *
     * is_numeric() guard (multi-agent review, F-B): the portfolio row's
     * latitude/longitude come straight from the external registry-api
     * payload — a malformed row (empty string, non-numeric junk) must not
     * reach raw string interpolation into the URL. Non-numeric lat/lng is
     * skipped entirely (same as the missing-coordinate case) rather than
     * building a garbage URL. sprintf('%F', …) formats the numeric value
     * deterministically (fixed-point, locale-independent — never scientific
     * notation) and rawurlencode() escapes the resulting query value, so no
     * unescaped external data is ever concatenated into the URL string.
     */
    protected function attachStreetviewUrls(): void
    {
        $apiKey = config('metis.google_maps_api_key');
        if (! $apiKey) {
            return;
        }

        foreach ($this->enrichmentPropertyRows() as $p) {
            $mid = (string) ($p['matrikel_id'] ?? '');
            $lat = $p['latitude'] ?? null;
            $lng = $p['longitude'] ?? null;

            if ($mid === '' || ! is_numeric($lat) || ! is_numeric($lng)) {
                continue;
            }

            $location = rawurlencode(sprintf('%F,%F', $lat, $lng));
            $this->enrichmentData['properties'][$mid] ??= [];
            $this->enrichmentData['properties'][$mid]['streetview_url'] =
                "https://maps.googleapis.com/maps/api/streetview?size=640x400&location={$location}&key=".rawurlencode($apiKey);
        }
    }
}
