<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_sites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('public_key', 36)->unique();
            $table->string('verification_token', 72);
            $table->json('allowed_domains')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->unsignedSmallInteger('retention_days')->default(365);
            $table->boolean('is_enabled')->default(true);
            $table->boolean('respect_dnt')->default(true);
            $table->unsignedTinyInteger('sampling_rate')->default(100);
            $table->json('flags')->nullable();
            $table->timestamps();

            $table->index(['property_id', 'is_enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_sites');
    }
};
