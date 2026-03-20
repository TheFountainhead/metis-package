<div>
    <flux:card>
        <flux:heading size="lg" class="mb-4">{{ __('Building Data (BBR)') }}</flux:heading>
        @if($bbr)
            <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                @if(isset($bbr['total_area']))
                    <dt class="text-zinc-500">{{ __('Total area') }}</dt>
                    <dd>{{ $bbr['total_area'] }} m²</dd>
                @endif
                @if(isset($bbr['building_year']))
                    <dt class="text-zinc-500">{{ __('Year built') }}</dt>
                    <dd>{{ $bbr['building_year'] }}</dd>
                @endif
                @if(isset($bbr['usage_text']))
                    <dt class="text-zinc-500">{{ __('Usage') }}</dt>
                    <dd>{{ $bbr['usage_text'] }}</dd>
                @endif
                @if(isset($bbr['wall_material']))
                    <dt class="text-zinc-500">{{ __('Wall material') }}</dt>
                    <dd>{{ $bbr['wall_material'] }}</dd>
                @endif
                @if(isset($bbr['roof_material']))
                    <dt class="text-zinc-500">{{ __('Roof material') }}</dt>
                    <dd>{{ $bbr['roof_material'] }}</dd>
                @endif
                @if(isset($bbr['heating']))
                    <dt class="text-zinc-500">{{ __('Heating') }}</dt>
                    <dd>{{ $bbr['heating'] }}</dd>
                @endif
                @if(isset($bbr['rooms']))
                    <dt class="text-zinc-500">{{ __('Rooms') }}</dt>
                    <dd>{{ $bbr['rooms'] }}</dd>
                @endif
            </dl>
        @else
            <p class="text-sm text-zinc-500">{{ __('No building data found.') }}</p>
        @endif
    </flux:card>
</div>
