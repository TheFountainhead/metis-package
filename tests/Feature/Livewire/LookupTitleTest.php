<?php

use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\LookupTitle;

it('shows the company name resolved from company-info on cvr lookups', function () {
    Http::fake(['*cvr/company/*' => Http::response(['data' => ['company' => ['name' => 'Lars Horsbøl Holding ApS', 'financials' => []]]])]);

    Livewire::withoutLazyLoading()->test(LookupTitle::class, ['type' => 'cvr', 'query' => '40072772'])
        ->assertSee('Lars Horsbøl Holding ApS')
        ->assertSee('CVR 40072772');
});

it('falls back to the cvr number when company-info fails — the title never breaks the page', function () {
    Http::fake(['*cvr/company/*' => Http::failedConnection('cURL error 28')]);

    Livewire::withoutLazyLoading()->test(LookupTitle::class, ['type' => 'cvr', 'query' => '40072772'])
        ->assertSee('CVR 40072772');
});

it('shows the search term directly for name and address lookups', function () {
    Livewire::withoutLazyLoading()->test(LookupTitle::class, ['type' => 'person', 'query' => 'Lars Sørensen'])
        ->assertSee('Lars Sørensen');

    Livewire::withoutLazyLoading()->test(LookupTitle::class, ['type' => 'address', 'query' => 'Hallandsgade 15, 2300 København S'])
        ->assertSee('Hallandsgade 15, 2300 København S');
});

it('never puts the raw cpr in the title on cpr lookups', function () {
    Livewire::withoutLazyLoading()->test(LookupTitle::class, ['type' => 'cpr', 'query' => '0101011234'])
        ->assertSee('Personopslag')
        ->assertDontSee('0101011234');
});
