<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_transparency_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->uuid('workspace_id')->nullable();
            $table->uuid('content_id')->nullable();
            $table->uuid('draft_id')->nullable();
            $table->string('asset_type', 64)->default('content');
            $table->uuid('asset_id');
            $table->string('origin', 40)->default('unknown');
            $table->string('ai_badge', 64)->default('unknown');
            $table->string('disclosure_label', 191)->default('AI status unknown');
            $table->string('human_review_status', 40)->default('not_reviewed');
            $table->string('fact_check_status', 40)->default('unchecked');
            $table->unsignedTinyInteger('trust_score')->default(0);
            $table->string('metadata_standard', 64)->default('argusly-ai-metadata-v1');
            $table->string('content_hash', 191)->nullable();
            $table->timestamp('last_reviewed_at')->nullable();
            $table->timestamp('last_fact_checked_at')->nullable();
            $table->timestamp('metadata_exported_at')->nullable();
            $table->json('machine_metadata')->nullable();
            $table->json('score_breakdown')->nullable();
            $table->timestamps();

            $table->unique(['asset_type', 'asset_id'], 'ai_records_asset_unique');
            $table->index(['content_id', 'created_at'], 'ai_records_content_created_idx');
            $table->index(['workspace_id', 'human_review_status'], 'ai_records_workspace_review_idx');
            $table->index(['workspace_id', 'fact_check_status'], 'ai_records_workspace_fact_idx');
            $table->index(['workspace_id', 'trust_score'], 'ai_records_workspace_trust_idx');

            $table->foreign('workspace_id')->references('id')->on('workspaces')->nullOnDelete();
            $table->foreign('content_id')->references('id')->on('contents')->nullOnDelete();
            $table->foreign('draft_id')->references('id')->on('drafts')->nullOnDelete();
        });

        Schema::create('ai_provenance_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('ai_transparency_record_id');
            $table->string('event_type', 64);
            $table->string('actor_type', 40)->default('system');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_label', 191)->nullable();
            $table->text('summary')->nullable();
            $table->string('input_hash', 191)->nullable();
            $table->string('output_hash', 191)->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['ai_transparency_record_id', 'occurred_at'], 'ai_events_record_occurred_idx');
            $table->index(['event_type', 'occurred_at'], 'ai_events_type_occurred_idx');

            $table->foreign('ai_transparency_record_id', 'ai_events_record_fk')
                ->references('id')
                ->on('ai_transparency_records')
                ->cascadeOnDelete();
        });

        Schema::create('ai_model_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('ai_transparency_record_id');
            $table->uuid('draft_id')->nullable();
            $table->string('provider', 80)->nullable();
            $table->string('model', 120);
            $table->string('model_version', 120)->nullable();
            $table->string('run_id', 191)->nullable();
            $table->json('settings')->nullable();
            $table->json('usage')->nullable();
            $table->string('input_hash', 191)->nullable();
            $table->string('output_hash', 191)->nullable();
            $table->timestamp('ran_at')->nullable();
            $table->timestamps();

            $table->index(['ai_transparency_record_id', 'ran_at'], 'ai_model_runs_record_ran_idx');
            $table->index(['provider', 'model'], 'ai_model_runs_provider_model_idx');

            $table->foreign('ai_transparency_record_id', 'ai_model_runs_record_fk')
                ->references('id')
                ->on('ai_transparency_records')
                ->cascadeOnDelete();
            $table->foreign('draft_id')->references('id')->on('drafts')->nullOnDelete();
        });

        Schema::create('ai_prompt_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('ai_transparency_record_id');
            $table->uuid('ai_model_run_id')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->string('prompt_type', 64)->default('generation');
            $table->longText('prompt_text')->nullable();
            $table->text('redacted_prompt_summary')->nullable();
            $table->string('prompt_hash', 191)->nullable();
            $table->boolean('contains_redactions')->default(false);
            $table->timestamp('captured_at')->nullable();
            $table->timestamps();

            $table->index(['ai_transparency_record_id', 'version'], 'ai_prompts_record_version_idx');

            $table->foreign('ai_transparency_record_id', 'ai_prompts_record_fk')
                ->references('id')
                ->on('ai_transparency_records')
                ->cascadeOnDelete();
            $table->foreign('ai_model_run_id', 'ai_prompts_model_run_fk')
                ->references('id')
                ->on('ai_model_runs')
                ->nullOnDelete();
        });

        Schema::create('ai_source_traces', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('ai_transparency_record_id');
            $table->string('source_type', 64)->default('url');
            $table->text('url')->nullable();
            $table->string('title', 500)->nullable();
            $table->string('retrieval_status', 40)->default('unknown');
            $table->timestamp('retrieved_at')->nullable();
            $table->string('content_hash', 191)->nullable();
            $table->unsignedTinyInteger('reliability_score')->nullable();
            $table->json('used_for_sections')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['ai_transparency_record_id', 'created_at'], 'ai_sources_record_created_idx');

            $table->foreign('ai_transparency_record_id', 'ai_sources_record_fk')
                ->references('id')
                ->on('ai_transparency_records')
                ->cascadeOnDelete();
        });

        Schema::create('ai_fact_checks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('ai_transparency_record_id');
            $table->text('claim');
            $table->string('status', 40)->default('unchecked');
            $table->unsignedTinyInteger('confidence')->nullable();
            $table->json('evidence')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['ai_transparency_record_id', 'status'], 'ai_fact_checks_record_status_idx');

            $table->foreign('ai_transparency_record_id', 'ai_fact_checks_record_fk')
                ->references('id')
                ->on('ai_transparency_records')
                ->cascadeOnDelete();
        });

        Schema::create('ai_human_reviews', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('ai_transparency_record_id');
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 40)->default('reviewed');
            $table->json('checklist')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['ai_transparency_record_id', 'reviewed_at'], 'ai_reviews_record_reviewed_idx');

            $table->foreign('ai_transparency_record_id', 'ai_reviews_record_fk')
                ->references('id')
                ->on('ai_transparency_records')
                ->cascadeOnDelete();
        });

        Schema::create('ai_audit_reports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('ai_transparency_record_id');
            $table->string('format', 20)->default('pdf');
            $table->string('status', 40)->default('generated');
            $table->string('path')->nullable();
            $table->string('checksum', 191)->nullable();
            $table->json('snapshot')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->index(['ai_transparency_record_id', 'generated_at'], 'ai_reports_record_generated_idx');

            $table->foreign('ai_transparency_record_id', 'ai_reports_record_fk')
                ->references('id')
                ->on('ai_transparency_records')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_audit_reports');
        Schema::dropIfExists('ai_human_reviews');
        Schema::dropIfExists('ai_fact_checks');
        Schema::dropIfExists('ai_source_traces');
        Schema::dropIfExists('ai_prompt_versions');
        Schema::dropIfExists('ai_model_runs');
        Schema::dropIfExists('ai_provenance_events');
        Schema::dropIfExists('ai_transparency_records');
    }
};
