<?php

namespace TheFountainhead\Metis\Services;

class SearchDetector
{
    /**
     * Suffixes that are safe for str_contains matching (unique enough to avoid false positives).
     */
    private const LEGAL_SUFFIXES = [
        'A/S', 'ApS', 'IVS', 'K/S', 'I/S', 'P/S', 'AmbA',
        'a/s', 'aps', 'ivs', 'k/s', 'i/s', 'p/s', 'amba',
        'A.M.B.A.', 'S/I', 's/i',
    ];

    /**
     * Patterns that require word-boundary matching to avoid false positives.
     * Merged from MetisInputDetector patterns.
     */
    private const COMPANY_WORD_PATTERN = '/\b(Holding|Invest|Fond|Fonden|Forening|Fund|FMBA|SMBA|SE|SCE)\b/i';

    /**
     * Er inputtet et CPR-nummer?
     *
     * 🚨 SAMLET HER 5/8. Regexen fandtes i FEM kopier: her, Search:127,
     * Search:263 (fjernet), Lookup (fjernet) og en fjerde jeg selv tilfoejede
     * foer jeg opdagede de andre. Hver kopi normaliserede forskelligt, saa
     * `123456 7890` slap forbi nogle af dem.
     *
     * Normaliseringen hoerer i detektoren, ikke hos hver kalder — ellers
     * gentager naeste kalder fejlen.
     */
    public function isCpr(string $input): bool
    {
        return preg_match('/^\d{6}-?\d{4}$/', preg_replace('/\s+/', '', $input)) === 1;
    }

    public function detect(string $input): string
    {
        $input = trim($input);

        // Mellemrums-formen (copy-paste fra Word/PDF, iOS-autokorrektur)
        // genkendes ogsaa — se isCpr().
        if ($this->isCpr($input)) {
            return 'cpr';
        }

        if (preg_match('/^\d{8}$/', $input)) {
            return 'cvr';
        }

        if (preg_match('/^[A-ZÆØÅa-zæøå\s]+\d+/u', $input)) {
            return 'address';
        }

        if ($this->looksLikeCompanyName($input)) {
            return 'company_name';
        }

        return 'name';
    }

    /**
     * Check if input looks like a company name.
     * Merged logic from Frankston-master's MetisInputDetector.
     */
    protected function looksLikeCompanyName(string $input): bool
    {
        // Check slash-based suffixes via str_contains (safe, no false positives)
        foreach (self::LEGAL_SUFFIXES as $suffix) {
            if (str_contains($input, $suffix)) {
                return true;
            }
        }

        // Check word-boundary patterns (Holding, Invest, Fond, Fund, SE, SCE, etc.)
        if (preg_match(self::COMPANY_WORD_PATTERN, $input)) {
            return true;
        }

        return false;
    }
}
