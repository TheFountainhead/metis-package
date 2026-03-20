<?php

use TheFountainhead\Metis\Livewire\MapPanel;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

it('renders map with layers for address search', function () {
    Http::fake([
        '*/v1/map/layers' => Http::response(['data' => [
            ['id' => 'cadastral', 'name' => 'Matrikelkort', 'type' => 'vector', 'tile_url' => 'https://tiles.test/{z}/{x}/{y}'],
            ['id' => 'bluespot', 'name' => 'Oversvømmelsesrisiko', 'type' => 'raster', 'tile_url' => 'https://tiles.test/bluespot/{z}/{x}/{y}.png', 'tms' => true, 'opacity' => 0.6],
        ]]),
    ]);

    Livewire::test(MapPanel::class, ['lat' => 55.6761, 'lng' => 12.5683, 'searchType' => 'address'])
        ->assertSee('Matrikelkort')
        ->assertSee('Oversvømmelsesrisiko');
});

it('renders fallback when API unreachable', function () {
    Http::fake(['*/v1/map/layers' => Http::response([], 500)]);

    Livewire::test(MapPanel::class, ['lat' => 55.6761, 'lng' => 12.5683, 'searchType' => 'address'])
        ->assertSee('Kortlag midlertidigt utilgængelige');
});

it('responds to search-completed event', function () {
    Http::fake(['*/v1/map/layers' => Http::response(['data' => []])]);

    Livewire::test(MapPanel::class)
        ->dispatch('search-completed', lat: 55.6, lng: 12.5, type: 'cvr', markers: [])
        ->assertSet('lat', 55.6)
        ->assertSet('searchType', 'cvr');
});
