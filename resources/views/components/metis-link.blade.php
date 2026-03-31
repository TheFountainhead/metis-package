@props(['type', 'query', 'label' => null])
@if($query)
<a href="{{ route('metis.lookup', ['type' => $type, 'query' => $query]) }}"
   {{ $attributes->merge(['class' => 'text-blue-600 hover:underline dark:text-blue-400']) }}>
    {{ $label ?? $slot ?? $query }}
</a>
@else
<span class="text-zinc-400">{{ $label ?? '-' }}</span>
@endif
