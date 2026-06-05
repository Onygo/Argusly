<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agentic_marketing_objectives', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->nullable()->index();
            $table->uuid('client_site_id')->nullable()->index();
            $table->string('name');
            $table->text('goal');
            $table->string('locale', 12)->default('en');
            $table->text('audience')->nullable();
            $table->string('target_market')->nullable();
            $table->json('languages')->nullable();
            $table->string('industry')->nullable();
            $table->string('priority', 32)->default('medium');
            $table->string('kpi_type')->nullable();
            $table->unsignedInteger('monthly_credit_budget')->nullable();
            $table->json('brand_entities')->nullable();
            $table->json('competitors')->nullable();
            $table->json('channels')->nullable();
            $table->string('tone')->nullable();
            $table->string('approval_mode', 32)->default('manual');
            $table->string('status', 32)->default('active')->index();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->nullOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->nullOnDelete();
            $table->index(['organization_id', 'status'], 'agentic_objectives_org_status_idx');
        });

        Schema::create('agentic_marketing_opportunities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('objective_id')->index();
            $table->uuid('content_id')->nullable()->index();
            $table->string('title');
            $table->string('type', 64)->nullable();
            $table->unsignedTinyInteger('priority_score')->default(50);
            $table->string('status', 32)->default('open')->index();
            $table->json('payload')->nullable();
            $table->char('payload_hash', 64)->nullable();
            $table->char('dedupe_hash', 64)->nullable();
            $table->char('open_dedupe_hash', 64)->nullable();
            $table->timestamps();

            $table->foreign('objective_id')->references('id')->on('agentic_marketing_objectives')->cascadeOnDelete();
            $table->foreign('content_id')->references('id')->on('contents')->nullOnDelete();
        });

        Schema::create('agentic_marketing_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('objective_id')->index();
            $table->string('status', 32)->default('queued')->index();
            $table->json('payload')->nullable();
            $table->json('result')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->foreign('objective_id')->references('id')->on('agentic_marketing_objectives')->cascadeOnDelete();
        });

        Schema::create('agentic_marketing_run_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('run_id')->index();
            $table->uuid('objective_id')->index();
            $table->uuid('opportunity_id')->nullable()->index();
            $table->uuid('action_id')->nullable()->index();
            $table->string('type', 32)->index();
            $table->string('name');
            $table->string('status', 32)->default('queued')->index();
            $table->json('payload')->nullable();
            $table->json('result')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->foreign('run_id')->references('id')->on('agentic_marketing_runs')->cascadeOnDelete();
            $table->foreign('objective_id')->references('id')->on('agentic_marketing_objectives')->cascadeOnDelete();
            $table->foreign('opportunity_id')->references('id')->on('agentic_marketing_opportunities')->nullOnDelete();
            $table->index(['run_id', 'status'], 'agentic_run_items_run_status_idx');
        });

        Schema::create('agentic_marketing_actions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('objective_id')->index();
            $table->uuid('opportunity_id')->nullable()->index();
            $table->uuid('run_id')->nullable()->index();
            $table->uuid('content_id')->nullable()->index();
            $table->uuid('draft_id')->nullable()->index();
            $table->string('action_type', 64)->index();
            $table->string('status', 32)->default('proposed')->index();
            $table->json('payload')->nullable();
            $table->char('payload_hash', 64)->nullable();
            $table->char('dedupe_hash', 64)->nullable();
            $table->char('open_dedupe_hash', 64)->nullable();
            $table->json('result')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('estimated_credits')->nullable();
            $table->uuid('credit_reservation_id')->nullable()->index();
            $table->unsignedInteger('credits_reserved')->nullable();
            $table->unsignedInteger('credits_captured')->nullable();
            $table->string('credit_status', 32)->default('unreserved')->index();
            $table->text('credit_error_message')->nullable();
            $table->timestamp('budget_checked_at')->nullable();
            $table->timestamp('budget_exceeded_at')->nullable();
            $table->uuid('execution_claim_id')->nullable()->index();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamp('execution_claimed_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->foreign('objective_id')->references('id')->on('agentic_marketing_objectives')->cascadeOnDelete();
            $table->foreign('opportunity_id')->references('id')->on('agentic_marketing_opportunities')->nullOnDelete();
            $table->foreign('run_id')->references('id')->on('agentic_marketing_runs')->nullOnDelete();
            $table->foreign('content_id')->references('id')->on('contents')->nullOnDelete();
            $table->foreign('draft_id')->references('id')->on('drafts')->nullOnDelete();
            $table->foreign('credit_reservation_id')->references('id')->on('credit_reservations')->nullOnDelete();
            $table->index(['objective_id', 'status'], 'agentic_actions_objective_status_idx');
        });

        Schema::create('agentic_marketing_audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('objective_id')->nullable()->index();
            $table->uuid('opportunity_id')->nullable()->index();
            $table->uuid('action_id')->nullable()->index();
            $table->uuid('run_id')->nullable()->index();
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->string('event', 96)->index();
            $table->string('subject_type', 96);
            $table->string('subject_id', 64);
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('objective_id')->references('id')->on('agentic_marketing_objectives')->nullOnDelete();
            $table->foreign('opportunity_id')->references('id')->on('agentic_marketing_opportunities')->nullOnDelete();
            $table->foreign('action_id')->references('id')->on('agentic_marketing_actions')->nullOnDelete();
            $table->foreign('run_id')->references('id')->on('agentic_marketing_runs')->nullOnDelete();
            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['organization_id', 'created_at'], 'agentic_audit_org_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agentic_marketing_audit_logs');
        Schema::dropIfExists('agentic_marketing_actions');
        Schema::dropIfExists('agentic_marketing_run_items');
        Schema::dropIfExists('agentic_marketing_runs');
        Schema::dropIfExists('agentic_marketing_opportunities');
        Schema::dropIfExists('agentic_marketing_objectives');
    }
};
