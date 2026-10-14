<?php

use Illuminate\Support\Facades\Http;

/**
 * 🚨 EN HALV DATO MAA ALDRIG FJERNE DATOFILTERET (13/10-2026).
 *
 * En halvt udfyldt dato (fx dd.03.2021) giver i browseren value="" med
 * `validity.badInput` og fyrer input+change. Livewire fik "", fjernede
 * filteret, og tallet sprang til alle selskaber mens feltet viste "03.2021".
 *
 * 🔑 Kun en rigtig browser kan se det: `Livewire::test()` kender hverken
 * badInput eller event-raekkefoelgen. Registret svarer et tal pr. filter-
 * kombination, saa tallet paa skaermen viser direkte, hvad serveren regnede
 * med: 749.027 uden dato, 43.093 med stiftet-fra, 12.345 med aarsvaerk.
 *
 * 🪤 Koerer IKKE i plain `pest` (phpunit.xml kender kun Unit/Feature), men i
 * sit eget CI-trin: `pest tests/Browser`. Hver handling ventes ud paa en
 * tekst (`waitForText`/`assertSee` poller), ikke en fast pause.
 */
beforeEach(function () {
    $this->withoutVite();
    config()->set('metis.mode', 'standalone');
    config()->set('metis.registry_api.url', 'https://registry.test');
    config()->set('metis.registry_api.key', 'test-noegle');
    config()->set('metis.gating.enabled', false);
    Http::fake([
        '*/company-segmentation' => fn ($r) => Http::response([
            'data' => [],
            'meta' => ['total' => match (true) {
                filled($r['fte_min'] ?? null) => 12345,
                filled($r['founded_from'] ?? null) => 43093,
                default => 749027,
            }],
        ]),
    ]);
});

function halvDatoFelt($side, string $felt): array
{
    return $side->script(<<<JS
        (() => {
            const f = document.querySelector('input[data-felt="{$felt}"]');
            return {
                value: f.value,
                halv: f.validity.badInput,
                server: Livewire.first().{$felt},
                roed: f.classList.contains('!border-red-500'),
                hint: document.querySelector('[data-halv-dato-hint]')?.textContent ?? null,
            };
        })()
    JS);
}

it('beholder den hele dato og tallet, naar datoen bliver halv', function () {
    $fra = 'input[data-felt="foundedFrom"]';
    $side = visit('/segmentering')->withLocale('da-DK');
    $side->fill($fra, '2021-03-15')->assertSee('43.093');

    $side->keys($fra, 'Backspace')->click('Fordel på')->waitForText('Tallet bruger 15.03.2021');

    expect(halvDatoFelt($side, 'foundedFrom'))->toMatchArray([
        'halv' => true, 'server' => '2021-03-15', 'roed' => true,
        'hint' => 'Stiftet fra er ikke udfyldt helt. Tallet bruger 15.03.2021.',
    ]);
    $side->assertSee('43.093')->assertDontSee('749.027');
});

it('fjerner filteret, naar en halv dato slettes helt (browseren fyrer intet event)', function () {
    $fra = 'input[data-felt="foundedFrom"]';
    $side = visit('/segmentering')->withLocale('da-DK');
    $side->fill($fra, '2021-03-15')->assertSee('43.093');

    $side->keys($fra, ['Backspace', 'ArrowRight', 'Backspace', 'ArrowRight', 'Backspace'])
        ->click('Fordel på')->assertSee('749.027');

    expect(halvDatoFelt($side, 'foundedFrom'))->toMatchArray([
        'value' => '', 'halv' => false, 'server' => '', 'roed' => false, 'hint' => null,
    ]);
});

it('siger at der taelles uden dato, naar en halv dato tastes i et tomt felt', function () {
    $fra = 'input[data-felt="foundedFrom"]';
    $side = visit('/segmentering')->withLocale('da-DK');
    $side->assertSee('749.027');

    $side->keys($fra, ['1', '5'])->click('Fordel på')->waitForText('Tallet tæller uden denne dato');

    expect(halvDatoFelt($side, 'foundedFrom'))->toMatchArray([
        'halv' => true, 'server' => '', 'roed' => true,
        'hint' => 'Stiftet fra er ikke udfyldt helt. Tallet tæller uden denne dato.',
    ]);
});

it('beholder roed kant og hint, naar et andet filter aendres (morph)', function () {
    $til = 'input[data-felt="foundedTo"]';
    $side = visit('/segmentering')->withLocale('da-DK');
    $side->fill($til, '2024-12-31')->keys($til, 'Backspace')->click('Fordel på')
        ->waitForText('Tallet bruger 31.12.2024');

    $side->fill('input[aria-label="Mindste antal årsværk"]', '5')->click('Fordel på')->assertSee('12.345');

    expect(halvDatoFelt($side, 'foundedTo'))->toMatchArray([
        'halv' => true, 'server' => '2024-12-31', 'roed' => true,
        'hint' => 'Stiftet til er ikke udfyldt helt. Tallet bruger 31.12.2024.',
    ]);
});

it('fjerner hintet, naar serveren skriver en hel dato i et halvt felt', function () {
    $fra = 'input[data-felt="foundedFrom"]';
    $side = visit('/segmentering')->withLocale('da-DK');
    $side->keys($fra, ['1', '5'])->click('Fordel på')->waitForText('Tallet tæller uden denne dato');

    // Ivaerksaetter foreslaar "Stiftet fra", naar feltet er tomt paa serveren.
    $side->click('input[wire\:model\.live="ivaerksaetter"]')->assertSee('43.093');

    $felt = halvDatoFelt($side, 'foundedFrom');
    expect($felt)->toMatchArray(['halv' => false, 'roed' => false, 'hint' => null])
        ->and($felt['server'])->not->toBe('');
});

it('Nulstil tømmer et halvt felt helt', function () {
    $fra = 'input[data-felt="foundedFrom"]';
    $side = visit('/segmentering')->withLocale('da-DK');
    $side->fill($fra, '2021-03-15')->keys($fra, 'Backspace')->click('Fordel på')
        ->waitForText('Tallet bruger 15.03.2021');

    $side->click('Nulstil filtre')->assertSee('749.027');

    expect(halvDatoFelt($side, 'foundedFrom'))->toMatchArray([
        'value' => '', 'halv' => false, 'server' => '', 'roed' => false, 'hint' => null,
    ]);
});

it('stopper ogsaa en halv "Stiftet til"', function () {
    $til = 'input[data-felt="foundedTo"]';
    $side = visit('/segmentering')->withLocale('da-DK');
    $side->fill($til, '2024-12-31')->keys($til, 'Backspace')->click('Fordel på')
        ->waitForText('Tallet bruger 31.12.2024');

    expect(halvDatoFelt($side, 'foundedTo'))->toMatchArray(['halv' => true, 'server' => '2024-12-31']);
});
