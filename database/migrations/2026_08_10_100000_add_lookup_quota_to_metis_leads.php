<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kvote pr. testbruger — Frederiks model 10/8-2026.
 *
 * 🔑 IDÉEN: en verificeret bruger faar et begraenset antal opslag. Loeber de
 * toer, kan brugeren TRYKKE PAA EN KNAP der sender Frederik en besked, og han
 * aabner for flere. Saa ser han hvem der rent faktisk bruger Metis, foer der
 * traeffes beslutning om betaling.
 *
 * 🪤 KVOTEN LIGGER PAA LEAD'ET, ikke i config. En faelles graense i config
 * kunne kun haeves for ALLE — og pointen er netop at give adgang til den
 * enkelte. `lookup_quota` er derfor pr. email og kan saettes individuelt.
 *
 * 🪤 `quota_requested_at` er et TIDSSTEMPEL, ikke et boolean-flag. Et flag
 * kunne kun besvare "har de spurgt?" — tidsstemplet besvarer ogsaa "hvornaar"
 * og "hvor laenge har de ventet", og lader UI'et vise "anmodning sendt" uden
 * at gaette. Samme grund som `cvr_synced_at` frem for `cvr_synced`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('metis_leads', function (Blueprint $table) {
            // 🪤 Default 5, ikke 0: en nyverificeret bruger skal kunne bruge
            // produktet med det samme. Rammer de muren straks efter at have
            // givet deres mail, foeles gaten som et afslag frem for en proeve.
            $table->integer('lookup_quota')->default(5)->after('lookup_count');

            $table->timestamp('quota_requested_at')->nullable()->after('lookup_quota');
        });
    }

    public function down(): void
    {
        Schema::table('metis_leads', function (Blueprint $table) {
            $table->dropColumn(['lookup_quota', 'quota_requested_at']);
        });
    }
};
