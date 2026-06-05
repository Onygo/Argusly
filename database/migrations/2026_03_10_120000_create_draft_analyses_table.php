<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('draft_analyses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('draft_id')->index();
            $table->unsignedTinyInteger('seo_score')->nullable();
            $table->unsignedTinyInteger('readability_score')->nullable();
            $table->unsignedTinyInteger('cta_score')->nullable();
            $table->unsignedTinyInteger('keyword_coverage')->nullable();
            $table->unsignedTinyInteger('entity_coverage')->nullable();
            $table->json('internal_link_opportunities')->nullable();
            $table->json('suggestions')->nullable();
            $table->string('analysis_model', 190)->nullable();
            $table->unsignedInteger('tokens_used')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('draft_id')->references('id')->on('drafts')->cascadeOnDelete();
            $table->index(['draft_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('draft_analyses');
    }
};
