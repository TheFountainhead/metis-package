<div class="mb-4">
    @if($immediate)
        <h1 class="font-serif text-2xl text-zinc-900 dark:text-white">{{ $immediate }}</h1>
    @else
        <div class="h-8 w-72 rounded bg-zinc-200/70 dark:bg-zinc-700/50 animate-pulse"></div>
    @endif
</div>
