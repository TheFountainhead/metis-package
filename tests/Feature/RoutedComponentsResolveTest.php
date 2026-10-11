<?php

use Illuminate\Support\Facades\Route;
use Livewire\Component;
use Livewire\Mechanisms\ComponentRegistry;

/**
 * Hver sidekomponent i en rute skal kunne findes igen af `/livewire/update`.
 *
 * 🐛 Uden `Livewire::component('metis-...')` udleder Livewire navnet af
 * klassen (`the-fountainhead.metis.livewire.x`), som det ikke kan slaa op
 * igen i den naeste request. Siden renderer fint, men enhver knap og ethvert
 * filter giver 500. Ramt: AlertDetail, og 11/10-2026 /segmentering (Flare
 * 9438177) samt /engagementer og /engagementer/{key}.
 *
 * Testen gaar paa ruterne, ikke paa en liste, saa en ny side uden navn
 * bliver roed med det samme.
 */
it('🚨 kan slaa hver routet Livewire-side op igen ud fra dens navn', function () {
    $registry = app(ComponentRegistry::class);

    $klasser = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($r) => $r->getAction('controller'))
        ->filter(fn ($uses) => is_string($uses) && class_exists($uses) && is_subclass_of($uses, Component::class))
        ->unique()
        ->values();

    expect($klasser)->not->toBeEmpty();

    // 🪤 IKKE `->not->toThrow(X::class, 'besked')`: i Pest betyder det
    // "kaster ikke X MED den besked", saa den var groen med fejlen til stede
    // (mutationstestet 11/10-2026). Saml fejlene og assertér listen.
    $fejl = [];

    foreach ($klasser as $klasse) {
        $navn = $registry->getName($klasse);

        try {
            $registry->getClass($navn);
        } catch (\Throwable $e) {
            $fejl[] = "{$klasse} ({$navn})";
        }
    }

    expect($fejl)->toBe([]);
});
