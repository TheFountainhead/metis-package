<div>
    <div class="flex items-center justify-between py-2 mb-4 border-b">
        <div class="flex items-center gap-3">
            <h1 class="text-xl font-bold">{{ __('Metis') }}</h1>
            <span class="px-2 py-0.5 text-xs rounded-full bg-zinc-100 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300 uppercase">
                {{ match($type) { 'address' => __('Address'), 'person' => __('Person'), default => strtoupper($type) } }}
            </span>
            <span class="text-sm text-zinc-500">{{ $query }}</span>
        </div>
        <div class="flex space-x-3">
            <a href="{{ route(Route::has('metis.index') ? 'metis.index' : 'metis.home') }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm border rounded-lg hover:bg-zinc-50 transition">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" /></svg>
                {{ __('New lookup') }}
            </a>
        </div>
    </div>

    <div class="max-w-7xl space-y-6">
        @if($type === 'cvr')
            <livewire:metis-company-info :query="$query" lazy />
            <livewire:metis-company-roles :query="$query" lazy />
            <livewire:metis-company-structure :query="$query" lazy />
            <livewire:metis-company-properties :query="$query" lazy />
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
