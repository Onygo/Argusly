<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('draft_improvement_results', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('draft_id')->index();
            $table->uuid('before_analysis_id')->nullable()->index();
            $table->uuid('after_analysis_id')->nullable()->index();
            $table->string('action', 50);
            $table->string('status', 20)->default('queued')->index();
            $table->string('operation_key', 80)->index();
            $table->uuid('requested_by_user_id')->nullable()->index();
            $table->string('prompt_version', 80)->nullable();
            $table->string('provider', 50)->nullable();
            $table->string('model_used', 120)->nullable();
            $table->string('request_id', 120)->nullable();
            $table->unsignedInteger('tokens_used')->default(0);
            $table->string('before_content_hash', 64)->nullable();
            $table->string('after_content_hash', 64)->nullable();
            $table->json('affected_sections')->nullable();
            $table->text('summary')->nullable();
            $table->json('change_notes')->nullable();
            $table->boolean('fully_applied')->default(false);
            $table->json('score_delta_snapshot')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->foreign('draft_id')->references('id')->on('drafts')->cascadeOnDelete();
            $table->foreign('before_analysis_id')->references('id')->on('draft_analyses')->nullOnDelete();
            $table->foreign('after_analysis_id')->references('id')->on('draft_analyses')->nullOnDelete();
            $table->unique(['draft_id', 'operation_key'], 'draft_improvement_results_draft_operation_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('draft_improvement_results');
    }
};
