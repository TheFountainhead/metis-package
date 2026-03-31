<?php

namespace TheFountainhead\Metis\Livewire\Sections;

use Livewire\Attributes\Lazy;
use Livewire\Component;

#[Lazy]
abstract class MetisSection extends Component
{
    public string $query;
    public bool $hasError = false;
    public ?string $errorMessage = null;

    abstract protected function sectionTitle(): string;

    public function placeholder(): string
    {
        $title = $this->sectionTitle();

        return <<<HTML
            <div class="bg-white rounded-xl border border-zinc-200 p-6">
                <div class="flex items-center gap-3 mb-4">
                    <div class="size-4 border-2 border-zinc-300 border-t-zinc-500 rounded-full animate-spin"></div>
                    <span class="text-sm font-semibold text-zinc-600">{$title}</span>
                </div>
                <div class="space-y-2.5 animate-pulse">
                    <div class="h-3.5 bg-zinc-100 rounded-full w-2/3"></div>
                    <div class="h-3.5 bg-zinc-100 rounded-full w-full"></div>
                    <div class="h-3.5 bg-zinc-100 rounded-full w-5/6"></div>
                </div>
            </div>
        HTML;
    }
}
