<?php

use TheFountainhead\Metis\Livewire\Sections\MetisSection;

/**
 * Sektioner maa ikke vente paa at brugeren scroller.
 *
 * 🚨 MAALT I BROWSEREN 11/8 paa "Nordre Frihavnsgade 24, 2100": fem sektioner
 * stod paa "Henter data" i det uendelige — Markedsanalyse, Virksomheder paa
 * adressen, Lokalplaner, Energy Label, Fredning & beskyttelse.
 *
 * Ikke en fejl i data eller kode. Maalt med request-tracing:
 *
 *   uden scroll:  9 livewire-requests, 5 sektioner haenger
 *   efter scroll: 14 livewire-requests, 0 haenger
 *
 * `#[Lazy]` uden `isolate: false` loader foerst naar komponenten kommer i
 * VIEWPORTEN. De fem ligger nederst paa siden, saa de ventede paa et scroll
 * der aldrig kom.
 *
 * 🪤 Symptomet er vaerre end forsinkelsen: en spinner der aldrig stopper
 * laeses som "systemet er gaaet i staa", ikke som "scroll ned". Frederik
 * rapporterede det tre gange som en fejl. Samme fejlklasse som "ingen
 * pantebreve" om en u-undersoegt ejendom — UI'et siger noget andet end det
 * der faktisk er sandt.
 *
 * 🪤 `isolate: false` samler dem desuden i ÉN request frem for én pr.
 * sektion, saa det er baade hurtigere og faerre kald.
 */
it('🚨 loader sektioner med det samme, ikke foerst ved scroll', function () {
    $attributter = (new ReflectionClass(MetisSection::class))
        ->getAttributes(\Livewire\Attributes\Lazy::class);

    expect($attributter)->not->toBeEmpty('MetisSection skal stadig vaere lazy');

    $args = $attributter[0]->getArguments();

    // Kernen: uden `isolate: false` venter komponenten paa viewporten, og
    // sektioner nederst paa siden loader aldrig for en bruger der ikke scroller.
    expect($args['isolate'] ?? true)->toBeFalse();
});
