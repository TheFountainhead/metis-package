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
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
                <div class="flex items-center gap-3 mb-4">
                    <svg class="animate-spin size-5 text-zinc-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    <span class="text-sm font-medium text-zinc-500">{$title}</span>
                </div>
                <div class="space-y-3 animate-pulse">
                    <div class="h-4 bg-zinc-100 dark:bg-zinc-800 rounded w-full"></div>
                    <div class="h-4 bg-zinc-100 dark:bg-zinc-800 rounded w-3/4"></div>
                    <div class="h-4 bg-zinc-100 dark:bg-zinc-800 rounded w-1/2"></div>
                </div>
            </div>
        HTML;
    }
}
