<?php

namespace TheFountainhead\Metis\Facades;

use Illuminate\Support\Facades\Facade;

class Metis extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'metis';
    }
}
