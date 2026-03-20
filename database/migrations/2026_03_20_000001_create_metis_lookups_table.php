<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metis_lookups', function (Blueprint $table) {
            $table->id();
            $table->string('session_id')->index();
            $table->string('email')->nullable()->index();
            $table->string('search_type', 50);
            $table->string('search_term', 500);
            $table->string('ip_address', 45);
            $table->boolean('is_cross_reference')->default(false);
            $table->timestamps();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metis_lookups');
    }
};
