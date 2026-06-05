<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('draft_intelligence_deltas', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('draft_id')->index();
            $table->uuid('draft_improvement_result_id')->nullable()->index();
            $table->uuid('before_analysis_id')->nullable()->index();
            $table->uuid('after_analysis_id')->nullable()->index();
            $table->string('metric_key', 40)->index();
            $table->unsignedTinyInteger('score_before')->nullable();
            $table->unsignedTinyInteger('score_after')->nullable();
            $table->smallInteger('delta')->default(0);
            $table->text('explanation')->nullable();
            $table->string('confidence_level', 20)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('draft_id')->references('id')->on('drafts')->cascadeOnDelete();
            $table->foreign('draft_improvement_result_id')->references('id')->on('draft_improvement_results')->cascadeOnDelete();
            $table->foreign('before_analysis_id')->references('id')->on('draft_analyses')->nullOnDelete();
            $table->foreign('after_analysis_id')->references('id')->on('draft_analyses')->nullOnDelete();
            $table->unique(['draft_improvement_result_id', 'metric_key'], 'draft_intelligence_deltas_result_metric_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('draft_intelligence_deltas');
    }
};
