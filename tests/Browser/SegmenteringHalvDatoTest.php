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
 * badInput eller event-raekkefoelgen. Registret svarer 43.093 MED et
 * stiftet-fra-filter og 749.027 UDEN, saa tallet paa skaermen viser direkte,
 * hvilket filter serveren regnede med.
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
            'meta' => ['total' => filled($r['founded_from'] ?? null) ? 43093 : 749027],
        ]),
    ]);
});

const FRA = 'input[data-felt="foundedFrom"]';
const TIL = 'input[data-felt="foundedTo"]';

function feltTilstand($side, string $felt): array
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
    $side = visit('/segmentering')->withLocale('da-DK');
    $side->fill(FRA, '2021-03-15')->assertSee('43.093');

    $side->keys(FRA, 'Backspace')->click('Fordel på')->wait(0.5);

    expect(feltTilstand($side, 'foundedFrom'))->toMatchArray([
        'halv' => true, 'server' => '2021-03-15', 'roed' => true,
        'hint' => 'Stiftet fra er ikke udfyldt helt. Tallet bruger 15.03.2021.',
    ]);
    $side->assertSee('43.093')->assertDontSee('749.027');
});

it('fjerner filteret, naar en halv dato slettes helt (browseren fyrer intet event)', function () {
    $side = visit('/segmentering')->withLocale('da-DK');
    $side->fill(FRA, '2021-03-15')->assertSee('43.093');

    $side->keys(FRA, ['Backspace', 'ArrowRight', 'Backspace', 'ArrowRight', 'Backspace'])
        ->click('Fordel på')->wait(0.5);

    expect(feltTilstand($side, 'foundedFrom'))->toMatchArray([
        'value' => '', 'halv' => false, 'server' => '', 'roed' => false, 'hint' => null,
    ]);
    $side->assertSee('749.027');
});

it('siger at der taelles uden dato, naar en halv dato tastes i et tomt felt', function () {
    $side = visit('/segmentering')->withLocale('da-DK');
    $side->assertSee('749.027');

    $side->keys(FRA, ['1', '5'])->click('Fordel på')->wait(0.5);

    expect(feltTilstand($side, 'foundedFrom'))->toMatchArray([
        'halv' => true, 'server' => '', 'roed' => true,
        'hint' => 'Stiftet fra er ikke udfyldt helt. Tallet tæller uden denne dato.',
    ]);
});

it('beholder roed kant og hint, naar et andet filter aendres (morph)', function () {
    $side = visit('/segmentering')->withLocale('da-DK');
    $side->fill(TIL, '2024-12-31')->keys(TIL, 'Backspace')->click('Fordel på')->wait(0.5);

    $side->fill('input[aria-label="Mindste antal årsværk"]', '5')->click('Fordel på')->wait(0.5);

    expect(feltTilstand($side, 'foundedTo'))->toMatchArray([
        'halv' => true, 'server' => '2024-12-31', 'roed' => true,
        'hint' => 'Stiftet til er ikke udfyldt helt. Tallet bruger 31.12.2024.',
    ]);
});

it('fjerner hintet, naar serveren skriver en hel dato i et halvt felt', function () {
    $side = visit('/segmentering')->withLocale('da-DK');
    $side->keys(FRA, ['1', '5'])->click('Fordel på')->wait(0.5);
    expect(feltTilstand($side, 'foundedFrom')['roed'])->toBeTrue();

    // Ivaerksaetter foreslaar "Stiftet fra" naar feltet er tomt paa serveren.
    $side->click('input[wire\:model\.live="ivaerksaetter"]')->wait(0.5);

    expect(feltTilstand($side, 'foundedFrom'))->toMatchArray(['halv' => false, 'roed' => false, 'hint' => null])
        ->and(feltTilstand($side, 'foundedFrom')['server'])->not->toBe('');
});

it('Nulstil tømmer et halvt felt helt', function () {
    $side = visit('/segmentering')->withLocale('da-DK');
    $side->fill(FRA, '2021-03-15')->keys(FRA, 'Backspace')->click('Fordel på')->wait(0.5);

    $side->click('Nulstil filtre')->wait(0.5);

    expect(feltTilstand($side, 'foundedFrom'))->toMatchArray([
        'value' => '', 'halv' => false, 'server' => '', 'roed' => false, 'hint' => null,
    ]);
    $side->assertSee('749.027');
});

it('stopper ogsaa en halv "Stiftet til"', function () {
    $side = visit('/segmentering')->withLocale('da-DK');
    $side->fill(TIL, '2024-12-31')->keys(TIL, 'Backspace')->click('Fordel på')->wait(0.5);

    expect(feltTilstand($side, 'foundedTo'))->toMatchArray(['halv' => true, 'server' => '2024-12-31']);
});
