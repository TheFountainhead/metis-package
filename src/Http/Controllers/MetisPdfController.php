<?php

namespace TheFountainhead\Metis\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Spatie\LaravelPdf\Enums\Format;
use Spatie\LaravelPdf\Facades\Pdf;
use TheFountainhead\Metis\Services\RegistryApi;

class MetisPdfController extends Controller
{
    public function download(string $type, string $query)
    {
        $api = app(RegistryApi::class);
        $data = [];

        if ($type === 'cvr') {
            $data['company'] = rescue(fn () => $api->fetchRolesByCvr([$query]));
            $data['structure'] = rescue(fn () => $api->fetchCompanyStructure($query), []);
            $data['portfolio'] = rescue(fn () => $api->fetchCompanyPropertyPortfolio($query));
            $data['tax'] = rescue(fn () => $api->fetchCompanyTaxRecords($query));
        } elseif ($type === 'cpr') {
            $data['properties'] = rescue(fn () => $api->fetchPropertiesByCpr($query));
            $data['companies'] = rescue(fn () => $api->fetchCompaniesByCpr($query));
        } elseif ($type === 'address') {
            $data['analysis'] = $api->resolveAddressAnalysis($query);
        }

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
