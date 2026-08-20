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

/*
 * 🚨 CVR OG CPR VAR VAERRE END ADRESSE-GRENEN.
 *
 * Adresse-grenen fejlede ved TAVSHED — sektionerne er `@if` uden `@else`, saa
 * dokumentet saa ufaerdigt ud. Cvr/cpr HAR `@else` paa hver sektion, saa en
 * total upstream-fejl printer en BEKRAEFTENDE BENAEGTELSE:
 *
 *   "Company Information  No data found.  Management & Roles  No roles found."
 *
 * Maalt 20/8: fejl, aegte tom og `rescue()`-null gav BYTE-IDENTISK dokument.
 * Det er en positiv paastand om et selskab eller en person, produceret naar
 * systemet i virkeligheden intet ved — paa den flade der arkiveres.
 *
 * 🪤 Og min egen adresse-rettelse gjorde det FARLIGERE: PDF'en demonstrerer
 * nu at systemet KAN udtrykke fejl paa én gren, hvilket goer tavsheden paa de
 * to andre mere trovaerdig. Praecis kontrast-argumentet fra
 * `partials/lookup-error.blade.php`, vendt mod os.
 *
 * 🔑 `rescue()` uden fallback giver `null` — fejl har derfor TO former her:
 * `null` og `['error' => …]`. Begge skal behandles som fejl, ikke som tom.
 */

function pdfTekstFor(string $type, array $data): string
{
    $html = view('metis::livewire.pdf', [
        'type' => $type,
        'query' => '12345678',
        'data' => $data,
    ])->render();

    $udenStil = preg_replace('/<style.*?<\/style>/s', '', $html);

    return trim(preg_replace('/\s+/', ' ', strip_tags($udenStil)));
}

it('siger eksplicit at et CVR-opslag fejlede — ingen falsk benaegtelse', function () {
    $tekst = pdfTekstFor('cvr', [
        'company' => ['error' => 'upstream_error'],
        'structure' => [], 'portfolio' => null, 'tax' => null,
    ]);

    // To POSITIVE assertions, ikke bare "fejl !== tom": en ulighed er et
    // svagere krav end to sande udsagn (lektien fra adresse-grenen).
    expect($tekst)->toContain('Opslaget kunne ikke udføres')
        ->and($tekst)->not->toContain('No roles found');
});

it('behandler rescue()-null som en FEJL, ikke som et tomt selskab', function () {
    $tekst = pdfTekstFor('cvr', [
        'company' => null, 'structure' => [], 'portfolio' => null, 'tax' => null,
    ]);

    expect($tekst)->toContain('Opslaget kunne ikke udføres');
});

it('viser stadig "No roles found" ved et AEGTE tomt CVR-svar', function () {
    $tekst = pdfTekstFor('cvr', [
        'company' => ['companies' => [['name' => 'Testfirma ApS', 'roles' => []]]],
        'structure' => [], 'portfolio' => null, 'tax' => null,
    ]);

    // Positiv kontrol: den aegte tomme tilstand maa IKKE forsvinde.
    expect($tekst)->toContain('Testfirma ApS')
        ->and($tekst)->not->toContain('Opslaget kunne ikke udføres');
});

it('siger eksplicit at et CPR-opslag fejlede', function () {
    $tekst = pdfTekstFor('cpr', [
        'properties' => ['error' => 'upstream_error'],
        'companies' => ['error' => 'upstream_error'],
    ]);

    expect($tekst)->toContain('Opslaget kunne ikke udføres')
        ->and($tekst)->not->toContain('No properties found');
});

it('viser stadig de aegte tomme CPR-tilstande', function () {
    $tekst = pdfTekstFor('cpr', [
        'properties' => ['properties' => []],
        'companies' => ['companies' => []],
    ]);

    expect($tekst)->toContain('No properties found')
        ->and($tekst)->not->toContain('Opslaget kunne ikke udføres');
});

/*
 * 🪤 DEKORRELEREDE FIXTURER. Mine foerste CPR-tests satte fejl paa BEGGE
 * felter samtidig, saa en mutation af ét felt overlevede: testen kunne ikke
 * skelne hvilken af de to betingelser der baerer. Maalt:
 *
 *   `($data['properties'] ?? null) === null` -> false   => INGEN roede
 *   `isset($data['companies']['error'])`     -> false   => INGEN roede
 *
 * Samme lektie som fixturen der satte to felter sammen i #174: en fixtur der
 * saetter flere ting SAMMEN kan ikke afgoere hvilken der maaler.
 */
it('fanger hver af de FIRE fejlbetingelser hver for sig', function (array $data, string $hvad) {
    expect(pdfTekstFor('cpr', $data))->toContain('Opslaget kunne ikke udføres');
})->with([
    // 🪤 ÉN fejl ad gangen, resten et gyldigt TOMT svar. Med to fejl sat
    // samtidig overlevede en mutation af hver enkelt betingelse — testen
    // kunne ikke afgoere hvilken der bar.
    'properties = rescue-null' => [[
        'properties' => null,
        'companies' => ['companies' => []],
    ], 'properties-null'],

    'properties har error' => [[
        'properties' => ['error' => 'upstream_error'],
        'companies' => ['companies' => []],
    ], 'properties-error'],

    'companies = rescue-null' => [[
        'properties' => ['properties' => []],
        'companies' => null,
    ], 'companies-null'],

    'companies har error' => [[
        'properties' => ['properties' => []],
        'companies' => ['error' => 'upstream_error'],
    ], 'companies-error'],
]);
