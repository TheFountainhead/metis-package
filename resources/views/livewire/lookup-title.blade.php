<div class="mb-4">
    <h1 class="font-serif text-2xl text-zinc-900 dark:text-white">{{ $title }}</h1>
    @if($type === 'cvr' && $title !== "CVR {$query}")
        <p class="text-sm text-zinc-500 mt-0.5">CVR {{ $query }}</p>
    @endif
</div>
