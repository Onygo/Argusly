<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_workflow_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('workflow_key');
            $table->string('trigger_type', 32);
            $table->string('trigger_source')->nullable();
            $table->string('status', 32);
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->uuid('workspace_id')->nullable();
            $table->uuid('site_id')->nullable();
            $table->uuid('content_id')->nullable();
            $table->uuid('draft_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->json('input_payload')->nullable();
            $table->json('output_payload')->nullable();
            $table->text('summary')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['workflow_key', 'created_at'], 'agent_workflow_runs_key_created_idx');
            $table->index(['site_id', 'status'], 'agent_workflow_runs_site_status_idx');
            $table->index(['content_id', 'created_at'], 'agent_workflow_runs_content_created_idx');
            $table->index(['draft_id', 'created_at'], 'agent_workflow_runs_draft_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_workflow_runs');
    }
};

