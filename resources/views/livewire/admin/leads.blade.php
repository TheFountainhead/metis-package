<div>
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Leads</h1>
            <p class="text-gray-500 text-sm">Alle registrerede leads</p>
        </div>
    </div>

    {{-- Search --}}
    <div class="mb-4">
        <input type="text" wire:model.live.debounce.300ms="search"
               placeholder="Søg på email, virksomhed, CVR, domæne..."
               class="w-full max-w-md border border-gray-300 rounded-lg px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
    </div>

    {{-- Table --}}
    <div class="bg-white rounded-lg shadow overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th wire:click="sortBy('email')" class="px-4 py-3 text-left text-gray-600 font-medium cursor-pointer hover:text-gray-900">
                        Email
                        @if ($sortField === 'email')
                            <span class="text-xs">{{ $sortDirection === 'asc' ? '▲' : '▼' }}</span>
                        @endif
                    </th>
                    <th wire:click="sortBy('company_name')" class="px-4 py-3 text-left text-gray-600 font-medium cursor-pointer hover:text-gray-900">
                        Virksomhed
                        @if ($sortField === 'company_name')
                            <span class="text-xs">{{ $sortDirection === 'asc' ? '▲' : '▼' }}</span>
                        @endif
                    </th>
                    <th class="px-4 py-3 text-left text-gray-600 font-medium">CVR</th>
                    <th class="px-4 py-3 text-left text-gray-600 font-medium">Branche</th>
                    <th wire:click="sortBy('lookup_count')" class="px-4 py-3 text-right text-gray-600 font-medium cursor-pointer hover:text-gray-900">
                        Opslag
                        @if ($sortField === 'lookup_count')
                            <span class="text-xs">{{ $sortDirection === 'asc' ? '▲' : '▼' }}</span>
                        @endif
                    </th>
                    <th wire:click="sortBy('last_active_at')" class="px-4 py-3 text-left text-gray-600 font-medium cursor-pointer hover:text-gray-900">
                        Sidst aktiv
                        @if ($sortField === 'last_active_at')
                            <span class="text-xs">{{ $sortDirection === 'asc' ? '▲' : '▼' }}</span>
                        @endif
                    </th>
                    <th wire:click="sortBy('created_at')" class="px-4 py-3 text-left text-gray-600 font-medium cursor-pointer hover:text-gray-900">
                        Oprettet
                        @if ($sortField === 'created_at')
                            <span class="text-xs">{{ $sortDirection === 'asc' ? '▲' : '▼' }}</span>
                        @endif
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @forelse ($leads as $lead)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 text-gray-900">{{ $lead->email }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ $lead->company_name ?? '-' }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ $lead->cvr ?? '-' }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ $lead->industry ?? '-' }}</td>
                        <td class="px-4 py-3 text-right text-gray-900 font-medium">{{ $lead->lookup_count }}</td>
                        <td class="px-4 py-3 text-gray-500">{{ $lead->last_active_at?->format('d/m/Y H:i') ?? '-' }}</td>
                        <td class="px-4 py-3 text-gray-500">{{ $lead->created_at->format('d/m/Y H:i') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-8 text-center text-gray-400">Ingen leads fundet</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Pagination --}}
    <div class="mt-4">
        {{ $leads->links() }}
    </div>
</div>
