<?php
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\DebtSearch;

it('uses advisor terminology and formats large debt as mia', function () {
    Http::fake(['*/v1/debt-search?*' => Http::response([
        'summary' => ['n_loans'=>21852,'n_properties'=>18976,'n_companies'=>5372,'n_creditors'=>50,'total_principal_kr'=>1_101_967_100_000,'avg_rate'=>10.5],
        'creditors' => [['creditor'=>'X A/S','n_loans'=>438,'avg_rate'=>10.0,'max_rate'=>10,'total_principal_kr'=>149_058_000_000]],
        'results' => [[
            'mortgage_id'=>1,'property'=>['id'=>1,'address'=>'Vej 1','postal_code'=>'2000'],
            'owners'=>[['type'=>'company','id'=>1,'name'=>'A ApS','cvr'=>'12345678','ownership_share_pct'=>100]],
            'debt'=>['type'=>'ejerpantebrev','interest_rate'=>20.0,'is_nominal_rate'=>true,'principal_amount_kr'=>500000,'creditor'=>'X','registration_date'=>'2020-01-01'],
        ]],
        'pagination'=>['next_cursor'=>null,'limit'=>25,'has_more'=>false],
        'meta'=>['aggregate_ms'=>1,'query_ms'=>1,'coverage_disclaimer'=>'x'],
    ])]);
    $html = Livewire::test(DebtSearch::class)->set('minRate', 8.0)->html();

    // Rådgiver-terminologi, ikke juridisk/engelsk
    expect($html)->toContain('Tinglyst gæld')->not->toContain('Total hovedstol');
    expect($html)->toContain('Gæld (kr)')->not->toContain('Hovedstol (kr)');
    expect($html)->toContain('Snit-rente')->not->toContain('Avg rente');
    // Stort beløb som mia, ikke ulæseligt 7-cifret mio + intet text-2xl overflow
    expect($html)->toContain('mia');
    expect($html)->not->toContain('text-2xl');
});
