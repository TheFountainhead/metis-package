<div>
    @if($this->viewerUrl)
        <flux:card>
            <div class="flex items-center justify-between mb-4">
                <flux:heading size="lg">{{ __('Skråfoto') }}</flux:heading>
                <a href="{{ $this->viewerUrl }}" target="_blank" rel="noopener"
                   class="text-sm text-zinc-500 hover:text-zinc-900 dark:hover:text-white hover:underline">
                    {{ __('Åbn i nyt vindue') }} ↗
                </a>
            </div>
            <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
                <iframe src="{{ $this->viewerUrl }}"
                        title="{{ __('Skråfoto') }} — {{ $query }}"
                        loading="lazy"
                        referrerpolicy="no-referrer-when-downgrade"
                        class="w-full h-96 border-0"></iframe>
            </div>
            <p class="text-[11px] text-zinc-400 dark:text-zinc-500 mt-2 italic">
                {{ __('Kilde:') }} <a href="https://skraafoto.dataforsyningen.dk" target="_blank" rel="noopener" class="hover:underline">skraafoto.dataforsyningen.dk</a> ({{ __('Klimadatastyrelsen') }})
            </p>
        </flux:card>
    @endif
</div>
