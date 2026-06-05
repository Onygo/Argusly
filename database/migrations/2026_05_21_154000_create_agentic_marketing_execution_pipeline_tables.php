<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agentic_marketing_execution_pipelines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('objective_id');
            $table->uuid('opportunity_id');
            $table->uuid('run_id')->nullable();
            $table->string('mode', 40)->default('manual');
            $table->string('status', 40)->default('queued')->index();
            $table->string('current_stage', 80)->default('queued');
            $table->string('approval_status', 40)->default('pending')->index();
            $table->string('publishing_readiness', 40)->default('not_ready')->index();
            $table->unsignedInteger('assets_count')->default(0);
            $table->unsignedInteger('pending_approvals_count')->default(0);
            $table->json('input')->nullable();
            $table->json('result')->nullable();
            $table->json('rollback_snapshot')->nullable();
            $table->text('failure_reason')->nullable();
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->foreign('objective_id')->references('id')->on('agentic_marketing_objectives')->cascadeOnDelete();
            $table->foreign('opportunity_id')->references('id')->on('agentic_marketing_opportunities')->cascadeOnDelete();
            $table->foreign('run_id')->references('id')->on('agentic_marketing_runs')->nullOnDelete();
            $table->foreign('requested_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['objective_id', 'status'], 'am_execution_objective_status_idx');
            $table->index(['opportunity_id', 'created_at'], 'am_execution_opportunity_created_idx');
        });

        Schema::create('agentic_marketing_execution_assets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('pipeline_id');
            $table->uuid('objective_id');
            $table->uuid('opportunity_id');
            $table->string('type', 80);
            $table->string('status', 40)->default('generated')->index();
            $table->string('title', 255);
            $table->json('payload')->nullable();
            $table->nullableUuidMorphs('assetable', 'am_execution_assets_assetable_idx');
            $table->boolean('requires_approval')->default(true);
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();

            $table->foreign('pipeline_id')->references('id')->on('agentic_marketing_execution_pipelines')->cascadeOnDelete();
            $table->foreign('objective_id')->references('id')->on('agentic_marketing_objectives')->cascadeOnDelete();
            $table->foreign('opportunity_id')->references('id')->on('agentic_marketing_opportunities')->cascadeOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['pipeline_id', 'type', 'status'], 'am_execution_assets_pipeline_type_idx');
        });

        Schema::create('agentic_marketing_execution_approvals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('pipeline_id');
            $table->uuid('asset_id')->nullable();
            $table->string('status', 40)->default('pending')->index();
            $table->string('approval_type', 80)->default('editorial_review');
            $table->string('requested_role', 80)->default('editor');
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->text('feedback')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->foreign('pipeline_id')->references('id')->on('agentic_marketing_execution_pipelines')->cascadeOnDelete();
            $table->foreign('asset_id')->references('id')->on('agentic_marketing_execution_assets')->cascadeOnDelete();
            $table->foreign('requested_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('agentic_marketing_execution_feedback', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('pipeline_id');
            $table->uuid('asset_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('type', 80)->default('review_note');
            $table->text('body');
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->foreign('pipeline_id')->references('id')->on('agentic_marketing_execution_pipelines')->cascadeOnDelete();
            $table->foreign('asset_id')->references('id')->on('agentic_marketing_execution_assets')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('agentic_marketing_execution_audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('pipeline_id');
            $table->uuid('asset_id')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('event', 120)->index();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('pipeline_id')->references('id')->on('agentic_marketing_execution_pipelines')->cascadeOnDelete();
            $table->foreign('asset_id')->references('id')->on('agentic_marketing_execution_assets')->nullOnDelete();
            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['pipeline_id', 'created_at'], 'am_execution_audit_pipeline_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agentic_marketing_execution_audit_logs');
        Schema::dropIfExists('agentic_marketing_execution_feedback');
        Schema::dropIfExists('agentic_marketing_execution_approvals');
        Schema::dropIfExists('agentic_marketing_execution_assets');
        Schema::dropIfExists('agentic_marketing_execution_pipelines');
    }
};
