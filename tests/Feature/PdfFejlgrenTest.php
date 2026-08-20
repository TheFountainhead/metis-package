<?php

/*
 * 🚨 EN PDF ER DEN FLADE DER ARKIVERES OG VIDERESENDES.
 *
 * Maalt 20/8 FOER denne rettelse: 422-fejl, aegte tom og ejendom-uden-data
 * gav BYTE-IDENTISK dokument — header, adresse, tidsstempel, sidefod, og
 * intet andet. Alle sektioner er `@if(...)` uden `@else`, saa de faldt tavst
 * igennem.
 *
 * Det er ikke en tom skaerm man klikker videre fra. Det er et dokument der
 * mailes til en laangiver og laegges i en sagsmappe, hvor "ingen pantebreve"
 * laeses som GAELDFRIHED. Praecis den falske benaegtelse hele denne
 * fejlfamilie handler om — paa den ene flade hvor den overlever.
 *
 * 🔑 `partials/lookup-error.blade.php` bemaerker at 3 aerlige sektioner ved
 * siden af 9 uaerlige goer de 9 MERE trovaerdige, fordi siden demonstrerer at
 * systemet KAN skelne. I PDF'en er ALLE tavse, saa der er ingen kontrast
 * overhovedet — dokumentet ser faerdigt ud.
 */

function pdfTekst(array $analysis): string
{
    $html = view('metis::livewire.pdf', [
        'type' => 'address',
        'query' => 'Søndergade 43A',
        'data' => ['analysis' => $analysis],
    ])->render();

    $udenStil = preg_replace('/<style.*?<\/style>/s', '', $html);

    return trim(preg_replace('/\s+/', ' ', strip_tags($udenStil)));
}

it('siger EKSPLICIT at opslaget fejlede — i stedet for at se faerdigt ud', function () {
    $tekst = pdfTekst(['error' => 'address_ambiguous', 'status' => 422]);

    expect($tekst)->toContain('Adressen kan ikke entydigt bestemmes');
});

it('skelner en FEJL fra en aegte tom ejendom', function () {
    $fejl = pdfTekst(['error' => 'address_ambiguous', 'status' => 422]);
    $tom = pdfTekst([]);

    // 🪤 Det var praecis dette der IKKE var sandt foer: de to var identiske.
    expect($fejl)->not->toBe($tom);
});

it('giver den generiske besked ved en transportfejl, ikke postnummer-hintet', function () {
    $tekst = pdfTekst(['error' => 'upstream_error', 'status' => 500]);

    expect($tekst)->toContain('Vi kunne ikke få svar fra kilden')
        ->and($tekst)->not->toContain('prøv med postnummer');
});

it('renderer stadig data helt normalt naar opslaget lykkes', function () {
    $tekst = pdfTekst(['property' => [
        'bbr' => ['total_area' => 140, 'building_year' => 1965],
    ]]);

    expect($tekst)->toContain('140')
        ->and($tekst)->not->toContain('Adressen kan ikke entydigt bestemmes');
});

/*
 * 🚨 DEN AEGTE TOMME TILSTAND VAR OGSAA ET TAVST DOKUMENT.
 *
 * Fanget ved at SE dokumentet, ikke af en test: efter fejlgrenen kunne 422 og
 * "ejendom uden data" skelnes — men den tomme gav stadig kun header, adresse
 * og sidefod. Opslaget LYKKEDES, og det skal dokumentet sige, ellers kan
 * modtageren ikke skelne "vi spurgte, der er intet" fra "noget gik galt".
 *
 * 🪤 Min egen test `skelner en FEJL fra en aegte tom` beviste kun at de to er
 * FORSKELLIGE — ikke at begge er aerlige. En ulighed er et svagere krav end
 * to sande udsagn.
 */
it('siger ogsaa eksplicit naar opslaget LYKKEDES men gav ingen data', function () {
    $tekst = pdfTekst([]);

    expect($tekst)->toContain('Opslaget blev udført')
        ->and($tekst)->not->toContain('Adressen kan ikke entydigt bestemmes');
});
