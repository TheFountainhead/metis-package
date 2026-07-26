<?php

use TheFountainhead\Metis\Livewire\Sections\AddressSkraafoto;

/**
 * `AddressSkraafoto::wgs84ToUtm32()` is `protected` — this subclass exposes
 * it as `public` for direct unit testing without going through the full
 * Livewire mount()/Http-fake dance (already covered by
 * tests/Feature/Livewire/Sections/AddressSkraafotoTest.php for one point).
 */
class TestableAddressSkraafoto extends AddressSkraafoto
{
    public function wgs84ToUtm32(float $lat, float $lng): array
    {
        return parent::wgs84ToUtm32($lat, $lng);
    }
}

/**
 * PHP↔JS parity anchor. `resources/js/ownership-graph.js`'s
 * `_wgs84ToUtm32()` (host app, ownership-graph fase 2a.2) is a hand-ported
 * copy of this exact Transverse Mercator formula, used client-side to build
 * the Skråfoto-viewer link for a property node's enrichment card. The three
 * fixtures below are CROSS-VERIFIED: the same lat/lng were run through both
 * the PHP original and the JS port and produced identical output (see
 * task-5-report.md, fase 2a.2). They are duplicated as a comment directly
 * above `_wgs84ToUtm32` in ownership-graph.js.
 *
 * If someone changes the constants/rounding in wgs84ToUtm32() here without
 * updating the JS port to match, this test is the CI anchor that catches
 * the drift — it will start failing (or, worse, silently keep passing while
 * the JS falls out of lockstep, which is why the JS comment also points
 * back here). Treat any change to this method as requiring a matching JS
 * change, re-verified the same cross-language way, with both fixture
 * comments updated together.
 */
it('matches the PHP↔JS cross-verified UTM32 fixtures', function () {
    $subject = new TestableAddressSkraafoto();

    [$e, $n] = $subject->wgs84ToUtm32(55.6761, 12.5683);
    expect($e)->toEqualWithDelta(724351.93, 0.02);
    expect($n)->toEqualWithDelta(6175804.02, 0.02);

    [$e, $n] = $subject->wgs84ToUtm32(56.1629, 10.2039);
    expect($e)->toEqualWithDelta(574766.39, 0.02);
    expect($n)->toEqualWithDelta(6224862.65, 0.02);

    [$e, $n] = $subject->wgs84ToUtm32(55.4038, 10.4024);
    expect($e)->toEqualWithDelta(588803.14, 0.02);
    expect($n)->toEqualWithDelta(6140622.08, 0.02);
});
