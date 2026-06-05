<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agentic_action_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->uuid('brand_id')->nullable()->index();
            $table->uuid('goal_id')->nullable()->index();
            $table->uuid('opportunity_id')->nullable()->index();
            $table->uuid('content_id')->nullable()->index();
            $table->uuid('action_id')->nullable()->index();
            $table->string('action_type', 96)->index();
            $table->string('execution_mode_snapshot', 32)->default('guided')->index();
            $table->string('status', 32)->default('proposed')->index();
            $table->text('reason')->nullable();
            $table->json('policy_snapshot')->nullable();
            $table->json('input_snapshot')->nullable();
            $table->json('output_snapshot')->nullable();
            $table->unsignedInteger('estimated_credits')->nullable();
            $table->unsignedInteger('actual_credits')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->boolean('executed_by_agent')->default(false)->index();
            $table->string('job_id')->nullable()->index();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('brand_id')->references('id')->on('brand_voices')->nullOnDelete();
            $table->foreign('goal_id')->references('id')->on('agentic_marketing_objectives')->nullOnDelete();
            $table->foreign('opportunity_id')->references('id')->on('agentic_marketing_opportunities')->nullOnDelete();
            $table->foreign('content_id')->references('id')->on('contents')->nullOnDelete();
            $table->foreign('action_id')->references('id')->on('agentic_marketing_actions')->nullOnDelete();
            $table->index(['workspace_id', 'status', 'created_at'], 'agentic_action_runs_workspace_status_idx');
            $table->index(['workspace_id', 'action_type', 'created_at'], 'agentic_action_runs_workspace_type_idx');
            $table->index(['workspace_id', 'execution_mode_snapshot', 'created_at'], 'agentic_action_runs_workspace_mode_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agentic_action_runs');
    }
};
