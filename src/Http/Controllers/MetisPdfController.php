<?php

namespace TheFountainhead\Metis\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Spatie\LaravelPdf\Enums\Format;
use Spatie\LaravelPdf\Facades\Pdf;
use TheFountainhead\Metis\Services\RegistryApi;
use TheFountainhead\Metis\Services\SearchDetector;

class MetisPdfController extends Controller
{
    /**
     * Dataindsamlingen — adskilt fra PDF-genereringen saa den kan TESTES.
     *
     * 🚨 Controlleren havde NUL daekning, og tre forsoeg paa at give den
     * daekning var falsk groenne:
     *   1. kaldte servicen direkte og roerte aldrig controlleren
     *   2. gik gennem en rute der ikke er registreret i testharnesket
     *      (`TestCase::defineRoutes()` loader kun `web.php`; PDF-ruten findes
     *      kun i `embedded.php`) => 500, og `assertNotSent` blev trivielt sand
     *   3. DUPLIKEREDE denne krop i testen for at stoppe foer Browsershot.
     *      En mutation af `if ($type === 'cvr')` til `'XXcvr'` overlevede
     *      HELE suiten (836/836), fordi testen asserterede mod sin egen kopi
     *      og drift-vagten kun sammenlignede TEKST. Hver CVR-PDF ville da
     *      vaere tom.
     *
     * ⇒ Testen kalder nu DENNE metode. Ingen kopi at drive fra.
     */
    public function gatherData(string $type, string $query): array
    {
        $api = app(RegistryApi::class);
        $data = [];

        if ($type === 'cvr') {
            $data['company'] = rescue(fn () => $api->fetchRolesByCvr([$query]));
            $data['structure'] = rescue(fn () => $api->fetchCompanyStructure($query), []);
            $data['portfolio'] = rescue(fn () => $api->fetchCompanyPropertyPortfolio($query));
            $data['tax'] = rescue(fn () => $api->fetchCompanyTaxRecords($query));
        } elseif ($type === 'address') {
            $data['analysis'] = $api->resolveAddressAnalysis($query);
        }

        return $data;
    }

    public function download(string $type, string $query)
    {
        // CPR-opslag er lukket (9/10-2026): heller ikke et CPR under en anden
        // type, ellers sendes det til registry-api som CVR og havner i filnavnet.
        abort_if(strtolower($type) === 'cpr' || (new SearchDetector)->isCpr($query), 404);

        $data = $this->gatherData($type, $query);

        $filename = 'metis-' . $type . '-' . Str::slug($query) . '.pdf';

        return Pdf::view('metis::livewire.pdf', [
            'type' => $type,
            'query' => $query,
            'data' => $data,
        ])
            ->withBrowsershot(function ($browsershot) {
                $browsershot
                    ->setIncludePath(config('services.puppeteer.path'))
                    ->setOption('args', ['--disable-web-security'])
                    ->noSandbox();
            })
            ->format(Format::A4)
            ->name($filename)
            ->download();
    }
}
