<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('draft_recommendations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('draft_id')->index();
            $table->uuid('draft_analysis_id')->index();
            $table->string('metric_key', 40)->index();
            $table->string('title', 190);
            $table->text('summary');
            $table->text('why_it_matters');
            $table->text('suggested_action');
            $table->string('impact_level', 20);
            $table->string('effort_level', 20);
            $table->string('confidence_level', 20);
            $table->unsignedInteger('priority_score')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('context_payload')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('draft_id')->references('id')->on('drafts')->cascadeOnDelete();
            $table->foreign('draft_analysis_id')->references('id')->on('draft_analyses')->cascadeOnDelete();
            $table->index(['draft_analysis_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('draft_recommendations');
    }
};
