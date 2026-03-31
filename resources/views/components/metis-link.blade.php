@props(['type', 'query', 'label' => null])
<a href="{{ route('metis.lookup', ['type' => $type, 'query' => $query]) }}"
   {{ $attributes->merge(['class' => 'text-blue-600 hover:underline dark:text-blue-400']) }}>
    {{ $label ?? $slot ?? $query }}
</a>
