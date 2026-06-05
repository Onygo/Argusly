<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('research_projects', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->uuid('brief_id')->nullable();
            $table->uuid('client_site_id')->nullable();
            $table->string('name', 191);
            $table->string('status', 32)->default('draft');
            $table->json('target_keywords')->nullable();
            $table->json('config')->nullable();
            $table->json('summary')->nullable();
            $table->text('human_summary')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status', 'created_at'], 'research_projects_ws_status_created_idx');
            $table->index(['workspace_id', 'created_at'], 'research_projects_ws_created_idx');
            $table->index(['workspace_id', 'brief_id'], 'research_projects_ws_brief_idx');
            $table->index(['workspace_id', 'client_site_id'], 'research_projects_ws_site_idx');

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('brief_id')->references('id')->on('briefs')->nullOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('research_projects');
    }
};
