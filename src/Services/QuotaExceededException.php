<?php

namespace TheFountainhead\Metis\Services;

use RuntimeException;

/**
 * Kastes af `RegistryApi::client()` naar sessionens gratis opslag er brugt op.
 *
 * 🔑 EN EXCEPTION, IKKE ET TOMT SVAR. Kaldsstederne bruger allerede `rescue()`
 * omkring deres API-kald (fejlhaandtering der var der i forvejen), saa en
 * exception giver en tom sektion uden at nogen skal skrive ny kode. Et
 * `null`-svar ville derimod se ud som "ingen data fundet" — en falsk paastand
 * om fravaer, hvilket er den vaerste fejlmodus i due diligence: brugeren tror
 * ejendommen er gaeldfri, hvor sandheden er at vi ikke ville vise det.
 *
 * 🪤 Rammer ALDRIG baggrundsjob. `kvoteOpbrugt()` returnerer `false` naar der
 * ingen session er, saa kommandoer og koe-jobs gaar upaavirket igennem.
 */
class QuotaExceededException extends RuntimeException
{
    protected $message = 'Metis: sessionens gratis opslag er brugt op.';
}
