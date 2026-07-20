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
        $loadingLabel = __('Henter data');

        // Tydelig "i gang"-tilstand: brand-farvet spinner (claret) i synlig
        // størrelse + "Henter data"-tekst + skeleton med kraftigere kontrast
        // (zinc-200 mod hvid) og pulse. Den gamle grå-på-cream var for svag
        // til at signalere aktivitet.
        return <<<HTML
            <div class="bg-white rounded-xl border border-zinc-200 p-6">
                <div class="flex items-center gap-3 mb-4">
                    <span class="text-sm font-semibold text-zinc-800">{$title}</span>
                    <span class="inline-flex items-center gap-1.5 text-xs text-warm-500">
                        <span class="size-3.5 border-2 border-warm-500/25 border-t-warm-500 rounded-full animate-spin"></span>
                        {$loadingLabel}
                    </span>
                </div>
                <div class="space-y-2.5 animate-pulse">
                    <div class="h-3.5 bg-zinc-200 rounded-full w-2/3"></div>
                    <div class="h-3.5 bg-zinc-200 rounded-full w-full"></div>
                    <div class="h-3.5 bg-zinc-200 rounded-full w-5/6"></div>
                </div>
            </div>
        HTML;
    }
}
