<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('growth_programs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->index();
            $table->string('name', 220);
            $table->text('description')->nullable();
            $table->string('status', 40)->default('detected')->index();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('score', 8, 2)->default(0)->index();
            $table->decimal('estimated_impact', 8, 2)->default(0);
            $table->decimal('estimated_reach', 12, 2)->default(0);
            $table->decimal('estimated_ai_visibility_impact', 8, 2)->default(0);
            $table->json('metrics')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('detected_at')->nullable();
            $table->timestamp('qualified_at')->nullable();
            $table->timestamp('planned_at')->nullable();
            $table->timestamp('briefed_at')->nullable();
            $table->timestamp('drafting_at')->nullable();
            $table->timestamp('review_at')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('measured_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->index(['workspace_id', 'status', 'score'], 'growth_programs_workspace_status_score_idx');
        });

        Schema::create('growth_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->index();
            $table->uuid('growth_program_id')->index();
            $table->string('status', 40)->default('running')->index();
            $table->string('stage', 40)->default('detected')->index();
            $table->string('triggered_by', 80)->default('manual');
            $table->json('input')->nullable();
            $table->json('result')->nullable();
            $table->json('metrics_snapshot')->nullable();
            $table->text('failure_reason')->nullable();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('growth_program_id')->references('id')->on('growth_programs')->cascadeOnDelete();
            $table->index(['growth_program_id', 'stage', 'status'], 'growth_runs_program_stage_status_idx');
        });

        Schema::create('growth_assets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->index();
            $table->uuid('growth_program_id')->index();
            $table->uuid('growth_run_id')->nullable()->index();
            $table->string('role', 80)->index();
            $table->string('assetable_type', 191);
            $table->uuid('assetable_id');
            $table->string('status_at_link', 80)->nullable();
            $table->string('source_type', 80)->default('manual');
            $table->decimal('weight', 8, 2)->default(1);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('growth_program_id')->references('id')->on('growth_programs')->cascadeOnDelete();
            $table->foreign('growth_run_id')->references('id')->on('growth_runs')->nullOnDelete();
            $table->unique(['growth_program_id', 'assetable_type', 'assetable_id', 'role'], 'growth_assets_program_asset_role_unique');
            $table->index(['assetable_type', 'assetable_id'], 'growth_assets_assetable_idx');
            $table->index(['growth_program_id', 'role'], 'growth_assets_program_role_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('growth_assets');
        Schema::dropIfExists('growth_runs');
        Schema::dropIfExists('growth_programs');
    }
};
