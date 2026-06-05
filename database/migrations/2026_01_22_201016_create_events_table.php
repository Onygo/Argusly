<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_site_id')->index();
            $table->string('type');
            $table->timestamp('occurred_at');
            $table->json('data')->nullable();
            $table->timestamps();

            $table->foreign('client_site_id')->references('id')->on('client_sites')->cascadeOnDelete();
        });
    }

    public function down(): void {
        Schema::dropIfExists('events');
    }
};
