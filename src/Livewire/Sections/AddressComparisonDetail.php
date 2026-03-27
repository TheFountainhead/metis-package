<?php

namespace TheFountainhead\Metis\Livewire\Sections;

use Livewire\Attributes\Lazy;
use Livewire\Component;

#[Lazy]
class AddressComparisonDetail extends Component
{
    public array $comparison = [];

    public function mount(array $comparison): void
    {
        $this->comparison = $comparison;
    }

    public function placeholder(): string
    {
        return <<<'HTML'
        <div class="animate-pulse space-y-4">
            <div class="h-[300px] bg-zinc-100 dark:bg-zinc-800 rounded-lg"></div>
            <div class="grid grid-cols-3 gap-4">
                <div class="h-48 bg-zinc-100 dark:bg-zinc-800 rounded-lg"></div>
                <div class="h-48 bg-zinc-100 dark:bg-zinc-800 rounded-lg"></div>
                <div class="h-48 bg-zinc-100 dark:bg-zinc-800 rounded-lg"></div>
            </div>
        </div>
        HTML;
    }

    public function render()
    {
        return view('metis::livewire.sections.address-comparison-detail');
    }
}
