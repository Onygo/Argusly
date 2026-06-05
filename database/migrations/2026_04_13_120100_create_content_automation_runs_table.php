<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_automation_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('automation_id');
            $table->unsignedBigInteger('organization_id');
            $table->uuid('workspace_id');
            $table->uuid('client_site_id')->nullable();
            $table->string('status', 32)->default('queued');
            $table->string('triggered_by', 24)->default('scheduled');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('result_summary')->nullable();
            $table->text('error_message')->nullable();
            $table->json('generated_draft_ids')->nullable();
            $table->json('generated_content_ids')->nullable();
            $table->json('published_content_ids')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['automation_id', 'created_at'], 'content_automation_runs_automation_idx');
            $table->index(['organization_id', 'status'], 'content_automation_runs_status_idx');
            $table->foreign('automation_id')->references('id')->on('content_automations')->cascadeOnDelete();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_automation_runs');
    }
};
