<div>
    <flux:card>
        <flux:heading size="lg" class="mb-4">{{ __('Local Planning') }}</flux:heading>
        @if($plans && count($plans) > 0)
            <div class="space-y-3">
                @foreach($plans as $plan)
                    <div class="p-3 rounded-lg bg-zinc-50 dark:bg-zinc-800 text-sm">
                        <p class="font-medium">{{ $plan['plan_name'] ?? $plan['plan_id'] ?? __('Local plan') }}</p>
                        @if(isset($plan['zone_status']))
                            <p class="text-zinc-500">{{ __('Zone') }}: {{ $plan['zone_status'] }}</p>
                        @endif
                        @if(isset($plan['max_floors']))
                            <p class="text-zinc-500">{{ __('Max floors') }}: {{ $plan['max_floors'] }}</p>
                        @endif
                        @if(isset($plan['building_percentage']))
                            <p class="text-zinc-500">{{ __('Building %') }}: {{ $plan['building_percentage'] }}%</p>
                        @endif
                        @if(isset($plan['document_url']))
                            <a href="{{ $plan['document_url'] }}" target="_blank" class="text-blue-600 hover:underline dark:text-blue-400 text-xs">{{ __('View document') }}</a>
                        @endif
                    </div>
                @endforeach
            </div>
        @else
            @if($hasError)
                @include('metis::livewire.sections.partials.lookup-error')
            @else
            <p class="text-sm text-zinc-500">{{ __('No planning data found.') }}</p>
            @endif
        @endif
    </flux:card>
</div>
