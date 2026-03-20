<a href="{{ route('metis.lookup', ['type' => $type, 'query' => $query]) }}"
   class="text-blue-600 hover:underline dark:text-blue-400 {{ $attributes->get('class', '') }}">
    {{ $label ?? $slot ?? $query }}
</a>
