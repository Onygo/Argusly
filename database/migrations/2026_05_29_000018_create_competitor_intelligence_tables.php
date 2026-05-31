<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competitors', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('website');
            $table->string('industry')->nullable();
            $table->string('status')->default('active')->index();
            $table->timestamps();

            $table->unique(['account_id', 'brand_id', 'website']);
            $table->index(['account_id', 'brand_id', 'status']);
        });

        Schema::create('competitor_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('competitor_id')->constrained()->cascadeOnDelete();
            $table->timestamp('captured_at')->index();
            $table->unsignedTinyInteger('visibility_score')->nullable();
            $table->unsignedTinyInteger('mention_score')->nullable();
            $table->unsignedTinyInteger('share_of_voice')->nullable();
            $table->json('metadata')->nullable();

            $table->index(['competitor_id', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competitor_snapshots');
        Schema::dropIfExists('competitors');
    }
};
