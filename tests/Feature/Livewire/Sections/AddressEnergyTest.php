<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\Sections\AddressEnergy;

beforeEach(function () {
    if (! Route::has('metis.lookup')) {
        Route::get('/lookup/{type}/{query}', fn () => null)
            ->name('metis.lookup')
            ->where('query', '.*');
    }
});

function fakeAnalysisWithEnergy(?array $energy): array
{
    return [
        'data' => [
            'property' => [
                'energy_label' => $energy,
            ],
        ],
    ];
}

it('shows the energy label with validity, area and report link', function () {
    Http::fake([
        '*property/analysis*' => Http::response(fakeAnalysisWithEnergy([
            'energy_label' => 'D',
            'label_number' => 311208156,
            'valid_from' => '2016-10-24',
            'valid_until' => '2023-10-24',
            'is_expired' => true,
            'heated_area_sqm' => 6149,
            'report_url' => 'https://emoweb.dk/getcompcon/api/Data/GetPDFToBrowser?EnergyLabelSerialIdentifier=311208156',
        ])),
    ]);

    Livewire::test(AddressEnergy::class, ['query' => 'Bredgade 40, 1260'])
        ->assertSee('D')
        ->assertSee('2023-10-24')
        ->assertSee(__('Expired'))
        ->assertSee('6.149')
        ->assertSeeHtml('https://emoweb.dk/getcompcon/api/Data/GetPDFToBrowser?EnergyLabelSerialIdentifier=311208156');
});

it('does not flag a currently valid label as expired', function () {
    Http::fake([
        '*property/analysis*' => Http::response(fakeAnalysisWithEnergy([
            'energy_label' => 'A2020',
            'label_number' => 311642417,
            'valid_from' => '2022-11-14',
            'valid_until' => '2032-11-14',
            'is_expired' => false,
            'heated_area_sqm' => 125,
            'report_url' => 'https://emoweb.dk/getcompcon/api/Data/GetPDFToBrowser?EnergyLabelSerialIdentifier=311642417',
        ])),
    ]);

    Livewire::test(AddressEnergy::class, ['query' => 'Travervænget 3, 2920'])
        ->assertSee('A2020')
        ->assertDontSee(__('Expired'));
});

it('shows an empty state when the property has no energy label', function () {
    Http::fake([
        '*property/analysis*' => Http::response(fakeAnalysisWithEnergy(null)),
    ]);

    Livewire::test(AddressEnergy::class, ['query' => 'Nymarken 9, 9999'])
        ->assertSee(__('No energy label found.'));
});
