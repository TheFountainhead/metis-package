<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pilotkonti: mail + kodeord i stedet for token og engangskoder. Kontoen bærer
 * registry-api-tokenet (krypteret), så piloten aldrig ser det. Oprettes med
 * `metis:pilot-account`; kodeordet sendes manuelt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metis_pilot_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('name')->nullable();
            $table->string('password');
            $table->text('registry_token');
            $table->string('remember_token', 100)->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metis_pilot_accounts');
    }
};
