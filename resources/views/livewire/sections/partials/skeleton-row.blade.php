@php
    $columns = $columns ?? 6;
@endphp
<tr class="border-b border-zinc-100 dark:border-zinc-800 animate-pulse">
    @for($c = 0; $c < $columns; $c++)
        <td class="py-2 pr-4">
            <div class="h-3 bg-zinc-100 dark:bg-zinc-800 rounded-full {{ $c === 0 ? 'w-2/3' : 'w-1/2' }}"></div>
        </td>
    @endfor
</tr>
