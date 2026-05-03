<div>
    {{-- Minimal header: "Nyt opslag" back-button only. Selskabs-CVR + navn vises
         allerede i CompanyInfo-kortet nedenfor. Global Metis-logo er i app-layout. --}}
    <div class="flex justify-end mb-4">
        <a href="{{ route(Route::has('metis.index') ? 'metis.index' : 'metis.home') }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm border rounded-lg hover:bg-zinc-50 dark:hover:bg-zinc-800 dark:border-zinc-700 transition">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" /></svg>
            {{ __('New lookup') }}
        </a>
    </div>

    <div class="max-w-7xl space-y-6">
        @if($type === 'cvr')
            <livewire:metis-company-info :query="$query" lazy />
            <livewire:metis-company-roles :query="$query" lazy />
            <livewire:metis-company-structure :query="$query" lazy />
            <livewire:metis-company-properties :query="$query" lazy />
            <livewire:metis-company-tinglysning :query="$query" lazy />
            <livewire:metis-company-tax :query="$query" lazy />
        @elseif($type === 'cpr')
            <livewire:metis-person-summary :query="$query" lazy />
            <livewire:metis-person-network :query="$query" lazy />
            <livewire:metis-person-companies :query="$query" lazy />
            <livewire:metis-person-info :query="$query" lazy />
            <livewire:metis-person-properties :query="$query" lazy />
            <livewire:metis-person-relations :query="$query" lazy />
        @elseif($type === 'person')
            <livewire:metis-person-roles :query="$query" lazy />
        @elseif($type === 'address')
            <livewire:metis-map-panel :query="$query" lazy />
            <livewire:metis-address-bbr :query="$query" lazy />
            <livewire:metis-address-valuation :query="$query" lazy />
            <livewire:metis-address-owners :query="$query" lazy />
            <livewire:metis-address-mortgages :query="$query" lazy />
            <livewire:metis-address-transactions :query="$query" lazy />
            <livewire:metis-address-comparison :query="$query" lazy />
            <livewire:metis-address-companies :query="$query" lazy />
            <livewire:metis-address-planning :query="$query" lazy />
            <livewire:metis-address-heritage :query="$query" lazy />
        @endif
    </div>
</div>
