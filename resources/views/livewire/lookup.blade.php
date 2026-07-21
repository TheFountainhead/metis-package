<div>
    {{-- Header: "Tilbage" (til forrige visning) + "Nyt opslag" (start forfra).
         Selskabs-CVR + navn vises i CompanyInfo-kortet; Metis-logo i app-layout. --}}
    <div class="flex justify-end gap-2 mb-4" x-data="{ canGoBack: window.history.length > 1 }">
        <button type="button" x-show="canGoBack" @click="window.history.back()"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm border rounded-lg hover:bg-zinc-50 dark:hover:bg-zinc-800 dark:border-zinc-700 transition">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
            {{ __('Tilbage') }}
        </button>
        <a href="{{ route(Route::has('metis.index') ? 'metis.index' : 'metis.home') }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm border rounded-lg hover:bg-zinc-50 dark:hover:bg-zinc-800 dark:border-zinc-700 transition">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" /></svg>
            {{ __('New lookup') }}
        </a>
    </div>

    <div class="max-w-7xl space-y-6">
        @if($type === 'cvr')
            <livewire:metis-company-overview :query="$query" lazy />
            <livewire:metis-company-info :query="$query" lazy />
            <livewire:metis-company-funding :query="$query" lazy />
            <livewire:metis-company-roles :query="$query" lazy />
            <livewire:metis-company-structure :query="$query" lazy />
            <livewire:metis-company-relations :query="$query" lazy />
            <livewire:metis-company-properties :query="$query" lazy />
            <livewire:metis-company-tinglysning :query="$query" lazy />
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
            <livewire:metis-address-skraafoto :query="$query" lazy />
            <livewire:metis-address-owners :query="$query" lazy />
            <livewire:metis-address-mortgages :query="$query" lazy />
            <livewire:metis-address-transactions :query="$query" lazy />
            <livewire:metis-address-similar-trades :query="$query" lazy />
            <livewire:metis-address-comparison :query="$query" lazy />
            <livewire:metis-address-companies :query="$query" lazy />
            <livewire:metis-address-planning :query="$query" lazy />
            <livewire:metis-address-energy :query="$query" lazy />
            <livewire:metis-address-heritage :query="$query" lazy />
        @endif
    </div>
</div>
