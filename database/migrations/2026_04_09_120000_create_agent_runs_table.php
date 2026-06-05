<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('agent_key', 120);
            $table->string('trigger_type', 64);
            $table->string('trigger_source', 191)->nullable();
            $table->string('status', 32);
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->uuid('workspace_id')->nullable();
            $table->uuid('site_id')->nullable();
            $table->uuid('content_id')->nullable();
            $table->uuid('draft_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('input_payload')->nullable();
            $table->json('output_payload')->nullable();
            $table->text('summary')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['agent_key', 'created_at'], 'agent_runs_agent_created_idx');
            $table->index(['status', 'created_at'], 'agent_runs_status_created_idx');
            $table->index(['trigger_type', 'created_at'], 'agent_runs_trigger_created_idx');
            $table->index(['workspace_id', 'site_id'], 'agent_runs_workspace_site_idx');
            $table->index(['content_id', 'draft_id'], 'agent_runs_content_draft_idx');

            $table->foreign('workspace_id')->references('id')->on('workspaces')->nullOnDelete();
            $table->foreign('site_id')->references('id')->on('client_sites')->nullOnDelete();
            $table->foreign('content_id')->references('id')->on('contents')->nullOnDelete();
            $table->foreign('draft_id')->references('id')->on('drafts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_runs');
    }
};
