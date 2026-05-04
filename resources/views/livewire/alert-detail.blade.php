@php
    $changeTypeLabels = [
        'new' => __('Nyt pantebrev'),
        'new_lien' => __('Nyt udlæg (høj prioritet)'),
        'removed' => __('Pantebrev fjernet'),
        'principal_change' => __('Hovedstol ændret'),
        'creditor_change' => __('Kreditor skiftet'),
    ];

    $priorityColors = [
        'high' => 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-200',
        'medium' => 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-200',
        'low' => 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300',
    ];

    $formatAmount = function ($oere) {
        if ($oere === null) {
            return '—';
        }
        return number_format($oere / 100, 0, ',', '.') . ' kr.';
    };

    $formatRate = function ($rate) {
        if ($rate === null) {
            return '—';
        }
        return number_format((float) $rate, 2, ',', '.') . ' %';
    };

    $formatActive = function ($active) {
        if ($active === null) {
            return '—';
        }
        return $active ? '✓ ' . __('Aktiv') : '✗ ' . __('Inaktiv');
    };

    $formatDate = function ($iso) {
        if (! $iso) {
            return '—';
        }
        try {
            return \Carbon\Carbon::parse($iso)->isoFormat('D. MMM YYYY');
        } catch (\Throwable $e) {
            return $iso;
        }
    };
@endphp

<div>
    <div class="flex items-center justify-between py-2 mb-4 border-b">
        <div class="flex items-center gap-3">
            <a href="/alerts" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm border rounded-lg hover:bg-zinc-50 transition">
                ← {{ __('Tilbage til alerts') }}
            </a>
            <h1 class="text-xl font-bold">{{ __('Alert-detaljer') }}</h1>
        </div>

        @if($alert && ! ($alert['is_read'] ?? true))
            <button wire:click="markRead"
                    class="text-sm px-3 py-1.5 border rounded-lg hover:bg-zinc-50">
                {{ __('Markér som læst') }}
            </button>
        @endif
    </div>

    @if($loading)
        <div class="p-12 text-center text-zinc-500" wire:loading.delay.300ms>
            <div class="inline-block w-6 h-6 border-2 border-zinc-300 border-t-zinc-600 rounded-full animate-spin"></div>
            <p class="mt-2 text-sm">{{ __('Henter alert...') }}</p>
        </div>
    @endif

    @if($error)
        <div class="p-4 bg-red-50 border border-red-200 rounded-lg text-red-700">
            ⚠️ {{ $error }}
        </div>
    @endif

    @if($alert)
        @php
            $meta = is_array($alert['metadata'] ?? null)
                ? $alert['metadata']
                : (json_decode($alert['metadata'] ?? '[]', true) ?: []);
            $changeType = $meta['change_type'] ?? 'new';
            $priority = $meta['priority'] ?? ($alert['priority'] ?? 'low');
            $before = $meta['before'] ?? null;
            $after = $meta['after'] ?? null;
            $bfe = $meta['bfe'] ?? null;
            $address = $meta['property_address'] ?? null;
            $changeLabel = $changeTypeLabels[$changeType] ?? $changeType;
            $priorityClass = $priorityColors[$priority] ?? $priorityColors['low'];

            // Determine which columns to show
            $showBefore = in_array($changeType, ['removed', 'principal_change', 'creditor_change'], true);
            $showAfter = in_array($changeType, ['new', 'new_lien', 'principal_change', 'creditor_change'], true);

            $singleColumnHeading = match (true) {
                in_array($changeType, ['new', 'new_lien'], true) => __('Ny tilstand'),
                $changeType === 'removed' => __('Fjernet'),
                default => null,
            };

            // Diff-detection — highlight which fields changed
            $diffFields = [];
            if ($showBefore && $showAfter && $before && $after) {
                foreach (['principal_amount', 'interest_rate', 'creditor', 'mortgage_type', 'registration_date', 'maturity_date', 'is_active'] as $f) {
                    if (($before[$f] ?? null) !== ($after[$f] ?? null)) {
                        $diffFields[] = $f;
                    }
                }
            }

            $fieldRows = [
                'principal_amount' => __('Hovedstol'),
                'creditor' => __('Kreditor'),
                'interest_rate' => __('Rente') . ' (%)',
                'mortgage_type' => __('Type'),
                'registration_date' => __('Tinglyst dato'),
                'maturity_date' => __('Udløbsdato'),
                'is_active' => __('Aktiv'),
            ];
        @endphp

        <div class="space-y-4">
            <!-- Property context box -->
            <div class="p-4 bg-blue-50 border border-blue-200 rounded-lg dark:bg-blue-900/20 dark:border-blue-800">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs text-blue-700 dark:text-blue-300 uppercase tracking-wide font-medium">
                            {{ __('Ejendom') }}
                        </p>
                        <p class="text-base font-semibold text-blue-900 dark:text-blue-200 mt-1">
                            {{ $address ?? ($bfe ? 'BFE ' . $bfe : __('Ukendt ejendom')) }}
                        </p>
                        @if($address && $bfe)
                            <p class="text-xs text-blue-700 dark:text-blue-300 font-mono mt-0.5">
                                BFE {{ $bfe }}
                            </p>
                        @endif
                    </div>
                    @if($address)
                        <a href="/lookup/address/{{ urlencode($address) }}"
                           class="text-sm text-blue-600 hover:underline whitespace-nowrap">
                            {{ __('Se ejendom') }} →
                        </a>
                    @endif
                </div>
            </div>

            <!-- Title + change-type badge -->
            <div class="p-4 bg-white border rounded-lg">
                <div class="flex items-start justify-between gap-3">
                    <h2 class="text-lg font-semibold">{{ $alert['title'] ?? '' }}</h2>
                    <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-medium {{ $priorityClass }} whitespace-nowrap">
                        {{ $changeLabel }}
                    </span>
                </div>

                @if(! empty($alert['description']))
                    <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                        {{ $alert['description'] }}
                    </p>
                @endif
            </div>

            <!-- Diff view -->
            <div class="bg-white border rounded-lg overflow-hidden">
                @if($singleColumnHeading)
                    <!-- Single-column layout (new / new_lien / removed) -->
                    <div class="px-4 py-3 bg-zinc-50 border-b">
                        <h3 class="text-sm font-semibold text-zinc-700">{{ $singleColumnHeading }}</h3>
                    </div>
                    <div class="p-4">
                        @php $row = $showAfter ? $after : $before; @endphp
                        <dl class="divide-y divide-zinc-100">
                            @foreach($fieldRows as $key => $label)
                                <div class="grid grid-cols-3 gap-2 py-2 text-sm">
                                    <dt class="text-zinc-500">{{ $label }}</dt>
                                    <dd class="col-span-2 font-medium {{ $changeType === 'removed' ? 'text-red-700 line-through' : ($showAfter ? 'text-emerald-700' : '') }}">
                                        @if($key === 'principal_amount')
                                            {{ $formatAmount($row[$key] ?? null) }}
                                        @elseif($key === 'interest_rate')
                                            {{ $formatRate($row[$key] ?? null) }}
                                        @elseif($key === 'is_active')
                                            {{ $formatActive($row[$key] ?? null) }}
                                        @elseif($key === 'registration_date' || $key === 'maturity_date')
                                            {{ $formatDate($row[$key] ?? null) }}
                                        @else
                                            {{ $row[$key] ?? '—' }}
                                        @endif
                                    </dd>
                                </div>
                            @endforeach
                        </dl>
                    </div>
                @else
                    <!-- Two-column diff (principal_change / creditor_change) -->
                    <div class="grid grid-cols-2 divide-x">
                        <div>
                            <div class="px-4 py-3 bg-red-50 border-b">
                                <h3 class="text-sm font-semibold text-red-700">{{ __('Før') }}</h3>
                            </div>
                            <div class="p-4">
                                <dl class="divide-y divide-zinc-100">
                                    @foreach($fieldRows as $key => $label)
                                        @php $changed = in_array($key, $diffFields, true); @endphp
                                        <div class="grid grid-cols-2 gap-2 py-2 text-sm {{ $changed ? 'bg-red-50 -mx-4 px-4' : '' }}">
                                            <dt class="text-zinc-500">{{ $label }}</dt>
                                            <dd class="font-medium {{ $changed ? 'text-red-700' : '' }}">
                                                @if($key === 'principal_amount')
                                                    {{ $formatAmount($before[$key] ?? null) }}
                                                @elseif($key === 'interest_rate')
                                                    {{ $formatRate($before[$key] ?? null) }}
                                                @elseif($key === 'is_active')
                                                    {{ $formatActive($before[$key] ?? null) }}
                                                @elseif($key === 'registration_date' || $key === 'maturity_date')
                                                    {{ $formatDate($before[$key] ?? null) }}
                                                @else
                                                    {{ $before[$key] ?? '—' }}
                                                @endif
                                            </dd>
                                        </div>
                                    @endforeach
                                </dl>
                            </div>
                        </div>
                        <div>
                            <div class="px-4 py-3 bg-emerald-50 border-b">
                                <h3 class="text-sm font-semibold text-emerald-700">{{ __('Nu') }}</h3>
                            </div>
                            <div class="p-4">
                                <dl class="divide-y divide-zinc-100">
                                    @foreach($fieldRows as $key => $label)
                                        @php $changed = in_array($key, $diffFields, true); @endphp
                                        <div class="grid grid-cols-2 gap-2 py-2 text-sm {{ $changed ? 'bg-emerald-50 -mx-4 px-4' : '' }}">
                                            <dt class="text-zinc-500">{{ $label }}</dt>
                                            <dd class="font-medium {{ $changed ? 'text-emerald-700' : '' }}">
                                                @if($key === 'principal_amount')
                                                    {{ $formatAmount($after[$key] ?? null) }}
                                                @elseif($key === 'interest_rate')
                                                    {{ $formatRate($after[$key] ?? null) }}
                                                @elseif($key === 'is_active')
                                                    {{ $formatActive($after[$key] ?? null) }}
                                                @elseif($key === 'registration_date' || $key === 'maturity_date')
                                                    {{ $formatDate($after[$key] ?? null) }}
                                                @else
                                                    {{ $after[$key] ?? '—' }}
                                                @endif
                                            </dd>
                                        </div>
                                    @endforeach
                                </dl>
                            </div>
                        </div>
                    </div>
                @endif
            </div>

            <!-- Metadata footer -->
            <div class="text-xs text-zinc-400 flex items-center gap-3 px-1">
                <span title="{{ __('Tidspunkt hvor Metis detekterede ændringen — ikke tinglysningsdatoen') }}">{{ __('Alert-detekteret') }}: {{ \Carbon\Carbon::parse($alert['created_at'])->format('d. M Y H:i') }}</span>
                <span>·</span>
                <span>Alert ID: {{ $alert['id'] }}</span>
                @if(! empty($alert['is_read']))
                    <span>·</span>
                    <span>{{ __('Læst') }}</span>
                @endif
            </div>
        </div>
    @endif
</div>
