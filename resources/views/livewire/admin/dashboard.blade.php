<div>
    <div class="mb-8">
        <h1 class="text-2xl font-bold text-gray-900">Dashboard</h1>
        <p class="text-gray-500 text-sm">Overblik over aktivitet</p>
    </div>

    {{-- Stats cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8">
        <div class="bg-white rounded-lg shadow p-5">
            <div class="text-sm text-gray-500">Søgninger i dag</div>
            <div class="text-3xl font-bold text-gray-900 mt-1">{{ $searchesToday }}</div>
        </div>
        <div class="bg-white rounded-lg shadow p-5">
            <div class="text-sm text-gray-500">Leads i dag</div>
            <div class="text-3xl font-bold text-gray-900 mt-1">{{ $leadsToday }}</div>
        </div>
        <div class="bg-white rounded-lg shadow p-5">
            <div class="text-sm text-gray-500">Unikke virksomheder i dag</div>
            <div class="text-3xl font-bold text-gray-900 mt-1">{{ $companiesToday }}</div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
        {{-- Latest leads --}}
        <div class="bg-white rounded-lg shadow">
            <div class="px-5 py-4 border-b border-gray-100">
                <h2 class="font-semibold text-gray-900">Seneste leads</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-2 text-left text-gray-600 font-medium">Email</th>
                            <th class="px-4 py-2 text-left text-gray-600 font-medium">Virksomhed</th>
                            <th class="px-4 py-2 text-left text-gray-600 font-medium">Dato</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        @forelse ($latestLeads as $lead)
                            <tr>
                                <td class="px-4 py-2 text-gray-900">{{ $lead->email }}</td>
                                <td class="px-4 py-2 text-gray-600">{{ $lead->company_name ?? '-' }}</td>
                                <td class="px-4 py-2 text-gray-500">{{ $lead->created_at->format('d/m H:i') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="px-4 py-4 text-center text-gray-400">Ingen leads endnu</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Popular searches --}}
        <div class="bg-white rounded-lg shadow">
            <div class="px-5 py-4 border-b border-gray-100">
                <h2 class="font-semibold text-gray-900">Populære søgninger (7 dage)</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-2 text-left text-gray-600 font-medium">Søgeterm</th>
                            <th class="px-4 py-2 text-left text-gray-600 font-medium">Type</th>
                            <th class="px-4 py-2 text-right text-gray-600 font-medium">Antal</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        @forelse ($popularSearches as $search)
                            <tr>
                                <td class="px-4 py-2 text-gray-900">{{ $search->search_term }}</td>
                                <td class="px-4 py-2 text-gray-600">{{ $search->search_type }}</td>
                                <td class="px-4 py-2 text-right text-gray-900 font-medium">{{ $search->count }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="px-4 py-4 text-center text-gray-400">Ingen søgninger endnu</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
