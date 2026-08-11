<?php

use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * Skjulte roller skal fremgaa af OVERSKRIFTEN, ikke kun af en knap nederst.
 *
 * 🚨 MAALT 11/8: Larnaes talte 9 virksomhedsrelationer hos Resights og saa 8
 * hos os. Det manglende (DANBOLIG HØRSHOLM, hvor han er traadt ud) var der
 * hele tiden — bag knappen "Vis også tidligere roller (1)" nederst i
 * sektionen. Han saa den ikke, og konkluderede at data manglede.
 *
 * 🔑 Standarden ER bevidst: gamle roller stoejer (Kristian 22/7), og den
 * beslutning omgoeres ikke her. Problemet er at overskriften "Company Roles"
 * ikke roeber at noget er filtreret fra — brugeren kan ikke vide at der er
 * mere at se, medmindre han laeser hele vejen ned.
 *
 * 🪤 Ikke et saertilfaelde: 207.649 personer har mindst én historisk rolle,
 * mod 65.509 uden. At skjule dem lydloest er altsaa normaltilstanden for
 * FLERTALLET af opslag.
 */
beforeEach(function () {
    $this->withoutVite();
    config()->set('metis.mode', 'standalone');
    config()->set('metis.gating.enabled', false);
    Http::preventStrayRequests();
});

function fakeRoller(array $companies): void
{
    Http::fake(['*' => Http::response(['data' => ['companies' => $companies]])]);
}

/** Ét aktivt selskab + ét personen er traadt ud af. */
function larnaesLignende(): array
{
    return [
        ['cvr' => '45170209', 'name' => 'Inova ApS', 'is_active' => true,
            'roles' => [['role' => 'ceo', 'role_label' => 'Direktør', 'is_current' => true]]],
        ['cvr' => '28479662', 'name' => 'DANBOLIG HØRSHOLM ApS', 'is_active' => false,
            'roles' => [['role' => 'board_member', 'role_label' => 'Bestyrelsesmedlem', 'is_current' => false]]],
    ];
}

it('🚨 roeber i overskriften at roller er filtreret fra', function () {
    fakeRoller(larnaesLignende());

    $html = Livewire::test('metis-person-roles', ['query' => 'Test Testesen'])->html();

    // Kernen: tallet skal staa dér hvor man laeser FOERST, ikke kun paa en
    // knap under tabellen. Ellers taeller brugeren forkert og tror vi mangler
    // data — praecis hvad der skete med Larnaes.
    expect($html)->toContain('1 tidligere');
});

it('🪤 skriver ikke om skjulte roller naar der ikke ER nogen', function () {
    // Modstykket: en person uden historik maa ikke faa en overskrift der
    // antyder at noget er gemt vaek.
    fakeRoller([
        ['cvr' => '45170209', 'name' => 'Inova ApS', 'is_active' => true,
            'roles' => [['role' => 'ceo', 'role_label' => 'Direktør', 'is_current' => true]]],
    ]);

    $html = Livewire::test('metis-person-roles', ['query' => 'Test Testesen'])->html();

    expect($html)->not->toContain('tidligere');
});

it('viser stadig det skjulte selskab naar man folder ud', function () {
    // Selve funktionen skal virke — overskriften er kun vejviseren.
    fakeRoller(larnaesLignende());

    Livewire::test('metis-person-roles', ['query' => 'Test Testesen'])
        ->assertDontSee('DANBOLIG')
        ->set('showAllRoles', true)
        ->assertSee('DANBOLIG');
});
