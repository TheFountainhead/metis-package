<div>
    <flux:card>
        <flux:heading size="lg" class="mb-4">{{ __('Person Information') }}</flux:heading>
        <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
            <dt class="text-zinc-500">{{ __('CPR') }}</dt>
            <dd class="font-medium">{{ substr($query, 0, 6) . '-' . substr($query, 6) }}</dd>
            <dt class="text-zinc-500">{{ __('Properties owned') }}</dt>
            <dd>{{ $properties ? count($properties) : 0 }}</dd>
        </dl>
    </flux:card>
</div>
