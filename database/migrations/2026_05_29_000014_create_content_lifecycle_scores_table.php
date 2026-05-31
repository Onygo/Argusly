<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('content_lifecycle_scores', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('content_asset_id')->constrained()->cascadeOnDelete();
            $table->string('status')->index();
            $table->unsignedTinyInteger('health_score')->nullable();
            $table->unsignedTinyInteger('freshness_score')->nullable();
            $table->unsignedTinyInteger('performance_score')->nullable();
            $table->unsignedTinyInteger('visibility_score')->nullable();
            $table->unsignedTinyInteger('refresh_priority')->nullable();
            $table->text('reason')->nullable();
            $table->json('signals')->nullable();
            $table->timestamp('scored_at')->index();
            $table->timestamps();

            $table->index(['account_id', 'brand_id', 'status', 'scored_at'], 'lifecycle_scope_status_scored_idx');
            $table->index(['content_asset_id', 'scored_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('content_lifecycle_scores');
    }
};
