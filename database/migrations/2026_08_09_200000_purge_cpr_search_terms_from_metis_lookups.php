<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fjern personnumre fra soegehistorikken.
 *
 * 🚨 MAALT PAA PROD 9/8: seks raekker i `metis_lookups` med
 * `search_type = 'cpr'` baerer et personnummer i `search_term`.
 *
 *   unikke numre    1
 *   sessioner       2
 *   IP'er           2, begge Telenor mobilbredbaand (DK)
 *   tidsrum         27.-28. juli, 21 timer
 *
 * Frederik har bekraeftet 9/8 at nummeret er TESTDATA, ikke en rigtig person.
 * Derfor er sletningen tilstraekkelig; ingen underretningsvurdering noedvendig.
 *
 * 🔑 SLETTER IKKE RAEKKEN, KUN NUMMERET. `search_type='cpr'` og tidspunktet er
 * brugbar statistik ("hvor mange CPR-opslag foretages der?") og siger intet om
 * hvem. Kun `search_term` peger paa en person.
 *
 * 🪤 Guarden i `Lookup::mount()` skriver allerede ALDRIG et CPR til
 * historikken (REVIEW-FUND 5/8). De seks raekker er en rest fra FOER den guard
 * daekkede alle indgange — dengang lukkede kun `cvr`-doeren, og kun i smaa
 * bogstaver. Denne migration rydder resten op; koden forhindrer nye.
 *
 * Ikke reversibel: en `down()` der genskabte personnumre ville modarbejde sit
 * eget formaal.
 */
return new class extends Migration
{
    /**
     * 🪤 MASKERING, IKKE NULL. `search_term` er `NOT NULL` i skemaet (maalt paa
     * prod 9/8) — et forsoeg paa at nulstille den fejler med en
     * integritetsfejl. Kolonnen kunne goeres nullable, men maskering er
     * bedre: `NOT NULL`-garantien bevares, og vaerdien siger EKSPLICIT at
     * nummeret er fjernet bevidst frem for at mangle ved et uheld.
     */
    private const MASKE = '[fjernet: personnummer]';

    public function up(): void
    {
        $n = DB::table('metis_lookups')
            ->where('search_type', 'cpr')
            ->where('search_term', '!=', self::MASKE)
            ->update(['search_term' => self::MASKE]);

        // 🪤 Ogsaa de typer hvor et CPR kunne vaere sluppet ind FOER guarden
        // daekkede alle doere. Maalt 5/8: `/lookup/person/`, `/lookup/address/`
        // og `/lookup/name/` gemte alle et CPR uden at blive fanget.
        //
        // 🔑 DATOVALIDERING, IKKE BARE TI CIFRE. Et bart `\d{10}` rammer CVR-
        // og enhedsnumre, som er legitim soegehistorik. Maalt af den
        // sideloebende session 9/8: `^\d{6}-?\d{4}$` gav 12 traf, hvoraf 6 var
        // enhedsnumre der begyndte med "40" — en dag der ikke findes. Dag
        // 01-31, maaned 01-12 skiller dem ad.
        //
        // 🪤 Filtreringen sker i PHP, ikke i SQL: `~` er PostgreSQL-syntaks og
        // fejler under SQLite, som testene koerer paa. En migration der kun
        // virker paa ét af to udviklingsmiljoeer er ikke testbar — og en
        // utestbar oprydning af persondata er praecis den slags man opdager er
        // forkert bagefter.
        $kandidater = DB::table('metis_lookups')
            ->where('search_term', '!=', self::MASKE)
            ->select('id', 'search_term')
            ->get()
            ->filter(fn ($r) => preg_match(
                '/^(0[1-9]|[12][0-9]|3[01])(0[1-9]|1[0-2])[0-9]{2}-?[0-9]{4}$/',
                (string) $r->search_term
            ))
            ->pluck('id');

        $m = $kandidater->isEmpty() ? 0 : DB::table('metis_lookups')
            ->whereIn('id', $kandidater)
            ->update(['search_term' => self::MASKE]);

        // 🪤 Kun ANTALLET logges, aldrig termen. Migrations-output ender i
        // deploy-loggen, og et personnummer maa ikke skrives dertil.
        if ($n + $m > 0) {
            logger()->info('purge_cpr_search_terms', ['cpr_rows' => $n, 'pattern_rows' => $m]);
        }
    }

    public function down(): void
    {
        // Bevidst tom. At genskabe personnumre ville modarbejde formaalet.
    }
};
