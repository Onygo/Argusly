<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agentic_marketing_orchestration_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->nullable();
            $table->uuid('client_site_id')->nullable();
            $table->uuid('objective_id')->nullable();
            $table->string('workflow_key', 120);
            $table->string('status', 40)->default('queued')->index();
            $table->string('mode', 40)->default('manual');
            $table->string('provider_key', 120)->default('deterministic');
            $table->string('trigger_source', 120)->nullable();
            $table->json('shared_context')->nullable();
            $table->json('input')->nullable();
            $table->json('normalized_result')->nullable();
            $table->decimal('confidence_score', 5, 2)->default(0);
            $table->unsignedInteger('tasks_count')->default(0);
            $table->unsignedInteger('completed_tasks_count')->default(0);
            $table->unsignedInteger('failed_tasks_count')->default(0);
            $table->unsignedInteger('conflicts_count')->default(0);
            $table->text('failure_reason')->nullable();
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->nullOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->nullOnDelete();
            $table->foreign('objective_id')->references('id')->on('agentic_marketing_objectives')->nullOnDelete();
            $table->foreign('requested_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['workspace_id', 'status'], 'am_orchestration_runs_workspace_status_idx');
            $table->index(['workflow_key', 'created_at'], 'am_orchestration_runs_workflow_created_idx');
        });

        Schema::create('agentic_marketing_agent_tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('orchestration_run_id');
            $table->string('agent_key', 120);
            $table->string('status', 40)->default('queued')->index();
            $table->unsignedInteger('sequence_order')->default(0);
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('max_attempts')->default(2);
            $table->json('input')->nullable();
            $table->json('normalized_result')->nullable();
            $table->decimal('confidence_score', 5, 2)->default(0);
            $table->json('tool_plan')->nullable();
            $table->json('mcp_context')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->foreign('orchestration_run_id')->references('id')->on('agentic_marketing_orchestration_runs')->cascadeOnDelete();
            $table->index(['orchestration_run_id', 'status'], 'am_agent_tasks_run_status_idx');
            $table->index(['agent_key', 'created_at'], 'am_agent_tasks_agent_created_idx');
        });

        Schema::create('agentic_marketing_agent_memories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->nullable();
            $table->uuid('client_site_id')->nullable();
            $table->string('agent_key', 120);
            $table->string('memory_type', 80)->default('insight');
            $table->string('memory_key', 191);
            $table->json('payload');
            $table->decimal('confidence_score', 5, 2)->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->nullOnDelete();
            $table->unique(['workspace_id', 'agent_key', 'memory_key'], 'am_agent_memory_workspace_agent_key_unique');
            $table->index(['workspace_id', 'agent_key'], 'am_agent_memory_workspace_agent_idx');
        });

        Schema::create('agentic_marketing_agent_traces', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('orchestration_run_id');
            $table->uuid('agent_task_id')->nullable();
            $table->string('event', 120)->index();
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();

            $table->foreign('orchestration_run_id')->references('id')->on('agentic_marketing_orchestration_runs')->cascadeOnDelete();
            $table->foreign('agent_task_id')->references('id')->on('agentic_marketing_agent_tasks')->nullOnDelete();
            $table->index(['orchestration_run_id', 'created_at'], 'am_agent_traces_run_created_idx');
        });

        Schema::create('agentic_marketing_agent_conflicts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('orchestration_run_id');
            $table->string('conflict_key', 191);
            $table->string('status', 40)->default('open')->index();
            $table->json('claims');
            $table->json('resolution')->nullable();
            $table->timestamps();

            $table->foreign('orchestration_run_id')->references('id')->on('agentic_marketing_orchestration_runs')->cascadeOnDelete();
            $table->index(['orchestration_run_id', 'status'], 'am_agent_conflicts_run_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agentic_marketing_agent_conflicts');
        Schema::dropIfExists('agentic_marketing_agent_traces');
        Schema::dropIfExists('agentic_marketing_agent_memories');
        Schema::dropIfExists('agentic_marketing_agent_tasks');
        Schema::dropIfExists('agentic_marketing_orchestration_runs');
    }
};
