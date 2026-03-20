<div>
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900">Søgningslog</h1>
        <p class="text-gray-500 text-sm">Alle opslag registreret i systemet</p>
    </div>

    {{-- Filters --}}
    <div class="flex flex-wrap gap-3 mb-4">
        <input type="text" wire:model.live.debounce.300ms="email"
               placeholder="Email..."
               class="border border-gray-300 rounded-lg px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">

        <select wire:model.live="searchType"
                class="border border-gray-300 rounded-lg px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
            <option value="">Alle typer</option>
            <option value="address">Adresse</option>
            <option value="company">Virksomhed</option>
            <option value="person">Person</option>
        </select>

        <input type="date" wire:model.live="dateFrom"
               class="border border-gray-300 rounded-lg px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">

        <input type="date" wire:model.live="dateTo"
               class="border border-gray-300 rounded-lg px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
    </div>

    {{-- Table --}}
    <div class="bg-white rounded-lg shadow overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-gray-600 font-medium">Tidspunkt</th>
                    <th class="px-4 py-3 text-left text-gray-600 font-medium">Email</th>
                    <th class="px-4 py-3 text-left text-gray-600 font-medium">Type</th>
                    <th class="px-4 py-3 text-left text-gray-600 font-medium">Søgeterm</th>
                    <th class="px-4 py-3 text-left text-gray-600 font-medium">IP</th>
                    <th class="px-4 py-3 text-center text-gray-600 font-medium">Krydsopslag</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @forelse ($logs as $log)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 text-gray-500">{{ $log->created_at->format('d/m/Y H:i:s') }}</td>
                        <td class="px-4 py-3 text-gray-900">{{ $log->email ?? '-' }}</td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                                {{ $log->search_type === 'address' ? 'bg-green-100 text-green-800' : '' }}
                                {{ $log->search_type === 'company' ? 'bg-blue-100 text-blue-800' : '' }}
                                {{ $log->search_type === 'person' ? 'bg-purple-100 text-purple-800' : '' }}">
                                {{ $log->search_type }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-gray-600">{{ $log->search_term }}</td>
                        <td class="px-4 py-3 text-gray-400 font-mono text-xs">{{ $log->ip_address }}</td>
                        <td class="px-4 py-3 text-center">
                            @if ($log->is_cross_reference)
                                <span class="text-green-600">&#10003;</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-gray-400">Ingen logposter fundet</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Pagination --}}
    <div class="mt-4">
        {{ $logs->links() }}
    </div>
</div>
