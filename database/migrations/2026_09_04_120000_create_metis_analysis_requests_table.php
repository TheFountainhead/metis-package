<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bestilte analyser. Spørgsmål på tværs af registret ("alle erhvervsejendomme i
 * 2100 med rente over 10 %") besvares ikke længere live: de bestilles, vurderes
 * på formål (tinglysningslovens § 50 c) og prissættes fra gang til gang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metis_analysis_requests', function (Blueprint $table) {
            $table->id();
            $table->string('email')->index();
            $table->string('name')->nullable();
            $table->string('company_name')->nullable();
            $table->text('question');
            $table->string('area', 200)->nullable();
            $table->string('purpose', 40);
            $table->string('phone', 40)->nullable();
            $table->string('status', 20)->default('new')->index();
            $table->text('notes')->nullable();
            $table->timestamp('handled_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metis_analysis_requests');
    }
};
