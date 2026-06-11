<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('programmatic_draft_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('growth_program_id')->nullable();
            $table->uuid('programmatic_brief_blueprint_id')->nullable();
            $table->uuid('brief_id');
            $table->uuid('programmatic_cluster_id')->nullable();
            $table->uuid('programmatic_cluster_item_id')->nullable();
            $table->string('growth_asset_type', 80);
            $table->string('title', 255);
            $table->string('slug', 255);
            $table->decimal('priority_score', 5, 2)->nullable();
            $table->decimal('estimated_cost', 10, 4)->nullable();
            $table->unsignedInteger('estimated_tokens')->nullable();
            $table->string('status', 40)->default('pending')->index();
            $table->string('generation_mode', 40)->default('manual')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('growth_program_id')->references('id')->on('growth_programs')->nullOnDelete();
            $table->foreign('programmatic_brief_blueprint_id', 'programmatic_draft_requests_blueprint_fk')
                ->references('id')
                ->on('programmatic_brief_blueprints')
                ->nullOnDelete();
            $table->foreign('brief_id')->references('id')->on('briefs')->cascadeOnDelete();
            $table->foreign('programmatic_cluster_id', 'programmatic_draft_requests_cluster_fk')
                ->references('id')
                ->on('programmatic_clusters')
                ->nullOnDelete();
            $table->foreign('programmatic_cluster_item_id', 'programmatic_draft_requests_item_fk')
                ->references('id')
                ->on('programmatic_cluster_items')
                ->nullOnDelete();
            $table->unique('brief_id', 'programmatic_draft_requests_brief_unique');
            $table->index('workspace_id', 'prog_draft_req_workspace_idx');
            $table->index('growth_program_id', 'prog_draft_req_program_idx');
            $table->index('programmatic_brief_blueprint_id', 'prog_draft_req_blueprint_idx');
            $table->index('programmatic_cluster_id', 'prog_draft_req_cluster_idx');
            $table->index('programmatic_cluster_item_id', 'prog_draft_req_item_idx');
            $table->index('growth_asset_type', 'prog_draft_req_asset_type_idx');
            $table->index(['workspace_id', 'status', 'generation_mode'], 'programmatic_draft_requests_workspace_status_mode_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('programmatic_draft_requests');
    }
};
