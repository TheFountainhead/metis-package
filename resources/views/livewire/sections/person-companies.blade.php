<div>
    @php
        $all = collect($companies);
        // Active = person has at least one current role in the company
        $active = $all->filter(fn ($c) => $c['is_active'] ?? false);
        // Historical = person has no current roles (company may still exist)
        $historical = $all->reject(fn ($c) => $c['is_active'] ?? false);
    @endphp

    @if($all->isEmpty())
        <flux:card>
            <flux:heading size="lg" class="mb-4">Selskaber & ejerskab</flux:heading>
            <p class="text-sm text-zinc-500">Ingen selskaber fundet.</p>
        </flux:card>
    @else
        {{-- Active companies --}}
        @if($active->isNotEmpty())
            <flux:card>
                <div class="flex items-center gap-2 mb-4">
                    <flux:heading size="lg">Aktive selskaber</flux:heading>
                    <flux:badge size="sm" color="green">{{ $active->count() }}</flux:badge>
                </div>
                <div class="space-y-4">
                    @foreach($active as $company)
                        @include('metis::livewire.sections.partials.company-card', ['company' => $company, 'isHistorical' => false])
                    @endforeach
                </div>
            </flux:card>
        @endif

        {{-- Historical companies --}}
        @if($historical->isNotEmpty())
            <div x-data="{ open: false }" class="opacity-75">
                <flux:card>
                    <div x-on:click="open = !open" class="w-full flex items-center justify-between cursor-pointer select-none" role="button">
                        <div class="flex items-center gap-2">
                            <flux:heading size="lg">Tidligere selskaber</flux:heading>
                            <flux:badge size="sm" color="zinc">{{ $historical->count() }}</flux:badge>
                        </div>
                        <svg :class="open && 'rotate-180'" class="size-5 text-zinc-400 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                        </svg>
                    </div>
                    <div x-show="open" x-transition class="mt-4">
                        <div class="space-y-3">
                            @foreach($historical as $company)
                                @include('metis::livewire.sections.partials.company-card', ['company' => $company, 'isHistorical' => true])
                            @endforeach
                        </div>
                    </div>
                </flux:card>
            </div>
        @endif
    @endif
</div>
