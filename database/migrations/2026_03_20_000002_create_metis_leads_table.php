<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metis_leads', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('cvr', 8)->nullable()->index();
            $table->string('company_name')->nullable();
            $table->string('industry')->nullable();
            $table->string('domain')->nullable();
            $table->string('first_search_type', 50)->nullable();
            $table->string('first_search_term', 500)->nullable();
            $table->integer('lookup_count')->default(0);
            $table->timestamp('last_active_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metis_leads');
    }
};
