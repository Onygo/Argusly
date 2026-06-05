<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_sites', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_site_id')->index();
            $table->string('public_key', 32)->unique();
            $table->string('verification_token', 64);
            $table->json('allowed_domains')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->unsignedSmallInteger('retention_days')->default(365);
            $table->boolean('is_enabled')->default(true);
            $table->boolean('respect_dnt')->default(true);
            $table->unsignedTinyInteger('sampling_rate')->default(100);
            $table->json('flags')->nullable();
            $table->timestamps();

            $table->foreign('client_site_id')
                ->references('id')
                ->on('client_sites')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_sites');
    }
};
