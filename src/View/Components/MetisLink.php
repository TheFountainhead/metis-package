<?php

namespace TheFountainhead\Metis\View\Components;

use Illuminate\View\Component;

class MetisLink extends Component
{
    public function __construct(
        public string $type,
        public string $query,
        public ?string $label = null,
    ) {}

    public function render()
    {
        return view('metis::components.metis-link');
    }
}
