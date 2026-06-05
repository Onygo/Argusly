<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('agentic_marketing_runs') && ! Schema::hasTable('agentic_marketing_run_items')) {
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
        }

        if (! Schema::hasTable('agentic_marketing_audit_logs')) {
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
    }

    public function down(): void
    {
        Schema::dropIfExists('agentic_marketing_audit_logs');
        Schema::dropIfExists('agentic_marketing_run_items');
    }
};
