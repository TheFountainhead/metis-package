<?php

namespace TheFountainhead\Metis\Livewire\Concerns;

use TheFountainhead\Metis\Services\LookupAccess;

/**
 * Alerts og watchlists: hvem maa se dem, og hvilken form har de.
 *
 * 🚨 ADGANG (opfoelgning paa PR #192): kun en BEKRAEFTET pilot
 * (`LookupAccess::erPilot()`). Datalaget (`RegistryApi::pilotFejl()`) afviser
 * i forvejen; gaten her goer at siden viser en tom tilstand eller en
 * login-besked frem for en fejl, og at metoderne ikke engang spoerger.
 *
 * 🐛 FORM (opfoelgning 2): `/alerts` og `/alerts/{id}` gav 500, naar
 * registry-api svarede med en anden form end bladen forventede (en raekke
 * uden `is_read`, `data` som streng, et array hvor bladen ventede tekst).
 * Normaliseret HER, ved kilden, saa bladene kun ser den form de kan rendere.
 * Raekker uden et brugbart `id` droppes: de kan hverken linkes eller
 * markeres som laest.
 */
trait NormalisererAlerts
{
    public function harPilotAdgang(): bool
    {
        $adgang = app(LookupAccess::class);

        return ! $adgang->gatingAktiv() || $adgang->erPilot();
    }

    /** @return array{data: list<array>, current_page: int, last_page: int} */
    protected static function normaliserAlertSvar(mixed $svar): array
    {
        $svar = is_array($svar) ? $svar : [];
        $raekker = is_array($svar['data'] ?? null) ? $svar['data'] : [];

        return [
            'data' => array_values(array_filter(array_map(self::normaliserAlert(...), $raekker))),
            'current_page' => max(1, is_numeric($svar['current_page'] ?? null) ? (int) $svar['current_page'] : 1),
            'last_page' => max(1, is_numeric($svar['last_page'] ?? null) ? (int) $svar['last_page'] : 1),
        ];
    }

    protected static function normaliserAlert(mixed $alert): ?array
    {
        if (! is_array($alert) || ! is_numeric($alert['id'] ?? null)) {
            return null;
        }

        $watchlist = is_array($alert['watchlist'] ?? null) ? $alert['watchlist'] : [];
        $priority = self::tekst($alert['priority'] ?? null);
        $oprettet = self::tekst($alert['created_at'] ?? null);

        return [
            'id' => (int) $alert['id'],
            'title' => self::tekst($alert['title'] ?? null) ?? '',
            'description' => self::tekst($alert['description'] ?? null) ?? '',
            'is_read' => (bool) filter_var($alert['is_read'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'priority' => in_array($priority, ['high', 'medium', 'low'], true) ? $priority : 'low',
            'created_at' => $oprettet !== null && strtotime($oprettet) !== false ? $oprettet : null,
            'watchlist' => [
                'watch_type' => self::tekst($watchlist['watch_type'] ?? null) ?? '',
                'watch_value' => self::tekst($watchlist['watch_value'] ?? null),
                'display_label' => self::tekst($watchlist['display_label'] ?? null),
            ],
            'metadata' => self::normaliserMetadata($alert['metadata'] ?? null),
        ];
    }

    /** @return list<array{id: int, watch_type: string, watch_value: ?string, display_label: ?string}> */
    protected static function normaliserWatchlists(mixed $svar): array
    {
        $raekker = is_array($svar) && is_array($svar['data'] ?? null) ? $svar['data'] : [];

        return array_values(array_filter(array_map(function ($w) {
            if (! is_array($w) || ! is_numeric($w['id'] ?? null)) {
                return null;
            }

            return [
                'id' => (int) $w['id'],
                'watch_type' => self::tekst($w['watch_type'] ?? null) ?? '',
                'watch_value' => self::tekst($w['watch_value'] ?? null),
                'display_label' => self::tekst($w['display_label'] ?? null),
            ];
        }, $raekker)));
    }

    /**
     * Metadata kommer fra ekstern JSON (array eller JSON-streng). Kun skalarer
     * overlever, undtagen `before`/`after`, som skal vaere objekter med
     * skalare felter. Beloeb og rente skal vaere tal: bladen regner paa dem.
     */
    protected static function normaliserMetadata(mixed $meta): array
    {
        if (is_string($meta)) {
            $meta = json_decode($meta, true);
        }

        if (! is_array($meta)) {
            return [];
        }

        $ud = [];

        foreach ($meta as $noegle => $vaerdi) {
            if (in_array($noegle, ['before', 'after'], true)) {
                if (is_array($vaerdi)) {
                    $ud[$noegle] = self::kunSkalarer($vaerdi, ['principal_amount', 'interest_rate']);
                }

                continue;
            }

            if (is_scalar($vaerdi) || $vaerdi === null) {
                $ud[$noegle] = $vaerdi;
            }
        }

        return self::kunSkalarer($ud, ['principal_amount_kr', 'interest_rate'], behold: ['before', 'after']);
    }

    /** Fjern ikke-skalarer, og fjern talfelter der ikke er tal. */
    private static function kunSkalarer(array $felter, array $talfelter, array $behold = []): array
    {
        return array_filter($felter, function ($vaerdi, $noegle) use ($talfelter, $behold) {
            if (in_array($noegle, $behold, true)) {
                return true;
            }

            if (in_array($noegle, $talfelter, true)) {
                return is_numeric($vaerdi);
            }

            return is_scalar($vaerdi) || $vaerdi === null;
        }, ARRAY_FILTER_USE_BOTH);
    }

    private static function tekst(mixed $vaerdi): ?string
    {
        return is_scalar($vaerdi) ? (string) $vaerdi : null;
    }
}
