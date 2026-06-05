<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agentic_marketing_workflow_rules')) {
            Schema::create('agentic_marketing_workflow_rules', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
                $table->uuid('workspace_id')->index();
                $table->uuid('campaign_id')->nullable()->index();
                $table->string('name', 180);
                $table->string('trigger_type', 80)->index();
                $table->string('status', 32)->default('active')->index();
                $table->unsignedTinyInteger('minimum_confidence_score')->default(70);
                $table->unsignedTinyInteger('maximum_actions_per_run')->default(10);
                $table->boolean('generate_campaign_proposals')->default(true);
                $table->boolean('generate_content_drafts')->default(true);
                $table->boolean('schedule_distribution_drafts')->default(true);
                $table->boolean('auto_queue_approved_actions')->default(false);
                $table->boolean('requires_human_approval')->default(true);
                $table->json('allowed_action_types')->nullable();
                $table->json('signal_filters')->nullable();
                $table->json('policy')->nullable();
                $table->timestamp('last_run_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('workspace_id', 'am_wr_workspace_fk')->references('id')->on('workspaces')->cascadeOnDelete();
                $table->foreign('campaign_id', 'am_wr_campaign_fk')->references('id')->on('campaigns')->nullOnDelete();
                $table->index(['workspace_id', 'trigger_type', 'status'], 'am_workflow_rules_workspace_trigger_status_idx');
            });
        }

        if (! Schema::hasTable('agentic_marketing_workflow_overrides')) {
            Schema::create('agentic_marketing_workflow_overrides', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
                $table->uuid('workspace_id')->index();
                $table->uuid('agent_workflow_run_id')->nullable()->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('override_type', 64)->index();
                $table->string('subject_type', 120)->nullable();
                $table->string('subject_id', 64)->nullable();
                $table->text('reason');
                $table->json('payload')->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('workspace_id', 'am_wo_workspace_fk')->references('id')->on('workspaces')->cascadeOnDelete();
                $table->foreign('agent_workflow_run_id', 'am_wo_workflow_run_fk')->references('id')->on('agent_workflow_runs')->nullOnDelete();
                $table->foreign('user_id', 'am_wo_user_fk')->references('id')->on('users')->nullOnDelete();
                $table->index(['workspace_id', 'override_type', 'is_active'], 'am_workflow_overrides_workspace_type_active_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('agentic_marketing_workflow_overrides');
        Schema::dropIfExists('agentic_marketing_workflow_rules');
    }
};
